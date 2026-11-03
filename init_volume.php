<?php
// Solo línea de comandos: desde el navegador este script no existe (ver CLAUDE.md, Seguridad)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Script de Inicialización de Volumen Railway
 * 
 * Este script copia las bases de datos iniciales al volumen persistente
 * si aún no existen. Ejecutar una sola vez después del despliegue.
 * 
 * Uso: php init_volume.php
 */

echo "=== Inicialización de Volumen Railway ===\n\n";

// Función para copiar archivo con verificación
function copyIfNotExists($source, $target, $description)
{
    if (file_exists($target)) {
        echo "⏭  $description ya existe en el volumen\n";
        echo "   Ubicación: $target\n";
        return false;
    }

    if (!file_exists($source)) {
        echo "❌ $description no encontrada en: $source\n";
        return false;
    }

    // Crear directorio si no existe
    $targetDir = dirname($target);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
        echo "📁 Creado directorio: $targetDir\n";
    }

    // Copiar archivo
    if (copy($source, $target)) {
        echo "✅ $description copiada exitosamente\n";
        echo "   De: $source\n";
        echo "   A:  $target\n";
        chmod($target, 0666);
        return true;
    } else {
        echo "❌ Error copiando $description\n";
        return false;
    }
}

// Bases de datos a copiar
$databases = [
    [
        'source' => __DIR__ . '/database_initial/central.db',
        'target' => __DIR__ . '/clients/central.db',
        'desc' => 'Base de datos central'
    ],
    [
        'source' => __DIR__ . '/database_initial/logs.db',
        'target' => __DIR__ . '/clients/logs/logs.db',
        'desc' => 'Base de datos de logs'
    ]
];

$copied = 0;
$skipped = 0;
$errors = 0;

foreach ($databases as $db) {
    echo "\n";
    $result = copyIfNotExists($db['source'], $db['target'], $db['desc']);

    if ($result === true) {
        $copied++;
    } elseif ($result === false && file_exists($db['target'])) {
        $skipped++;
    } else {
        $errors++;
    }
}

// Resumen
echo "\n=== Resumen ===\n";
echo "✅ Copiadas: $copied\n";
echo "⏭  Ya existían: $skipped\n";
echo "❌ Errores: $errors\n";

// Verificar permisos
echo "\n=== Verificación de Permisos ===\n";
$clientsDir = __DIR__ . '/clients';
if (is_dir($clientsDir)) {
    echo "📁 Directorio clients/: ";
    echo is_writable($clientsDir) ? "✅ Escribible\n" : "❌ No escribible\n";

    if (file_exists(__DIR__ . '/clients/central.db')) {
        echo "📄 central.db: ";
        echo is_writable(__DIR__ . '/clients/central.db') ? "✅ Escribible\n" : "❌ No escribible\n";
    }
}

echo "\n✨ Inicialización completada\n";
