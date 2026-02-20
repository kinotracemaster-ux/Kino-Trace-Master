<?php
/**
 * helpers/auth.php
 * 
 * Authentication helper con aislamiento de sesiones por subdominio.
 * Incluye session_init.php para sesión aislada por hostname.
 */

// Iniciar sesión aislada por subdominio
require_once __DIR__ . '/session_init.php';


/**
 * Check if the user is logged in as a client.
 * SEGURIDAD: Valida fingerprint de sesión (IP + User-Agent) para prevenir secuestro.
 * @return bool
 */
function is_logged_in()
{
    if (!isset($_SESSION['client_code']) || empty($_SESSION['client_code'])) {
        return false;
    }

    // Validar fingerprint de sesión (si existe - backward compatible)
    if (isset($_SESSION['ip']) && isset($_SESSION['user_agent'])) {
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($_SESSION['ip'] !== $currentIp || $_SESSION['user_agent'] !== $currentAgent) {
            // Posible secuestro de sesión: invalidar
            session_unset();
            session_destroy();
            return false;
        }
    }

    return true;
}

/**
 * Get the current logged-in client code.
 * @return string|null
 */
function get_current_client()
{
    return $_SESSION['client_code'] ?? null;
}

/**
 * Require login or redirect.
 * NOTE: This assumes the calling script is 2 levels deep (e.g. modules/foo/index.php).
 * If structure varies, use absolute paths or defined constants.
 */
function require_login_or_redirect($depth = 2)
{
    if (!is_logged_in()) {
        $prefix = str_repeat('../', $depth);
        header("Location: {$prefix}login.php");
        exit;
    }
}
