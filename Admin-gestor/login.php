<?php
/**
 * Login del Gestor de Clientes - KINO TRACE
 *
 * Página de acceso independiente para el panel de administración.
 * Acepta el código secreto de admin.
 */
require_once __DIR__ . '/../helpers/session_init.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/subdomain.php';

// Si ya está autenticado como admin, ir directo al panel
$allowedAdminUsers = ['admin', 'kino'];
if (isset($_SESSION['client_code']) && in_array($_SESSION['client_code'], $allowedAdminUsers)) {
    header('Location: panel.php');
    exit;
}

// Admin secret password
define('ADMIN_SECRET', getenv('ADMIN_SECRET') ?: '3312');

$error = '';

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = $_POST['admin_secret'] ?? '';
    if ($secret === ADMIN_SECRET) {
        session_reopen();
        $_SESSION['client_code'] = 'admin';
        $_SESSION['is_admin'] = true;
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        session_write_close();
        header('Location: panel.php');
        exit;
    } else {
        $error = 'Código de acceso incorrecto';
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Admin - KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Animated background particles */
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            top: -100px;
            right: -100px;
            border-radius: 50%;
            animation: pulse 8s ease-in-out infinite;
        }

        body::after {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.1) 0%, transparent 70%);
            bottom: -50px;
            left: -50px;
            border-radius: 50%;
            animation: pulse 6s ease-in-out infinite reverse;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 0.5;
            }

            50% {
                transform: scale(1.2);
                opacity: 1;
            }
        }

        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 420px;
            padding: 1rem;
        }

        .login-card {
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow:
                0 25px 50px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            animation: cardIn 0.6s ease-out;
        }

        @keyframes cardIn {
            from {
                transform: translateY(30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .login-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
        }

        .login-title {
            text-align: center;
            color: #f1f5f9;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .login-subtitle {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .form-input {
            width: 100%;
            padding: 0.9rem 1rem;
            background: rgba(15, 23, 42, 0.6);
            border: 2px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            color: #f1f5f9;
            font-size: 1.1rem;
            letter-spacing: 0.2em;
            text-align: center;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
            background: rgba(15, 23, 42, 0.8);
        }

        .form-input::placeholder {
            color: #475569;
            letter-spacing: 0.3em;
        }

        .btn-login {
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            letter-spacing: 0.025em;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            animation: shake 0.5s ease;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-8px);
            }

            75% {
                transform: translateX(8px);
            }
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #64748b;
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #3b82f6;
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: #475569;
            font-size: 0.75rem;
        }
    </style>
</head>

<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-icon">🔐</div>
            <h1 class="login-title">Panel de Gestión</h1>
            <p class="login-subtitle">Ingrese el código de acceso administrativo</p>

            <?php if ($error): ?>
                <div class="error-msg">⚠️
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label class="form-label">Código de acceso</label>
                    <input type="password" name="admin_secret" class="form-input" placeholder="• • • •" autofocus
                        required>
                </div>
                <button type="submit" class="btn-login">
                    Acceder al Panel
                </button>
            </form>

            <a href="../login.php" class="back-link">← Volver al inicio de sesión</a>

            <div class="security-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                Acceso protegido · KINO TRACE
            </div>
        </div>
    </div>

    <?php if (defined('APP_BRANCH') && APP_BRANCH !== 'main'): ?>
        <div
            style="position:fixed;top:0;left:0;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;font-size:0.7rem;font-weight:700;padding:0.25rem 1rem;z-index:10000;letter-spacing:0.1em;border-radius:0 0 8px 0;">
            🧪
            <?= strtoupper(htmlspecialchars(APP_BRANCH)) ?>
        </div>
    <?php endif; ?>

    <footer class="app-footer" style="position:fixed;bottom:0;left:0;right:0;background:transparent;color:#475569;">
        Elaborado por <a href="#" style="color:#64748b;">KINO GENIUS</a>
    </footer>
</body>

</html>