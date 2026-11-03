<?php
/**
 * Mide la velocidad de las consultas frecuentes sobre la base de un cliente,
 * opcionalmente multiplicada para ver cómo se comporta cuando el cliente crezca.
 *
 *   php tools/medir_bd.php clients/kino/kino.db        # tal cual
 *   php tools/medir_bd.php clients/kino/kino.db 20     # 20 veces más grande
 *
 * Trabaja sobre una COPIA en una carpeta temporal: nunca toca la base original.
 * Muestra la mediana (p50) y el p95 en milisegundos de cada operación.
 * Úsalo antes y después de un cambio de base de datos para el "antes → después" del PR.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$origen = $argv[1] ?? '';
$veces = max(1, (int) ($argv[2] ?? 1));
if (!is_file($origen)) {
    fwrite(STDERR, "Uso: php tools/medir_bd.php ruta/a/cliente.db [veces]\n");
    exit(1);
}

// central.db de prueba en una carpeta temporal (config.php la crea al cargar)
$tmp = sys_get_temp_dir() . '/kt_medir_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('KT_CLIENTS_DIR=' . $tmp);
require_once dirname(__DIR__) . '/helpers/search_engine.php';
register_shutdown_function(function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

$copia = "$tmp/medir.db";
copy($origen, $copia);
$db = new PDO('sqlite:' . $copia);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_client_schema($db);

// Multiplicar: cada copia con sus propios números y códigos (sufijo -R1, R2...)
if ($veces > 1) {
    $cols = array_values(array_diff($db->query('PRAGMA table_info(documentos)')->fetchAll(PDO::FETCH_COLUMN, 1), ['id']));
    $maxId = (int) $db->query('SELECT MAX(id) FROM documentos')->fetchColumn();
    $db->beginTransaction();
    for ($k = 1; $k < $veces; $k++) {
        $off = $k * ($maxId + 1000);
        $sel = implode(',', array_map(fn($c) => $c === 'numero' ? "numero || '-R$k'"
            : ($c === 'fecha' ? "date(COALESCE(fecha, '2025-01-01'), '-$k days')" : $c), $cols));
        $db->exec('INSERT INTO documentos (id,' . implode(',', $cols) . ") SELECT id + $off, $sel FROM documentos WHERE id <= $maxId");
        $db->exec("INSERT INTO codigos (documento_id, codigo) SELECT documento_id + $off, codigo || 'R$k' FROM codigos WHERE documento_id <= $maxId");
    }
    $db->commit();
    $db->exec('PRAGMA optimize');
}
$db->exec('PRAGMA journal_mode=WAL');

$docs = (int) $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
$cods = (int) $db->query('SELECT COUNT(*) FROM codigos')->fetchColumn();
printf("%s ×%d: %s documentos, %s códigos, %.1f MB\n", basename($origen), $veces,
    number_format($docs), number_format($cods), filesize($copia) / 1048576);

$codigos = $db->query('SELECT codigo FROM codigos WHERE length(codigo) >= 3 GROUP BY codigo ORDER BY RANDOM() LIMIT 400')->fetchAll(PDO::FETCH_COLUMN);
if (!$codigos) {
    fwrite(STDERR, "La base no tiene códigos para medir.\n");
    exit(1);
}
$c = fn(int $i) => $codigos[$i % count($codigos)];

function kt_medir(string $nombre, int $n, callable $f): void
{
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f($i);
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);
    printf("  %-30s p50 %8.2f ms   p95 %8.2f ms\n", $nombre, $t[intdiv($n, 2)], $t[(int) ceil($n * 0.95) - 1]);
}

kt_medir('buscar 1 código', 25, fn($i) => search_by_code($db, $c($i)));
kt_medir('búsqueda voraz (200 códigos)', 10, fn($i) => greedy_search($db, array_slice($codigos, $i % 200, 200)));
kt_medir('sugerencias (3 letras)', 25, fn($i) => suggest_codes($db, substr($c($i), 0, 3)));
kt_medir('portada (estadísticas)', 10, fn() => get_search_stats($db));
kt_medir('listado de documentos (pág. 1)', 10, fn() => kt_listar_documentos($db, '', 50, 0));
kt_medir('búsqueda en contenido', 8, function ($i) use ($db) {
    $q = '%' . ['factura', 'zzqx', 'contenedor', 'peso'][$i % 4] . '%';
    $st = $db->prepare('SELECT id FROM documentos d WHERE LOWER(d.datos_extraidos) LIKE ? OR LOWER(d.numero) LIKE ? ORDER BY d.fecha DESC, d.id DESC LIMIT 100');
    $st->execute([$q, $q]);
    $st->fetchAll();
});
