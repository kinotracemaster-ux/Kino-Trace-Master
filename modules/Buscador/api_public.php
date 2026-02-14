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

$clientCode = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
if (empty($clientCode)) {
    $clientCode = getClientFromSubdomain() ?? '';
}
if (empty($clientCode)) {
    echo json_encode(['error' => 'Cliente no especificado']);
    exit;
}

// Verify client exists
$clientDir = CLIENTS_DIR . "/{$clientCode}";
if (!is_dir($clientDir)) {
    echo json_encode(['error' => 'Cliente no encontrado']);
    exit;
}

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
        if ($code === '') {
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
                MAX(c.codigo) AS codigo_encontrado
            FROM documentos d
            JOIN codigos c ON d.id = c.documento_id
            WHERE UPPER(c.codigo) = UPPER(?)
            GROUP BY d.id
            ORDER BY d.fecha DESC
        ");
        $stmt->execute([$code]);
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
