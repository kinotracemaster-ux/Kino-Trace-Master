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

if (headers_sent()) {
    // Algo (ej. un warning de PHP por límite de POST excedido) ya envió
    // salida antes de este punto. Iniciar sesión ahora solo generaría un
    // warning adicional que oculta el error real; lo omitimos.
    return;
}

// Obtener hostname actual
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_host = strtolower(explode(':', $_host)[0]); // Quitar puerto

// Generar nombre de sesión único basado en el hostname completo
// Esto crea cookies separadas: KT_losmonte_kino_trace_com, KT_kino_kino_trace_com, etc.
$_safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $_host);
$_sessionName = 'KT_' . substr($_safeName, 0, 30);

session_name($_sessionName);

// Sesiones en el volumen persistente (clients/_sesiones, 404 por web): antes quedaban en /tmp
// del contenedor y CADA deploy de Railway cerraba la sesión de todos (clientes y admin); una
// pantalla ya abierta recibía la página de login en vez de JSON ("Unexpected token '<'").
// 8 horas sin actividad antes de que la sesión caduque (antes 24 minutos).
if (PHP_SAPI !== 'cli') {
    $_dirSesiones = (getenv('KT_CLIENTS_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'clients') . DIRECTORY_SEPARATOR . '_sesiones';
    if ((is_dir($_dirSesiones) || @mkdir($_dirSesiones, 0700, true)) && is_writable($_dirSesiones)) {
        session_save_path($_dirSesiones);
    }
    ini_set('session.gc_maxlifetime', '28800');
    unset($_dirSesiones);
}

// Cookie limitada al hostname EXACTO (no al dominio base .kino-trace.com)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    // Sin atributo Domain = cookie "host-only": el navegador la manda SOLO a este
    // hostname exacto. Con Domain=kino-trace.com la cookie de kino-trace.com viajaba
    // también a todos los subdominios de clientes (losmonte.kino-trace.com, ...).
    // También evita el problema de Domain=127.0.0.1 en modo local.
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

// Asegurar que el token CSRF exista ANTES de liberar la sesión para escritura.
// Las páginas embeben el token dentro del <head> (después de haber emitido ya
// HTML como <!DOCTYPE html>), momento en el que headers_sent() es true y
// session_start() ya no puede reabrir la sesión para persistir un token nuevo.
// Generarlo aquí, mientras la sesión sigue abierta y no se ha emitido salida,
// garantiza que quede guardado y que la validación posterior en api.php coincida.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

// Limpiar variables temporales
unset($_host, $_safeName, $_sessionName);

// Funciones por cliente (helpers/funciones.php): si config.php ya se cargó antes que
// la sesión, la revisión de la función de esta página se hace aquí
if (function_exists('kt_guardia_funciones')) {
    kt_guardia_funciones();
}
