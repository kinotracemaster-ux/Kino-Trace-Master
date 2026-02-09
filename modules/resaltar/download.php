<?php
/**
 * Resolves the correct path for a document and redirects to the static file.
 * Used for "Original" / "Download" buttons to handle variable directory structures.
 */

session_start();
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

$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$rutaArchivo = $document['ruta_archivo'];

// --- Centralized Path Resolution ---
$pdfPath = resolve_pdf_path($clientCode, $document);

if (!$pdfPath) {
    $folders = get_available_folders($clientCode);
    $foldersStr = implode(', ', $folders);
    die("Archivo PDF no encontrado en el servidor.<br>Rutas revisadas automáticamente.<br>Carpetas disponibles: $foldersStr");
}

// Calculate relative path for URL
// Note: We need the relative URL from the webroot.
// Assuming this script is in /modules/resaltar/
// And uploads are in /clients/CODE/uploads/
// We need to return ../../clients/CODE/uploads/RELATIVE_PATH

// $uploadsDir is absolute path e.g. C:\...\clients\code\uploads\
// $pdfPath is absolute path e.g. C:\...\clients\code\uploads\manifiestos\file.pdf

// Get the part after uploads/
$relativePath = substr($pdfPath, strlen($uploadsDir));
// Ensure no leading slash issues
$relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

// Redirect to the static file
$redirectUrl = "../../clients/{$clientCode}/uploads/{$relativePath}";

header("Location: $redirectUrl");
exit;
