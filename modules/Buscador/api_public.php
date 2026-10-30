<?php
/**
 * API Pública para Buscador - KINO TRACE
 *
 * Endpoint ligero SIN autenticación para:
 *   - search_by_code: buscar documentos por código
 *   - suggest: autocompletado de códigos
 *
 * Uso: api_public.php?cliente=CODIGO&action=search_by_code&code=XXX
 *      api_public.php?cliente=CODIGO&action=suggest&term=XXX
 */

// Prevent HTML errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/subdomain.php';
require_once __DIR__ . '/../../autoload.php';

// SEGURIDAD: Rate limiting en endpoint público
if (class_exists('RateLimiter')) {
    RateLimiter::middleware();
}

// SEGURIDAD: Validar y sanitizar código de cliente
$clientInput = isset($_GET['cliente']) ? $_GET['cliente'] : '';
if (empty($clientInput)) {
    $clientInput = getClientFromSubdomain() ?? '';
}
$clientCode = validate_public_client($clientInput, true);

try {
    $db = open_client_db($clientCode);
} catch (Exception $e) {
    echo json_encode(['error' => 'Error de base de datos']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'search_by_code':
        $code = strtoupper(trim($_GET['code'] ?? ''));
        if (code_match_key($code) === '') {
            echo json_encode(['documents' => []]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT
                d.id,
                d.tipo,
                d.numero,
                d.fecha,
                d.proveedor,
                d.ruta_archivo,
                COALESCE(MAX(CASE WHEN UPPER(c.codigo) = ? THEN c.codigo END), MAX(c.codigo)) AS codigo_encontrado,
                MAX(CASE WHEN UPPER(c.codigo) = ? THEN 1 ELSE 0 END) AS exacta
            FROM documentos d
            JOIN codigos c ON d.id = c.documento_id
            WHERE " . code_match_sql('c.codigo') . " = ?
            GROUP BY d.id
            ORDER BY exacta DESC, d.fecha DESC
        ");
        // Igual que search_by_code(): "BL1258" encuentra "BL-1258"; los escritos exacto primero
        $stmt->execute([$code, $code, code_match_key($code)]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['documents' => $docs]);
        break;

    case 'suggest':
        $term = strtoupper(trim($_GET['term'] ?? ''));
        if (strlen($term) < 2) {
            echo json_encode([]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT DISTINCT codigo
            FROM codigos
            WHERE codigo LIKE ?
            ORDER BY codigo ASC
            LIMIT 10
        ");
        $stmt->execute([$term . '%']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        break;

    default:
        echo json_encode(['error' => 'Acción no válida']);
}
