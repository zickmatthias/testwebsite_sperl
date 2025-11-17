<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

// .env laden
Dotenv::createImmutable(__DIR__ . '/..')->load();

$WORKER_URL   = getenv('MAILJET_WORKER_URL');
$WORKER_TOKEN = getenv('WORKER_TOKEN');

// Fallback: wenn dotenv nicht geladen (z. B. builtin PHP server), versuche .env manuell zu parsen
if (!$WORKER_URL || !$WORKER_TOKEN) {
    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            // remove surrounding quotes
            if ((substr($v,0,1) === '"' && substr($v,-1) === '"') || (substr($v,0,1) === "'" && substr($v,-1) === "'")) {
                $v = substr($v,1,-1);
            }
            // put into getenv/$_ENV/$_SERVER
            putenv(sprintf('%s=%s', $k, $v));
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        // reload
        $WORKER_URL   = getenv('MAILJET_WORKER_URL');
        $WORKER_TOKEN = getenv('WORKER_TOKEN');
    }
}

// If still missing, return a helpful error showing which keys are absent (no secret values)
if (!$WORKER_URL || !$WORKER_TOKEN) {
    $have_url = $WORKER_URL ? true : false;
    $have_token = $WORKER_TOKEN ? true : false;
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Worker nicht konfiguriert.',
        'have' => [
            'MAILJET_WORKER_URL' => $have_url,
            'WORKER_TOKEN' => $have_token,
            'env_file_found' => is_readable(__DIR__ . '/../.env')
        ]
    ]);
    exit;
}

// Nur POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Only POST allowed']);
    exit;
}

// Formulardaten
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? 'Kontaktanfrage');
$subject = preg_replace('/[\r\n]+/',' ', $subject);
$message = trim($_POST['message'] ?? '');

if (!$name || !$email || !$message || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Bitte gültige Daten eingeben.']);
    exit;
}

// Anhänge (konvertieren zu Mailjet-kompatiblen Keys)
$attachments = [];
$attachments_omitted = false;
// Safety: limit total uploaded payload forwarded to worker (avoid extremely large requests)
// adjust MAX_TOTAL_ATTACHMENTS_BYTES as needed (Cloudflare Worker + Mailjet limits)
define('MAX_TOTAL_ATTACHMENTS_BYTES', 20 * 1024 * 1024); // 20 MB

// support multiple possible file field names (attachments[], files[], etc.)
$fileField = null;
if (!empty($_FILES['attachments'])) {
    $fileField = 'attachments';
} elseif (!empty($_FILES['files'])) {
    $fileField = 'files';
} elseif (!empty($_FILES)) {
    // fallback: take the first uploaded files field
    reset($_FILES);
    $firstKey = key($_FILES);
    if ($firstKey) $fileField = $firstKey;
}

if ($fileField) {
    $files = $_FILES[$fileField];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $types = is_array($files['type']) ? $files['type'] : [$files['type']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

    $totalSize = array_sum(array_map('intval', $sizes));
    if ($totalSize > MAX_TOTAL_ATTACHMENTS_BYTES) {
        // skip attachments but keep processing; signal in body
        $attachments = [];
        $attachments_omitted = true;
    } else {
        $attachments_omitted = false;
        foreach ($tmp as $i => $tmpName) {
            if (!$tmpName || !is_uploaded_file($tmpName)) continue;
            $data = file_get_contents($tmpName);
            if ($data === false) continue;
            // Mailjet expects keys: ContentType, Filename, Base64
            $b64 = base64_encode($data);
            // include both keys (Base64 and Base64Content) to be compatible with Mailjet client and direct API
            $attachments[] = [
                'ContentType' => $types[$i] ?? 'application/octet-stream',
                'Filename' => basename($names[$i] ?? 'attachment'),
                'Base64' => $b64,
                'Base64Content' => $b64
            ];
        }
    }
}

// E-Mail Payload
$SENDER_EMAIL    = getenv('SENDER_EMAIL') ?: 'no-reply@yourdomain.tld';
$RECIPIENT_EMAIL = getenv('RECIPIENT_EMAIL');

if (!$RECIPIENT_EMAIL) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Recipient nicht gesetzt']);
    exit;
}

// optional note when attachments omitted due to size
$attachmentsNote = '';
if (!empty($attachments_omitted)) {
    $attachmentsNote = "\n\nHinweis: Anhänge wurden aufgrund der Dateigröße nicht übermittelt.";
}

$messageBody = [
    'From' => ['Email' => $SENDER_EMAIL, 'Name' => 'Kontaktformular'],
    'To'   => [['Email' => $RECIPIENT_EMAIL, 'Name' => 'Empfänger']],
    'Subject' => $subject,
    'TextPart' => "Von: $name <$email>\n\n$message" . $attachmentsNote,
    'HTMLPart' => "<p><strong>Von:</strong> ".htmlspecialchars($name)." &lt;".htmlspecialchars($email)."&gt;</p><p>".nl2br(htmlspecialchars($message))."</p>" . (!empty($attachments_omitted) ? "<p><em>Hinweis: Anhänge wurden aufgrund der Dateigröße nicht übermittelt.</em></p>" : ''),
    'Headers' => ['Reply-To' => $email]
];

if (!empty($attachments)) {
    $messageBody['Attachments'] = $attachments;
}

$payload = ['Messages' => [$messageBody]];

// compute attachments metadata for debugging (counts, filenames, decoded sizes)
$attachments_meta = ['count' => 0, 'total_decoded_bytes' => 0, 'files' => []];
if (!empty($attachments)) {
    $attachments_meta['count'] = count($attachments);
    foreach ($attachments as $at) {
        $fn = $at['Filename'] ?? ($at['filename'] ?? 'unknown');
        $b64 = $at['Base64'] ?? $at['Base64Content'] ?? $at['base64'] ?? '';
        $decoded = base64_decode($b64, true);
        $size = ($decoded === false) ? null : strlen($decoded);
        if ($size !== null) $attachments_meta['total_decoded_bytes'] += $size;
        $attachments_meta['files'][] = ['filename' => $fn, 'decoded_bytes' => $size];
    }
}

// POST an Worker
$ch = curl_init($WORKER_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-worker-token: ' . $WORKER_TOKEN
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$errstr   = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) {
    http_response_code(502);
    echo json_encode(['success'=>false,'message'=>'Worker request failed','err'=>$errstr]);
    exit;
}

// Normalize worker / Mailjet response into the simple shape the frontend expects
$decoded = json_decode($response, true);
$out = ['success' => false, 'message' => 'Unknown error from worker', 'worker_http' => $httpCode];

if ($httpCode >= 200 && $httpCode < 300) {
    // Prefer Mailjet success detection: Messages[0].Status == 'success'
    if (is_array($decoded) && isset($decoded['Messages']) && is_array($decoded['Messages']) && isset($decoded['Messages'][0]['Status'])) {
        $status = strtolower($decoded['Messages'][0]['Status']);
        if ($status === 'success' || $status === 'sent') {
            $out['success'] = true;
            $out['message'] = 'Nachricht gesendet.';
        } else {
            $out['success'] = false;
            $out['message'] = 'Mailjet returned status: ' . $decoded['Messages'][0]['Status'];
        }
    } else {
        // If Mailjet returned 2xx but doesn't follow expected shape, treat as success
        $out['success'] = true;
        $out['message'] = 'Nachricht gesendet.';
    }
} else {
    // non-2xx -> return worker response message if present
    $out['success'] = false;
    if (is_array($decoded) && isset($decoded['error'])) $out['message'] = $decoded['error'];
    elseif (is_array($decoded) && isset($decoded['Message'])) $out['message'] = $decoded['Message'];
    else $out['message'] = 'Worker returned HTTP ' . $httpCode;
}

// expose if attachments were omitted by PHP due to size
if (!empty($attachments_omitted)) $out['attachments_omitted'] = true;

// include attachments debug metadata (non-sensitive)
$out['attachments_meta'] = $attachments_meta;

http_response_code(200);
echo json_encode($out);
exit;
