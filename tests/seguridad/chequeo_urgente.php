<?php
/**
 * Chequeo de los arreglos de seguridad urgentes (sin PHPUnit): php tests/seguridad/chequeo_urgente.php
 * Sale con código 1 si algo falla.
 */
$raiz = dirname(__DIR__, 2);
$fallos = 0;
function ok(bool $c, string $msg): void { global $fallos; echo ($c ? "OK    " : "FALLA ") . $msg . "\n"; if (!$c) $fallos++; }

// 1. Scripts de mantenimiento: solo línea de comandos
foreach (['create_admin_db.php', 'import_backup.php', 'init_volume.php', 'railway_init.php', 'railway_fix.php', 'migrate.php'] as $f) {
    $s = file_get_contents("$raiz/$f");
    ok(strpos(substr($s, 0, 400), "PHP_SAPI !== 'cli'") !== false, "$f solo se ejecuta por línea de comandos");
}

// 2. Ninguna clave admin escrita en el código
$conClave = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('/\.(php|js)$/', $p) || preg_match('#/(vendor|\.git|tests)/#', $p)) continue;
    if (preg_match("/getenv\('ADMIN_SECRET'\)\s*\?:|code !== '\d+'/", file_get_contents($p))) $conClave[] = $p;
}
ok($conClave === [], 'sin clave admin por defecto en el código' . ($conClave ? ': ' . implode(', ', $conClave) : ''));

// 3. .htaccess: sin listado de carpetas, csv bloqueado, carpetas internas fuera de la web
$h = file_get_contents("$raiz/.htaccess");
ok(strpos($h, 'Options -Indexes') !== false, '.htaccess apaga el listado de carpetas');
ok((bool) preg_match('/FilesMatch "[^"]*\|csv\|/', $h), '.htaccess bloquea .csv');
ok(strpos($h, 'database_initial|backups') !== false, '.htaccess oculta database_initial/ y backups/');

// 4. Clave admin: sin variable no hay acceso; con variable, límite de intentos
define('CLIENTS_DIR', sys_get_temp_dir() . '/kt_chequeo_' . getmypid());
require "$raiz/helpers/admin_secret.php";
putenv('ADMIN_SECRET');
ok(kt_admin_verificar('3312', '1.1.1.1') === 'no_configurado', 'sin ADMIN_SECRET el acceso admin está apagado');
putenv('ADMIN_SECRET=corta');
ok(kt_admin_verificar('corta', '1.1.1.1') === 'no_configurado', 'ADMIN_SECRET corta (<12) no sirve');
putenv('ADMIN_SECRET=una-clave-larga-de-prueba');
ok(kt_admin_verificar('una-clave-larga-de-prueba', '1.1.1.1') === 'ok', 'clave correcta entra');
for ($i = 0; $i < 5; $i++) kt_admin_verificar('mala', '2.2.2.2');
ok(kt_admin_verificar('una-clave-larga-de-prueba', '2.2.2.2') === 'bloqueado', 'tras 5 fallos la IP queda bloqueada');
ok(kt_admin_verificar('una-clave-larga-de-prueba', '3.3.3.3') === 'ok', 'otra IP no se bloquea');

// 5. Datos de clientes fuera del repo
$tracked = shell_exec('cd ' . escapeshellarg($raiz) . ' && git ls-files clients backups "if0_*" "backup_*.zip" 2>/dev/null');
ok(trim((string) $tracked) === '', 'git no guarda clients/, backups/ ni volcados de clientes');

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
