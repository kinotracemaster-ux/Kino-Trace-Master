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
    if (!isset($_SESSION['client_code']) || $_SESSION['client_code'] !== 'admin') {
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

    $response['success'] = true;
    $response['pending'] = (int) $pendingAfter;

} catch (Exception $e) {
    logMsg("ERROR: " . $e->getMessage(), "error");
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
