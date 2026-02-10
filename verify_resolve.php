<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

$clientCode = 'kino';
$doc = [
    'ruta_archivo' => 'declaracion/declaracion_6983743ccf2de3.17304381.pdf'
];

$path = resolve_pdf_path($clientCode, $doc);
echo "Resolved Path: " . ($path ? $path : "NULL") . "\n";
echo "Exists: " . ($path && file_exists($path) ? "YES" : "NO") . "\n";
