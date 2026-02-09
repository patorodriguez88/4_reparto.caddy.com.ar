<?php
// SistemaReparto/Mail/delivered.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/config_mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Validación mínima
$to      = trim($_POST['txtEmail'] ?? '');
$name    = trim($_POST['txtName'] ?? '');
$subject = trim($_POST['txtAsunto'] ?? '');
$message = trim($_POST['txtMensa'] ?? '');
$templateKey = trim($_POST['txtHtml'] ?? 'delivered'); // opcional

if ($to === '') {
    echo json_encode(['success' => 0, 'error' => 'txtEmail vacío']);
    exit;
}

$mail = new PHPMailer(true);

try {
    // SMTP (cPanel)
    $mail->isSMTP();
    $mail->CharSet = 'UTF-8';
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = MAIL_SECURE === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addReplyTo(MAIL_REPLY, 'No responder');

    // Destinatario
    $mail->addAddress($to, $name ?: $to);

    // Asunto y cuerpo
    $mail->isHTML(true);
    $mail->Subject = $subject !== '' ? $subject : 'Notificación de Caddy';

    // Template seguro
    $templateKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $templateKey);
    if ($templateKey === '') $templateKey = 'delivered';

    $tplPath = __DIR__ . "/templates/{$templateKey}.html";
    if (!is_file($tplPath)) {
        $tplPath = __DIR__ . "/templates/delivered.html";
    }

    $html = file_get_contents($tplPath);

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $safeMsg = $message;
    // Mantengo los <p> originales
    $html = str_replace('<p id="name"></p>', '<p id="name">' . $safeName . '</p>', $html);
    $html = str_replace('<p id="message"></p>', '<p id="message">' . $safeMsg . '</p>', $html);

    $mail->Body    = $html;
    $mail->AltBody = strip_tags($message);

    $mail->send();

    echo json_encode(['success' => 1]);
} catch (Exception $e) {
    echo json_encode(['success' => 0, 'error' => $mail->ErrorInfo ?: $e->getMessage()]);
}
