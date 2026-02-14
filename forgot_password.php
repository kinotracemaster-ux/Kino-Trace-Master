<?php
/**
 * Forgot Password Page - KINO TRACE
 *
 * Allows clients to request a password reset link via email.
 * Protegido con CSRF y rate limiting.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers/mailer.php';
require_once __DIR__ . '/helpers/subdomain.php'; // Para APP_BASE_DOMAIN

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!CsrfProtection::validate($csrfToken)) {
        $error = 'Solicitud inválida. Recargue la página e intente de nuevo.';
    } else {
        // Rate limiting: máximo 5 solicitudes por IP por hora
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlKey = 'forgot_pw_' . md5($ip);
        if (!isset($_SESSION[$rlKey])) {
            $_SESSION[$rlKey] = ['count' => 0, 'reset_at' => time() + 3600];
        }
        if (time() > $_SESSION[$rlKey]['reset_at']) {
            $_SESSION[$rlKey] = ['count' => 0, 'reset_at' => time() + 3600];
        }
        $_SESSION[$rlKey]['count']++;

        if ($_SESSION[$rlKey]['count'] > 5) {
            $error = 'Demasiados intentos. Intente de nuevo en 1 hora.';
        } else {
            $email = trim($_POST['email'] ?? '');

            if ($email === '') {
                $error = 'Debe ingresar su correo electrónico.';
            } else {
                // Buscar cliente por email
                $stmt = $centralDb->prepare('SELECT codigo, nombre, email FROM control_clientes WHERE email = ? AND activo = 1 LIMIT 1');
                $stmt->execute([$email]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($client) {
                    // Generar token seguro
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // Guardar token en BD
                    $stmt = $centralDb->prepare('UPDATE control_clientes SET reset_token = ?, reset_token_expiry = ? WHERE codigo = ?');
                    $stmt->execute([$token, $expiry, $client['codigo']]);

                    // Construir URL de reset
                    // FIX: Admin y Kino siempre deben usar el dominio principal
                    // para evitar bloqueo 403 en subdominios de clientes.
                    $host = $_SERVER['HTTP_HOST'];
                    if (in_array($client['codigo'], ['admin', 'kino'])) {
                        $host = APP_BASE_DOMAIN;
                    }

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                    $resetLink = "{$protocol}://{$host}{$basePath}/reset_password.php?token={$token}";

                    // Enviar correo
                    $result = send_reset_email($client['email'], $client['nombre'], $resetLink);

                    if ($result['success']) {
                        $message = 'Se ha enviado un enlace de recuperación a su correo electrónico.';
                    } else {
                        $error = $result['error'];
                    }
                } else {
                    // No revelar si el email existe o no (seguridad)
                    $message = 'Si el correo está registrado, recibirá un enlace de recuperación.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .reset-info {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.15);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .reset-info svg {
            vertical-align: middle;
            margin-right: 0.25rem;
        }

        .success-box {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #059669;
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-align: center;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--accent-primary);
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">K</div>
                <h1 class="login-title">KINO TRACE</h1>
                <p class="login-subtitle">Recuperar Contraseña</p>
            </div>

            <?php if ($message): ?>
                <div class="success-box">✅
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-box">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$message): ?>
                <div class="reset-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Ingrese el correo electrónico asociado a su cuenta. Le enviaremos un enlace para restablecer su
                    contraseña.
                </div>

                <form method="post">
                    <?= CsrfProtection::tokenField() ?>
                    <div class="form-group">
                        <label class="form-label" for="email">Correo Electrónico</label>
                        <input type="email" name="email" id="email" class="form-input" required
                            placeholder="ejemplo@correo.com" autocomplete="email">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                        Enviar Enlace de Recuperación
                    </button>
                </form>
            <?php endif; ?>

            <a href="login.php" class="back-link">← Volver al inicio de sesión</a>
        </div>
    </div>

    <?php if (defined('APP_BRANCH') && APP_BRANCH !== 'main'): ?>
        <div
            style="position:fixed;top:0;left:0;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;font-size:0.7rem;font-weight:700;padding:0.25rem 1rem;z-index:10000;letter-spacing:0.1em;border-radius:0 0 8px 0;box-shadow:0 2px 8px rgba(245,158,11,0.4);pointer-events:none;">
            🧪
            <?= strtoupper(htmlspecialchars(APP_BRANCH)) ?>
        </div>
    <?php endif; ?>

    <footer class="app-footer" style="position: fixed; bottom: 0; left: 0; right: 0; background: transparent;">
        Elaborado por <a href="#">KINO GENIUS</a>
    </footer>
</body>

</html>