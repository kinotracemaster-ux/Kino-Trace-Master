<?php
/**
 * Enlazar PDFs desde ZIP - Admin endpoint
 * 
 * Similar a link_zip.php pero recibe client_code como POST param
 * en vez de usar la sesión (para uso desde el panel de admin).
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
    // Admin auth check
    $allowedAdminUsers = ['admin', 'kino'];
    if (!isset($_SESSION['client_code']) || !in_array($_SESSION['client_code'], $allowedAdminUsers)) {
        throw new Exception('Acceso no autorizado. Solo admin.');
    }

    $clientCode = trim($_POST['client_code'] ?? '');
    if ($clientCode === '') {
        throw new Exception('Debe especificar un cliente destino.');
    }

    $db = open_client_db($clientCode);

    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió archivo ZIP válido.');
    }

    $zipFile = $_FILES['zip_file']['tmp_name'];
    $zipName = $_FILES['zip_file']['name'];
    $zipSize = round($_FILES['zip_file']['size'] / 1024 / 1024, 1);

    logMsg("📦 ZIP recibido: {$zipName} ({$zipSize} MB) → cliente: {$clientCode}");

    // Count existing docs before linking
    $totalDocs = $db->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
    $pendingDocs = $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();

    logMsg("📊 Base de datos: {$totalDocs} documentos, {$pendingDocs} sin PDF enlazado");

    // Process ZIP and link
    $uploadDir = CLIENTS_DIR . "/{$clientCode}/uploads/sql_import/";
    processZipAndLink($db, $zipFile, $uploadDir, "sql_import/");

    // Count after linking
    $pendingAfter = $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();
    $linked = $pendingDocs - $pendingAfter;

    logMsg("───────────────────────────────", "info");
    logMsg("📊 Resultado: {$linked} PDFs enlazados, {$pendingAfter} aún pendientes", "success");

    // Get orphan details: documents without PDF (with original_path for diagnostics)
    $orphanDocs = [];
    $stmtOrphan = $db->query("SELECT d.id, d.numero, d.tipo, d.original_path,
                                (SELECT COUNT(*) FROM codigos c WHERE c.documento_id = d.id) AS num_codes
                              FROM documentos d 
                              WHERE d.ruta_archivo = 'pending' OR d.ruta_archivo IS NULL OR d.ruta_archivo = ''
                              ORDER BY d.numero LIMIT 200");
    while ($row = $stmtOrphan->fetch(PDO::FETCH_ASSOC)) {
        $orphanDocs[] = [
            'id' => (int) $row['id'],
            'numero' => $row['numero'],
            'tipo' => $row['tipo'],
            'codes' => (int) $row['num_codes'],
            'original_path' => $row['original_path'] ?? ''
        ];
    }

    // Get orphan PDFs: auto-created documents with 0 codes
    $orphanPdfs = [];
    $stmtOrphanPdf = $db->query("SELECT d.id, d.numero, d.original_path
                                  FROM documentos d 
                                  LEFT JOIN codigos c ON d.id = c.documento_id
                                  WHERE d.tipo = 'generado_auto' AND c.id IS NULL
                                  ORDER BY d.numero LIMIT 200");
    while ($row = $stmtOrphanPdf->fetch(PDO::FETCH_ASSOC)) {
        $orphanPdfs[] = [
            'id' => (int) $row['id'],
            'numero' => $row['numero'],
            'path' => $row['original_path']
        ];
    }

    $response['success'] = true;
    $response['pending'] = (int) $pendingAfter;
    $response['orphan_docs'] = $orphanDocs;
    $response['orphan_pdfs'] = $orphanPdfs;

} catch (Exception $e) {
    logMsg("ERROR: " . $e->getMessage(), "error");
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
