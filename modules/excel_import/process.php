<?php
/**
 * Process.php - Backend handler for CSV Import Module
 * 
 * Handles:
 * - CSV/Excel file import processing
 * - Database reset (clean all)
 * - Statistics retrieval
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

header('Content-Type: application/json');

if (!isset($_SESSION['client_code'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

$action = $_POST['action'] ?? '';
$logs = [];

function addLog($msg, $type = 'info')
{
    global $logs;
    $logs[] = ['msg' => $msg, 'type' => $type];
}

/**
 * Parse CSV file
 */
function parseCSV(string $filePath): array
{
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle, 0, ',');
        if ($headers) {
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $headers);

            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if (count($data) >= count($headers)) {
                    $rows[] = array_combine($headers, array_slice($data, 0, count($headers)));
                }
            }
        }
        fclose($handle);
    }
    return $rows;
}

/**
 * Parse XLSX file (basic)
 */
function parseXLSX(string $filePath): array
{
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $rows;
    }

    // Read shared strings
    $sharedStrings = [];
    $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($stringsXml) {
        $xml = @simplexml_load_string($stringsXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string) $si->t;
            }
        }
    }

    // Read first sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml) {
        $xml = @simplexml_load_string($sheetXml);
        if ($xml && isset($xml->sheetData)) {
            $allRows = [];
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $value = '';
                    $type = (string) $cell['t'];

                    if ($type === 's') {
                        $index = (int) $cell->v;
                        $value = $sharedStrings[$index] ?? '';
                    } else {
                        $value = (string) $cell->v;
                    }

                    $rowData[] = $value;
                }
                $allRows[] = $rowData;
            }

            if (!empty($allRows)) {
                $headers = array_map(function ($h) {
                    return strtolower(trim($h));
                }, $allRows[0]);

                for ($i = 1; $i < count($allRows); $i++) {
                    if (count($allRows[$i]) >= count($headers)) {
                        $rows[] = array_combine($headers, array_slice($allRows[$i], 0, count($headers)));
                    }
                }
            }
        }
    }

    $zip->close();
    return $rows;
}

/**
 * Find document by name - ROBUST STRICT VERSION
 * Matches ONLY by the exact filename (basename), ignoring the folder path.
 * Case-insensitive to prevent silly mismatches.
 */
function findDocumentByName(PDO $db, string $name): ?array
{
    $name = trim($name);
    if (empty($name)) {
        return null;
    }

    // 1. Try exact match on 'ruta_archivo' or 'original_path' (Case Insensitive)
    // using LIKE for implicit CI in SQLite
    $stmt = $db->prepare('
        SELECT * FROM documentos 
        WHERE ruta_archivo LIKE ? 
           OR original_path LIKE ? 
        LIMIT 1
    ');
    $stmt->execute([$name, $name]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($doc)
        return $doc;

    // 2. Try matching as a filename at the end of a path (Basename match)
    // Matches "uploads/folder/file.pdf" if search is "file.pdf"
    // We check for both / and \ to be safe
    $stmt = $db->prepare('
        SELECT * FROM documentos 
        WHERE ruta_archivo LIKE ? 
           OR ruta_archivo LIKE ?
           OR original_path LIKE ?
           OR original_path LIKE ?
        LIMIT 1
    ');
    $stmt->execute([
        '%/' . $name,  // Forward slash separator
        '%\\' . $name, // Backslash separator
        '%/' . $name,  // Check original_path too
        '%\\' . $name
    ]);

    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    return $doc ?: null;
}

// ============ ACTION HANDLERS ============

if ($action === 'import') {
    try {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el archivo o hubo un error al subirlo');
        }

        $file = $_FILES['excel_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx'])) {
            throw new Exception('Formato no soportado. Use archivos .csv o .xlsx');
        }

        addLog("📂 Procesando archivo: {$file['name']}", 'info');

        // Parse file
        $data = ($ext === 'csv') ? parseCSV($file['tmp_name']) : parseXLSX($file['tmp_name']);

        if (empty($data)) {
            throw new Exception('No se pudo leer el archivo o está vacío');
        }

        addLog("✓ Archivo leído: " . count($data) . " filas", 'success');

        // Find columns
        $columns = array_keys($data[0]);
        $nameColumn = null;
        $codeColumn = null;

        foreach ($columns as $col) {
            $colLower = strtolower($col);
            if (in_array($colLower, ['archivo', 'nombre', 'documento', 'file', 'name', 'numero', 'nombre_pdf', 'nombre_doc'])) {
                $nameColumn = $col;
            }
            if (in_array($colLower, ['codigo', 'code', 'código', 'codigos', 'codes'])) {
                $codeColumn = $col;
            }
        }

        if (!$nameColumn) {
            throw new Exception('No se encontró columna de nombre/archivo. Columnas: ' . implode(', ', $columns));
        }

        addLog("📋 Columnas detectadas: nombre='{$nameColumn}', codigo='" . ($codeColumn ?: 'N/A') . "'", 'info');

        // Process rows
        $stats = ['matched' => 0, 'not_found' => 0, 'codes_added' => 0];
        $insertedThisRun = []; // CACHÉ: key = nombre_pdf (normalizado) => docId

        $stmtInsertCode = $db->prepare('INSERT OR IGNORE INTO codigos (documento_id, codigo) VALUES (?, ?)');

        foreach ($data as $index => $row) {
            $fileName = $row[$nameColumn] ?? '';
            $code = $codeColumn ? ($row[$codeColumn] ?? '') : '';

            if (empty($fileName))
                continue;

            $pdfKey = strtolower(trim($fileName));
            $docId = null;
            $doc = null;

            // 1. Revisar Caché (Mismo proceso)
            if (isset($insertedThisRun[$pdfKey])) {
                $docId = $insertedThisRun[$pdfKey];
                $stats['matched']++; // Contamos como match porque ya lo procesamos
            }
            // 2. Buscar en BD
            else {
                $doc = findDocumentByName($db, $fileName);
                if ($doc) {
                    $docId = $doc['id'];
                    $stats['matched']++;
                } else {
                    $stats['not_found']++;
                    if ($index < 5) { // Log first 5 not found
                        addLog("⚠ No encontrado en BD: {$fileName}", 'warning');
                    }
                    continue; // En excel_import NO creamos documentos, solo enlazamos
                }

                // Guardar en caché
                if ($docId) {
                    $insertedThisRun[$pdfKey] = $docId;
                }
            }

            if ($docId) {
                if (!empty($code)) {
                    // Soportar múltiples códigos en una celda separados por comas
                    $subCodes = explode(',', $code);
                    foreach ($subCodes as $c) {
                        $c = trim($c);
                        if (empty($c))
                            continue;

                        $stmtInsertCode->execute([$docId, $c]);
                        if ($stmtInsertCode->rowCount() > 0) {
                            $stats['codes_added']++;
                        }
                    }
                }
            }
        }

        if ($stats['not_found'] > 5) {
            addLog("⚠ ... y " . ($stats['not_found'] - 5) . " más no encontrados", 'warning');
        }

        addLog("✅ Procesamiento completado", 'success');
        addLog("   - Coincidencias: {$stats['matched']}", 'success');
        addLog("   - Códigos agregados: {$stats['codes_added']}", 'success');
        addLog("   - No encontrados: {$stats['not_found']}", 'warning');

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'stats' => $stats
        ]);

    } catch (Exception $e) {
        addLog("❌ Error: " . $e->getMessage(), 'error');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'logs' => $logs
        ]);
    }
    exit;
}

if ($action === 'reset') {
    try {
        addLog("🔄 Iniciando limpieza de base de datos...", 'warning');

        $db->exec('DELETE FROM codigos');
        $codesDeleted = $db->exec('SELECT changes()');
        addLog("✓ Códigos eliminados: " . $codesDeleted, 'info');

        $db->exec('DELETE FROM documentos');
        $docsDeleted = $db->exec('SELECT changes()');
        addLog("✓ Documentos eliminados: " . $docsDeleted, 'info');

        $db->exec('VACUUM');
        addLog("✓ Base de datos optimizada", 'success');

        addLog("✅ Limpieza completada correctamente", 'success');

        echo json_encode([
            'success' => true,
            'logs' => $logs
        ]);

    } catch (Exception $e) {
        addLog("❌ Error: " . $e->getMessage(), 'error');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'logs' => $logs
        ]);
    }
    exit;
}

if ($action === 'stats') {
    try {
        $totalDocs = $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
        $totalCodes = $db->query('SELECT COUNT(*) FROM codigos')->fetchColumn();

        // Documents without codes
        $docsWithoutCodes = $db->query('
            SELECT COUNT(*) FROM documentos d 
            WHERE NOT EXISTS (SELECT 1 FROM codigos c WHERE c.documento_id = d.id)
        ')->fetchColumn();

        echo json_encode([
            'success' => true,
            'stats' => [
                'total_docs' => $totalDocs,
                'total_codes' => $totalCodes,
                'docs_without_codes' => $docsWithoutCodes
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => 'Acción no reconocida'
]);
