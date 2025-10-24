<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit;
}

// load composer autoload and dotenv
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Autoloader not found. Install dependencies with Composer.']);
  exit;
}
require $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// load .env
$root = realpath(__DIR__ . '/..');
if ($root && is_dir($root) && file_exists($root . '/.env')) {
  $dotenv = Dotenv::createImmutable($root);
  $dotenv->safeLoad();
}

// helper to read env with fallback
function env($key, $fallback = null) {
  if (isset($_ENV[$key])) return $_ENV[$key];
  if (getenv($key) !== false) return getenv($key);
  return $fallback;
}

// collect + sanitize
$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Spam detected']);
  exit;
}

$name    = trim(strip_tags($_POST['name'] ?? ''));
$email   = trim($_POST['email'] ?? '');
$subject = trim(strip_tags($_POST['subject'] ?? 'Kontaktanfrage'));
$message = trim(strip_tags($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Bitte alle Pflichtfelder ausfüllen.']);
  exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Ungültige E-Mail-Adresse.']);
  exit;
}

// config from .env
$smtpHost = env('SMTP_HOST', '');
$smtpUser = env('SMTP_USER', '');
$smtpPass = env('SMTP_PASS', '');
$smtpPort = env('SMTP_PORT', 587);
$smtpSecure = env('SMTP_SECURE', 'tls');

$recipientEmail = env('CONTACT_RECIPIENT', 'vermietung@quartier-johann-sperl.de');
$recipientName  = env('CONTACT_RECIPIENT_NAME', 'Vermietung');

$fromEmail = env('MAIL_FROM', 'no-reply@yourdomain.tld');
$fromName  = env('MAIL_FROM_NAME', 'Kontaktformular');

// send mail
$mail = new PHPMailer(true);
try {
  if ($smtpHost !== '') {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = !empty($smtpUser);
    if (!empty($smtpUser)) {
      $mail->Username = $smtpUser;
      $mail->Password = $smtpPass;
    }
    if (!empty($smtpSecure)) $mail->SMTPSecure = $smtpSecure;
    $mail->Port = (int)$smtpPort;
  } else {
    $mail->isMail();
  }

  $mail->setFrom($fromEmail, $fromName);
  $mail->addReplyTo($email, $name);
  $mail->addAddress($recipientEmail, $recipientName);

  $mail->isHTML(true);
  $mail->Subject = mb_substr($subject, 0, 120, 'UTF-8');
  $mail->Body = "
    <p><strong>Von:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>
    <p><strong>Betreff:</strong> " . htmlspecialchars($subject) . "</p>
    <hr>
    <p>" . nl2br(htmlspecialchars($message)) . "</p>
    <hr>
    <p>IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'n/a') . "</p>
  ";
  $mail->AltBody = strip_tags($name . "\n" . $subject . "\n\n" . $message);

  $mail->send();

  echo json_encode(['success' => true, 'message' => 'Ihre Nachricht wurde gesendet.']);
  exit;
} catch (Exception $e) {
  error_log('Mail error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Nachricht.']);
  exit;
}
?>