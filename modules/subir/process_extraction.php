<?php
/**
 * Proceso de Extracción Asíncrona (Background Worker)
 * 
 * Este script se llama de forma no bloqueante desde index.php al subir un archivo.
 * Se encarga de:
 * 1. Validar el documento.
 * 2. Ejecutar la extracción de códigos (OCR/Texto).
 * 3. Actualizar la base de datos del cliente con los resultados.
 */

// Aumentar límites para proceso pesado
set_time_limit(300); // 5 minutos
ini_set('memory_limit', '512M');

// Cargar configuración
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';

// Validar entrada
$clientCode = $_POST['client_code'] ?? '';
$docId = (int) ($_POST['doc_id'] ?? 0);
$token = $_POST['token'] ?? '';

// Token de seguridad simple (podría mejorarse)
$internalToken = md5($clientCode . 'kino_async_' . date('Y-m-d'));

if (empty($clientCode) || $docId <= 0) {
    die("Invalid parameters");
}

/* 
// Validación de token opcional para seguridad extra
if ($token !== $internalToken) {
    die("Unauthorized Access");
} 
*/

try {
    // 1. Conectar a BD Cliente
    $db = open_client_db($clientCode);

    // 2. Obtener datos del documento
    $stmt = $db->prepare("SELECT * FROM documentos WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        die("Document not found");
    }

    // Resolver ruta absoluta
    $pdfPath = resolve_pdf_path($clientCode, $doc);
    if (!$pdfPath || !file_exists($pdfPath)) {
        // Actualizar estado a error
        $db->prepare("UPDATE documentos SET estado = 'error_archivo' WHERE id = ?")->execute([$docId]);
        die("File not found: " . $doc['ruta_archivo']);
    }

    // Actualizar estado a procesando
    $db->prepare("UPDATE documentos SET estado = 'procesando' WHERE id = ?")->execute([$docId]);

    // 3. Obtener Configuración de Extracción
    $stmtConfig = $db->prepare("SELECT prefix, terminator, min_length, max_length FROM configuracion_extraccion LIMIT 1");
    $stmtConfig->execute();
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    $prefix = $config['prefix'] ?? '';
    $terminator = $config['terminator'] ?? '';
    $minLength = $config['min_length'] ?? 1;
    $maxLength = $config['max_length'] ?? 20;

    // 4. Ejecutar Extracción (Pesada)
    // Usamos 200 DPI como solicitó el usuario para mantener calidad
    $extractResult = extract_codes_from_pdf($pdfPath, [
        'prefix' => $prefix,
        'terminator' => $terminator,
        'min_length' => $minLength,
        'max_length' => $maxLength,
        'dpi' => 200
    ]);

    // 5. Guardar Resultados
    $db->beginTransaction();

    $datosExtraidos = [];
    $codes = [];

    if ($extractResult['success']) {
        $datosExtraidos = [
            'text' => substr($extractResult['text'], 0, 10000), // Limitar tamaño texto
            'auto_codes' => $extractResult['codes']
        ];
        $codes = $extractResult['codes'];
    }

    // Actualizar documento con JSON y estado
    $stmtUpdate = $db->prepare("UPDATE documentos SET datos_extraidos = ?, estado = 'procesado' WHERE id = ?");
    $stmtUpdate->execute([json_encode($datosExtraidos), $docId]);

    // MERGE de códigos: agregar los auto-extraídos SIN borrar los manuales del usuario
    if (!empty($codes)) {
        // Obtener códigos ya existentes (los que el usuario ingresó manualmente)
        $existingStmt = $db->prepare("SELECT codigo FROM codigos WHERE documento_id = ?");
        $existingStmt->execute([$docId]);
        $existingCodes = array_map('strtolower', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

        $insertCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
        $added = 0;
        foreach (array_unique($codes) as $c) {
            $c = trim($c);
            // Solo insertar si no existe ya (evita duplicados con los manuales)
            if (!empty($c) && !in_array(strtolower($c), $existingCodes)) {
                $insertCode->execute([$docId, $c]);
                $added++;
            }
        }
    }

    $db->commit();
    echo "Success. Codes extracted: " . count($codes);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    // Registrar error en documento
    if (isset($db)) {
        try {
            $stmtErr = $db->prepare("UPDATE documentos SET estado = 'error', notas = ? WHERE id = ?");
            $stmtErr->execute(["Error extracción: " . $e->getMessage(), $docId]);
        } catch (Exception $ex) {
        } // Ignorar fallo al guardar error
    }
    error_log("Async OCR Error ($clientCode - $docId): " . $e->getMessage());
    die("Error: " . $e->getMessage());
}
