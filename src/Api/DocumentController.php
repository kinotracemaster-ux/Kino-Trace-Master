<?php

namespace Kino\Api;

use PDO;
use Exception;
use SecureFileUploader;
use Logger;

class DocumentController extends BaseController
{
    public function upload($post, $files)
    {
        // Validar campos requeridos
        $validationError = $this->validateRequired($post, ['tipo', 'numero', 'fecha']);
        if ($validationError) {
            $this->jsonExit($validationError);
        }

        $tipo = sanitize_code($post['tipo']);
        $numero = trim($post['numero']);
        $fecha = trim($post['fecha']);
        $proveedor = trim($post['proveedor'] ?? '');
        $codes = array_filter(array_map('trim', explode("\n", $post['codes'] ?? '')));

        // ✨ SEGURIDAD: Validación robusta de archivo
        $uploadResult = SecureFileUploader::secureMove(
            $files['file'],
            $this->clientCode,
            $tipo
        );

        if (isset($uploadResult['error'])) {
            $this->jsonExit(['error' => $uploadResult['error']]);
        }

        // Verificar duplicado por hash
        $duplicate = SecureFileUploader::checkDuplicate($this->db, $uploadResult['hash']);
        if ($duplicate) {
            $this->jsonExit([
                'warning' => 'Este archivo ya existe',
                'existing_doc' => $duplicate,
                'message' => 'El documento "' . $duplicate['numero'] . '" ya contiene este archivo'
            ]);
        }

        $targetPath = CLIENTS_DIR . '/' . $this->clientCode . '/uploads/' . $uploadResult['path'];
        $hash = $uploadResult['hash'];
        $targetName = basename($uploadResult['path']); // Ensure targetName is defined from path
        $ext = pathinfo($targetPath, PATHINFO_EXTENSION);

        // Extraer texto si es PDF
        $datosExtraidos = [];
        if (strtolower($ext) === 'pdf') {
            $extractResult = extract_codes_from_pdf($targetPath);
            if ($extractResult['success']) {
                $datosExtraidos = [
                    'text' => substr($extractResult['text'], 0, 50000),
                    'auto_codes' => $extractResult['codes']
                ];
            }
        }

        // Insertar documento
        $stmt = $this->db->prepare("
            INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, hash_archivo, datos_extraidos)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tipo,
            $numero,
            $fecha,
            $proveedor,
            $uploadResult['path'], // Storing relative path as returned by uploader
            $hash,
            json_encode($datosExtraidos)
        ]);

        $docId = $this->db->lastInsertId();

        // Insertar códigos
        if (!empty($codes)) {
            $insertCode = $this->db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
            foreach (array_unique($codes) as $code) {
                $insertCode->execute([$docId, $code]);
            }
        }

        $this->jsonExit([
            'success' => true,
            'message' => 'Documento guardado',
            'document_id' => $docId,
            'codes_count' => count($codes)
        ]);
    }

    public function update($post, $files)
    {
        $id = (int) ($post['id'] ?? 0);
        $tipo = trim($post['tipo'] ?? '');
        $numero = trim($post['numero'] ?? '');
        $fecha = trim($post['fecha'] ?? '');
        $proveedor = trim($post['proveedor'] ?? '');

        if (!$id || !$tipo || !$numero || !$fecha) {
            $this->jsonExit(['error' => 'Faltan campos requeridos']);
        }

        $codes = array_filter(array_map('trim', explode("\n", $post['codes'] ?? '')));

        // Check if document exists
        $stmt = $this->db->prepare("SELECT id, ruta_archivo FROM documentos WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            $this->jsonExit(['error' => 'Documento no encontrado']);
        }

        $rutaArchivo = $doc['ruta_archivo'];
        $hash = null;
        $datosExtraidos = null;

        // Check if a new file was uploaded
        if (isset($files['file']) && $files['file']['error'] === UPLOAD_ERR_OK) {
            $clientDir = CLIENTS_DIR . '/' . $this->clientCode;

            // Delete old file
            $oldFilePath = $clientDir . '/uploads/' . $doc['ruta_archivo'];
            if (file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }

            // Upload new file
            $uploadResult = SecureFileUploader::secureMove(
                $files['file'],
                $this->clientCode,
                $tipo
            );

            if (isset($uploadResult['error'])) {
                $this->jsonExit(['error' => $uploadResult['error']]);
            }

            $targetPath = CLIENTS_DIR . '/' . $this->clientCode . '/uploads/' . $uploadResult['path'];
            $hash = $uploadResult['hash'];
            $rutaArchivo = $uploadResult['path'];
            $ext = pathinfo($targetPath, PATHINFO_EXTENSION);

            if (strtolower($ext) === 'pdf') {
                $extractResult = extract_codes_from_pdf($targetPath);
                if ($extractResult['success']) {
                    $datosExtraidos = [
                        'text' => substr($extractResult['text'], 0, 50000),
                        'auto_codes' => $extractResult['codes']
                    ];
                }
            }
        }

        // Update document
        if ($hash && $datosExtraidos) {
            // New file uploaded
            $stmt = $this->db->prepare("
                UPDATE documentos 
                SET tipo = ?, numero = ?, fecha = ?, proveedor = ?, 
                    ruta_archivo = ?, hash_archivo = ?, datos_extraidos = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $tipo,
                $numero,
                $fecha,
                $proveedor,
                $rutaArchivo,
                $hash,
                json_encode($datosExtraidos),
                $id
            ]);
        } else {
            // Metadata only
            $stmt = $this->db->prepare("
                UPDATE documentos 
                SET tipo = ?, numero = ?, fecha = ?, proveedor = ?
                WHERE id = ?
            ");
            $stmt->execute([$tipo, $numero, $fecha, $proveedor, $id]);
        }

        // Update codes
        $this->db->prepare("DELETE FROM codigos WHERE documento_id = ?")->execute([$id]);

        if (!empty($codes)) {
            $insertCode = $this->db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
            foreach (array_unique($codes) as $code) {
                $insertCode->execute([$id, $code]);
            }
        }

        $this->jsonExit([
            'success' => true,
            'message' => 'Documento actualizado',
            'document_id' => $id,
            'codes_count' => count($codes)
        ]);
    }

    public function delete($post)
    {
        $id = (int) ($post['id'] ?? $_GET['id'] ?? 0);

        if (!$id) {
            $this->sendError('VALIDATION_001', 'ID de documento requerido');
        }

        $stmt = $this->db->prepare('SELECT ruta_archivo, tipo FROM documentos WHERE id = ?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            $this->sendError('DOC_001');
        }

        $uploadsDir = CLIENTS_DIR . "/{$this->clientCode}/uploads/";
        $filePath = $uploadsDir . $doc['ruta_archivo'];

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $stmt = $this->db->prepare('DELETE FROM documentos WHERE id = ?');
        $stmt->execute([$id]);

        Logger::info('Document deleted', [
            'doc_id' => $id,
            'file' => $doc['ruta_archivo']
        ]);

        $this->jsonExit([
            'success' => true,
            'message' => 'Documento eliminado correctamente'
        ]);
    }

    public function list($get)
    {
        $page = max(1, (int) ($get['page'] ?? 1));
        $perPage = (int) ($get['per_page'] ?? 50);
        $tipo = $get['tipo'] ?? '';

        $where = '';
        $params = [];
        if ($tipo !== '') {
            $where = 'WHERE LOWER(d.tipo) = LOWER(?)';
            $params[] = $tipo;
        }

        if ($params) {
            // Fix: add 'd.' prefix to tipo column in COUNT query
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM documentos d $where");
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();
        } else {
            $total = (int) $this->db->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
        }

        $offset = ($page - 1) * $perPage;
        // Inject integers directly to avoid PDO string casting issues in LIMIT
        $stmt = $this->db->prepare("
            SELECT
                d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                GROUP_CONCAT(c.codigo, '||') AS codigos
            FROM documentos d
            LEFT JOIN codigos c ON d.id = c.documento_id
            $where
            GROUP BY d.id
            ORDER BY d.fecha DESC, d.id DESC
            LIMIT $perPage OFFSET $offset
        ");

        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $docs = array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'tipo' => $r['tipo'],
                'numero' => $r['numero'],
                'fecha' => $r['fecha'],
                'proveedor' => $r['proveedor'],
                'ruta_archivo' => $r['ruta_archivo'],
                'codes' => $r['codigos'] ? array_filter(explode('||', $r['codigos'])) : []
            ];
        }, $rows);

        $this->jsonExit([
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
            'data' => $docs
        ]);
    }

    public function get($get)
    {
        $id = (int) ($get['id'] ?? 0);
        $stmt = $this->db->prepare("
            SELECT d.*, GROUP_CONCAT(c.codigo, '||') AS codigos
            FROM documentos d
            LEFT JOIN codigos c ON d.id = c.documento_id
            WHERE d.id = ?
            GROUP BY d.id
        ");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            $this->jsonExit(['error' => 'Documento no encontrado']);
        }

        $doc['codes'] = $doc['codigos'] ? array_filter(explode('||', $doc['codigos'])) : [];
        unset($doc['codigos']);

        $this->jsonExit($doc);
    }
}
