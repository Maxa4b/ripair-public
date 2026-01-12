<?php
require_once __DIR__ . '/../config/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../phpmailer/src/Exception.php';
require '../phpmailer/src/PHPMailer.php';
require '../phpmailer/src/SMTP.php';

header('Content-Type: application/json; charset=utf-8');

// === Récupération de la config depuis .env ===
$smtpHost = env('SMTP_HOST');
$smtpPort = env('SMTP_PORT');
$smtpUser = env('SMTP_USERNAME');
$smtpPass = env('SMTP_PASSWORD');
$smtpSecure = env('SMTP_SECURE', 'tls');
$mailFrom = env('MAIL_FROM');
$mailTo   = env('MAIL_TO');
$mailName = env('MAIL_FROM_NAME', 'RIPAIR');
$recaptchaSecret = env('RECAPTCHA_SECRET', '');

// === Honeypot anti-bot ===
if (!empty($_POST['website'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Bot detected']);
    exit;
}

// === Rate limit (1 message / IP / 30 s) ===
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lockDir = __DIR__ . '/locks';
if (!is_dir($lockDir)) mkdir($lockDir, 0755, true);

$lockFile = $lockDir . '/ripair_contact_' . md5($ip);
$limitDelay = 30;

if (file_exists($lockFile)) {
    $lastTime = (int)@file_get_contents($lockFile);
    $age = time() - $lastTime;
    if ($age < $limitDelay) {
        http_response_code(429);
        echo json_encode([
            'error' => 'too_many_requests',
            'remaining' => $limitDelay - $age
        ]);
        exit;
    }
}

@unlink($lockFile);
file_put_contents($lockFile, time());

// Auto-nettoyage des verrous vieux de +1 jour
foreach (glob($lockDir . '/ripair_contact_*') as $f) {
    $t = (int)@file_get_contents($f);
    if (time() - $t > 86400) @unlink($f);
}

// === Nettoyage des entrées ===
function clean($v) { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }
$name    = clean($_POST['name'] ?? '');
$email   = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
$message = clean($_POST['message'] ?? '');
$token   = $_POST['recaptcha_token'] ?? '';

if (!$name || !$email || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email']);
    exit;
}

// === Vérification reCAPTCHA ===
if ($recaptchaSecret) {
    $url = 'https://www.google.com/recaptcha/api/siteverify?secret=' .
           urlencode($recaptchaSecret) . '&response=' . urlencode($token);
    $resp = @file_get_contents($url);
    $data = @json_decode($resp, true);
    if (empty($data['success']) || ($data['score'] ?? 0) < 0.5) {
        http_response_code(403);
        echo json_encode(['error' => 'Captcha failed']);
        exit;
    }
}

// === Envoi du mail ===
// === Envoi du mail ===
try {
    $mail = new PHPMailer(true);

    // --- Configuration SMTP ---
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->Port = $smtpPort;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = ($smtpSecure === 'ssl')
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    // --- Encodage & format ---
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);

    // --- Expéditeur et destinataire ---
    $mail->setFrom($mailFrom, $mailName);
    $mail->addAddress($mailTo);
    $mail->addReplyTo($email, $name);

    // --- Contenu du message ---
    $mail->Subject = "📩 Nouveau message depuis le site RIPAIR";

    // Version HTML stylée
    $mail->Body = "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333;'>
      <h2 style='color:#3abafc;'>📨 Nouveau message de contact</h2>
      <p><strong>Nom :</strong> {$name}</p>
      <p><strong>Email :</strong> {$email}</p>
      <hr style='border:none; border-top:1px solid #ddd; margin:15px 0;'>
      <p style='white-space:pre-line;'>{$message}</p>
      <hr style='border:none; border-top:1px solid #ddd; margin:15px 0;'>
      <p style='font-size:13px;color:#999;'>Message envoyé automatiquement depuis le site <strong>ripair.shop</strong></p>
    </body>
    </html>";

    // Version texte (fallback)
    $mail->AltBody = "Nouveau message de contact\n\n"
        . "Nom : {$name}\n"
        . "Email : {$email}\n\n"
        . "Message :\n{$message}\n\n"
        . "--\nEnvoyé depuis le site ripair.shop";

    // --- Envoi ---
    $mail->send();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Mailer Error: ' . $mail->ErrorInfo]);
}
