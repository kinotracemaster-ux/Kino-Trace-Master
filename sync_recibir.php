<?php
/**
 * sync_recibir.php — La NUBE recibe un paquete .ktsync enviado desde un PC en modo local.
 * Documentación: docs/modo_local/SINCRONIZACION.md
 *
 * POST multipart: codigo, password, paquete (archivo .ktsync)
 *
 * - Autentica con el código y la contraseña del cliente (los mismos de la web).
 * - Valida el paquete (estructura, hashes de cada archivo, integridad de la BD).
 * - Respalda la BD del cliente y aplica:
 *     REEMPLAZO si la nube no cambió desde que se sacó la copia local,
 *     FUSIÓN (solo altas) si la nube también cambió.
 * - Guarda el paquete y el resultado en clients/{codigo}/_sync/ para auditoría.
 *
 * Responde siempre JSON.
 */
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/sync_local.php';

header('Content-Type: application/json; charset=utf-8');

function kt_responder(int $http, array $datos): void
{
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (defined('KT_MODO_LOCAL')) {
    kt_responder(404, ['ok' => false, 'error' => 'No disponible en modo local.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    kt_responder(405, ['ok' => false, 'error' => 'Use POST.']);
}
RateLimiter::middleware();

// --- Autenticación ---
$codigo = sanitize_code((string) ($_POST['codigo'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$st = $centralDb->prepare('SELECT password_hash FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
$st->execute([$codigo]);
$hash = $st->fetchColumn();
if ($codigo === '' || !$hash || !password_verify($password, $hash)) {
    sleep(2); // frena intentos de adivinar la contraseña
    kt_responder(401, ['ok' => false, 'error' => 'Código o contraseña incorrectos.']);
}

// --- Archivo ---
$f = $_FILES['paquete'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    kt_responder(400, ['ok' => false, 'error' => 'No llegó el paquete (¿demasiado grande? código ' . (int) ($f['error'] ?? -1) . ').']);
}

set_time_limit(0);
$tmp = kt_tmp_dir('ktrecibe');
try {
    $paquete = kt_leer_paquete_sync($f['tmp_name'], $tmp);
    $m = $paquete['manifiesto'];
    if ($m['cliente'] !== $codigo) {
        throw new RuntimeException('El paquete es de otro cliente.');
    }

    // Guardar copia del paquete recibido (auditoría / reintento manual)
    $dirSync = CLIENTS_DIR . "/$codigo/_sync/recibidos";
    @mkdir($dirSync, 0770, true);
    @copy($f['tmp_name'], "$dirSync/" . gmdate('Ymd_His') . '_' . $m['paquete_id'] . '.ktsync');

    $r = kt_aplicar_sync(CLIENTS_DIR, $paquete, null, 'cliente:' . $codigo);
    $mensajes = [
        'reemplazo' => 'Listo: la nube quedó igual a la copia de su equipo.',
        'fusion' => 'Listo: se agregaron a la nube los documentos nuevos de su equipo.',
        'ya_aplicado' => 'La nube ya tenía estos datos; no hubo que hacer nada.',
    ];
    $http = 200;
    $respuesta = [
        'ok' => true,
        'resultado' => $r['modo'] === 'ya_aplicado' ? 'ya_aplicado' : 'aplicado',
        'modo' => $r['modo'],
        'mensaje' => $mensajes[$r['modo']] ?? 'Sincronización completada.',
        'informe' => $r['informe'],
        'respaldo' => $r['respaldo'],
    ];
} catch (Throwable $e) {
    error_log('sync_recibir: ' . $e->getMessage());
    $http = 422;
    $respuesta = ['ok' => false, 'error' => 'Paquete rechazado: ' . $e->getMessage()];
} finally {
    kt_borrar_dir($tmp);
}
kt_responder($http, $respuesta);
