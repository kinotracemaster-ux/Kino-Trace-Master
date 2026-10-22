<?php
/**
 * Resolves the correct path for a document and redirects to the static file.
 * Used for "Original" / "Download" buttons to handle variable directory structures.
 */

require_once __DIR__ . '/../../helpers/session_init.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    die('Acceso denegado');
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;

if ($documentId <= 0) {
    die('ID de documento inválido');
}

// Get document info
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

// --- Centralized Path Resolution ---
$pdfPath = resolve_pdf_path($clientCode, $document);

if (!$pdfPath) {
    // En vez de un error: pantalla para buscarlo en el servidor o subirlo de nuevo
    header('Location: pdf_faltante.php?doc=' . $documentId . '&volver=' . rawurlencode('download.php?doc=' . $documentId));
    exit;
}

// Serve the file directly (works regardless of file location on disk)
$filename = basename($pdfPath);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdfPath));
header('Cache-Control: public, max-age=3600');
readfile($pdfPath);
exit;
