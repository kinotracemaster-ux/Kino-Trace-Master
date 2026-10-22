<?php
/**
 * local/arranque.php — Reglas del MODO LOCAL.
 *
 * Lo carga config.php SOLO cuando existe el marcador local/MODO_LOCAL, que la
 * herramienta de empaquetado crea dentro del paquete del cliente. En la nube
 * (Railway) ese marcador no existe y este archivo no se ejecuta.
 *
 * Aquí se aplican, en cada petición:
 *   1. Acceso solo desde este equipo (IP 127.0.0.1 y Host local).
 *   2. Panel de administración y herramientas deshabilitados.
 *   3. Licencia válida (firma, equipo, vencimiento, reloj) o se pide activar.
 */

if (!defined('BASE_DIR')) {
    http_response_code(404); // abierto directo por URL (p. ej. en la nube): no hacer nada
    exit;
}

define('KT_MODO_LOCAL', true);
require_once __DIR__ . '/licencia.php';

if (PHP_SAPI !== 'cli') {
    // 1. Solo este equipo. El Host también se valida para frenar "DNS rebinding"
    //    (una web maliciosa que apunta su dominio a 127.0.0.1).
    $ktIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $ktHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (!in_array($ktIp, ['127.0.0.1', '::1'], true) || !in_array($ktHost, ['127.0.0.1', 'localhost', '[::1]'], true)) {
        http_response_code(403);
        exit('Acceso permitido solo desde este equipo.');
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    // 2. Rutas que no existen en modo local
    $ktScript = str_replace('\\', '/', (string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
    $ktRaiz = str_replace('\\', '/', BASE_DIR);
    $ktRel = strtolower(ltrim(substr($ktScript, strlen($ktRaiz)), '/'));
    foreach (['admin-gestor/', 'local/herramientas/', 'sync_recibir.php', 'migrate.php', 'railway_', 'init_volume.php', 'import_backup.php', 'create_admin_db.php'] as $ktNo) {
        if (strpos($ktRel, $ktNo) === 0) {
            http_response_code(404);
            exit('No disponible en modo local.');
        }
    }

    // 3. Licencia
    $ktLic = kt_lic_estado();
    if ($ktLic['valida']) {
        $ktSt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ? AND activo = 1');
        $ktSt->execute([$ktLic['datos']['cliente']]);
        if (!(int) $ktSt->fetchColumn()) {
            $ktLic['valida'] = false;
            $ktLic['motivo'] = 'La licencia es de un cliente que no está en este paquete.';
        }
    }
    define('KT_LICENCIA', $ktLic);

    if (!$ktLic['valida'] && $ktRel !== 'local/activar.php') {
        $ktEsApi = strpos($ktRel, 'api.php') === 0
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($ktEsApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['success' => false, 'error' => 'Licencia: ' . $ktLic['motivo']]));
        }
        header('Location: /local/activar.php');
        exit;
    }
    unset($ktIp, $ktHost, $ktScript, $ktRaiz, $ktRel, $ktNo, $ktLic, $ktSt, $ktEsApi);
}
