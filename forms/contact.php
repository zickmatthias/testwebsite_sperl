<?php
header('Content-Type: application/json; charset=utf-8');

// Contact handler using mailjet/mailjet-apiv3-php client (preferred)
// Falls back to HTTP cURL if the client class is not present.

$logFile = __DIR__ . '/../tmp/contact_debug.log';
if (!is_dir(dirname($logFile))) { @mkdir(dirname($logFile), 0755, true); }

$root = realpath(__DIR__ . '/..');
if ($root && is_dir($root) && file_exists($root . '/vendor/autoload.php')) {
  require_once $root . '/vendor/autoload.php';
  if (class_exists('\Dotenv\Dotenv')) {
    try { \Dotenv\Dotenv::createImmutable($root)->safeLoad(); } catch (Throwable $e) {}
  }
}

function env($k, $d = null) {
  // Check common places: $_ENV, $_SERVER, then getenv().
  if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
  if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
  $v = getenv($k);
  return $v === false ? $d : $v;
}
function log_debug($data) { global $logFile; @file_put_contents($logFile, date('c') . ' ' . json_encode($data) . PHP_EOL, FILE_APPEND); }

// Basic request validation
$rawInput = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST' && empty($_POST) && empty($rawInput)) { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Spam detected']); exit; }

$name    = trim(strip_tags($_POST['name'] ?? ''));
$email   = trim($_POST['email'] ?? '');
$subject = trim(strip_tags($_POST['subject'] ?? 'Kontaktanfrage'));
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Bitte alle Pflichtfelder ausfüllen.']); exit; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Ungültige E-Mail-Adresse.']); exit; }

$mjPublic  = env('MJ_APIKEY_PUBLIC', env('SMTP_USER'));
$mjPrivate = env('MJ_APIKEY_PRIVATE', env('SMTP_PASS'));
$recipient = env('CONTACT_RECIPIENT', 'vermietung@quartier-johann-sperl.de');
$recipientName = env('CONTACT_RECIPIENT_NAME', 'Vermietung');
$fromEmail = env('MAIL_FROM', 'no-reply@yourdomain.tld');
$fromName  = env('MAIL_FROM_NAME', 'Kontaktformular');

log_debug(['incoming'=>['ip'=>$_SERVER['REMOTE_ADDR']??null,'post_keys'=>array_keys($_POST)]]);

if (empty($mjPublic) || empty($mjPrivate)) { log_debug(['error'=>'Mailjet API keys missing']); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Email service not configured.']); exit; }

// Message body
$textPart = $name . "\n\n" . $message;
$htmlPart = "<p><strong>Von:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>" .
      "<p><strong>Betreff:</strong> " . htmlspecialchars($subject) . "</p><hr><p>" . nl2br(htmlspecialchars($message)) . "</p>";

// If Mailjet client class available, use it (preferred)
if (class_exists('\Mailjet\Client')) {
  try {
    $mj = new \Mailjet\Client($mjPublic, $mjPrivate, true, ['version' => 'v3.1']);
    $body = [
      'Messages' => [[
        'From' => ['Email' => $fromEmail, 'Name' => $fromName],
        'To' => [['Email' => $recipient, 'Name' => $recipientName]],
        'Subject' => mb_substr($subject, 0, 150, 'UTF-8'),
        'TextPart' => $textPart,
        'HTMLPart' => $htmlPart,
        'Headers' => ['Reply-To' => $email]
      ]]
    ];

    $response = $mj->post(\Mailjet\Resources::$Email, ['body' => $body]);
    log_debug(['mailjet_client_http' => $response->getStatus(), 'response' => $response->getData()]);

    if ($response->success()) {
      echo json_encode(['success' => true, 'message' => 'Ihre Nachricht wurde gesendet.']);
      exit;
    }

    // client returned non-success
    $respData = $response->getData();
    $errMsg = $respData['ErrorMessage'] ?? json_encode($respData);
    log_debug(['mailjet_client_failed' => $errMsg, 'raw' => $respData]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Nachricht.']);
    exit;

  } catch (Throwable $e) {
    log_debug(['mailjet_client_exception' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Nachricht.']);
    exit;
  }
}

// Fallback to cURL if client not available
$payload = [
  'Messages' => [[
    'From' => ['Email' => $fromEmail, 'Name' => $fromName],
    'To' => [['Email' => $recipient, 'Name' => $recipientName]],
    'Subject' => mb_substr($subject, 0, 150, 'UTF-8'),
    'TextPart' => $textPart,
    'HTMLPart' => $htmlPart,
    'Headers' => ['Reply-To' => $email]
  ]]
];

$ch = curl_init('https://api.mailjet.com/v3.1/send');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_USERPWD, $mjPublic . ':' . $mjPrivate);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$errstr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) { log_debug(['mailjet_curl_error' => $errno, 'error' => $errstr]); http_response_code(500); echo json_encode(['success' => false, 'message' => 'Fehler beim Senden (HTTP client).']); exit; }

$respData = json_decode($response, true);
log_debug(['mailjet_response_http' => $httpCode, 'body' => $respData]);

if ($httpCode >= 200 && $httpCode < 300 && isset($respData['Messages'][0]['Status']) && in_array(strtolower($respData['Messages'][0]['Status']), ['success','queued','sent'])) {
  echo json_encode(['success' => true, 'message' => 'Ihre Nachricht wurde gesendet.']);
  exit;
}

$errMsg = $respData['ErrorMessage'] ?? ($respData['Messages'][0]['Errors'][0]['Error'] ?? 'Unbekannter Mailjet-Fehler');
log_debug(['mailjet_failed' => $errMsg, 'raw' => $respData]);
http_response_code(500); echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Nachricht.']); exit;

