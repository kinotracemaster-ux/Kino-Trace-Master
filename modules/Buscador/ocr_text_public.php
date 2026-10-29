<?php
/**
 * OCR Text Extraction Endpoint - Public Version (No Session Required)
 * 
 * M7: Endpoint para viewer_publico.php que no requiere sesión de usuario.
 * Valida acceso por parámetro 'cliente' en lugar de sesión.
 * Reutiliza la misma lógica de ocr_text.php (helpers/ocr_highlight.php).
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/cache_manager.php';
require_once __DIR__ . '/../../helpers/ocr_highlight.php';
require_once __DIR__ . '/../../helpers/subdomain.php';
require_once __DIR__ . '/../../autoload.php';

header('Content-Type: application/json');

// PROTECCIÓN: Rate limiter para endpoint público
require_once __DIR__ . '/../../helpers/rate_limiter.php';
RateLimiter::middleware();

// SEGURIDAD: Validar y sanitizar código de cliente
$clientInput = isset($_GET['cliente']) ? $_GET['cliente'] : '';
if (empty($clientInput)) {
    $clientInput = getClientFromSubdomain() ?? '';
}
$clientCode = validate_public_client($clientInput, true);

try {
    $documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
    $termsParam = isset($_GET['terms']) ? $_GET['terms'] : '';
    $pageNum = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    $terms = array_filter(array_map('trim', explode(',', $termsParam)));

    if (empty($terms)) {
        echo json_encode(['success' => true, 'matches' => [], 'text' => '', 'highlights' => []]);
        exit;
    }

    // Resolver ruta del PDF
    $pdfPath = null;

    if ($documentId > 0) {
        $db = open_client_db($clientCode);
        $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($document) {
            $pdfPath = resolve_pdf_path($clientCode, $document);
        }
    }

    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('Archivo PDF no encontrado');
    }

    // Cache por archivo → capa de texto del PDF → OCR solo si la página es escaneada
    $pageWords = ocr_hl_get_page_words($clientCode, $pdfPath, $pageNum);
    if (!$pageWords['success']) {
        echo json_encode(['success' => false, 'error' => $pageWords['error'] ?? 'Error de OCR']);
        exit;
    }

    echo json_encode(ocr_hl_build_response($pageWords, $terms));

} catch (Exception $e) {
    error_log("Public OCR Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
