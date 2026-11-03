<?php
/**
 * Resolves the correct path for a document and serves it (only with the client's session).
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

// Servir el archivo (privado: con sesión; con rangos para que PDF.js no lo baje entero)
require_once __DIR__ . '/../../helpers/pdf_acceso.php';
session_write_close();
kt_pdf_enviar($pdfPath);
exit;
