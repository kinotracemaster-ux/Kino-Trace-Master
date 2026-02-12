<?php
/**
 * Helper de correo electrónico usando PHPMailer + SMTP.
 * 
 * Variables de entorno requeridas:
 *   SMTP_HOST     - Servidor SMTP (ej: smtp.gmail.com)
 *   SMTP_PORT     - Puerto (ej: 587)
 *   SMTP_USER     - Usuario/email de autenticación
 *   SMTP_PASS     - Contraseña o App Password
 *   SMTP_FROM     - Email del remitente (opcional, usa SMTP_USER)
 *   SMTP_FROM_NAME - Nombre del remitente (opcional, default: KINO TRACE)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Lee una variable de entorno de cualquier fuente disponible.
 * Railway puede exponerlas vía getenv(), $_ENV o $_SERVER.
 */
function smtp_env(string $key): string
{
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
}

/**
 * Verifica si el servicio SMTP está configurado.
 */
function is_smtp_configured(): bool
{
    return smtp_env('SMTP_HOST') !== '' && smtp_env('SMTP_USER') !== '' && smtp_env('SMTP_PASS') !== '';
}

/**
 * Envía un correo de recuperación de contraseña.
 *
 * @param string $to       Email del destinatario
 * @param string $nombre   Nombre del cliente
 * @param string $resetLink URL completa de recuperación
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_reset_email(string $to, string $nombre, string $resetLink): array
{
    if (!is_smtp_configured()) {
        return ['success' => false, 'error' => 'SMTP no configurado. Configure las variables de entorno SMTP_HOST, SMTP_USER y SMTP_PASS.'];
    }

    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP
        $mail->isSMTP();
        $mail->Host = smtp_env('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = smtp_env('SMTP_USER');
        $mail->Password = smtp_env('SMTP_PASS');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $port = (int) (smtp_env('SMTP_PORT') ?: 465);
        $mail->Port = $port;
        // Si el puerto es 587, usar STARTTLS en vez de SMTPS
        if ($port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->CharSet = 'UTF-8';

        // Remitente y destinatario
        $fromEmail = smtp_env('SMTP_FROM') ?: smtp_env('SMTP_USER');
        $fromName = smtp_env('SMTP_FROM_NAME') ?: 'KINO TRACE';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $nombre);

        // Contenido del correo
        $mail->isHTML(true);
        $mail->Subject = 'Recuperar Contraseña - KINO TRACE';
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 2rem;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
        <div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); padding: 2rem; text-align: center;">
            <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; color: #fff;">K</div>
            <h1 style="color: #fff; margin: 0.75rem 0 0; font-size: 1.25rem;">KINO TRACE</h1>
        </div>
        <div style="padding: 2rem;">
            <h2 style="color: #1e293b; font-size: 1.1rem; margin-top: 0;">Hola, {$nombre}</h2>
            <p style="color: #475569; line-height: 1.6;">
                Recibimos una solicitud para restablecer la contraseña de su cuenta. 
                Haga clic en el botón de abajo para crear una nueva contraseña.
            </p>
            <div style="text-align: center; margin: 2rem 0;">
                <a href="{$resetLink}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; text-decoration: none; padding: 0.875rem 2rem; border-radius: 8px; font-weight: 600; font-size: 1rem;">
                    Restablecer Contraseña
                </a>
            </div>
            <p style="color: #94a3b8; font-size: 0.875rem; line-height: 1.5;">
                Este enlace expira en <strong>1 hora</strong>.<br>
                Si no solicitó este cambio, ignore este correo.
            </p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 1.5rem 0;">
            <p style="color: #94a3b8; font-size: 0.75rem; text-align: center;">
                © <?= date('Y') ?> KINO GENIUS — Gestión Documental
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        $mail->AltBody = "Hola {$nombre},\n\nPara restablecer su contraseña, visite:\n{$resetLink}\n\nEste enlace expira en 1 hora.\nSi no solicitó este cambio, ignore este correo.";

        $mail->send();
        return ['success' => true, 'error' => null];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error al enviar correo: ' . $mail->ErrorInfo];
    }
}
