<?php
/**
 * Alta de clientes en un paso y funciones por cliente, probado por HTTP (sin PHPUnit):
 *   php tests/multicliente/chequeo_alta_funciones.php
 *
 * Copia la app a una carpeta temporal, levanta `php -S` y, con la clave maestra:
 *  - crea un cliente desde el panel en un solo envío y comprueba que entra, con su
 *    subdominio y sus funciones, y que el alta se verificó sola;
 *  - comprueba que el alta rechaza lo que antes rompía el aislamiento: código repetido,
 *    subdominio de otro cliente, carpeta vieja con datos, plantilla con datos de ejemplo;
 *  - apaga funciones y comprueba que el servidor las bloquea (página, API y buscador
 *    público), que el menú no las muestra y que lo demás sigue funcionando.
 * Sale con código 1 si algo falla. Nunca toca clients/ del repo.
 */
$raiz = dirname(__DIR__, 2);
$fallos = 0;
function ok(bool $c, string $msg): void { global $fallos; echo ($c ? 'OK    ' : 'FALLA ') . $msg . "\n"; if (!$c) $fallos++; }

$app = sys_get_temp_dir() . '/kt_alta_' . bin2hex(random_bytes(4));
exec('mkdir -p ' . escapeshellarg($app) . ' && cd ' . escapeshellarg($raiz)
    . ' && tar --exclude=./.git --exclude=./clients -cf - . | tar -xf - -C ' . escapeshellarg($app), $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "No se pudo copiar la app\n"); exit(1); }

// Cliente existente "alfa" con un documento (para probar choques de subdominio y lo que sigue funcionando)
$setup = <<<'PHP'
<?php
chdir($argv[1]);
require 'config.php';
require 'helpers/tenant.php';
create_client_structure('alfa', 'Cliente Alfa', password_hash('clave-alfa', PASSWORD_DEFAULT), 'Titulo Alfa');
$db = open_client_db('alfa');
$db->exec("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo) VALUES ('manifiesto', 'MAN-ALFA', '2026-01-01', 'x.pdf')");
$db->exec("INSERT INTO codigos (documento_id, codigo) VALUES (1, 'ALFA-001')");
// Restos de un cliente borrado a medias: carpeta con su base, sin fila en central.db
@mkdir(CLIENTS_DIR . '/viejo', 0777, true);
copy(CLIENTS_DIR . '/alfa/alfa.db', CLIENTS_DIR . '/viejo/viejo.db');
echo "ok";
PHP;
file_put_contents("$app/_setup.php", $setup);
$r = trim((string) shell_exec('php ' . escapeshellarg("$app/_setup.php") . ' ' . escapeshellarg($app) . ' 2>&1'));
ok($r === 'ok', 'preparar cliente alfa y una carpeta vieja' . ($r === 'ok' ? '' : ": $r"));
unlink("$app/_setup.php");

$puerto = 19000 + random_int(0, 999);
$srv = proc_open("ADMIN_SECRET=clave-maestra-de-prueba-123 php -S 127.0.0.1:$puerto -t " . escapeshellarg($app),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $app);
$base = "http://127.0.0.1:$puerto";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);

function pedir(string $url, $post = null, ?string $jar = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_USERAGENT => 'kt-chequeo', CURLOPT_TIMEOUT => 30]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $resp = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($resp, $hs)];
}
function central(string $app, string $sql) {
    return (new PDO("sqlite:$app/clients/central.db"))->query($sql)->fetchColumn();
}
$todas = ['buscador_publico', 'subir', 'trazabilidad', 'importar', 'ia'];

try {
    $adm = tempnam(sys_get_temp_dir(), 'ktjar');
    pedir("$base/Admin-gestor/login.php", null, $adm);
    pedir("$base/Admin-gestor/login.php", ['admin_secret' => 'clave-maestra-de-prueba-123'], $adm);
    ok(pedir("$base/Admin-gestor/panel.php", null, $adm)['code'] === 200, 'panel admin con la clave maestra');

    // --- Alta en un paso: sin buscador público
    $alta = ['action' => 'create', 'code' => 'gamma', 'name' => 'Cliente Gamma', 'password' => 'clave-gamma',
        'email' => 'gamma@ejemplo.com', 'titulo' => 'Gamma SAS', 'subdominio' => 'gamma-sas',
        'funciones' => array_values(array_diff($todas, ['buscador_publico']))];
    $r = pedir("$base/Admin-gestor/panel.php", $alta, $adm);
    ok(strpos($r['body'], 'creado y verificado') !== false, 'alta de "gamma" en un solo envío, verificada sola');
    preg_match('/verificado en (\d+) ms/', $r['body'], $m);
    echo '      DATO: alta completa en ' . ($m[1] ?? '?') . " ms\n";
    ok(central($app, "SELECT subdominio FROM control_clientes WHERE codigo='gamma'") === 'gamma-sas', 'el subdominio quedó guardado en el mismo paso');
    ok(is_file("$app/clients/gamma/gamma.db") && is_dir("$app/clients/gamma/uploads"), 'carpeta y base creadas');
    $gam = tempnam(sys_get_temp_dir(), 'ktjar');
    ok(pedir("$base/login.php", ['codigo' => 'gamma', 'password' => 'clave-gamma'], $gam)['code'] === 302, 'gamma inicia sesión enseguida');

    // --- Lo que el alta rechaza (y no deja nada a medias)
    $r = pedir("$base/Admin-gestor/panel.php", ['code' => 'gamma'] + $alta, $adm);
    ok(strpos($r['body'], 'Ya existe un cliente') !== false, 'código repetido: rechazado');
    $r = pedir("$base/Admin-gestor/panel.php", ['code' => 'delta', 'subdominio' => 'alfa', 'email' => ''] + $alta, $adm);
    ok(strpos($r['body'], 'ya lo usa el cliente') !== false && !is_dir("$app/clients/delta")
        && (int) central($app, "SELECT COUNT(*) FROM control_clientes WHERE codigo='delta'") === 0,
        'subdominio igual al código de otro cliente: rechazado, sin crear nada');
    $r = pedir("$base/Admin-gestor/panel.php", ['code' => 'viejo', 'subdominio' => '', 'email' => ''] + $alta, $adm);
    ok(strpos($r['body'], 'Ya existe la carpeta') !== false && (int) central($app, "SELECT COUNT(*) FROM control_clientes WHERE codigo='viejo'") === 0,
        'carpeta vieja con la base de otro: rechazado (antes el nuevo heredaba esos datos)');
    $r = pedir("$base/Admin-gestor/panel.php", ['code' => 'cliente', 'subdominio' => '', 'email' => ''] + $alta, $adm);
    ok(strpos($r['body'], 'plantilla') !== false || strpos($r['body'], 'Ya existe') !== false, 'código de una plantilla con datos de ejemplo: rechazado');
    $r = pedir("$base/Admin-gestor/panel.php", ['action' => 'update_subdomain', 'sub_code' => 'alfa', 'subdominio' => 'gamma-sas'], $adm);
    ok(strpos($r['body'], 'ya lo usa el cliente') !== false && central($app, "SELECT subdominio FROM control_clientes WHERE codigo='alfa'") !== 'gamma-sas',
        'cambiar el subdominio de alfa al de gamma: rechazado');

    // --- Funciones: buscador público apagado desde el alta
    $r = pedir("$base/modules/Buscador/index.php?cliente=gamma");
    ok($r['code'] === 403, 'buscador público de gamma (apagado): 403');
    $r = pedir("$base/modules/Buscador/api_public.php?cliente=gamma&action=suggest&term=AB");
    ok($r['code'] === 403 && strpos($r['body'], 'no está activa') !== false, 'API del buscador público de gamma: 403 en JSON');
    ok(pedir("$base/modules/Buscador/index.php?cliente=alfa")['code'] === 200, 'el buscador público de alfa sigue abierto');

    // --- Apagar "subir" e "importar" a gamma
    pedir("$base/Admin-gestor/panel.php", ['action' => 'update_funciones', 'fn_code' => 'gamma', 'funciones' => ['trazabilidad', 'ia']], $adm);
    ok(pedir("$base/modules/subir/", null, $gam)['code'] === 403, 'gamma sin "subir": la página de subir da 403');
    ok(pedir("$base/modules/lote/index.php", null, $gam)['code'] === 403, 'gamma sin "subir": la subida por ZIP da 403');
    ok(pedir("$base/modules/subir/process_extraction.php", ['x' => 1], $gam)['code'] === 403, 'gamma sin "subir": también donde la sesión se abre después de config.php');
    $r = pedir("$base/api.php?action=upload", ['tipo' => 'x'], $gam);
    ok($r['code'] === 403 && strpos($r['body'], 'no está activa') !== false, 'gamma sin "subir": la API de subir da 403');
    ok(pedir("$base/modules/excel_import/", null, $gam)['code'] === 403, 'gamma sin "importar": importar Excel da 403');
    ok(pedir("$base/api.php?action=search_by_code&code=X", null, $gam)['code'] === 200, 'gamma sigue buscando por código');
    ok(pedir("$base/modules/trazabilidad/validar.php", null, $gam)['code'] === 200, 'gamma sigue con trazabilidad (activa)');
    $menu = pedir("$base/index.php", null, $gam)['body'];
    ok(strpos($menu, 'modules/lote/') === false && strpos($menu, 'modules/excel_import/') === false && strpos($menu, 'modules/trazabilidad/validar.php') !== false,
        'el menú de gamma no muestra lo apagado y sí lo activo');

    // --- Los demás clientes y el admin no se ven afectados
    $alf = tempnam(sys_get_temp_dir(), 'ktjar');
    pedir("$base/login.php", ['codigo' => 'alfa', 'password' => 'clave-alfa'], $alf);
    ok(pedir("$base/modules/lote/index.php", null, $alf)['code'] === 200, 'alfa (todas activas) sí abre la subida por ZIP');
    ok(strpos(pedir("$base/index.php", null, $alf)['body'], 'modules/lote/') !== false, 'el menú de alfa sí muestra la subida por ZIP');

    // --- Verificar desde el panel
    $r = pedir("$base/Admin-gestor/panel.php", ['action' => 'verify_client', 'verify_code' => 'gamma'], $adm);
    ok(strpos($r['body'], 'funciona: ✓ Registrado') !== false && strpos($r['body'], '✗') === false, 'botón Verificar: gamma funciona (todos los chequeos en verde)');
    ok(strpos($r['body'], 'apagadas: Buscador público, Subir documentos, Importar') !== false, 'Verificar muestra las funciones apagadas');

    // --- Borrar limpia sus funciones
    pedir("$base/Admin-gestor/panel.php", ['action' => 'delete', 'delete_code' => 'gamma'], $adm);
    ok((int) central($app, "SELECT COUNT(*) FROM funciones_cliente WHERE codigo='gamma'") === 0 && !is_dir("$app/clients/gamma"),
        'eliminar gamma borra también sus funciones y su carpeta');
} finally {
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($app));
    foreach (array_filter([$adm ?? null, $gam ?? null, $alf ?? null]) as $j) @unlink($j);
}
echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
