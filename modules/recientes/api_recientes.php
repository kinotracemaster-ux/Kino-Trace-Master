<?php
/**
 * API Recientes - AJAX Handler
 * Devuelve JSON con documentos paginados
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

header('Content-Type: application/json');

if (!isset($_SESSION['client_code'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;

// Máximo límite por seguridad
if ($limit > 200)
    $limit = 200;

try {
    $stmt = $db->prepare("
        SELECT d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.fecha_creacion, d.ruta_archivo,
               (SELECT COUNT(*) FROM codigos WHERE documento_id = d.id) as code_count
        FROM documentos d
        ORDER BY d.fecha_creacion DESC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear fechas para JS
    foreach ($docs as &$doc) {
        $doc['fecha_creacion_fmt'] = date('d/m/Y H:i', strtotime($doc['fecha_creacion']));
    }

    echo json_encode($docs);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
