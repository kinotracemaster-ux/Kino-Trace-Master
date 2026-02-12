<?php
// modules/importar_masiva/process.php
ob_start();
header('Content-Type: application/json');
session_start();
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once '../../config.php';
require_once '../../helpers/tenant.php';
require_once '../../helpers/import_engine.php';
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

    // --- RESET ACTION ---
    if (isset($_POST['action']) && $_POST['action'] === 'reset') {
        $dbPath = client_db_path($clientCode);
        $db = null;
        gc_collect_cycles();
        if (file_exists($dbPath))
            @unlink($dbPath);

        $db = open_client_db($clientCode); // Recreate
        // Schema definition
        $db->exec("CREATE TABLE IF NOT EXISTS documentos (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT, numero TEXT, fecha DATE, fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP, proveedor TEXT, naviera TEXT, peso_kg REAL, valor_usd REAL, ruta_archivo TEXT NOT NULL, original_path TEXT, hash_archivo TEXT, datos_extraidos TEXT, ai_confianza REAL, requiere_revision INTEGER DEFAULT 0, estado TEXT DEFAULT 'pendiente', notas TEXT);");
        $db->exec("CREATE TABLE IF NOT EXISTS codigos (id INTEGER PRIMARY KEY AUTOINCREMENT, documento_id INTEGER NOT NULL, codigo TEXT NOT NULL, descripcion TEXT, cantidad INTEGER, valor_unitario REAL, validado INTEGER DEFAULT 0, alerta TEXT, FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE);");
        $db->exec("CREATE TABLE IF NOT EXISTS vinculos (id INTEGER PRIMARY KEY AUTOINCREMENT, documento_origen_id INTEGER NOT NULL, documento_destino_id INTEGER NOT NULL, tipo_vinculo TEXT NOT NULL, codigos_coinciden INTEGER DEFAULT 0, codigos_faltan INTEGER DEFAULT 0, codigos_extra INTEGER DEFAULT 0, discrepancias TEXT, FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE, FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE);");

        ob_clean();
        echo json_encode(['success' => true, 'logs' => [['msg' => "Base de Datos Reiniciada (Cero Kilómetros)", 'type' => 'success']]]);
        exit;
    }

    // --- IMPORT ACTION ---
    if (isset($_FILES['csv_file']) && isset($_FILES['zip_file'])) {

        // 1. Process CSV
        $csvFile = $_FILES['csv_file']['tmp_name'];
        logMsg("📄 Procesando CSV...");

        // Use native PHP CSV parser (robust) via engine helper or direct
        $csvData = array_map('str_getcsv', file($csvFile));
        $header = array_shift($csvData);
        // Normalize headers
        $header = array_map(function ($h) {
            return strtolower(trim($h));
        }, $header);

        // Identify columns
        $colMap = [
            'pdf' => array_search('nombre_pdf', $header),
            'doc' => array_search('nombre_doc', $header),
            'fecha' => array_search('fecha', $header),
            'cantidad_codigos' => array_search('cantidad_codigos', $header),
            'codigos' => array_search('codigos', $header)
        ];

        if ($colMap['pdf'] === false)
            throw new Exception("Columna faltante en CSV: 'nombre_pdf'");

        $db->beginTransaction();
        $importedDocs = 0;
        $importedCodes = 0;
        $skippedDuplicates = 0;
        $codesAddedToExisting = 0;

        // ====== CACHÉ EN MEMORIA para documentos creados en esta misma importación ======
        $insertedThisRun = []; // key = nombre_pdf normalizado => docId

        // Prepare statements
        $stmtFindDoc = $db->prepare("SELECT id, numero, ruta_archivo FROM documentos WHERE ruta_archivo LIKE ? OR original_path = ? LIMIT 1");
        $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, original_path, estado) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtCode = $db->prepare("INSERT OR IGNORE INTO codigos (documento_id, codigo, descripcion, cantidad, validado) VALUES (?, ?, ?, ?, ?)");

        foreach ($csvData as $row) {
            if (count($row) < count($header))
                continue; // Skip malformed rows

            $rawPdf = trim($row[$colMap['pdf']]); // Critical for linking
            $rawDoc = $row[$colMap['doc']] ?? 'S/N';
            $rawFecha = $row[$colMap['fecha']] ?? date('Y-m-d');
            $rawCodes = $row[$colMap['codigos']] ?? '';
            $expectedCount = isset($colMap['cantidad_codigos']) && $colMap['cantidad_codigos'] !== false
                ? (int) ($row[$colMap['cantidad_codigos']] ?? 0)
                : 0;

            if (empty($rawPdf))
                continue;

            // Normalizar clave para comparación
            $pdfKey = strtolower(trim($rawPdf));

            // ====== VERIFICAR PRIMERO EN CACHÉ DE ESTA MISMA IMPORTACIÓN ======
            if (isset($insertedThisRun[$pdfKey])) {
                $docId = $insertedThisRun[$pdfKey];
                $skippedDuplicates++;
                // No log para evitar spam - solo agregar códigos
            } else {
                // ====== BUSCAR DOCUMENTO EXISTENTE EN BASE DE DATOS ======
                $stmtFindDoc->execute(['%' . $rawPdf, $rawPdf]);
                $existingDoc = $stmtFindDoc->fetch(PDO::FETCH_ASSOC);

                if ($existingDoc) {
                    $docId = $existingDoc['id'];
                    $insertedThisRun[$pdfKey] = $docId; // Agregar a caché
                    $skippedDuplicates++;
                    logMsg("📎 Documento existente encontrado: {$rawPdf} (ID: {$docId})", "info");
                } else {
                    // Documento nuevo - crear
                    try {
                        $stmtDoc->execute(['importado_csv', $rawDoc, $rawFecha, 'Importacion Masiva', 'pending', $rawPdf, 'procesado']);
                        $docId = $db->lastInsertId();
                        $insertedThisRun[$pdfKey] = $docId; // ¡IMPORTANTE! Agregar a caché
                        $importedDocs++;
                        logMsg("✅ Nuevo documento creado: {$rawPdf} (ID: {$docId})", "success");
                    } catch (Exception $e) {
                        logMsg("Error creando documento ($rawPdf): " . $e->getMessage(), "warning");
                        continue;
                    }
                }
            }

            // ====== AGREGAR CÓDIGOS (EVITAR DUPLICADOS) ======
            if (!empty($rawCodes) && $docId) {
                $codes = explode(',', $rawCodes);
                $codesInserted = 0;

                foreach ($codes as $c) {
                    $c = trim($c);
                    $c = trim($c, '"\''); // Limpiar comillas
                    if (strlen($c) > 1) {
                        try {
                            $stmtCode->execute([$docId, $c, 'Importado CSV', 1, 0]);
                            if ($stmtCode->rowCount() > 0) {
                                $importedCodes++;
                                $codesInserted++;
                                if ($existingDoc) {
                                    $codesAddedToExisting++;
                                }
                            }
                        } catch (Exception $e) {
                            // Código duplicado - ignorar silenciosamente (INSERT OR IGNORE)
                        }
                    }
                }

                // Validar cantidad de códigos
                if ($expectedCount > 0 && $codesInserted !== $expectedCount) {
                    logMsg("⚠ Discrepancia de códigos en {$rawPdf}: esperados {$expectedCount}, insertados {$codesInserted}", "warning");
                }
            }
        }
        $db->commit();

        logMsg("✅ Importación completada:", "success");
        logMsg("   - Documentos nuevos: {$importedDocs}", "success");
        logMsg("   - Documentos existentes (reutilizados): {$skippedDuplicates}", "info");
        logMsg("   - Códigos totales insertados: {$importedCodes}", "success");
        if ($codesAddedToExisting > 0) {
            logMsg("   - Códigos agregados a docs existentes: {$codesAddedToExisting}", "info");
        }

        // 2. Process ZIP
        $zipFile = $_FILES['zip_file']['tmp_name'];
        $uploadDir = __DIR__ . "/../../clients/" . $clientCode . "/uploads/csv_import";

        logMsg("📦 Procesando ZIP y Enlazando PDFs...");
        processZipAndLink($db, $zipFile, $uploadDir, "csv_import/");

        $response['success'] = true;
    }

} catch (Exception $e) {
    if ($db && $db->inTransaction())
        $db->rollBack();
    logMsg("ERROR CRÍTICO: " . $e->getMessage(), "error");
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
