<?php
/**
 * CHEQUEO ANTES DE PUBLICAR (corre solo en cada PR vía GitHub Actions, y a mano):
 *
 *   php tools/chequeo.php
 *
 * 1. Sintaxis de todos los .php del repo (php -l).
 * 2. Simula un deploy NUEVO en una carpeta temporal (nunca toca clients/):
 *    copia database_initial, migra central.db y la base de cada cliente.
 * 3. Revisa que cada base quede con las tablas/columnas/índices esperados.
 * 4. Vuelve a correr TODOS los pasos sobre bases ya migradas (deben ser
 *    idempotentes: así se comportan en producción si algo quedó a medio camino).
 * 5. Crea un cliente nuevo vacío y revisa que tenga el esquema completo.
 * 6. Si PHPUnit está instalado, corre los tests.
 *
 * Código de salida 0 = todo bien; 1 = no publicar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$errores = [];
$paso = function (string $t) { echo "\n== {$t}\n"; };
$mal = function (string $m) use (&$errores) { $errores[] = $m; echo "  !! {$m}\n"; };

// ---------------------------------------------------------------------------
$paso('1. Sintaxis PHP');
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if ($f->isFile() && substr($rel, -4) === '.php'
        && !preg_match('#^(vendor|clients|\.git|node_modules|backups)/#', $rel)) {
        $files[] = $rel;
    }
}
sort($files);
$php = escapeshellarg(PHP_BINARY);
foreach ($files as $rel) {
    exec($php . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $mal("Error de sintaxis en {$rel}: " . trim(implode(' ', $out)));
    }
    $out = [];
}
echo '  ' . count($files) . " archivos revisados\n";

// ---------------------------------------------------------------------------
$paso('2. Deploy nuevo simulado + migración de todos los clientes');
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kt_chequeo_' . getmypid() . '_' . bin2hex(random_bytes(3));
mkdir($tmp, 0777, true);
putenv('KT_CLIENTS_DIR=' . $tmp);
register_shutdown_function(function () use ($tmp) {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});

require_once $root . '/autoload.php'; // config.php (copia database_initial + migra central) y helpers base
if (realpath(CLIENTS_DIR) !== realpath($tmp)) {
    fwrite(STDERR, "ABORTADO: CLIENTS_DIR no apunta a la carpeta temporal.\n");
    exit(1);
}

$target = client_schema_version();
echo "  esquema de clientes esperado: v{$target}\n";
$centralV = (int) $centralDb->query('PRAGMA user_version')->fetchColumn();
echo "  central.db: v{$centralV}\n";
if ($centralV < 1) {
    $mal('central.db no quedó migrada');
}
foreach (['email', 'subdominio', 'password_plain', 'reset_token'] as $col) {
    $cols = $centralDb->query('PRAGMA table_info(control_clientes)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($col, $cols, true)) {
        $mal("central.db: falta la columna control_clientes.{$col}");
    }
}

$verificar = function (string $code) use ($mal, $target) {
    $db = new PDO('sqlite:' . client_db_path($code));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $v = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($v !== $target) {
        $mal("{$code}: quedó en v{$v} (esperado v{$target})");
    }
    $tablas = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['documentos', 'codigos', 'vinculos', 'configuracion_extraccion', 'tipos_documento', 'log_actividad'] as $t) {
        if (!in_array($t, $tablas, true)) {
            $mal("{$code}: falta la tabla {$t}");
        }
    }
    $cols = $db->query('PRAGMA table_info(documentos)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['texto_extraido', 'estado_extraccion', 'original_path'] as $c) {
        if (!in_array($c, $cols, true)) {
            $mal("{$code}: falta la columna documentos.{$c}");
        }
    }
    $idx = $db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('idx_codigos_match', $idx, true)) {
        $mal("{$code}: falta el índice idx_codigos_match");
    }
    // Las consultas por código que usa la app deben funcionar sobre la base migrada
    $db->query('SELECT COUNT(*) FROM codigos WHERE ' . code_match_sql('codigo') . ' = ' . $db->quote(code_match_key('BL-1258')))->fetchColumn();
};

$report = migrate_all_clients();
if (!$report) {
    $mal('no se encontró ningún cliente en database_initial');
}
foreach ($report as $code => $r) {
    if ($r['despues'] === null && $r['ok']) {
        echo "  -  {$code}: {$r['error']}\n";
        continue;
    }
    echo ($r['ok'] ? '  OK ' : '  !! ') . "{$code}: v" . var_export($r['antes'], true) . " -> v" . var_export($r['despues'], true) . ($r['error'] ? " ({$r['error']})" : '') . "\n";
    if (!$r['ok']) {
        $mal("{$code}: {$r['error']}");
        continue;
    }
    $verificar($code);
}

// ---------------------------------------------------------------------------
$paso('3. Idempotencia: repetir todos los pasos sobre bases ya migradas');
foreach ($report as $code => $r) {
    if (!$r['ok'] || $r['despues'] === null) {
        continue;
    }
    try {
        $db = new PDO('sqlite:' . client_db_path($code));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA user_version = 0');
        ensure_client_schema($db);
        $db = null;
        $verificar($code);
        echo "  OK {$code}\n";
    } catch (Throwable $e) {
        $mal("{$code}: un paso de migración no es repetible: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
$paso('4. Cliente nuevo (base vacía)');
try {
    open_client_db('chequeo_nuevo');
    $verificar('chequeo_nuevo');
    echo "  OK chequeo_nuevo\n";
} catch (Throwable $e) {
    $mal('cliente nuevo: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
$paso('5. Tests (PHPUnit)');
$phpunit = $root . '/vendor/bin/phpunit';
if (is_file($phpunit)) {
    passthru($php . ' ' . escapeshellarg($phpunit) . ' -c ' . escapeshellarg($root . '/phpunit.xml'), $code);
    if ($code !== 0) {
        $mal('PHPUnit falló');
    }
} else {
    echo "  (PHPUnit no instalado: se omite)\n";
}

// ---------------------------------------------------------------------------
echo "\n" . ($errores ? '❌ ' . count($errores) . " problema(s). NO publicar.\n" : "✅ Todo bien: se puede publicar.\n");
exit($errores ? 1 : 0);
