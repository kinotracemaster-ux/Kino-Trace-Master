<?php

// Cargar el autoloader principal del proyecto
require_once __DIR__ . '/../autoload.php';

// Definir constantes necesarias para pruebas si no están definidas
if (!defined('CLIENTS_DIR')) {
    define('CLIENTS_DIR', __DIR__ . '/../clients_test');
}

// Asegurar directorio de prueba
if (!is_dir(CLIENTS_DIR)) {
    mkdir(CLIENTS_DIR, 0777, true);
}

if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

if (headers_sent($file, $line)) {
    die("Headers already sent in $file:$line");
}
