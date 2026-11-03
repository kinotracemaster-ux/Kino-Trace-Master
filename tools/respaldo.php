<?php
/**
 * Respaldos por línea de comandos (desde el navegador no existe).
 *
 *   php tools/respaldo.php auto                 lo que corre cada hora el contenedor: respalda si
 *                                               el último tiene más de 20 h y prueba si la última
 *                                               prueba tiene más de 7 días
 *   php tools/respaldo.php ahora                respaldar todo ya
 *   php tools/respaldo.php probar               prueba de restauración (no toca datos reales)
 *   php tools/respaldo.php listar <cliente>     respaldos disponibles de un cliente
 *   php tools/respaldo.php restaurar <cliente> <respaldo.db.gz>
 *   php tools/respaldo.php desde-nube           baja de la nube lo que falte (tras perder el volumen)
 *
 * Guía: docs/RESPALDOS.md
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../helpers/respaldo.php';

$clientsDir = getenv('KT_CLIENTS_DIR') ?: dirname(__DIR__) . '/clients';
$accion = $argv[1] ?? '';
$json = fn($x) => print(json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

try {
    switch ($accion) {
        case 'auto':
            $r = kt_resp_auto($clientsDir);
            echo gmdate('c') . ' respaldo auto: ' . ($r['hecho'] ? implode(', ', $r['hecho']) : ($r['nota'] ?? 'nada pendiente')) . "\n";
            break;
        case 'ahora':
            $json(kt_resp_todo($clientsDir, 'manual'));
            break;
        case 'probar':
            $r = kt_resp_probar($clientsDir);
            $json($r);
            exit($r['ok'] ? 0 : 1);
        case 'listar':
            $json(kt_resp_listar($clientsDir, (string) ($argv[2] ?? '')));
            break;
        case 'restaurar':
            $json(kt_resp_restaurar($clientsDir, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')));
            break;
        case 'desde-nube':
            $json(kt_s3_bajar_todo(kt_resp_dir($clientsDir)));
            break;
        default:
            fwrite(STDERR, "Uso: php tools/respaldo.php auto|ahora|probar|listar <cliente>|restaurar <cliente> <respaldo>|desde-nube\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
