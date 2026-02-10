<?php
/**
 * Script temporal para descargar la base de datos desde Railway
 * ELIMINAR DESPUÉS DE USAR por seguridad
 */

// Ruta a la base de datos central
$centralDb = __DIR__ . '/clients/central.db';

if (!file_exists($centralDb)) {
    die('❌ Base de datos no encontrada en: ' . $centralDb);
}

// Descargar el archivo
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="central.db"');
header('Content-Length: ' . filesize($centralDb));
readfile($centralDb);
exit;
