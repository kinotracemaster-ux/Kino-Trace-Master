<?php
/**
 * Chequeo de respaldos (sin PHPUnit): php tests/respaldo/chequeo_respaldo.php
 * Arma clientes de prueba en una carpeta temporal, respalda, simula un desastre
 * (base borrada + PDFs borrados), restaura y mide. Nunca toca clients/ real.
 */
require_once __DIR__ . '/../../helpers/respaldo.php';
$raiz = dirname(__DIR__, 2);
$fallos = 0;
function ok(bool $c, string $msg): void { global $fallos; echo ($c ? 'OK    ' : 'FALLA ') . $msg . "\n"; if (!$c) $fallos++; }
function contar(string $db): array { return kt_resp_conteo(kt_abrir_sqlite($db)); }
function archivos(string $dir): int { $n = 0; if (is_dir($dir)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) $n += $f->isFile() ? 1 : 0; return $n; }

$cd = kt_tmp_dir('ktchk_clients');
putenv('KT_RESPALDO_DIR'); // usar el de por defecto: $cd/_respaldos
try {
    // --- Clientes de prueba: "kino" (base real de la plantilla + sus PDFs) y "beta" (pequeño)
    copy("$raiz/database_initial/central.db", "$cd/central.db");
    mkdir("$cd/kino/uploads", 0777, true);
    copy("$raiz/database_initial/kino/kino.db", "$cd/kino/kino.db");
    $pdfs = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/database_initial/kino/uploads", FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $dst = "$cd/kino/uploads/" . substr($f->getPathname(), strlen("$raiz/database_initial/kino/uploads/"));
        @mkdir(dirname($dst), 0777, true);
        copy($f->getPathname(), $dst);
        $pdfs++;
    }
    mkdir("$cd/beta/uploads/manifiesto", 0777, true);
    $b = kt_abrir_sqlite("$cd/beta/beta.db");
    $b->exec("CREATE TABLE documentos (id INTEGER PRIMARY KEY, tipo TEXT, numero TEXT, ruta_archivo TEXT)");
    $b->exec("CREATE TABLE codigos (id INTEGER PRIMARY KEY, documento_id INT, codigo TEXT)");
    $b->exec("INSERT INTO documentos VALUES (1,'manifiesto','B-1','manifiesto/b1.pdf')");
    $b->exec("INSERT INTO codigos VALUES (1,1,'BETA-001')");
    $b = null;
    file_put_contents("$cd/beta/uploads/manifiesto/b1.pdf", "%PDF-1.4 beta");
    mkdir("$cd/beta/cache"); file_put_contents("$cd/beta/cache/x.cache", 'no va al respaldo');

    $antes = contar("$cd/kino/kino.db");

    // --- 1. Respaldo completo
    $r = kt_resp_todo($cd, 'diario');
    ok($r['errores'] === 0, 'respaldo completo sin errores (' . $r['ms'] . ' ms)');
    ok(isset($r['clientes']['kino'], $r['clientes']['beta']), 'respalda todos los clientes con base');
    ok(count(glob("$cd/_respaldos/central/*.db.gz")) === 1, 'central.db respaldada');
    ok($r['clientes']['kino']['copiados'] === $pdfs, "espejo de kino con sus $pdfs archivos");
    ok(!is_file("$cd/_respaldos/clientes/beta/archivos/cache/x.cache"), 'la caché no va al respaldo');
    ok(fileinode("$cd/_respaldos/clientes/beta/archivos/uploads/manifiesto/b1.pdf") === fileinode("$cd/beta/uploads/manifiesto/b1.pdf"),
        'en el mismo disco el espejo usa enlaces duros (0 bytes extra)');
    ok(!is_file("$cd/_respaldos/clientes/kino/archivos/kino.db"), 'la base no va al espejo (va comprimida y consistente)');

    // --- 2. Segundo respaldo: incremental
    $r2 = kt_resp_cliente($cd, 'kino', 'diario');
    ok($r2['copiados'] === 0, 'segundo respaldo no vuelve a copiar PDFs sin cambios');

    // --- 3. Desastre en kino: base dañada a mano + 10 PDFs borrados
    $db = kt_abrir_sqlite("$cd/kino/kino.db");
    $db->exec('DELETE FROM codigos'); $db->exec('DELETE FROM documentos'); $db = null;
    $borrados = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$cd/kino/uploads", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $borrados < 10) { unlink($f->getPathname()); $borrados++; }
    }
    $betaAntes = md5_file("$cd/beta/beta.db");

    // --- 4. Restaurar solo kino desde su primer respaldo
    $lista = kt_resp_listar($cd, 'kino');
    $res = kt_resp_restaurar($cd, 'kino', end($lista)['nombre']);
    $despues = contar("$cd/kino/kino.db");
    ok($despues === $antes, "restaurar devuelve documentos y códigos ({$despues['documentos']} / {$despues['codigos']})");
    ok($res['archivos_devueltos'] === $borrados, "restaurar devuelve los $borrados PDFs borrados");
    ok(archivos("$cd/kino/uploads") === $pdfs, 'kino vuelve a tener todos sus PDFs');
    ok($res['respaldo_previo'] !== null, 'antes de restaurar se guarda el estado actual (por si fue un error)');
    ok(md5_file("$cd/beta/beta.db") === $betaAntes, 'restaurar kino no toca a beta');
    ok($res['ms'] < 300000, "restaurar kino tardó {$res['ms']} ms (meta: menos de 5 minutos)");
    echo "      DATO: restaurar un cliente ({$despues['documentos']} docs, {$despues['codigos']} códigos, $pdfs archivos): {$res['ms']} ms\n";

    // --- 5. Respaldo dañado: no toca nada
    $malo = "$cd/_respaldos/clientes/beta/bd/20200101_000000_malo.db.gz";
    file_put_contents($malo, gzencode('esto no es una base sqlite'));
    $beta = md5_file("$cd/beta/beta.db");
    try { kt_resp_restaurar($cd, 'beta', basename($malo)); ok(false, 'respaldo dañado se rechaza'); }
    catch (Throwable $e) { ok(md5_file("$cd/beta/beta.db") === $beta, 'respaldo dañado se rechaza y la base queda igual'); }
    unlink($malo);

    // --- 6. Nombres peligrosos
    foreach (['../kino/bd/x.db.gz', 'x.db', '20200101_000000_a.db.gz/../../central.db'] as $n) {
        try { kt_resp_restaurar($cd, 'beta', $n); ok(false, "nombre rechazado: $n"); }
        catch (InvalidArgumentException $e) { ok(true, "nombre rechazado: $n"); }
    }

    // --- 7. Prueba de restauración
    // La prueba usa el respaldo más nuevo de cada cliente. Tras restaurar, el más nuevo
    // era "antes_restaurar" (la base vaciada del paso 3) cuando caía en otro segundo, y
    // el chequeo fallaba a veces. Un respaldo normal ahora deja claro cuál se prueba.
    kt_resp_cliente($cd, 'kino', 'diario');
    $p = kt_resp_probar($cd);
    ok($p['ok'], 'prueba de restauración OK para todos los clientes');
    ok(($p['clientes']['kino']['documentos'] ?? -1) === $antes['documentos'], 'la prueba cuenta los documentos del respaldo');
    ok(($p['clientes']['beta']['pdfs_sin_copia'] ?? -1) === 0, 'la prueba encuentra en el espejo cada PDF que la base menciona');
    unlink("$cd/_respaldos/clientes/beta/archivos/uploads/manifiesto/b1.pdf");
    $p2 = kt_resp_probar($cd);
    ok(($p2['clientes']['beta']['pdfs_sin_copia'] ?? 0) === 1, 'la prueba detecta un PDF sin copia');

    // --- 8. Automático: no repite si es reciente; sí si pasó un día
    $a = kt_resp_auto($cd);
    ok($a['hecho'] === [], 'auto no repite un respaldo reciente');
    $a = kt_resp_auto($cd, time() + 86400 * 8);
    ok($a['hecho'] === ['respaldo', 'prueba'], 'auto respalda y prueba cuando toca');

    // --- 9. Retención
    $dir = kt_tmp_dir('ktpoda');
    $ahora = time();
    for ($d = 0; $d < 90; $d++) touch("$dir/" . gmdate('Ymd_His', $ahora - $d * 86400) . '_diario.db.gz');
    kt_resp_podar($dir, $ahora);
    $quedan = count(glob("$dir/*.db.gz"));
    ok($quedan >= 15 + 5 && $quedan <= 15 + 9, "retención: de 90 bases diarias quedan $quedan (14 días + 1 por semana hasta 8)");
    kt_borrar_dir($dir);

    // --- 10. Firma S3 (ejemplo oficial de AWS, sin red)
    $h = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    $f = kt_s3_firmar('GET', 'https://examplebucket.s3.amazonaws.com/test.txt',
        ['Host' => 'examplebucket.s3.amazonaws.com', 'Range' => 'bytes=0-9', 'x-amz-content-sha256' => $h, 'x-amz-date' => '20130524T000000Z'],
        $h, 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', '20130524T000000Z');
    ok(str_ends_with($f, 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'), 'firma S3 igual al ejemplo oficial de AWS');
} finally {
    kt_borrar_dir($cd);
}
echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
