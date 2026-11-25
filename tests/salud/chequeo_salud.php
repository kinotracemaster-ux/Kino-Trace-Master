<?php
// Chequeo: salud.php (healthcheck de Railway) y alertas de respaldo.
//   php tests/salud/chequeo_salud.php
require_once __DIR__ . '/../../helpers/respaldo.php';

$fallos = 0;
$ok = function (bool $c, string $m) use (&$fallos) { echo ($c ? 'OK    ' : 'FALLA ') . $m . "\n"; if (!$c) $fallos++; };
$raiz = realpath(__DIR__ . '/../..');
$tmp = sys_get_temp_dir() . '/kt_salud_' . getmypid();
mkdir($tmp . '/_respaldos', 0777, true);

// --- Alertas
$ahora = strtotime('2026-11-25T12:00:00Z');
$hace = fn(int $h) => gmdate('c', $ahora - $h * 3600);
$niveles = fn(array $a) => array_column($a, 'nivel');

$ok(count(kt_resp_alertas($tmp, $ahora)) >= 1 && kt_resp_alertas($tmp, $ahora)[0]['nivel'] === 'alto', 'sin estado: alerta alta "no hay respaldo"');

kt_resp_estado_guardar("$tmp/_respaldos", ['ultimo_respaldo' => ['fecha' => $hace(5), 'errores' => 0], 'ultima_prueba' => ['fecha' => $hace(24), 'ok' => true]]);
$ok(kt_resp_alertas($tmp, $ahora) === [], 'respaldo de hace 5 h y prueba de ayer: sin alertas');

kt_resp_estado_guardar("$tmp/_respaldos", ['ultimo_respaldo' => ['fecha' => $hace(60), 'errores' => 0], 'ultima_prueba' => ['fecha' => $hace(24), 'ok' => true]]);
$ok(in_array('alto', $niveles(kt_resp_alertas($tmp, $ahora)), true), 'último respaldo de hace 60 h: alerta alta');

kt_resp_estado_guardar("$tmp/_respaldos", ['ultimo_respaldo' => ['fecha' => $hace(5), 'errores' => 2], 'ultima_prueba' => ['fecha' => $hace(24), 'ok' => true]]);
$ok(in_array('alto', $niveles(kt_resp_alertas($tmp, $ahora)), true), 'respaldo con errores: alerta alta');

kt_resp_estado_guardar("$tmp/_respaldos", ['ultimo_respaldo' => ['fecha' => $hace(5), 'errores' => 0], 'ultima_prueba' => ['fecha' => $hace(24), 'ok' => false]]);
$ok(in_array('alto', $niveles(kt_resp_alertas($tmp, $ahora)), true), 'prueba de restauración fallida: alerta alta');

kt_resp_estado_guardar("$tmp/_respaldos", ['ultimo_respaldo' => ['fecha' => $hace(5), 'errores' => 0], 'ultima_prueba' => ['fecha' => $hace(24 * 20), 'ok' => true]]);
$ok($niveles(kt_resp_alertas($tmp, $ahora)) === ['medio'], 'prueba de hace 20 días: alerta media');

// --- salud.php por HTTP (carpeta clients temporal)
$llamar = function (string $clients) use ($raiz) {
    $puerto = 20000 + random_int(0, 900);
    $p = proc_open(['php', '-S', "127.0.0.1:$puerto", '-t', $raiz], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $raiz,
        array_merge(getenv(), ['KT_CLIENTS_DIR' => $clients]));
    $r = false;
    for ($i = 0; $i < 30 && $r === false; $i++) {
        usleep(100000);
        $r = @file_get_contents("http://127.0.0.1:$puerto/salud.php", false, stream_context_create(['http' => ['ignore_errors' => true]]));
    }
    $codigo = (int) (preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    proc_terminate($p);
    return [$codigo, $r];
};
[$c1] = $llamar($tmp);
$ok($c1 === 503, 'sin central.db: salud responde 503');
$db = new PDO('sqlite:' . $tmp . '/central.db');
$db->exec('CREATE TABLE control_clientes (codigo TEXT)');
unset($db);
[$c2, $cuerpo] = $llamar($tmp);
$ok($c2 === 200 && strpos((string) $cuerpo, '"ok":true') !== false, 'con volumen y central.db: salud responde 200');
[$c3] = $llamar($tmp . '/no_existe');
$ok($c3 === 503, 'sin carpeta clients: salud responde 503');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo OK\n";
exit($fallos ? 1 : 0);
