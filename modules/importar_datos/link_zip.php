<?php
/**
 * Enlazar PDFs desde ZIP - KINO TRACE
 * 
 * Endpoint independiente para subir ZIPs con PDFs y enlazarlos
 * a documentos ya existentes en la base de datos.
 * Se puede llamar múltiples veces para subir PDFs por lotes.
 */
ob_start();
header('Content-Type: application/json');
session_start();
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
set_time_limit(300);

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

    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió archivo ZIP válido.');
    }

    $zipFile = $_FILES['zip_file']['tmp_name'];
    $zipName = $_FILES['zip_file']['name'];
    $zipSize = round($_FILES['zip_file']['size'] / 1024 / 1024, 1);

    logMsg("📦 ZIP recibido: {$zipName} ({$zipSize} MB)");

    // Count existing docs before linking
    $totalDocs = $db->query("SELECT COUNT(*) FROM documentos")->fetchColumn();
    $pendingDocs = $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();

    logMsg("📊 Base de datos: {$totalDocs} documentos, {$pendingDocs} sin PDF enlazado");

    // Process ZIP and link
    $uploadDir = __DIR__ . "/../../clients/" . $clientCode . "/uploads/sql_import";
    processZipAndLink($db, $zipFile, $uploadDir, "sql_import/");

    // Count after linking
    $pendingAfter = $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();
    $linked = $pendingDocs - $pendingAfter;

    logMsg("───────────────────────────────", "info");
    logMsg("📊 Resultado de este lote:", "success");
    logMsg("   PDFs enlazados en este ZIP: {$linked}", "success");
    logMsg("   Documentos aún sin PDF: {$pendingAfter}", $pendingAfter > 0 ? "warning" : "success");

    if ($pendingAfter > 0) {
        logMsg("ℹ️ Puedes subir más ZIPs para enlazar los documentos restantes.", "info");
    } else {
        logMsg("🎉 ¡Todos los documentos tienen PDF enlazado!", "success");
    }

    $response['success'] = true;
    $response['pending'] = (int) $pendingAfter;

} catch (Exception $e) {
    logMsg("ERROR: " . $e->getMessage(), "error");
    $response['error'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit;
