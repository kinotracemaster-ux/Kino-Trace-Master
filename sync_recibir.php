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
ini_set('display_errors', '0'); // un warning antes del JSON rompe la respuesta y muestra rutas

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

// Paquete más grande que post_max_size: PHP descarta el cuerpo entero y $_POST llega vacío
// (sin esto se respondía "contraseña incorrecta", que confunde).
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    kt_responder(413, ['ok' => false, 'error' => 'El paquete supera el tamaño que acepta el servidor ('
        . ini_get('post_max_size') . '). Use "Descargar paquete" y envíelo a Kino.']);
}

// --- Autenticación ---
// Fuerza bruta: 5 fallos en 15 minutos bloquean la sincronización de ESE código por 15 minutos.
// Se cuenta por cliente (no por IP, que se puede falsear) y sin dormir procesos de Apache.
const KT_MAX_FALLOS = 5;
const KT_VENTANA_FALLOS = 900;
$codigo = sanitize_code((string) ($_POST['codigo'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$st = $centralDb->prepare('SELECT password_hash FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
$st->execute([$codigo]);
$hash = $st->fetchColumn();
$intentosF = $hash ? CLIENTS_DIR . "/$codigo/_sync/intentos.json" : null;
$fallos = [];
if ($intentosF && is_file($intentosF)) {
    $fallos = array_values(array_filter((array) json_decode((string) file_get_contents($intentosF), true),
        fn($t) => is_int($t) && $t > time() - KT_VENTANA_FALLOS));
}
if (count($fallos) >= KT_MAX_FALLOS) {
    kt_responder(429, ['ok' => false, 'error' => 'Demasiados intentos fallidos. Espere 15 minutos o envíe el paquete a Kino.']);
}
if ($codigo === '' || !$hash || !password_verify($password, $hash)) {
    if ($intentosF) {
        @mkdir(dirname($intentosF), 0770, true);
        $fallos[] = time();
        file_put_contents($intentosF, json_encode($fallos), LOCK_EX);
    }
    kt_responder(401, ['ok' => false, 'error' => 'Código o contraseña incorrectos.']);
}
if ($intentosF && is_file($intentosF)) {
    @unlink($intentosF);
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

    // Guardar copia del paquete recibido (auditoría / reintento manual); se conservan los últimos
    $dirSync = CLIENTS_DIR . "/$codigo/_sync/recibidos";
    @mkdir($dirSync, 0770, true);
    $copia = "$dirSync/" . gmdate('Ymd_His') . '_' . $m['paquete_id'] . '.ktsync';
    @copy($f['tmp_name'], $copia);
    kt_podar(glob("$dirSync/*.ktsync") ?: [], KT_RETENER);

    $r = kt_aplicar_sync(CLIENTS_DIR, $paquete, null, 'cliente:' . $codigo);
    if ($r['modo'] === 'ya_aplicado') {
        @unlink($copia); // un reenvío no ocupa espacio
    }
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
