<?php
// ...existing code...
<?php
// Simple contact handler using PHPMailer
// Requires: composer require phpmailer/phpmailer
// Configure SMTP below before use.

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']);
  exit;
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? 'Kontaktanfrage');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Bitte alle Pflichtfelder ausfüllen.']);
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Ungültige E‑Mail-Adresse.']);
  exit;
}

require __DIR__ . '/../vendor/autoload.php'; // Composer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
  // ---------- SMTP configuration (edit these) ----------
  $useSmtp = true; // set false to try PHP mail() instead

  if ($useSmtp) {
    $mail->isSMTP();
    $mail->Host       = 'smtp.example.com';    // z.B. smtp.gmail.com
    $mail->SMTPAuth   = true;
    $mail->Username   = 'you@example.com';     // SMTP username
    $mail->Password   = 'yourpassword';        // SMTP password (app password for Gmail)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // or PHPMailer::ENCRYPTION_SMTPS
    $mail->Port       = 587;                   // 587 für TLS, 465 für SSL
  } else {
    // fallback to mail()
    $mail->isMail();
  }

  // ---------- Mail headers & body ----------
  $receiving_email_address = 'contact@example.com'; // Empfänger anpassen
  $mail->setFrom($mail->Username ?? $email, $name); // wenn SMTP, nutze Username als From
  $mail->addReplyTo($email, $name);
  $mail->addAddress($receiving_email_address);

  $mail->Subject = $subject;
  $body  = "<strong>Name:</strong> " . htmlspecialchars($name) . "<br>";
  $body .= "<strong>Email:</strong> " . htmlspecialchars($email) . "<br>";
  $body .= "<strong>Nachricht:</strong><br>" . nl2br(htmlspecialchars($message)) . "<br>";
  $body .= "<hr><small>IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'n/a') . "</small>";

  $mail->isHTML(true);
  $mail->Body    = $body;
  $mail->AltBody = strip_tags(str_replace("<br>", "\n", $body));

  $mail->send();

  echo json_encode(['success' => true, 'message' => 'Nachricht wurde gesendet.']);
  exit;
} catch (Exception $e) {
  http_response_code(500);
  // Do not leak sensitive info in production
  echo json_encode(['success' => false, 'message' => 'Senden fehlgeschlagen: ' . $mail->ErrorInfo]);
  exit;
}
?>
// ...existing code...