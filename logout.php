<?php
/**
 * Cierra la sesión del usuario y lo redirige a la página de login.
 */
require_once __DIR__ . '/helpers/session_init.php';
// Reabrir sesión (session_init la cierra con session_write_close)
session_reopen();
// Destruir toda la sesión
session_unset();
session_destroy();
// Redirigir al login
header('Location: login.php');
exit;
