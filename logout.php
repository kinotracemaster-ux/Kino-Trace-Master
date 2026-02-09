<?php
/**
 * Cierra la sesión del usuario y lo redirige a la página de login.
 */
session_start();
// Destruir toda la sesión
session_unset();
session_destroy();
// Redirigir al login
header('Location: login.php');
exit;
