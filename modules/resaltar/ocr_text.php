<?php
/**
 * OCR Text Extraction Endpoint for Scanned PDFs with Coordinates
 * 
 * El visor lo llama solo para páginas sin capa de texto (escaneadas).
 * Lógica compartida en helpers/ocr_highlight.php: cache por archivo, luego
 * pdftotext -bbox y, si la página no trae texto, Tesseract HOCR.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../helpers/session_init.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/cache_manager.php';
require_once __DIR__ . '/../../helpers/ocr_highlight.php';

header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$clientCode = $_SESSION['client_code'];
// LIBERAR LOCK DE SESIÓN: Permitir peticiones concurrentes (OCR paralelo)
session_write_close();

// PROTECCIÓN: Rate limiter para evitar abuso (100 req/min por IP)
require_once __DIR__ . '/../../helpers/rate_limiter.php';
RateLimiter::middleware();

try {
    $documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
    $filePath = isset($_GET['file']) ? $_GET['file'] : '';
    $termsParam = isset($_GET['terms']) ? $_GET['terms'] : '';
    $pageNum = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $forceOcr = !empty($_GET['force_ocr']);

    // Parse terms to search
    $terms = array_filter(array_map('trim', explode(',', $termsParam)));

    if (empty($terms)) {
        echo json_encode(['success' => true, 'matches' => [], 'text' => '', 'highlights' => []]);
        exit;
    }

    // Resolve PDF path
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
    $pdfPath = null;

    if ($documentId > 0) {
        $db = open_client_db($clientCode);
        $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($document) {
            $pdfPath = resolve_pdf_path($clientCode, $document);
        }
    } elseif (!empty($filePath)) {
        // Mismo saneamiento que viewer.php (evitar path traversal)
        $filePath = str_replace('\\', '/', $filePath);
        $filePath = preg_replace('#(^|/)\.\.(/|$)#', '', $filePath);
        $pdfPath = $uploadsDir . ltrim($filePath, '/');
    }

    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('Archivo PDF no encontrado');
    }

    // Cache por archivo → capa de texto del PDF → OCR solo si la página es escaneada
    $pageWords = ocr_hl_get_page_words($clientCode, $pdfPath, $pageNum, $forceOcr);
    if (!$pageWords['success']) {
        echo json_encode(['success' => false, 'error' => $pageWords['error'] ?? 'Error de OCR']);
        exit;
    }

    echo json_encode(ocr_hl_build_response($pageWords, $terms));

} catch (Exception $e) {
    error_log("OCR Error in ocr_text.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
