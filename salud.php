<?php
/**
 * Chequeo de salud para Railway (healthcheckPath). Responde 200 solo si la app puede
 * trabajar de verdad: el volumen existe y se puede escribir, y central.db abre y responde.
 * Si falla, Railway no da por buena la versión nueva (no se cae la anterior).
 * No pide sesión ni muestra datos: solo "ok" o qué parte falla. No crea nada si falta.
 * Los respaldos viejos NO tumban la salud (reiniciar no los arregla): eso es una alerta
 * en el panel admin (kt_resp_alertas).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$clients = getenv('KT_CLIENTS_DIR') ?: __DIR__ . '/clients';
$falla = [];

if (!is_dir($clients)) {
    $falla[] = 'volumen: no existe la carpeta clients';
} else {
    $tmp = @tempnam($clients, '.salud_');
    if ($tmp === false) {
        $falla[] = 'volumen: no se puede escribir en clients';
    } else {
        @unlink($tmp);
    }
    $central = $clients . '/central.db';
    if (!is_file($central)) {
        $falla[] = 'central.db: no existe';
    } else {
        try {
            $db = new PDO('sqlite:' . $central, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA busy_timeout = 2000');
            $db->query('SELECT COUNT(*) FROM control_clientes')->fetchColumn();
        } catch (Throwable $e) {
            $falla[] = 'central.db: no responde';
        }
    }
}

http_response_code($falla ? 503 : 200);
echo json_encode($falla ? ['ok' => false, 'fallas' => $falla] : ['ok' => true]);
