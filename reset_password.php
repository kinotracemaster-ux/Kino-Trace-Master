<?php
/**
 * Reset Password Page - KINO TRACE
 *
 * Validates a reset token and allows the user to set a new password.
 */
session_start();
require_once __DIR__ . '/config.php';

$message = '';
$error = '';
$tokenValid = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if ($token !== '') {
    // Verificar token
    $stmt = $centralDb->prepare(
        'SELECT codigo, nombre FROM control_clientes WHERE reset_token = ? AND reset_token_expiry > datetime(\'now\') AND activo = 1 LIMIT 1'
    );
    $stmt->execute([$token]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($client) {
        $tokenValid = true;

        // Procesar cambio de contraseña
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newPassword = $_POST['password'] ?? '';
            $confirmPassword = $_POST['password_confirm'] ?? '';

            if (strlen($newPassword) < 4) {
                $error = 'La contraseña debe tener al menos 4 caracteres.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $centralDb->prepare(
                    'UPDATE control_clientes SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE codigo = ?'
                );
                $stmt->execute([$hash, $client['codigo']]);
                $tokenValid = false;
                $message = 'Contraseña actualizada correctamente. Ya puede iniciar sesión.';
            }
        }
    } else {
        $error = 'El enlace de recuperación es inválido o ha expirado.';
    }
} else {
    $error = 'No se proporcionó un token de recuperación.';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
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

        .password-requirements {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
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

        .client-name {
            text-align: center;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">K</div>
                <h1 class="login-title">KINO TRACE</h1>
                <p class="login-subtitle">Restablecer Contraseña</p>
            </div>

            <?php if ($message): ?>
                <div class="success-box">✅
                    <?= htmlspecialchars($message) ?>
                </div>
                <a href="login.php" class="btn btn-primary"
                    style="width: 100%; text-align: center; text-decoration: none; display: block;">
                    Ir al Inicio de Sesión
                </a>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-box">
                    <?= htmlspecialchars($error) ?>
                </div>
                <a href="forgot_password.php" class="back-link">Solicitar un nuevo enlace</a>
            <?php endif; ?>

            <?php if ($tokenValid): ?>
                <div class="client-name">
                    🔐
                    <?= htmlspecialchars($client['nombre']) ?>
                </div>

                <form method="post">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group">
                        <label class="form-label" for="password">Nueva Contraseña</label>
                        <input type="password" name="password" id="password" class="form-input" required
                            placeholder="••••••••" minlength="4">
                        <p class="password-requirements">Mínimo 4 caracteres</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password_confirm">Confirmar Contraseña</label>
                        <input type="password" name="password_confirm" id="password_confirm" class="form-input" required
                            placeholder="••••••••" minlength="4">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                        Cambiar Contraseña
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$tokenValid && !$message): ?>
                <a href="login.php" class="back-link">← Volver al inicio de sesión</a>
            <?php endif; ?>
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