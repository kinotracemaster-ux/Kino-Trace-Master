<?php
/**
 * helpers/auth.php
 * 
 * Simple authentication helper to standardize session checks.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is logged in as a client.
 * @return bool
 */
function is_logged_in()
{
    return isset($_SESSION['client_code']) && !empty($_SESSION['client_code']);
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
