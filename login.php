<?php
/**
 * Login Page - KINO TRACE
 *
 * Authentication page with modern minimalist design.
 * Includes hidden admin access with special password.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/subdomain.php';

// Detect client from subdomain (e.g. losmonte.kino-trace.com)
$detectedSub = getSubdomain();
$subdomainClient = null;
$subdomainClientName = '';

if ($detectedSub === 'admin') {
    // admin.kino-trace.com → show admin modal automatically
    $showAdminModal = true;
} elseif ($detectedSub) {
    $subdomainClient = resolveClientCode($detectedSub);
    if ($subdomainClient) {
        // Get client name for display
        $nameStmt = $centralDb->prepare('SELECT nombre, titulo FROM control_clientes WHERE codigo = ? LIMIT 1');
        $nameStmt->execute([$subdomainClient]);
        $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $subdomainClientName = $nameRow['titulo'] ?: $nameRow['nombre'] ?? $subdomainClient;
    }
}

// Admin secret password
define('ADMIN_SECRET', getenv('ADMIN_SECRET') ?: '3312');

$error = '';
$adminError = '';

// Handle admin secret access
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_secret'])) {
    $secret = $_POST['admin_secret'] ?? '';
    if ($secret === ADMIN_SECRET) {
        // Create admin session for panel access only
        $_SESSION['client_code'] = 'admin';
        $_SESSION['is_admin'] = true;
        header('Location: admin/panel.php');
        exit;
    } else {
        $adminError = 'Código incorrecto';
    }
}

// Handle normal login (acepta código o correo electrónico)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['codigo'])) {
    $input = trim($_POST['codigo'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($input === '' || $password === '') {
        $error = 'Debe escribir su código o correo y una contraseña.';
    } else {
        // Si contiene @, buscar por email; sino, buscar por código
        if (strpos($input, '@') !== false) {
            $stmt = $centralDb->prepare('SELECT codigo, password_hash FROM control_clientes WHERE email = ? AND activo = 1 LIMIT 1');
            $stmt->execute([$input]);
        } else {
            $codigo = sanitize_code($input);
            $stmt = $centralDb->prepare('SELECT codigo, password_hash FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
            $stmt->execute([$codigo]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Credenciales inválidas.';
        } else {
            $_SESSION['client_code'] = $row['codigo'];
            $_SESSION['is_admin'] = ($row['codigo'] === 'admin');
            header('Location: index.php');
            exit;
        }
    }
}

$clients = $centralDb->query('SELECT codigo, nombre FROM control_clientes WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .admin-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .admin-link button {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 0.75rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .admin-link button:hover {
            color: var(--text-secondary);
        }

        .admin-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .admin-modal.active {
            display: flex;
        }

        .admin-modal-content {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            width: 90%;
            max-width: 320px;
            text-align: center;
        }

        .admin-modal-content h3 {
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .admin-modal-content .form-input {
            text-align: center;
            letter-spacing: 0.5em;
            font-size: 1.25rem;
        }

        .admin-error {
            color: var(--accent-danger);
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">K</div>
                <h1 class="login-title">KINO TRACE</h1>
                <p class="login-subtitle">
                    <?php if ($subdomainClientName): ?>
                        <?= htmlspecialchars($subdomainClientName) ?>
                    <?php else: ?>
                        Gestión Documental
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="error-box"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label class="form-label" for="codigo">Usuario</label>
                    <input type="text" name="codigo" id="codigo" class="form-input" required
                        placeholder="Código o correo electrónico" autocomplete="username"
                        value="<?= htmlspecialchars($subdomainClient ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" name="password" id="password" class="form-input" required
                        placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    Ingresar
                </button>
            </form>

            <div style="text-align: center; margin-top: 1rem;">
                <a href="forgot_password.php"
                    style="font-size: 0.8rem; color: var(--text-muted); text-decoration: none;">
                    ¿Olvidaste tu contraseña?
                </a>
            </div>

            <?php if (!$subdomainClient || $subdomainClient === 'kino'): ?>
                <div class="admin-link">
                    <button type="button" onclick="openAdminModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Gestor de Clientes
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Admin Access Modal -->
    <div class="admin-modal" id="adminModal">
        <div class="admin-modal-content">
            <h3>🔐 Acceso Administrador</h3>
            <form method="post">
                <div class="form-group">
                    <input type="password" name="admin_secret" class="form-input" placeholder="•••" maxlength="10"
                        autofocus>
                    <?php if ($adminError): ?>
                        <p class="admin-error"><?= htmlspecialchars($adminError) ?></p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Acceder</button>
                <button type="button" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;"
                    onclick="closeAdminModal()">Cancelar</button>
            </form>
        </div>
    </div>

    <?php if (defined('APP_BRANCH') && APP_BRANCH !== 'main'): ?>
        <div
            style="position:fixed;top:0;left:0;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;font-size:0.7rem;font-weight:700;padding:0.25rem 1rem;z-index:10000;letter-spacing:0.1em;border-radius:0 0 8px 0;box-shadow:0 2px 8px rgba(245,158,11,0.4);pointer-events:none;">
            🧪 <?= strtoupper(htmlspecialchars(APP_BRANCH)) ?>
        </div>
    <?php endif; ?>

    <footer class="app-footer" style="position: fixed; bottom: 0; left: 0; right: 0; background: transparent;">
        Elaborado por <a href="#">KINO GENIUS</a>
    </footer>

    <script>
        function openAdminModal() {
            document.getElementById('adminModal').classList.add('active');
        }

        function closeAdminModal() {
            document.getElementById('adminModal').classList.remove('active');
        }

        // Close on overlay click
        document.getElementById('adminModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeAdminModal();
            }
        });

        // Show modal if there was an error or admin subdomain
        <?php if ($adminError || !empty($showAdminModal)): ?>
            openAdminModal();
        <?php endif; ?>
    </script>
</body>

</html>