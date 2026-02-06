<?php
// SistemaReparto/Mail/delivered.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

header('Content-Type: application/json; charset=utf-8');

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
    $mail->Host       = 'mail.caddy.com.ar';           // SMTP real
    $mail->SMTPAuth   = true;
    $mail->Username   = 'notificaciones@caddy.com.ar';
    $mail->Password   = 'TU_PASSWORD_DEL_BUZON';       // mover a config seguro
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->CharSet = 'UTF-8';

    // Headers
    $mail->setFrom('notificaciones@caddy.com.ar', 'Caddy. Yo lo llevo!');
    $mail->addReplyTo('noreply@caddy.com.ar', 'No responder');

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
    $safeMsg  = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

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
