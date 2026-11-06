<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envia un correo usando los datos SMTP guardados por el admin
 * (tabla smtp_config, editable en /admin/smtp.php).
 *
 * Devuelve [true, ''] si se envio, o [false, 'motivo'] si fallo.
 */
function send_mail(string $to, string $subject, string $htmlBody): array
{
    $stmt = db()->query("SELECT * FROM smtp_config WHERE id = 1");
    $cfg = $stmt->fetch();

    if (!$cfg || empty($cfg['host'])) {
        return [false, 'El SMTP todavia no esta configurado (Panel Admin > SMTP).'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int) $cfg['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        if ($cfg['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($cfg['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($cfg['from_email'] ?: $cfg['username'], $cfg['from_name'] ?: APP_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return [true, ''];
    } catch (PHPMailerException $e) {
        return [false, 'No se pudo enviar el correo: ' . $mail->ErrorInfo];
    }
}
