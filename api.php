<?php
/**
 * API Unificada para KINO-TRACE
 *
 * Proporciona endpoints para:
 * - Subida de documentos con extracción de códigos
 * - Búsqueda inteligente voraz
 * - CRUD de documentos
 * - Integración con IA (Gemini)
 */

use Kino\Api\PdfController;
use Kino\Api\DocumentController;
use Kino\Api\SearchController;
use Kino\Api\AiController;
use Kino\Api\SystemController;

session_start();

// ✨ PREVENT HTML ERRORS IN JSON RESPONSE
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Custom Error Handler to return JSON on fatal errors/warnings
function apiErrorHandler($errno, $errstr, $errfile, $errline)
{
    if (!(error_reporting() & $errno)) {
        return false;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'PHP Error',
        'message' => $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
}
set_error_handler("apiErrorHandler");

function apiShutdownHandler()
{
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        // Prepare clean slate for error JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Critical System Error',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
}
register_shutdown_function('apiShutdownHandler');

// ✨ Autoload centralizado
require_once __DIR__ . '/autoload.php';

// ✨ Cargar helpers específicos bajo demanda
load_helpers(['search_engine', 'pdf_extractor', 'gemini_ai', 'cache_manager']);

// ✨ SEGURIDAD: Aplicar middlewares
RateLimiter::middleware();    // Limitar a 100 req/min por IP
CsrfProtection::middleware(); // Validar tokens en POST/DELETE

header('Content-Type: application/json; charset=utf-8');

/**
 * Respuesta JSON y salida.
 */
function json_exit($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    send_error_response(api_error('AUTH_002'));
}

$clientCode = $_SESSION['client_code'];

try {
    $db = open_client_db($clientCode);
} catch (PDOException $e) {
    Logger::exception($e, ['client' => $clientCode]);
    send_error_response(api_error('DB_001', null, ['db_error' => $e->getMessage()]));
} catch (Exception $e) {
    Logger::exception($e, ['client' => $clientCode]);
    send_error_response(api_error('SYS_001'));
}

$action = $_REQUEST['action'] ?? '';

// Dispatcher Logic
try {
    switch ($action) {
        // PDF Operations
        case 'extract_codes':
            (new PdfController($db, $clientCode))->extractCodes($_FILES, $_POST);
            break;
        case 'search_in_pdf':
            (new PdfController($db, $clientCode))->searchInPdf($_FILES, $_POST);
            break;

        // Document CRUD
        case 'upload':
            (new DocumentController($db, $clientCode))->upload($_POST, $_FILES);
            break;
        case 'update':
            (new DocumentController($db, $clientCode))->update($_POST, $_FILES);
            break;
        case 'delete':
            (new DocumentController($db, $clientCode))->delete($_REQUEST);
            break;
        case 'list':
            (new DocumentController($db, $clientCode))->list($_GET);
            break;
        case 'get':
            (new DocumentController($db, $clientCode))->get($_GET);
            break;

        // Search Operations
        case 'search':
            (new SearchController($db, $clientCode))->search($_REQUEST);
            break;
        case 'search_by_code':
            (new SearchController($db, $clientCode))->searchByCode($_REQUEST);
            break;
        case 'suggest':
            (new SearchController($db, $clientCode))->suggest($_GET);
            break;
        case 'stats':
            (new SearchController($db, $clientCode))->stats();
            break;
        case 'fulltext_search':
            (new SearchController($db, $clientCode))->fulltextSearch($_REQUEST);
            break;

        // AI Operations
        case 'ai_extract':
            (new AiController($db, $clientCode))->extract($_POST);
            break;
        case 'ai_chat':
            (new AiController($db, $clientCode))->chat($_POST);
            break;
        case 'smart_chat':
            (new AiController($db, $clientCode))->smartChat($_POST);
            break;
        case 'ai_status':
            (new AiController($db, $clientCode))->status();
            break;

        // System/Maintenance
        case 'reindex_documents':
            (new SystemController($db, $clientCode))->reindex($_REQUEST);
            break;
        case 'pdf_diagnostic':
            (new SystemController($db, $clientCode))->diagnostic($_REQUEST);
            break;
        case 'clear_cache':
            if (class_exists('CacheManager')) {
                CacheManager::clear($clientCode);
                json_exit(['success' => true, 'message' => 'Caché limpiado correctamente']);
            }
            break;

        default:
            json_exit(['error' => 'Acción no válida']);
    }
} catch (Throwable $e) {
    Logger::exception($e, ['action' => $action]);
    send_error_response(api_error('SYS_001', $e->getMessage()));
}
