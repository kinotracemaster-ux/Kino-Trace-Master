<?php
/**
 * OCR Text Extraction Endpoint - Public Version (No Session Required)
 * 
 * M7: Endpoint para viewer_publico.php que no requiere sesión de usuario.
 * Valida acceso por parámetro 'cliente' en lugar de sesión.
 * Reutiliza la misma lógica de ocr_text.php.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/cache_manager.php';
require_once __DIR__ . '/../../helpers/subdomain.php';

header('Content-Type: application/json');

// Validar cliente: primero por parámetro, luego por subdominio
$clientCode = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
if (empty($clientCode)) {
    $clientCode = getClientFromSubdomain() ?? '';
}
if (empty($clientCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cliente no especificado']);
    exit;
}

// Verificar que el cliente existe
$clientDir = CLIENTS_DIR . "/{$clientCode}";
if (!is_dir($clientDir)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Cliente no encontrado']);
    exit;
}

try {
    $documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
    $termsParam = isset($_GET['terms']) ? $_GET['terms'] : '';
    $pageNum = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    $terms = array_filter(array_map('trim', explode(',', $termsParam)));

    if (empty($terms)) {
        echo json_encode(['success' => true, 'matches' => [], 'text' => '', 'highlights' => []]);
        exit;
    }

    // Verificar cache
    $cacheKey = "ocr_v4_doc{$documentId}_p{$pageNum}";
    $cachedOcr = CacheManager::get($clientCode, $cacheKey);

    if ($cachedOcr && !empty($cachedOcr['words'])) {
        $matches = [];
        $highlights = [];

        foreach ($cachedOcr['words'] as $word) {
            foreach ($terms as $term) {
                if (mb_stripos($word['text'], $term, 0, 'UTF-8') !== false) {
                    $matches[] = ['term' => $term, 'word' => $word['text'], 'x' => $word['x'], 'y' => $word['y'], 'w' => $word['w'], 'h' => $word['h']];
                    $highlights[] = ['x' => $word['x'], 'y' => $word['y'], 'w' => $word['w'], 'h' => $word['h'], 'term' => $term];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'matches' => $matches,
            'match_count' => count($matches),
            'highlights' => $highlights,
            'image_width' => $cachedOcr['image_width'] ?? 0,
            'image_height' => $cachedOcr['image_height'] ?? 0,
            'text' => $cachedOcr['text'] ?? '',
            'terms_searched' => $terms,
            'source' => 'cache'
        ]);
        exit;
    }

    // Resolver ruta del PDF
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
    }

    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('Archivo PDF no encontrado');
    }

    // Ejecutar OCR
    if (function_exists('extract_with_ocr_coordinates')) {
        $ocrResult = extract_with_ocr_coordinates($pdfPath, $pageNum);

        if ($ocrResult['success'] && !empty($ocrResult['words'])) {
            CacheManager::set($clientCode, $cacheKey, [
                'words' => $ocrResult['words'],
                'text' => $ocrResult['text'] ?? '',
                'image_width' => $ocrResult['image_width'] ?? 0,
                'image_height' => $ocrResult['image_height'] ?? 0
            ], 604800);
        }
    } else {
        $text = extract_text_from_pdf($pdfPath);
        $ocrResult = ['success' => !empty($text), 'text' => $text, 'words' => []];
    }

    if (!$ocrResult['success'] || empty($ocrResult['words'])) {
        echo json_encode(['success' => true, 'matches' => [], 'highlights' => [], 'text' => $ocrResult['text'] ?? '']);
        exit;
    }

    // Buscar coincidencias
    $matches = [];
    $highlights = [];

    foreach ($ocrResult['words'] as $word) {
        foreach ($terms as $term) {
            if (mb_stripos($word['text'], $term, 0, 'UTF-8') !== false) {
                $matches[] = ['term' => $term, 'word' => $word['text'], 'x' => $word['x'], 'y' => $word['y'], 'w' => $word['w'], 'h' => $word['h']];
                $highlights[] = ['x' => $word['x'], 'y' => $word['y'], 'w' => $word['w'], 'h' => $word['h'], 'term' => $term];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'matches' => $matches,
        'match_count' => count($matches),
        'highlights' => $highlights,
        'image_width' => $ocrResult['image_width'] ?? 0,
        'image_height' => $ocrResult['image_height'] ?? 0,
        'text' => $ocrResult['text'] ?? '',
        'terms_searched' => $terms
    ]);

} catch (Exception $e) {
    error_log("Public OCR Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
