<?php
// migrate_uploads.php

// Configuración
$clientCode = 'kino';
$baseDir = __DIR__;
$sourceDir = $baseDir . '/uploads/sql_import';
$clientDir = $baseDir . '/clients/' . $clientCode;
$initialDbDir = $baseDir . '/database_initial/' . $clientCode;
$dbPath = $clientDir . '/' . $clientCode . '.db';

echo "[INFO] Iniciando migración de archivos para cliente: $clientCode\n";
echo "[INFO] Directorio fuente: $sourceDir\n";
echo "[INFO] Base de datos: $dbPath\n";

if (!file_exists($dbPath)) {
    die("[ERROR] No se encuentra la base de datos en $dbPath\n");
}

if (!is_dir($sourceDir)) {
    die("[ERROR] No existe el directorio fuente $sourceDir\n");
}

try {
    $pdo = new PDO("sqlite:$dbPath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Obtener todos los paths de archivos esperados
    $stmt = $pdo->query("SELECT id, ruta_archivo as file_path FROM documentos WHERE ruta_archivo IS NOT NULL AND ruta_archivo != ''");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "[INFO] Encontrados " . count($documents) . " documentos en la base de datos.\n";

    $movedCount = 0;
    $missingCount = 0;
    $errors = 0;

    foreach ($documents as $doc) {
        $relativePath = $doc['file_path'];
        $filename = basename($relativePath);

        // El archivo fuente debería estar en sql_import con este nombre
        $sourceFile = $sourceDir . '/' . $filename;

        // Destino en clients/
        $destPathClient = $clientDir . '/' . $relativePath;
        $destDirClient = dirname($destPathClient);

        // Destino en database_initial/
        $destPathInitial = $initialDbDir . '/' . $relativePath;
        $destDirInitial = dirname($destPathInitial);

        if (file_exists($sourceFile)) {
            // Asegurar directorios destino
            if (!is_dir($destDirClient)) {
                mkdir($destDirClient, 0777, true);
            }
            if (!is_dir($destDirInitial)) {
                mkdir($destDirInitial, 0777, true);
            }

            // Copiar a destinations
            // Usamos copy en lugar de rename para mantener el original por seguridad hasta el final,
            // o para poder copiar a ambos destinos.

            $successClient = copy($sourceFile, $destPathClient);
            $successInitial = copy($sourceFile, $destPathInitial);

            if ($successClient && $successInitial) {
                // echo "[OK] Migrado: $filename\n";
                $movedCount++;
            } else {
                echo "[ERROR] Fallo al copiar: $filename\n";
                $errors++;
            }

        } else {
            // A veces el filename en la DB puede diferir ligeramente o el usuario no subió todo.
            // O quizás está en una subcarpeta en source? Asumimos plano por ahora.
            echo "[MISSING] No encontrado en source: $filename (ID: {$doc['id']})\n";
            $missingCount++;
        }
    }

    echo "\n[RESUMEN]\n";
    echo "Total documentos en DB: " . count($documents) . "\n";
    echo "Migrados exitosamente: $movedCount\n";
    echo "Faltantes (no en source): $missingCount\n";
    echo "Errores de copia: $errors\n";

} catch (PDOException $e) {
    die("[ERROR] Excepción de base de datos: " . $e->getMessage() . "\n");
}
