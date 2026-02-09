<?php

namespace Kino\Api;

use PDO;
use Exception;

class SystemController extends BaseController
{
    public function reindex($request)
    {
        // Start output buffering to catch any stray warnings/errors
        ob_start();
        $docs = [];
        $indexed = 0;
        $errors = [];

        try {
            set_time_limit(300);
            session_write_close(); // Prevent session locking during long process

            // Silence fontconfig warnings - ensure valid temp dir
            $tmpDir = sys_get_temp_dir();
            putenv("FONTCONFIG_PATH=$tmpDir");

            $forceAll = isset($request['force']);
            // Increased batch size to 200 for faster processing of large workloads
            $batchSize = min(200, max(1, (int) ($request['batch'] ?? 120)));
            $offset = (int) ($request['offset'] ?? 0);

            error_log("Reindex started: forceAll=" . ($forceAll ? 'true' : 'false') . ", batchSize=$batchSize, offset=$offset");

            if ($forceAll) {
                $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo 
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                ORDER BY id DESC
                LIMIT $batchSize OFFSET $offset
            ");
                $stmt->execute();
                $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Optimized: Select only potential candidates for reindexing
                // (Null data, empty string, or missing "text" key in JSON)
                $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo, datos_extraidos
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                AND (
                    datos_extraidos IS NULL 
                    OR datos_extraidos = '' 
                    OR datos_extraidos NOT LIKE '%\"text\":%'
                )
                ORDER BY id DESC
                LIMIT $batchSize
            ");
                $stmt->execute();
                $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            error_log("Docs to process: " . count($docs));

            $updateStmt = $this->db->prepare("UPDATE documentos SET datos_extraidos = ? WHERE id = ?");

            foreach ($docs as $doc) {
                // error_log("Processing doc #{$doc['id']}");

                // Robust path resolution via centralized helper
                $pdfPath = resolve_pdf_path($this->clientCode, $doc);
                $type = strtolower($doc['tipo']);

                // 3. Verify physical existence
                if (!$pdfPath || !file_exists($pdfPath)) {
                    $uploadsDir = CLIENTS_DIR . "/{$this->clientCode}/uploads/";
                    $existingFolders = get_available_folders($this->clientCode);

                    $triedPaths = [
                        $uploadsDir . $doc['ruta_archivo'],
                        $uploadsDir . $type . '/' . basename($doc['ruta_archivo']),
                        "Resolved was NULL"
                    ];
                    $folderList = implode(', ', array_slice($existingFolders, 0, 10));
                    $errorMsg = "Archivo no encontrado. Carpetas en uploads/: [$folderList]";
                    $errors[] = "#{$doc['id']}: $errorMsg";
                    error_log("Doc #{$doc['id']} error: $errorMsg");

                    // Save error to DB so we don't retry forever
                    $errorData = json_encode([
                        'error' => 'Archivo no encontrado',
                        'type_expected' => $type,
                        'timestamp' => time()
                    ]);
                    $updateStmt->execute([$errorData, $doc['id']]);

                    continue;
                }

                try {
                    // Suppress fontconfig warnings temporarily
                    $originalErrorReporting = error_reporting();
                    error_reporting($originalErrorReporting & ~E_NOTICE & ~E_WARNING);

                    $extractResult = extract_codes_from_pdf($pdfPath);

                    // Restore error reporting
                    error_reporting($originalErrorReporting);

                    // 5. Validate extraction result
                    if (!isset($extractResult['success']) || !$extractResult['success'] || empty($extractResult['text'])) {
                        $msg = $extractResult['error'] ?? 'Extracción fallida o sin texto';
                        $errors[] = "#{$doc['id']}: $msg";

                        // Save error status
                        $errorData = json_encode(['error' => $msg, 'timestamp' => time()]);
                        $updateStmt->execute([$errorData, $doc['id']]);
                    } else {
                        // Success
                        $datosExtraidos = [
                            'text' => substr($extractResult['text'], 0, 50000),
                            'auto_codes' => $extractResult['codes'],
                            'indexed_at' => date('Y-m-d H:i:s')
                        ];
                        $updateStmt->execute([json_encode($datosExtraidos, JSON_UNESCAPED_UNICODE), $doc['id']]);
                        $indexed++;
                    }
                } catch (Exception $e) {
                    $errors[] = "#{$doc['id']}: " . $e->getMessage();
                    error_log("Doc #{$doc['id']} fatal match: " . $e->getMessage());
                }

                // Free memory after each iteration
                gc_collect_cycles();
            }

            // 4. Optimized Pending Count using SQL
            $stmtCount = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                AND (
                    datos_extraidos IS NULL 
                    OR datos_extraidos = '' 
                    OR datos_extraidos NOT LIKE '%\"text\":%'
                )
            ");
            $stmtCount->execute();
            $pending = $stmtCount->fetchColumn();

            $response = [
                'success' => true,
                'indexed' => $indexed,
                'errors' => $errors,
                'pending' => $pending,
                'message' => "Indexados: $indexed, Pendientes: $pending"
            ];

        } catch (\Throwable $e) {
            error_log("Reindex Fatal Error: " . $e->getMessage());
            $response = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        // Clean any output buffered so far (warnings, etc)
        ob_end_clean();

        $this->jsonExit($response);
    }

    public function diagnostic($request)
    {
        $diagnostics = [
            'pdftotext_available' => false,
            'pdftotext_path' => null,
            'smalot_available' => false,
            'native_php' => true,
            'test_result' => null,
            'sample_doc' => null
        ];

        // Global function presumed available
        $pdftotextPath = find_pdftotext();
        if ($pdftotextPath) {
            $diagnostics['pdftotext_available'] = true;
            $diagnostics['pdftotext_path'] = $pdftotextPath;
            $version = shell_exec("$pdftotextPath -v 2>&1");
            $diagnostics['pdftotext_version'] = trim(substr($version, 0, 100));
        }

        $parserPath = __DIR__ . '/../../vendor/autoload.php'; // Adjusted path
        if (file_exists($parserPath)) {
            require_once $parserPath;
            $diagnostics['smalot_available'] = class_exists('Smalot\PdfParser\Parser');
        }

        $testId = (int) ($request['doc_id'] ?? 0);
        if ($testId) {
            $stmt = $this->db->prepare("SELECT id, ruta_archivo, tipo FROM documentos WHERE id = ?");
            $stmt->execute([$testId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $diagnostics['sample_doc'] = $doc['ruta_archivo'];
                $uploadsDir = CLIENTS_DIR . "/{$this->clientCode}/uploads/";

                $possiblePaths = [
                    $uploadsDir . $doc['ruta_archivo'],
                    $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'],
                    $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']),
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $diagnostics['pdf_found'] = true;
                        $diagnostics['pdf_path'] = $path;
                        $diagnostics['pdf_size'] = filesize($path);

                        try {
                            $diagnostics['test_result'] = extract_codes_from_pdf($path);
                        } catch (Exception $e) {
                            $diagnostics['test_result'] = ['error' => $e->getMessage()];
                        }
                        break;
                    }
                }
            }
        }
        $this->jsonExit($diagnostics);
    }
}
