<?php
/**
 * Importación desde SQL (MySQL dump) - KINO TRACE
 * 
 * Parsea un archivo .sql exportado de MySQL (phpMyAdmin) que contiene
 * tablas `documents` y `codes`, y los importa al esquema SQLite de KINO-TRACE.
 * 
 * Mapeo:
 *   documents(id, name, date, path) → documentos(id, tipo, numero, fecha, ruta_archivo, original_path, estado)
 *   codes(id, document_id, code)    → codigos(id, documento_id, codigo, descripcion, validado)
 */
ob_start();
header('Content-Type: application/json');
session_start();
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
set_time_limit(600);

require_once '../../config.php';
require_once '../../helpers/tenant.php';
require_once '../../helpers/pdf_linker.php';

$response = ['success' => false, 'logs' => [], 'error' => null];

function logMsg($msg, $type = "info")
{
    global $response;
    $response['logs'][] = ['msg' => $msg, 'type' => $type];
}

try {
    if (!isset($_SESSION['client_code']))
        throw new Exception('Sesión no iniciada.');
    $clientCode = $_SESSION['client_code'];
    $db = open_client_db($clientCode);

    // ========================
    // ACTION: Import SQL File
    // ========================
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {

        $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
        if ($sqlContent === false)
            throw new Exception('No se pudo leer el archivo SQL.');

        logMsg("📄 Archivo SQL recibido: " . $_FILES['sql_file']['name'] . " (" . round(strlen($sqlContent) / 1024) . " KB)");

        // --- Step 1: Parse INSERT INTO documents ---
        logMsg("🔍 Parseando tabla 'documents'...");
        $documents = [];
        $docPattern = "/INSERT INTO `documents`[^;]*?VALUES\s*(.*?);/s";

        if (preg_match_all($docPattern, $sqlContent, $docMatches)) {
            foreach ($docMatches[1] as $valuesBlock) {
                // Match individual rows: (id, 'name', 'date', 'path')
                $rowPattern = "/\((\d+),\s*'((?:[^'\\\\]|\\\\.|'')*)',\s*'([^']*)',\s*'((?:[^'\\\\]|\\\\.|'')*)'\)/";
                if (preg_match_all($rowPattern, $valuesBlock, $rows, PREG_SET_ORDER)) {
                    foreach ($rows as $row) {
                        $documents[$row[1]] = [
                            'id' => (int) $row[1],
                            'name' => str_replace(["''", "\\'"], "'", $row[2]),
                            'date' => $row[3],
                            'path' => str_replace(["''", "\\'"], "'", $row[4])
                        ];
                    }
                }
            }
        }

        if (empty($documents))
            throw new Exception('No se encontraron datos en la tabla "documents" del SQL.');

        logMsg("✅ Encontrados " . count($documents) . " documentos en el SQL", "success");

        // --- Step 2: Parse INSERT INTO codes ---
        logMsg("🔍 Parseando tabla 'codes'...");
        $codes = [];
        $codePattern = "/INSERT INTO `codes`[^;]*?VALUES\s*(.*?);/s";

        if (preg_match_all($codePattern, $sqlContent, $codeMatches)) {
            foreach ($codeMatches[1] as $valuesBlock) {
                // Match individual rows: (id, document_id, 'code')
                $rowPattern = "/\((\d+),\s*(\d+),\s*'((?:[^'\\\\]|\\\\.|'')*)'\)/";
                if (preg_match_all($rowPattern, $valuesBlock, $rows, PREG_SET_ORDER)) {
                    foreach ($rows as $row) {
                        $codes[] = [
                            'id' => (int) $row[1],
                            'document_id' => (int) $row[2],
                            'code' => str_replace(["''", "\\'"], "'", $row[3])
                        ];
                    }
                }
            }
        }

        logMsg("✅ Encontrados " . count($codes) . " códigos en el SQL", "success");

        // --- Step 3: Insert into KINO-TRACE SQLite ---
        logMsg("💾 Insertando datos en la base de datos del cliente '{$clientCode}'...");

        $db->beginTransaction();

        // Map: old MySQL document id → new SQLite document id
        $idMap = [];
        $importedDocs = 0;
        $skippedDocs = 0;

        // Check for existing documents to avoid duplicates
        $stmtFindDoc = $db->prepare("SELECT id FROM documentos WHERE original_path = ? LIMIT 1");
        $stmtDoc = $db->prepare(
            "INSERT INTO documentos (tipo, numero, fecha, ruta_archivo, original_path, estado) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($documents as $oldId => $doc) {
            // Check if already imported (by original_path)
            $stmtFindDoc->execute([$doc['path']]);
            $existing = $stmtFindDoc->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $idMap[$oldId] = $existing['id'];
                $skippedDocs++;
                continue;
            }

            try {
                $stmtDoc->execute([
                    'importado_sql',           // tipo
                    $doc['name'],              // numero (nombre descriptivo)
                    $doc['date'],              // fecha
                    'pending',                 // ruta_archivo (pending until ZIP links)
                    $doc['path'],              // original_path (original filename)
                    'procesado'                // estado
                ]);
                $newId = $db->lastInsertId();
                $idMap[$oldId] = $newId;
                $importedDocs++;
            } catch (Exception $e) {
                logMsg("⚠ Error doc '{$doc['name']}': " . $e->getMessage(), "warning");
            }
        }

        logMsg("📊 Documentos: {$importedDocs} nuevos, {$skippedDocs} ya existentes", "info");

        // Insert codes
        $importedCodes = 0;
        $skippedCodes = 0;
        $stmtCode = $db->prepare(
            "INSERT OR IGNORE INTO codigos (documento_id, codigo, descripcion, cantidad, validado) 
             VALUES (?, ?, ?, ?, ?)"
        );

        foreach ($codes as $code) {
            $oldDocId = $code['document_id'];
            if (!isset($idMap[$oldDocId])) {
                $skippedCodes++;
                continue; // No matching document
            }

            $newDocId = $idMap[$oldDocId];
            try {
                $stmtCode->execute([
                    $newDocId,
                    trim($code['code']),
                    'Importado SQL',
                    1,
                    0
                ]);
                if ($stmtCode->rowCount() > 0) {
                    $importedCodes++;
                }
            } catch (Exception $e) {
                // Ignore duplicate codes silently
            }
        }

        $db->commit();

        logMsg("📊 Códigos: {$importedCodes} insertados, {$skippedCodes} sin documento", "info");
        logMsg("✅ Importación SQL completada exitosamente", "success");
        logMsg("───────────────────────────────", "info");
        logMsg("📋 Resumen:", "success");
        logMsg("   Documentos importados: {$importedDocs}", "success");
        logMsg("   Documentos existentes: {$skippedDocs}", "info");
        logMsg("   Códigos importados: {$importedCodes}", "success");

        // --- Step 4: Process ZIP if provided ---
        if (isset($_FILES['zip_file']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
            $zipFile = $_FILES['zip_file']['tmp_name'];
            $uploadDir = __DIR__ . "/../../clients/" . $clientCode . "/uploads/sql_import";

            logMsg("📦 Procesando ZIP y enlazando PDFs...");
            processZipAndLink($db, $zipFile, $uploadDir, "sql_import/");
            logMsg("✅ PDFs enlazados correctamente", "success");
        } else {
            logMsg("ℹ️ Sin archivo ZIP. Los PDFs se pueden enlazar después.", "info");
        }

        $response['success'] = true;

    } else {
        throw new Exception('No se recibió archivo SQL válido.');
    }

} catch (Exception $e) {
    if (isset($db) && $db && $db->inTransaction())
        $db->rollBack();
    logMsg("ERROR CRÍTICO: " . $e->getMessage(), "error");
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
