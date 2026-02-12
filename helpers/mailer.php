<?php
/**
 * Helper de correo electrónico - KINO TRACE
 * 
 * Soporta dos backends:
 *   1. Resend (HTTP API) — recomendado para Railway/Docker
 *   2. SMTP (PHPMailer)  — fallback para servidores tradicionales
 * 
 * Variables de entorno:
 *   RESEND_API_KEY  - API key de Resend (https://resend.com) — usar este para Railway
 *   MAIL_FROM       - Email del remitente (ej: onboarding@resend.dev o tu@tudominio.com)
 *   MAIL_FROM_NAME  - Nombre del remitente (default: KINO TRACE)
 * 
 *   --- O para SMTP tradicional ---
 *   SMTP_HOST, SMTP_USER, SMTP_PASS, SMTP_PORT
 */

/**
 * Lee una variable de entorno de cualquier fuente disponible.
 */
function mail_env(string $key): string
{
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
}

/**
 * Verifica si el servicio de correo está configurado.
 */
function is_mail_configured(): bool
{
    // Resend tiene prioridad
    if (mail_env('RESEND_API_KEY') !== '') {
        return true;
    }
    // Fallback a SMTP
    return mail_env('SMTP_HOST') !== '' && mail_env('SMTP_USER') !== '' && mail_env('SMTP_PASS') !== '';
}

/**
 * Genera el HTML del correo de recuperación.
 */
function build_reset_email_html(string $nombre, string $resetLink): string
{
    $year = date('Y');
    return <<<HTML
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
                © {$year} KINO GENIUS — Gestión Documental
            </p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Envía correo vía Resend HTTP API.
 */
function send_via_resend(string $to, string $nombre, string $subject, string $html, string $textBody): array
{
    $apiKey = mail_env('RESEND_API_KEY');
    $from = mail_env('MAIL_FROM') ?: 'KINO TRACE <onboarding@resend.dev>';
    $fromName = mail_env('MAIL_FROM_NAME') ?: 'KINO TRACE';

    // Si MAIL_FROM no tiene formato "Nombre <email>", agregarlo
    if (strpos($from, '<') === false) {
        $from = "{$fromName} <{$from}>";
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
        'text' => $textBody
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Error de conexión: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'error' => null];
    }

    $data = json_decode($response, true);
    $errorMsg = $data['message'] ?? $data['error'] ?? "Error HTTP {$httpCode}";
    return ['success' => false, 'error' => 'Error Resend: ' . $errorMsg];
}

/**
 * Envía correo vía SMTP (PHPMailer).
 */
function send_via_smtp(string $to, string $nombre, string $subject, string $html, string $textBody): array
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = mail_env('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = mail_env('SMTP_USER');
        $mail->Password = mail_env('SMTP_PASS');
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $port = (int) (mail_env('SMTP_PORT') ?: 465);
        $mail->Port = $port;
        if ($port === 587) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->SMTPOptions = [
            'socket' => ['bindto' => '0.0.0.0:0'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        ];
        $mail->CharSet = 'UTF-8';

        $fromEmail = mail_env('SMTP_FROM') ?: mail_env('SMTP_USER');
        $fromName = mail_env('MAIL_FROM_NAME') ?: 'KINO TRACE';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $nombre);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $textBody;

        $mail->send();
        return ['success' => true, 'error' => null];

    } catch (\Exception $e) {
        return ['success' => false, 'error' => 'Error SMTP: ' . $mail->ErrorInfo];
    }
}

/**
 * Envía un correo de recuperación de contraseña.
 * Usa Resend si RESEND_API_KEY está configurada, sino SMTP.
 *
 * @param string $to       Email del destinatario
 * @param string $nombre   Nombre del cliente
 * @param string $resetLink URL completa de recuperación
 * @return array ['success' => bool, 'error' => string|null]
 */
function send_reset_email(string $to, string $nombre, string $resetLink): array
{
    if (!is_mail_configured()) {
        return ['success' => false, 'error' => 'Correo no configurado. Configure RESEND_API_KEY o las variables SMTP.'];
    }

    $subject = 'Recuperar Contraseña - KINO TRACE';
    $html = build_reset_email_html($nombre, $resetLink);
    $textBody = "Hola {$nombre},\n\nPara restablecer su contraseña, visite:\n{$resetLink}\n\nEste enlace expira en 1 hora.\nSi no solicitó este cambio, ignore este correo.";

    // Resend tiene prioridad (funciona en Railway)
    if (mail_env('RESEND_API_KEY') !== '') {
        return send_via_resend($to, $nombre, $subject, $html, $textBody);
    }

    // Fallback a SMTP
    return send_via_smtp($to, $nombre, $subject, $html, $textBody);
}
