<?php
// verify_recovery.php
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "=== DIAGNÓSTICO DE RECUPERACIÓN ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "BASE_DIR: " . BASE_DIR . "\n";
echo "CLIENTS_DIR: " . CLIENTS_DIR . "\n";
echo "Usuario PHP: " . get_current_user() . " (UID: " . posix_getuid() . ")\n";

echo "\n--- Verificando database_initial/admin/admin.db ---\n";
$adminSource = BASE_DIR . '/database_initial/admin/admin.db';
if (file_exists($adminSource)) {
    echo "✅ EXISTE\n";
    echo "   Tamaño: " . filesize($adminSource) . " bytes\n";
    echo "   Permisos: " . substr(sprintf('%o', fileperms($adminSource)), -4) . "\n";
} else {
    echo "❌ NO EXISTE\n";
    echo "   Ruta buscada: $adminSource\n";
    echo "   Contenido de database_initial:\n";
    $files = glob(BASE_DIR . '/database_initial/*');
    foreach ($files as $f) {
        echo "     - " . basename($f) . (is_dir($f) ? '/' : '') . "\n";
        if (is_dir($f)) {
            $subfiles = glob($f . '/*');
            foreach ($subfiles as $sf) {
                echo "       └─ " . basename($sf) . "\n";
            }
        }
    }
}

echo "\n--- Verificando clients/admin ---\n";
$adminDestDir = CLIENTS_DIR . '/admin';
if (file_exists($adminDestDir)) {
    echo "📂 Carpeta clients/admin EXISTE\n";
    echo "   Permisos: " . substr(sprintf('%o', fileperms($adminDestDir)), -4) . "\n";
    echo "   Propietario: " . posix_getpwuid(fileowner($adminDestDir))['name'] . "\n";
} else {
    echo "❌ Carpeta clients/admin NO EXISTE\n";
}

echo "\n--- Verificando clients/kino/kino.db ---\n";
$kinoDb = CLIENTS_DIR . '/kino/kino.db';
if (file_exists($kinoDb)) {
    echo "✅ EXISTE\n";
    echo "   Tamaño: " . filesize($kinoDb) . " bytes\n";
    try {
        $pdo = new PDO("sqlite:$kinoDb");
        $count = $pdo->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
        echo "   Registros en 'documentos': $count\n";
    } catch (Exception $e) {
        echo "   ❌ Error leyendo BD: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ NO EXISTE\n";
}

echo "\n--- Prueba de Escritura en clients/ ---\n";
$testFile = CLIENTS_DIR . '/write_test.txt';
if (@file_put_contents($testFile, 'test')) {
    echo "✅ Escritura exitosa en " . CLIENTS_DIR . "\n";
    unlink($testFile);
} else {
    echo "❌ FALLÓ escritura en " . CLIENTS_DIR . "\n";
    echo "   Error: " . error_get_last()['message'] . "\n";
}
