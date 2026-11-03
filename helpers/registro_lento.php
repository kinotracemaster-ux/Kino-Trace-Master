<?php
/**
 * Registro de páginas lentas.
 *
 * config.php lo activa en cada request web: al terminar, si la página tardó más de
 * KT_LENTO_MS milisegundos (por defecto 1000), deja una línea JSON en
 * clients/logs/lento.log con la página, la acción, el cliente y cuánto tardó.
 * Así se ve qué hay que acelerar con datos reales, no a ojo.
 * El panel admin muestra el resumen (kt_lento_resumen).
 *
 * No guarda lo buscado ni ningún dato del cliente: solo la ruta y el nombre de la acción.
 */

const KT_LENTO_MAX_BYTES = 2 * 1024 * 1024; // al pasar de 2 MB se rota a lento.log.1

function kt_lento_archivo(): string
{
    return CLIENTS_DIR . '/logs/lento.log';
}

function kt_lento_umbral_ms(): int
{
    $ms = (int) (getenv('KT_LENTO_MS') ?: 1000);
    return $ms > 0 ? $ms : 1000;
}

/** Se llama una vez por request (config.php). */
function kt_lento_activar(): void
{
    if (PHP_SAPI === 'cli' || defined('KT_LENTO_ACTIVO')) {
        return;
    }
    define('KT_LENTO_ACTIVO', true);
    register_shutdown_function('kt_lento_anotar');
}

function kt_lento_anotar(): void
{
    $inicio = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $ms = (int) round((microtime(true) - $inicio) * 1000);
    if ($ms < kt_lento_umbral_ms()) {
        return;
    }
    $accion = $_REQUEST['action'] ?? '';
    $linea = [
        'fecha' => date('c'),
        'ms' => $ms,
        'pagina' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'accion' => is_string($accion) ? substr(preg_replace('/[^a-z0-9_]/i', '', $accion), 0, 40) : '',
        'cliente' => (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) ? (string) ($_SESSION['client_code'] ?? '') : '',
        'memoria_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ];
    $archivo = kt_lento_archivo();
    $dir = dirname($archivo);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return;
    }
    if (@filesize($archivo) > KT_LENTO_MAX_BYTES) {
        @rename($archivo, $archivo . '.1');
    }
    @file_put_contents($archivo, json_encode($linea, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Resumen de los últimos $dias días: por página+acción, cuántas veces fue lenta,
 * la mediana y el máximo. Ordenado de peor a mejor.
 *
 * @return array<int, array{pagina:string, accion:string, veces:int, mediana_ms:int, max_ms:int, ultima:string, clientes:string}>
 */
function kt_lento_resumen(int $dias = 7, int $max = 15): array
{
    $desde = time() - $dias * 86400;
    $grupos = [];
    foreach ([kt_lento_archivo() . '.1', kt_lento_archivo()] as $f) {
        if (!is_file($f)) {
            continue;
        }
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $r = json_decode($l, true);
            if (!is_array($r) || strtotime((string) ($r['fecha'] ?? '')) < $desde) {
                continue;
            }
            $k = ($r['pagina'] ?? '') . '|' . ($r['accion'] ?? '');
            $grupos[$k]['pagina'] = (string) ($r['pagina'] ?? '');
            $grupos[$k]['accion'] = (string) ($r['accion'] ?? '');
            $grupos[$k]['ms'][] = (int) ($r['ms'] ?? 0);
            $grupos[$k]['ultima'] = (string) $r['fecha'];
            if (!empty($r['cliente'])) {
                $grupos[$k]['clientes'][$r['cliente']] = true;
            }
        }
    }
    $out = [];
    foreach ($grupos as $g) {
        sort($g['ms']);
        $out[] = [
            'pagina' => $g['pagina'],
            'accion' => $g['accion'],
            'veces' => count($g['ms']),
            'mediana_ms' => $g['ms'][intdiv(count($g['ms']), 2)],
            'max_ms' => end($g['ms']),
            'ultima' => $g['ultima'],
            'clientes' => implode(', ', array_keys($g['clientes'] ?? [])),
        ];
    }
    usort($out, fn($a, $b) => ($b['veces'] * $b['mediana_ms']) <=> ($a['veces'] * $a['mediana_ms']));
    return array_slice($out, 0, $max);
}
