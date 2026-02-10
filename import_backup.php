<?php
// import_backup.php
// Script para importar el respaldo de base de datos desde el zip extraído

require_once 'config.php';
require_once 'helpers/tenant.php';

$extractDir = __DIR__ . '/temp_zip_extract';
$backupDir = __DIR__ . '/backups';

if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// 1. Crear respaldo de seguridad (Copia de carpetas)
echo "Creando respaldo de seguridad antes de importar...\n";
$timestamp = date('Y-m-d_H-i-s');
$backupDest = $backupDir . '/backup_' . $timestamp;

if (!mkdir($backupDest, 0777, true)) {
    die("Error creando directorio de respaldo: $backupDest\n");
}

function recursive_copy_backup($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recursive_copy_backup($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

// Respaldo de clients
echo " - Respaldando directorio clients...\n";
if (is_dir(CLIENTS_DIR)) {
    recursive_copy_backup(CLIENTS_DIR, $backupDest . '/clients');
}

// Respaldo de database_initial
echo " - Respaldando directorio database_initial...\n";
$initialDir = __DIR__ . '/database_initial';
if (is_dir($initialDir)) {
    recursive_copy_backup($initialDir, $backupDest . '/database_initial');
}

echo "Respaldo creado en: $backupDest\n";

// 2. Importar Central DB
echo "Importando Base de Datos Central...\n";
$centralSource = $extractDir . '/central.db';
if (file_exists($centralSource)) {
    // Copiar a clients/central.db
    if (copy($centralSource, CENTRAL_DB)) {
        echo " - Actualizado: " . CENTRAL_DB . "\n";
    } else {
        echo " - Error actualizando: " . CENTRAL_DB . "\n";
    }

    // Copiar a database_initial/central.db
    $initialCentral = __DIR__ . '/database_initial/central.db';
    if (!is_dir(dirname($initialCentral)))
        mkdir(dirname($initialCentral), 0777, true);
    if (copy($centralSource, $initialCentral)) {
        echo " - Actualizado: " . $initialCentral . "\n";
    } else {
        echo " - Error actualizando: " . $initialCentral . "\n";
    }
} else {
    echo "ALERTA: No se encontró central.db en el respaldo extraído.\n";
}

// 3. Importar Bases de Datos de Clientes
echo "Importando Bases de Datos de Clientes...\n";
$clientesSourceDir = $extractDir . '/clientes';

if (is_dir($clientesSourceDir)) {
    $files = scandir($clientesSourceDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..')
            continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'db')
            continue;

        $clientCode = pathinfo($file, PATHINFO_FILENAME);
        echo "Procesando cliente: $clientCode\n";

        // Rutas destino
        $clientDir = CLIENTS_DIR . '/' . $clientCode;
        $clientDbPath = $clientDir . '/' . $clientCode . '.db';

        $initialClientDir = __DIR__ . '/database_initial/' . $clientCode;
        $initialClientDbPath = $initialClientDir . '/' . $clientCode . '.db';

        // Asegurar directorios
        if (!is_dir($clientDir)) {
            echo " - Creando directorio: $clientDir\n";
            mkdir($clientDir, 0777, true);
            // Crear estructura básica de uploads si es nuevo
            mkdir($clientDir . '/uploads/manifiestos', 0777, true);
            mkdir($clientDir . '/uploads/declaraciones', 0777, true);
            mkdir($clientDir . '/uploads/facturas', 0777, true);
        }

        if (!is_dir($initialClientDir)) {
            mkdir($initialClientDir, 0777, true);
        }

        // Copiar DB
        $sourceDb = $clientesSourceDir . '/' . $file;

        if (copy($sourceDb, $clientDbPath)) {
            echo " - BD actualizada en clients/: $clientDbPath\n";
        } else {
            echo " - Error copiando a clients/: $clientDbPath\n";
        }

        if (copy($sourceDb, $initialClientDbPath)) {
            echo " - BD actualizada en database_initial/: $initialClientDbPath\n";
        } else {
            echo " - Error copiando a database_initial/: $initialClientDbPath\n";
        }
    }
} else {
    echo "ALERTA: No se encontró el directorio 'clientes' en el respaldo extraído.\n";
}

echo "Proceso completado.\n";
