<?php
/**
 * Aislamiento entre clientes, probado por HTTP de verdad (sin PHPUnit):
 *   php tests/seguridad/chequeo_aislamiento.php
 *
 * Copia la app a una carpeta temporal, crea dos clientes ("alfa" y "beta") con documentos,
 * códigos y PDFs propios, levanta `php -S` y comprueba que:
 *  - alfa no ve por ninguna vía (API, descarga, visor, buscador público) los datos de beta;
 *  - los enlaces firmados a PDFs no se pueden cambiar para abrir el archivo de otro, ni usar vencidos;
 *  - sin sesión no se abre nada privado y el panel admin solo entra con la clave maestra.
 * Sale con código 1 si algo falla. Nunca toca clients/ del repo.
 */
$raiz = dirname(__DIR__, 2);
$fallos = 0;
function ok(bool $c, string $msg): void { global $fallos; echo ($c ? 'OK    ' : 'FALLA ') . $msg . "\n"; if (!$c) $fallos++; }

// --- 1. Copia de la app en una carpeta temporal
$app = sys_get_temp_dir() . '/kt_aisla_' . bin2hex(random_bytes(4));
exec('mkdir -p ' . escapeshellarg($app) . ' && cd ' . escapeshellarg($raiz)
    . ' && tar --exclude=./.git --exclude=./clients -cf - . | tar -xf - -C ' . escapeshellarg($app), $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "No se pudo copiar la app\n"); exit(1); }

// --- 2. Datos: dos clientes con un documento cada uno (ambos con id 1: las bases son separadas)
$setup = <<<'PHP'
<?php
chdir($argv[1]);
require 'config.php';
require 'helpers/tenant.php';
function pdf(string $marca): string {
    return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj "
        . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n% $marca\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}
foreach (['alfa' => 'ALFA', 'beta' => 'BETA'] as $cod => $m) {
    create_client_structure($cod, "Cliente $m", password_hash("clave-$cod", PASSWORD_DEFAULT), "Titulo $m");
    $db = open_client_db($cod);
    @mkdir(CLIENTS_DIR . "/$cod/uploads/manifiesto", 0777, true);
    file_put_contents(CLIENTS_DIR . "/$cod/uploads/manifiesto/doc_$cod.pdf", pdf("SECRETO-$m"));
    $db->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo, datos_extraidos) VALUES ('manifiesto', ?, '2026-01-01', ?, ?)")
       ->execute(["MAN-$m", "manifiesto/doc_$cod.pdf", "texto $m-001 contenido $m"]);
    $id = $db->lastInsertId();
    $db->prepare('INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)')->execute([$id, "$m-001"]);
}
// Cliente "admin" con contraseña de cliente: no debe abrir el panel
// Clientes "admin" y "kino" con contraseña de cliente: ninguno debe abrir el panel
foreach (['admin', 'kino'] as $c) {
    if (!$centralDb->query("SELECT COUNT(*) FROM control_clientes WHERE codigo = '$c'")->fetchColumn()) {
        create_client_structure($c, $c, password_hash("clave-$c", PASSWORD_DEFAULT), $c);
    }
    $centralDb->prepare('UPDATE control_clientes SET password_hash = ?, activo = 1 WHERE codigo = ?')->execute([password_hash("clave-$c", PASSWORD_DEFAULT), $c]);
}
echo "ok";
PHP;
file_put_contents("$app/_setup.php", $setup);
$r = shell_exec('php ' . escapeshellarg("$app/_setup.php") . ' ' . escapeshellarg($app) . ' 2>&1');
ok(trim((string) $r) === 'ok', 'preparar clientes alfa y beta' . (trim((string) $r) === 'ok' ? '' : ": $r"));
unlink("$app/_setup.php");

// --- 3. Servidor
$puerto = 18000 + random_int(0, 999);
$env = 'ADMIN_SECRET=clave-maestra-de-prueba-123';
$srv = proc_open("$env php -S 127.0.0.1:$puerto -t " . escapeshellarg($app), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $app);
$base = "http://127.0.0.1:$puerto";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) usleep(100000);

function pedir(string $url, array $post = null, ?string $jar = null, bool $seguir = false): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $seguir,
        CURLOPT_USERAGENT => 'kt-chequeo', CURLOPT_TIMEOUT => 20]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $resp = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'head' => substr($resp, 0, $hs), 'body' => substr($resp, $hs)];
}
function enlace_pdf(string $html): ?string {
    return preg_match("/const pdfUrl = '([^']+)'/", $html, $m) ? stripslashes($m[1]) : null;
}

try {
    $alfa = tempnam(sys_get_temp_dir(), 'ktjar');
    $r = pedir("$base/login.php", ['codigo' => 'alfa', 'password' => 'clave-alfa'], $alfa);
    ok($r['code'] === 302, 'alfa inicia sesión');

    // --- API
    $r = pedir("$base/api.php?action=search_by_code&code=BETA-001", null, $alfa);
    ok(strpos($r['body'], 'MAN-BETA') === false && strpos($r['body'], 'doc_beta') === false, 'API: alfa no encuentra el código de beta');
    $r = pedir("$base/api.php?action=search_by_code&code=ALFA-001", null, $alfa);
    ok(strpos($r['body'], 'MAN-ALFA') !== false, 'API: alfa sí encuentra su propio código');
    $r = pedir("$base/api.php?action=list", null, $alfa);
    ok(strpos($r['body'], 'MAN-ALFA') !== false && strpos($r['body'], 'MAN-BETA') === false, 'API: la lista de alfa solo tiene sus documentos');
    $r = pedir("$base/api.php?action=fulltext_search&query=BETA-001", null, $alfa);
    ok(strpos($r['body'], 'MAN-BETA') === false, 'API: búsqueda en contenido no muestra a beta');
    $r = pedir("$base/api.php?action=list");
    ok($r['code'] === 401, 'API sin sesión: 401');

    // --- Descarga y visor interno
    $r = pedir("$base/modules/resaltar/download.php?doc=1", null, $alfa);
    ok(strpos($r['body'], 'SECRETO-ALFA') !== false && strpos($r['body'], 'SECRETO-BETA') === false, 'descarga doc 1 con sesión de alfa da el PDF de alfa');
    ok(stripos($r['head'], 'Cache-Control: private') !== false, 'descarga con caché privada (no compartida por proxies)');
    $r = pedir("$base/modules/resaltar/download.php?doc=1");
    ok(strpos($r['body'], 'SECRETO') === false, 'descarga sin sesión no entrega el PDF');
    $r = pedir("$base/modules/resaltar/viewer.php?doc=1&codes=ALFA-001", null, $alfa);
    $url = enlace_pdf($r['body']);
    ok($url !== null && strpos($url, 'pdf.php?') !== false && strpos($r['body'], '/uploads/manifiesto/doc_alfa.pdf\'') === false,
        'visor interno usa enlace firmado, no la ruta estática');
    $pdfUrl = $base . '/' . preg_replace('#^(\.\./)+#', '', (string) $url);
    $r = pedir($pdfUrl);
    ok($r['code'] === 200 && strpos($r['body'], 'SECRETO-ALFA') !== false, 'el enlace firmado abre el PDF de alfa');
    $r = pedir($pdfUrl, null, null);
    $ch = curl_init($pdfUrl); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Range: bytes=0-7']]);
    $parcial = curl_exec($ch); $pc = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    ok($pc === 206 && $parcial === '%PDF-1.4', 'pdf.php responde por rangos (PDF.js no baja el archivo entero)');

    // --- Enlaces firmados manipulados
    parse_str((string) parse_url($pdfUrl, PHP_URL_QUERY), $q);
    $otro = $q; $otro['r'] = 'beta/uploads/manifiesto/doc_beta.pdf';
    $r = pedir("$base/pdf.php?" . http_build_query($otro));
    ok($r['code'] === 403 && strpos($r['body'], 'SECRETO-BETA') === false, 'cambiar la ruta del enlace para abrir el PDF de beta: 403');
    $otro = $q; $otro['r'] = 'alfa/uploads/../../beta/uploads/manifiesto/doc_beta.pdf';
    ok(pedir("$base/pdf.php?" . http_build_query($otro))['code'] === 403, 'ruta con ../ en el enlace: 403');
    $otro = $q; $otro['e'] = (string) ((int) ($q['e'] ?? 0) + 86400);
    ok(pedir("$base/pdf.php?" . http_build_query($otro))['code'] === 403, 'alargar el vencimiento invalida la firma: 403');
    ok(pedir("$base/pdf.php?r=" . rawurlencode('beta/uploads/manifiesto/doc_beta.pdf'))['code'] === 403, 'pdf.php sin firma: 403');

    // --- Buscador público (sin sesión, por cliente)
    $r = pedir("$base/modules/Buscador/api_public.php?cliente=alfa&action=search_by_code&code=BETA-001");
    ok(strpos($r['body'], 'MAN-BETA') === false, 'buscador público de alfa no encuentra el código de beta');
    $r = pedir("$base/modules/Buscador/viewer_publico.php?cliente=alfa&doc=1&term=ALFA-001");
    $url = enlace_pdf($r['body']);
    ok($url !== null && strpos($url, 'pdf.php?') !== false && strpos($url, 'alfa%2Fuploads') !== false, 'visor público usa enlace firmado del propio cliente');

    // --- Panel admin
    $r = pedir("$base/Admin-gestor/panel.php", null, $alfa);
    ok($r['code'] === 302, 'panel admin con sesión de cliente: redirige al login');
    $adm = tempnam(sys_get_temp_dir(), 'ktjar');
    pedir("$base/login.php", ['codigo' => 'admin', 'password' => 'clave-admin'], $adm);
    ok(pedir("$base/Admin-gestor/panel.php", null, $adm)['code'] === 302, 'panel admin con la contraseña de cliente de "admin": no entra');
    $kin = tempnam(sys_get_temp_dir(), 'ktjar');
    pedir("$base/login.php", ['codigo' => 'kino', 'password' => 'clave-kino'], $kin);
    ok(pedir("$base/Admin-gestor/panel.php", null, $kin)['code'] === 302, 'panel admin con la contraseña del cliente "kino": no entra');
    $mae = tempnam(sys_get_temp_dir(), 'ktjar');
    pedir("$base/Admin-gestor/login.php", null, $mae);
    pedir("$base/Admin-gestor/login.php", ['admin_secret' => 'clave-maestra-de-prueba-123'], $mae);
    ok(pedir("$base/Admin-gestor/panel.php", null, $mae)['code'] === 200, 'panel admin con la clave maestra: entra');
    ok(pedir("$base/Admin-gestor/respaldos.php", null, $alfa)['code'] === 302, 'respaldos con sesión de cliente: no entra');
    $r = pedir("$base/index.php", null, $alfa, true);
    ok(strpos($r['body'], 'Admin-gestor/panel.php') === false, 'el menú de un cliente no muestra el gestor de clientes');

    // --- Reglas de Apache (php -S no las aplica: se revisa que estén)
    $h = file_get_contents("$raiz/.htaccess");
    ok(strpos($h, '^/clients/(?![a-z0-9_]+/logo') !== false, '.htaccess: de clients/ solo se sirven logos (PDFs por enlace firmado)');
} finally {
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($app));
    foreach (array_filter([$alfa ?? null, $adm ?? null, $kin ?? null, $mae ?? null]) as $j) @unlink($j);
}
echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
