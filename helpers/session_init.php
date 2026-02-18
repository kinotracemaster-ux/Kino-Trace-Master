<?php
/**
 * helpers/session_init.php
 * 
 * Punto de entrada ÚNICO para iniciar sesiones en toda la aplicación.
 * Genera una sesión aislada por hostname/subdominio.
 * 
 * Usar SIEMPRE este archivo en lugar de session_start() directo.
 * Si también necesitas autenticación, usa helpers/auth.php que incluye este archivo.
 */

if (session_status() !== PHP_SESSION_NONE) {
    return; // Ya hay sesión activa
}

// Obtener hostname actual
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_host = strtolower(explode(':', $_host)[0]); // Quitar puerto

// Generar nombre de sesión único basado en el hostname completo
// Esto crea cookies separadas: KT_losmonte_kino_trace_com, KT_kino_kino_trace_com, etc.
$_safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $_host);
$_sessionName = 'KT_' . substr($_safeName, 0, 30);

session_name($_sessionName);

// Cookie limitada al hostname EXACTO (no al dominio base .kino-trace.com)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_host,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// RENDIMIENTO: Liberar el bloqueo de sesión inmediatamente.
// Esto permite que solicitudes concurrentes del mismo usuario
// (múltiples pestañas, AJAX paralelo) NO se encolen esperando.
// La sesión queda en modo LECTURA: $_SESSION es accesible pero read-only.
session_write_close();

/**
 * Reabrir sesión para escritura.
 * Usar SOLO en archivos que necesiten modificar $_SESSION (login, logout, etc.)
 * Llamar justo antes de escribir y cerrar con session_write_close() al terminar.
 */
function session_reopen(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Limpiar variables temporales
unset($_host, $_safeName, $_sessionName);
