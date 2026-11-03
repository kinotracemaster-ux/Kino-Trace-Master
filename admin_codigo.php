<?php
/**
 * Verifica en el servidor el código del menú "Administrador" del sidebar.
 *
 * Antes el código se comparaba en JavaScript (includes/sidebar.php), así que cualquier
 * cliente con sesión podía verlo en el código fuente de la página: y era la misma clave
 * que abre el panel admin. Ahora se compara aquí contra ADMIN_SECRET, con límite de
 * intentos (helpers/admin_secret.php).
 *
 * POST codigo → {"ok": bool, "mensaje": string}
 */
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/admin_secret.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión expirada']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido']);
    exit;
}

$resultado = kt_admin_verificar((string) ($_POST['codigo'] ?? ''));
echo json_encode([
    'ok' => $resultado === 'ok',
    'mensaje' => $resultado === 'ok' ? '' : kt_admin_mensaje($resultado),
]);
