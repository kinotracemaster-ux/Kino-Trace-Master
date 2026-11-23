<?php

namespace Kino\Api;

use PDO;
use Exception;

class SystemController extends BaseController
{
    /**
     * Documento pendiente de indexar: sin texto extraído y sin error registrado.
     * Los que fallaron (archivo faltante, PDF sin texto) quedan marcados con "error"
     * y ya no se toman en cada lote; si no, bloqueaban la cola (siempre se pedían
     * los mismos N más recientes y los más antiguos nunca se indexaban).
     */
    private const PENDING_CONDITION = "(
        datos_extraidos IS NULL
        OR datos_extraidos = ''
        OR (datos_extraidos NOT LIKE '%\"text\":%' AND datos_extraidos NOT LIKE '%\"error\":%')
    )";

    /** Segundos de trabajo por petición: muy por debajo del corte del proxy (Railway/Cloudflare ~100 s). */
    private const REINDEX_PRESUPUESTO_SEG = 25;

    public function reindex($request)
    {
        // Start output buffering to catch any stray warnings/errors
        ob_start();
        $indexed = 0;
        $errors = [];
        $procesados = 0;   // documentos terminados (bien o con error) en esta petición
        $parcial = null;   // documento escaneado a medio leer (se retoma en la próxima petición)
        $tiempoAgotado = false;

        try {
            \load_helper('indexar_pdf');
            $inicio = microtime(true);
            $deadline = $inicio + self::REINDEX_PRESUPUESTO_SEG;
            set_time_limit(120);
            session_write_close(); // Prevent session locking during long process

            // Silence fontconfig warnings - ensure valid temp dir
            $tmpDir = sys_get_temp_dir();
            putenv("FONTCONFIG_PATH=$tmpDir");

            $forceAll = isset($request['force']);
            // "batch" es un máximo de documentos; el que manda es el tiempo por petición.
            $batchSize = min(200, max(1, (int) ($request['batch'] ?? 50)));
            $offset = max(0, (int) ($request['offset'] ?? 0));

            if ($forceAll) {
                $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo, datos_extraidos
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                ORDER BY id DESC
                LIMIT $batchSize OFFSET $offset
            ");
            } else {
                $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo, datos_extraidos
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                AND " . self::PENDING_CONDITION . "
                ORDER BY id DESC
                LIMIT $batchSize
            ");
            }
            $stmt->execute();
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $updateStmt = $this->db->prepare("UPDATE documentos SET datos_extraidos = ? WHERE id = ?");

            foreach ($docs as $doc) {
                // No empezar un documento nuevo si ya se usó el tiempo de esta petición.
                if ($procesados > 0 && microtime(true) >= $deadline) {
                    $tiempoAgotado = true;
                    break;
                }

                $pdfPath = resolve_pdf_path($this->clientCode, $doc);
                $type = strtolower($doc['tipo']);

                if (!$pdfPath || !file_exists($pdfPath)) {
                    $errors[] = "#{$doc['id']}: Archivo no encontrado";
                    // Guardar el error para no reintentarlo en cada lote
                    $updateStmt->execute([json_encode([
                        'error' => 'Archivo no encontrado',
                        'type_expected' => $type,
                        'timestamp' => time()
                    ]), $doc['id']]);
                    $procesados++;
                    continue;
                }

                $previo = json_decode((string) ($doc['datos_extraidos'] ?? ''), true);
                // En "forzar" se relee desde cero salvo que haya un OCR a medias que retomar.
                if (!is_array($previo) || !isset($previo['ocr_pagina'])) {
                    $previo = null;
                }

                try {
                    $originalErrorReporting = error_reporting();
                    error_reporting($originalErrorReporting & ~E_NOTICE & ~E_WARNING);
                    try {
                        $r = kt_indexar_pdf($pdfPath, $previo, $deadline);
                    } finally {
                        error_reporting($originalErrorReporting);
                    }

                    if ($r['estado'] === 'parcial') {
                        $updateStmt->execute([json_encode($r['avance'], JSON_UNESCAPED_UNICODE), $doc['id']]);
                        $parcial = ['id' => (int) $doc['id'], 'pagina' => $r['pagina'], 'paginas' => $r['paginas']];
                        $tiempoAgotado = true;
                        break; // la próxima petición sigue en este mismo documento
                    }

                    if ($r['estado'] === 'error') {
                        $errors[] = "#{$doc['id']}: {$r['error']}";
                        $updateStmt->execute([json_encode(['error' => $r['error'], 'timestamp' => time()], JSON_UNESCAPED_UNICODE), $doc['id']]);
                    } else {
                        $updateStmt->execute([json_encode([
                            'text' => mb_substr($r['text'], 0, 50000),
                            'auto_codes' => $r['codes'],
                            'indexed_at' => date('Y-m-d H:i:s')
                        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $doc['id']]);
                        $indexed++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc['id']}: " . $e->getMessage();
                    error_log("Reindex doc #{$doc['id']}: " . $e->getMessage());
                    // Guardar el error para que el documento salga de la cola de pendientes
                    $updateStmt->execute([json_encode(['error' => $e->getMessage(), 'timestamp' => time()]), $doc['id']]);
                }
                $procesados++;
                gc_collect_cycles();
            }

            $pending = (int) $this->db->query("
                SELECT COUNT(*) FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                AND " . self::PENDING_CONDITION . "
            ")->fetchColumn();

            // Documentos que ya fallaron (archivo faltante, PDF sin texto, etc.).
            // Se reintentan solo con force=1.
            $failed = (int) $this->db->query("
                SELECT COUNT(*) FROM documentos
                WHERE ruta_archivo LIKE '%.pdf'
                AND datos_extraidos NOT LIKE '%\"text\":%'
                AND datos_extraidos LIKE '%\"error\":%'
            ")->fetchColumn();

            $response = [
                'success' => true,
                'indexed' => $indexed,
                'errors' => $errors,
                'pending' => $pending,
                'failed' => $failed,
                'processed' => $procesados,
                // En modo forzar: desde dónde pedir el siguiente lote (el doc a medias se repite y se retoma).
                'next_offset' => $offset + $procesados,
                'partial' => $parcial,
                'time_up' => $tiempoAgotado,
                'done' => $forceAll ? ($parcial === null && !$tiempoAgotado && count($docs) < $batchSize) : ($pending === 0),
                'seconds' => round(microtime(true) - $inicio, 1),
                'message' => "Indexados: $indexed, Pendientes: $pending, Con error: $failed"
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

    public function updatePassword($request)
    {
        $newPassword = $request['new_password'] ?? '';
        $confirmPassword = $request['confirm_password'] ?? '';

        if (empty($newPassword) || strlen($newPassword) < 4) {
            $this->jsonExit(['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres']);
        }

        if ($newPassword !== $confirmPassword) {
            $this->jsonExit(['success' => false, 'error' => 'Las contraseñas no coinciden']);
        }

        try {
            // Need connection to central DB to update password
            // Since this controller runs with client context, we need to open central manually
            // or use the 'centralDb' variable if available in scope (it's not here).
            // We'll reopen central.db connection

            $centralDbPath = BASE_DIR . '/clients/central.db';
            if (!file_exists($centralDbPath)) {
                throw new Exception("Base de datos central no encontrada");
            }

            $centralPdo = new PDO('sqlite:' . $centralDbPath);
            $centralPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $centralPdo->prepare("UPDATE control_clientes SET password_hash = ? WHERE codigo = ?");
            $stmt->execute([$hash, $this->clientCode]);

            $this->jsonExit(['success' => true, 'message' => 'Contraseña actualizada correctamente']);

        } catch (Exception $e) {
            $this->jsonExit(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
        }
    }
}
