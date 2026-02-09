<?php
/**
 * Autoloader PSR-4 simplificado para KINO TRACE
 * 
 * Carga automáticamente clases y helpers eliminando la necesidad
 * de múltiples require_once en cada archivo.
 * 
 * Uso:
 *   require_once __DIR__ . '/autoload.php';
 */

// Registrar función de autoload
spl_autoload_register(function ($class) {
    // PSR-4 Basic Implementation for Kino\ namespace
    $prefix = 'Kino\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // Fallback to helpers for legacy
        $classFile = __DIR__ . '/helpers/' . $class . '.php';
        if (file_exists($classFile)) {
            require_once $classFile;
            return true;
        }
        return false;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
        return true;
    }
    return false;
});

// Cargar archivos de configuración esenciales
require_once __DIR__ . '/config.php';

// Cargar helpers principales automáticamente
$helpers = [
    'db',
    'config_helper',
    'auth',
    'tenant',
    'secure_uploader',
    'file_manager',
    'error_codes',
    'rate_limiter',
    'csrf_protection'
];


foreach ($helpers as $helper) {
    $helperFile = __DIR__ . '/helpers/' . $helper . '.php';
    if (file_exists($helperFile)) {
        require_once $helperFile;
    }
}

// Helpers opcionales bajo demanda
function load_helper($name)
{
    static $loaded = [];

    if (isset($loaded[$name])) {
        return true;
    }

    $helperFile = __DIR__ . '/helpers/' . $name . '.php';
    if (file_exists($helperFile)) {
        require_once $helperFile;
        $loaded[$name] = true;
        return true;
    }

    return false;
}

// Función helper para cargar múltiples helpers
function load_helpers(array $names)
{
    foreach ($names as $name) {
        load_helper($name);
    }
}
