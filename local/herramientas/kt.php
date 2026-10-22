<?php
/**
 * local/herramientas/kt.php — Herramienta del ADMINISTRADOR para el modo local.
 * Se usa en el equipo de Kino (no en el del cliente). Guía: docs/modo_local/DESPLIEGUE_PC.md
 *
 *   php kt.php llaves                         Crea el par de llaves (una sola vez)
 *   php kt.php exportar --clients D --cliente C [--salida F.ktdata]
 *   php kt.php exportar --respaldo B.zip --cliente C --nombre "Empresa"   (desde un Backup de la app)
 *   php kt.php paquete --datos F.ktdata [--salida DIR] [--nube URL]       Arma el paquete Windows
 *   php kt.php licencia --cliente C --equipo KT-XXXX-XXXX-XXXX-XXXX [--dias 30 | --vence AAAA-MM-DD]
 *   php kt.php verificar --licencia "KTL1...."
 *   php kt.php aplicar --clients D --paquete F.ktsync [--si]               Aplica un .ktsync sin la web
 *   php kt.php clave --app DIR --cliente C                                Cambia la contraseña local
 *   php kt.php equipo                                                     Código de ESTE equipo
 *
 * Opciones comunes: --llaves DIR (por defecto ~/.kinotrace), --cache DIR (descargas).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../helpers/sync_local.php';
require_once __DIR__ . '/../licencia.php';

const KT_REPO = __DIR__ . '/../..';

// ---------------------------------------------------------------------------
// Utilidades de consola
// ---------------------------------------------------------------------------

function kt_args(array $argv): array
{
    $out = ['_' => []];
    for ($i = 1; $i < count($argv); $i++) {
        if (strpos($argv[$i], '--') === 0) {
            $k = substr($argv[$i], 2);
            if (isset($argv[$i + 1]) && strpos($argv[$i + 1], '--') !== 0) {
                $out[$k] = $argv[++$i];
            } else {
                $out[$k] = true;
            }
        } else {
            $out['_'][] = $argv[$i];
        }
    }
    return $out;
}

function kt_salir(string $msg, int $codigo = 1): void
{
    fwrite(STDERR, "ERROR: $msg\n");
    exit($codigo);
}

function kt_info(string $msg): void
{
    echo $msg, "\n";
}

function kt_preguntar(string $texto): string
{
    echo $texto;
    return trim((string) fgets(STDIN));
}

function kt_dir_llaves(array $a): string
{
    if (!empty($a['llaves']) && is_string($a['llaves'])) {
        return rtrim($a['llaves'], '/\\');
    }
    $home = getenv('USERPROFILE') ?: (getenv('HOME') ?: '.');
    return $home . DIRECTORY_SEPARATOR . '.kinotrace';
}

function kt_copiar_dir(string $origen, string $destino, ?callable $filtro = null): int
{
    $n = 0;
    @mkdir($destino, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($origen) + 1));
        if ($filtro && !$filtro($rel, $f)) {
            continue;
        }
        $dst = $destino . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if ($f->isDir()) {
            @mkdir($dst, 0775, true);
        } else {
            @mkdir(dirname($dst), 0775, true);
            copy($f->getPathname(), $dst);
            $n++;
        }
    }
    return $n;
}

/** Descarga con verificación opcional de SHA-256. */
function kt_descargar(string $url, string $destino, ?string $sha256 = null): void
{
    kt_info("  descargando $url");
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: KinoTrace-kt\r\n", 'timeout' => 600, 'follow_location' => 1]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) {
        kt_salir("No se pudo descargar $url (¿hay internet? ¿proxy?). Puede bajarlo a mano y pasarlo con --php-zip/--poppler-zip/--cacert.");
    }
    $out = fopen($destino, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($sha256 !== null && !hash_equals(strtolower($sha256), hash_file('sha256', $destino))) {
        @unlink($destino);
        kt_salir("El archivo descargado de $url no coincide con su SHA-256 publicado. No se usa.");
    }
}

function kt_json_url(string $url): array
{
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: KinoTrace-kt\r\nAccept: application/json\r\n", 'timeout' => 60]]);
    $txt = @file_get_contents($url, false, $ctx);
    $j = $txt ? json_decode($txt, true) : null;
    if (!is_array($j)) {
        kt_salir("No se pudo leer $url");
    }
    return $j;
}

function kt_extraer_zip(string $zip, string $destino): void
{
    $z = new ZipArchive();
    if ($z->open($zip) !== true) {
        kt_salir("Zip ilegible: $zip");
    }
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = rtrim(str_replace('\\', '/', $z->getNameIndex($i)), '/');
        if ($n !== '' && !kt_ruta_segura($n)) {
            kt_salir("Zip con ruta peligrosa ($n): $zip");
        }
    }
    @mkdir($destino, 0775, true);
    $z->extractTo($destino);
    $z->close();
}

function kt_buscar_archivo(string $dir, string $nombre): ?string
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strcasecmp($f->getFilename(), $nombre) === 0) {
            return $f->getPathname();
        }
    }
    return null;
}

/** Escribe un archivo de texto con saltos de línea de Windows (los .bat lo necesitan). */
function kt_escribir_crlf(string $ruta, string $texto): void
{
    file_put_contents($ruta, str_replace("\n", "\r\n", str_replace("\r\n", "\n", $texto)));
}

// ---------------------------------------------------------------------------
// Comandos
// ---------------------------------------------------------------------------

function cmd_llaves(array $a): void
{
    $dir = kt_dir_llaves($a);
    $priv = "$dir/llave_privada.txt";
    if (is_file($priv) && empty($a['forzar'])) {
        kt_salir("Ya existen llaves en $dir. Si las regenera, TODAS las licencias emitidas dejan de valer. Use --forzar si está seguro.");
    }
    @mkdir($dir, 0700, true);
    $par = sodium_crypto_sign_keypair();
    file_put_contents($priv, base64_encode(sodium_crypto_sign_secretkey($par)));
    @chmod($priv, 0600);
    file_put_contents("$dir/clave_publica.txt", base64_encode(sodium_crypto_sign_publickey($par)));
    kt_info("Llaves creadas en $dir");
    kt_info("  llave_privada.txt  → SECRETA. No la suba a GitHub, Railway ni la envíe por chat. Haga copia en un USB.");
    kt_info("  clave_publica.txt  → va dentro de cada paquete (se copia sola al armarlo).");
}

function kt_llave_privada(array $a): string
{
    $f = kt_dir_llaves($a) . '/llave_privada.txt';
    if (!is_file($f)) {
        kt_salir("No hay llave privada en $f. Ejecute primero: php kt.php llaves");
    }
    $k = base64_decode(trim(file_get_contents($f)), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        kt_salir("Llave privada dañada: $f");
    }
    return $k;
}

function kt_llave_publica(array $a): string
{
    $f = kt_dir_llaves($a) . '/clave_publica.txt';
    if (!is_file($f)) {
        kt_salir("No hay llave pública en $f. Ejecute primero: php kt.php llaves");
    }
    return trim(file_get_contents($f));
}

function cmd_licencia(array $a): void
{
    $cliente = (string) ($a['cliente'] ?? '');
    $equipo = strtoupper((string) ($a['equipo'] ?? ''));
    if (!kt_codigo_valido($cliente)) {
        kt_salir('Falta --cliente (código del cliente, ej. losmonta).');
    }
    if ($equipo !== '*' && !preg_match('/^KT(-[0-9A-F]{4}){4}$/', $equipo)) {
        kt_salir('Falta --equipo con el código que muestra el PC del cliente (KT-XXXX-XXXX-XXXX-XXXX).');
    }
    if (!empty($a['vence'])) {
        $vence = (string) $a['vence'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence)) {
            kt_salir('--vence debe ser AAAA-MM-DD');
        }
    } else {
        $dias = max(1, min(366, (int) ($a['dias'] ?? 30)));
        $vence = date('Y-m-d', strtotime("+$dias days"));
    }
    $datos = [
        'v' => 1,
        'id' => bin2hex(random_bytes(8)),
        'cliente' => $cliente,
        'equipo' => $equipo,
        'emitida' => date('Y-m-d'),
        'vence' => $vence,
    ];
    $lic = kt_lic_firmar($datos, kt_llave_privada($a));
    // Autocomprobación con la llave pública
    $pk = base64_decode(kt_llave_publica($a), true);
    if (!kt_lic_decodificar($lic, $pk)['ok']) {
        kt_salir('La licencia generada no verifica con la llave pública: revise que ambas llaves sean del mismo par.');
    }
    kt_info("Licencia para $cliente · equipo $equipo · vence $vence (id {$datos['id']}):\n");
    echo $lic, "\n\n";
    kt_info('Envíe ese texto al cliente; lo pega en la pantalla "Activar" del programa.');
    if (!empty($a['salida']) && is_string($a['salida'])) {
        file_put_contents($a['salida'], $lic);
        kt_info("Guardada también en {$a['salida']}");
    }
}

function cmd_verificar(array $a): void
{
    $txt = (string) ($a['licencia'] ?? '');
    if ($txt !== '' && is_file($txt)) {
        $txt = file_get_contents($txt);
    }
    $pk = base64_decode(kt_llave_publica($a), true);
    $r = kt_lic_decodificar($txt, $pk);
    if (!$r['ok']) {
        kt_salir($r['error']);
    }
    kt_info(json_encode($r['datos'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function cmd_equipo(array $a): void
{
    $c = kt_equipo_codigo();
    kt_info($c !== '' ? $c : 'No se pudo leer el identificador de este equipo.');
}

function cmd_exportar(array $a): void
{
    $cliente = (string) ($a['cliente'] ?? '');
    if (!kt_codigo_valido($cliente)) {
        kt_salir('Falta --cliente');
    }
    $salida = is_string($a['salida'] ?? null) ? $a['salida'] : $cliente . '_' . date('Ymd_His') . '.ktdata';

    if (!empty($a['respaldo'])) {
        // Desde el ZIP del botón "Backup" de la app: {codigo}.db + uploads/
        $tmp = kt_tmp_dir('ktresp');
        try {
            $z = new ZipArchive();
            if ($z->open((string) $a['respaldo']) !== true) {
                kt_salir('No se pudo abrir el respaldo.');
            }
            $db = $z->locateName("$cliente.db") !== false ? "$cliente.db" : ($z->locateName('data.db') !== false ? 'data.db' : null);
            if ($db === null) {
                kt_salir("El respaldo no trae la base de datos ($cliente.db). Los respaldos hechos antes del arreglo de Backup solo traen PDFs.");
            }
            $dirC = "$tmp/$cliente";
            @mkdir("$dirC/uploads", 0775, true);
            kt_zip_extraer($z, $db, "$dirC/$cliente.db");
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = $z->getNameIndex($i);
                if (strpos($n, 'uploads/') === 0 && substr($n, -1) !== '/' && kt_ruta_segura($n)) {
                    @mkdir(dirname("$dirC/$n"), 0775, true);
                    kt_zip_extraer($z, $n, "$dirC/$n");
                }
            }
            $z->close();
            // central.db mínima (el respaldo no la trae): pedir contraseña
            $nombre = is_string($a['nombre'] ?? null) ? $a['nombre'] : $cliente;
            $clave = is_string($a['clave'] ?? null) ? $a['clave'] : kt_preguntar("Contraseña para entrar en el PC (mín. 8 caracteres): ");
            if (strlen($clave) < 8) {
                kt_salir('La contraseña debe tener al menos 8 caracteres.');
            }
            $c = kt_abrir_sqlite("$tmp/central.db");
            $c->exec("CREATE TABLE control_clientes (id INTEGER PRIMARY KEY AUTOINCREMENT, codigo TEXT UNIQUE, nombre TEXT NOT NULL,
                password_hash TEXT NOT NULL, titulo TEXT, color_primario TEXT, color_secundario TEXT, activo INTEGER DEFAULT 1,
                fecha_creacion TEXT DEFAULT (datetime('now')), email TEXT, reset_token TEXT, reset_token_expiry TEXT, subdominio TEXT)");
            $c->prepare('INSERT INTO control_clientes (codigo, nombre, password_hash, titulo) VALUES (?, ?, ?, ?)')
                ->execute([$cliente, $nombre, password_hash($clave, PASSWORD_DEFAULT), $nombre]);
            $c = null;
            $m = kt_exportar_datos($tmp, $cliente, $salida, 'respaldo ' . basename((string) $a['respaldo']));
        } finally {
            kt_borrar_dir($tmp);
        }
    } else {
        $clients = (string) ($a['clients'] ?? '');
        if (!is_dir($clients)) {
            kt_salir('Falta --clients (carpeta con central.db y la carpeta del cliente) o --respaldo.');
        }
        $m = kt_exportar_datos(rtrim($clients, '/\\'), $cliente, $salida, (string) ($a['origen'] ?? 'carpeta ' . basename(realpath($clients))));
    }
    kt_info("Paquete de datos: $salida");
    kt_info('  documentos: ' . $m['conteos']['documentos'] . ' · códigos: ' . $m['conteos']['codigos'] . ' · archivos: ' . $m['archivos']);
    kt_info('  ⚠ Contiene datos del cliente: trátelo como confidencial.');
}

/** Archivos de la app que viajan al paquete (lista blanca + exclusiones). */
function kt_filtro_app(string $rel, SplFileInfo $f): bool
{
    $top = explode('/', $rel)[0];
    $dirs = ['assets', 'helpers', 'includes', 'modules', 'pwa', 'src', 'vendor', 'local'];
    $raiz = ['api.php', 'autoload.php', 'config.php', 'index.php', 'index_tabs.php', 'login.php', 'logout.php',
        'forgot_password.php', 'reset_password.php'];
    if (strpos($rel, '/') === false && !$f->isDir()) {
        return in_array($rel, $raiz, true);
    }
    if (!in_array($top, $dirs, true)) {
        return false;
    }
    if (preg_match('#(^|/)\.#', $rel)) {
        return false; // .git, .reloj, etc.
    }
    if (preg_match('#^local/(herramientas|construir)(/|$)#', $rel)
        || in_array($rel, ['local/licencia.txt', 'local/MODO_LOCAL', 'local/clave_publica.txt'], true)) {
        return false;
    }
    if (!$f->isDir() && preg_match('/\.(md|sql|csv|zip|db|db-wal|db-shm|log|ktdata|ktsync)$/i', $rel)) {
        return false;
    }
    return !preg_match('#(^|/)debug_panic\.txt$#', $rel);
}

function cmd_paquete(array $a): void
{
    $datos = (string) ($a['datos'] ?? '');
    if (!is_file($datos)) {
        kt_salir('Falta --datos con el archivo .ktdata del cliente.');
    }
    $z = new ZipArchive();
    if ($z->open($datos) !== true || !($m = json_decode((string) $z->getFromName('kt_manifest.json'), true))
        || ($m['tipo'] ?? '') !== 'ktdata') {
        kt_salir('El archivo no es un .ktdata válido.');
    }
    $cliente = $m['cliente'];
    $pub = kt_llave_publica($a);
    $salida = rtrim(is_string($a['salida'] ?? null) ? $a['salida'] : getcwd(), '/\\');
    $nombre = 'KinoTrace_' . $cliente;
    $dest = "$salida/$nombre";
    if (file_exists($dest)) {
        if (empty($a['forzar'])) {
            kt_salir("Ya existe $dest (use --forzar para rehacerlo).");
        }
        kt_borrar_dir($dest);
    }
    $nube = is_string($a['nube'] ?? null) ? $a['nube'] : 'https://kino-trace.com';
    if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/?$#i', $nube)) {
        kt_salir('--nube debe ser una dirección https:// (ej. https://kino-trace.com)');
    }

    kt_info("1/5 Copiando la aplicación…");
    $n = kt_copiar_dir(realpath(KT_REPO), "$dest/app", 'kt_filtro_app');
    file_put_contents("$dest/app/local/MODO_LOCAL", "Paquete local de $cliente armado el " . date('c') . "\n");
    file_put_contents("$dest/app/local/clave_publica.txt", $pub);
    kt_info("    $n archivos");

    kt_info("2/5 Copiando los datos del cliente ($cliente)…");
    for ($i = 0; $i < $z->numFiles; $i++) {
        $e = $z->getNameIndex($i);
        if (substr($e, -1) === '/' || $e === 'kt_manifest.json') {
            continue;
        }
        if (strpos($e, 'clients/') !== 0 || !kt_ruta_segura($e)) {
            kt_salir("Entrada inesperada en el .ktdata: $e");
        }
        @mkdir(dirname("$dest/app/$e"), 0775, true);
        kt_zip_extraer($z, $e, "$dest/app/$e");
    }
    $z->close();

    kt_info("3/5 Preparando PHP, Poppler y certificados para Windows…");
    $rt = "$dest/runtime";
    @mkdir("$rt/sesiones", 0775, true);
    @mkdir("$rt/logs", 0775, true);
    file_put_contents("$rt/preparar.php", KT_PREPARAR_PHP);
    if (empty($a['sin-runtime'])) {
        $cache = is_string($a['cache'] ?? null) ? $a['cache'] : sys_get_temp_dir() . '/kinotrace_descargas';
        @mkdir($cache, 0775, true);

        // PHP para Windows (x64, Non Thread Safe), con SHA-256 publicado por php.net
        $phpZip = is_string($a['php-zip'] ?? null) ? $a['php-zip'] : null;
        if (!$phpZip) {
            $rel = kt_json_url('https://windows.php.net/downloads/releases/releases.json');
            $entrada = null;
            foreach (['8.3', '8.2', '8.4'] as $v) {
                foreach (($rel[$v] ?? []) as $k => $info) {
                    if (preg_match('/^nts-vs\d+-x64$/', (string) $k) && !empty($info['zip']['path'])) {
                        $entrada = $info['zip'];
                        break 2;
                    }
                }
            }
            if (!$entrada) {
                kt_salir('No se encontró PHP para Windows en releases.json');
            }
            $phpZip = "$cache/" . basename($entrada['path']);
            if (!is_file($phpZip) || hash_file('sha256', $phpZip) !== strtolower($entrada['sha256'] ?? '')) {
                kt_descargar('https://windows.php.net/downloads/releases/' . $entrada['path'], $phpZip, $entrada['sha256'] ?? null);
            }
        }
        kt_extraer_zip($phpZip, "$rt/php");
        $exts = [];
        foreach (['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'sodium', 'zip'] as $e) {
            if (is_file("$rt/php/ext/php_$e.dll")) {
                $exts[] = "extension=$e";
            }
        }
        kt_escribir_crlf("$rt/php/php.ini", "; PHP del modo local de KINO TRACE (generado por kt.php)\n"
            . "; Base: runtime/preparar.php le agrega las rutas absolutas y escribe php-local.ini\n"
            . implode("\n", $exts) . "\n"
            . "memory_limit = 1024M\nupload_max_filesize = 1024M\npost_max_size = 1100M\nmax_execution_time = 600\nmax_input_time = 600\n"
            . "display_errors = Off\nlog_errors = On\ndate.timezone = America/Bogota\nsession.use_strict_mode = 1\nexpose_php = Off\n");

        // Poppler (pdftotext / pdftoppm)
        $popZip = is_string($a['poppler-zip'] ?? null) ? $a['poppler-zip'] : null;
        if (!$popZip) {
            $r = kt_json_url('https://api.github.com/repos/oschwartz10612/poppler-windows/releases/latest');
            $asset = null;
            foreach ($r['assets'] ?? [] as $as) {
                if (preg_match('/\.zip$/i', $as['name'] ?? '')) {
                    $asset = $as;
                    break;
                }
            }
            if (!$asset) {
                kt_salir('No se encontró el zip de Poppler para Windows.');
            }
            $popZip = "$cache/" . basename($asset['name']);
            $sha = (isset($asset['digest']) && strpos($asset['digest'], 'sha256:') === 0) ? substr($asset['digest'], 7) : null;
            if (!is_file($popZip)) {
                kt_descargar($asset['browser_download_url'], $popZip, $sha);
            }
        }
        $tmpPop = kt_tmp_dir('ktpop');
        kt_extraer_zip($popZip, $tmpPop);
        $exe = kt_buscar_archivo($tmpPop, 'pdftotext.exe');
        if (!$exe) {
            kt_salir('El zip de Poppler no trae pdftotext.exe');
        }
        kt_copiar_dir(dirname($exe, 2), "$rt/poppler"); // carpeta que contiene bin/
        kt_borrar_dir($tmpPop);

        // Certificados raíz para que curl valide HTTPS al sincronizar
        $cacert = is_string($a['cacert'] ?? null) ? $a['cacert'] : null;
        if (!$cacert) {
            $cacert = "$cache/cacert.pem";
            $shaTxt = @file_get_contents('https://curl.se/ca/cacert.pem.sha256');
            $sha = $shaTxt ? strtok(trim($shaTxt), " \t") : null;
            kt_descargar('https://curl.se/ca/cacert.pem', $cacert, $sha ?: null);
        }
        copy($cacert, "$rt/cacert.pem");

        // PHP para Windows necesita el Visual C++ 2015-2022 (x64). Muchos PC ya lo tienen;
        // si falta, el lanzador ofrece instalarlo desde aquí (pide permiso de administrador).
        if (empty($a['sin-vcredist'])) {
            $vc = is_string($a['vcredist'] ?? null) ? $a['vcredist'] : "$cache/vc_redist.x64.exe";
            if (!is_file($vc)) {
                kt_descargar('https://aka.ms/vs/17/release/vc_redist.x64.exe', $vc);
            }
            copy($vc, "$rt/vc_redist.x64.exe");
        }
    } else {
        kt_info('    (--sin-runtime: no se incluyen PHP/Poppler; solo para pruebas)');
    }

    kt_info("4/5 Lanzadores e instrucciones…");
    kt_escribir_crlf("$dest/INICIAR KINO TRACE.bat", kt_plantilla_iniciar($nube));
    kt_escribir_crlf("$dest/CREAR ACCESO DIRECTO.bat", kt_plantilla_acceso());
    kt_escribir_crlf("$dest/LEAME.txt", kt_plantilla_leame($cliente));

    if (empty($a['sin-zip'])) {
        kt_info("5/5 Comprimiendo…");
        $zipFinal = "$salida/{$nombre}_" . date('Ymd') . '.zip';
        $zz = new ZipArchive();
        $zz->open($zipFinal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $total = kt_zip_agregar_dir($zz, $dest, "$nombre/");
        $zz->close();
        kt_info("    $zipFinal ($total archivos, " . round(filesize($zipFinal) / 1048576, 1) . ' MB)');
    }
    kt_info("\nListo. Siguientes pasos (docs/modo_local/PROCESO.md):");
    kt_info("  1. Entregue el zip al cliente por un canal privado (contiene sus datos).");
    kt_info("  2. El cliente lo descomprime en C:\\KinoTrace (NO en Archivos de programa) y abre \"INICIAR KINO TRACE.bat\".");
    kt_info("  3. Le aparecerá un código KT-…; cuando se lo envíe:  php kt.php licencia --cliente $cliente --equipo KT-…");
}

function kt_plantilla_iniciar(string $nube): string
{
    return <<<BAT
@echo off
setlocal
title KINO TRACE - Modo local
cd /d "%~dp0"
set "KT_NUBE_URL={$nube}"
set "PATH=%~dp0runtime\\poppler\\bin;%~dp0runtime\\php;%ProgramFiles%\\Tesseract-OCR;%PATH%"

if not exist "runtime\\php\\php.exe" (
    echo No se encuentra runtime\\php\\php.exe
    echo Descomprima el paquete COMPLETO y vuelva a intentar.
    pause
    exit /b 1
)

rem PHP necesita el Visual C++ 2015-2022. Si falta, ofrecer instalarlo.
rem (si falta una DLL el código de salida es NEGATIVO: "if errorlevel 1" no lo detecta)
runtime\\php\\php.exe -n -v >nul 2>&1
if %errorlevel% neq 0 goto :sin_vcredist

if not exist "runtime\\sesiones" mkdir "runtime\\sesiones"
if not exist "runtime\\logs" mkdir "runtime\\logs"

rem preparar.php escribe php-local.ini (rutas absolutas) y devuelve un puerto libre
set "KT_PUERTO="
for /f "delims=" %%p in ('runtime\\php\\php.exe -n runtime\\preparar.php') do set "KT_PUERTO=%%p"
if not defined KT_PUERTO (
    echo No se pudo preparar el programa. Revise que la carpeta no sea de solo lectura.
    pause
    exit /b 1
)
set "KT_URL=http://127.0.0.1:%KT_PUERTO%/"

echo.
echo  ==========================================================
echo    KINO TRACE - Modo local
echo    Direccion: %KT_URL%
echo.
echo    NO CIERRE ESTA VENTANA mientras use el programa.
echo    Para terminar: cierre el navegador y luego esta ventana.
echo  ==========================================================
echo.

start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; try { Start-Process msedge -ArgumentList '--app=%KT_URL%' -ErrorAction Stop } catch { Start-Process '%KT_URL%' }"

runtime\\php\\php.exe -c runtime\\php\\php-local.ini -S 127.0.0.1:%KT_PUERTO% -t app app\\local\\router.php

echo.
echo El servidor se detuvo. Si fue un error, revise runtime\\logs\\php_errores.log
pause
exit /b 0

:sin_vcredist
echo.
echo  Falta un componente de Windows (Microsoft Visual C++ 2015-2022) que PHP necesita.
if exist "runtime\\vc_redist.x64.exe" (
    echo  Se abrira el instalador de Microsoft. Acepte el permiso de administrador.
    pause
    start /wait "" "runtime\\vc_redist.x64.exe" /install /passive /norestart
    echo  Listo. Vuelva a abrir INICIAR KINO TRACE.
) else (
    echo  Descarguelo de https://aka.ms/vs/17/release/vc_redist.x64.exe e instalelo.
)
pause
exit /b 1

BAT;
}

/**
 * Se copia a runtime/preparar.php. Escribe php-local.ini con las rutas absolutas del
 * paquete ENTRE COMILLAS y con "/" (así una carpeta con espacios, paréntesis, &, ~ o
 * acentos no rompe el INI) y devuelve un puerto libre.
 */
const KT_PREPARAR_PHP = <<<'PHP'
<?php
// Generado por kt.php — lo ejecuta INICIAR KINO TRACE.bat en cada arranque.
$raiz = str_replace('\\', '/', dirname(__DIR__));
$rt = $raiz . '/runtime';
$q = fn($v) => '"' . str_replace(['"', '$'], '', $v) . '"';
$ini = (string) @file_get_contents($rt . '/php/php.ini') . "\r\n"
    . 'extension_dir = ' . $q($rt . '/php/ext') . "\r\n"
    . 'curl.cainfo = ' . $q($rt . '/cacert.pem') . "\r\n"
    . 'openssl.cafile = ' . $q($rt . '/cacert.pem') . "\r\n"
    . 'session.save_path = ' . $q($rt . '/sesiones') . "\r\n"
    . 'error_log = ' . $q($rt . '/logs/php_errores.log') . "\r\n";
if (@file_put_contents($rt . '/php/php-local.ini', $ini) === false) {
    exit(1);
}
// Puerto libre: nadie debe responder al conectar Y debe poder abrirse. En Windows abrir un
// puerto puede "funcionar" aunque otro programa ya lo use, por eso se prueba conectando.
for ($p = 8765; $p < 8800; $p++) {
    $c = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.3);
    if ($c) {
        fclose($c);
        continue;
    }
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s) {
        fclose($s);
        echo $p;
        exit(0);
    }
}
exit(1);

PHP;

function kt_plantilla_acceso(): string
{
    return <<<'BAT'
@echo off
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\KINO TRACE.lnk'); $s.TargetPath='%~dp0INICIAR KINO TRACE.bat'; $s.WorkingDirectory='%~dp0'; $s.IconLocation='%SystemRoot%\System32\shell32.dll,13'; $s.Save()"
echo Se creo el acceso directo "KINO TRACE" en el Escritorio.
pause

BAT;
}

function kt_plantilla_leame(string $cliente): string
{
    return <<<TXT
KINO TRACE - COPIA DE TRABAJO EN ESTE EQUIPO ({$cliente})
=========================================================

1. Descomprima esta carpeta en C:\\KinoTrace (o en Documentos).
   NO la ponga en "Archivos de programa": ahí Windows no deja guardar.
2. Doble clic en "INICIAR KINO TRACE.bat".
   - Se abre una ventana negra: NO la cierre mientras use el programa.
   - Se abre el programa en el navegador.
3. La primera vez aparece un CODIGO DE EQUIPO (KT-XXXX-...).
   Envíelo a Kino. Le devolverán una licencia (texto que empieza con KTL1.)
   Péguela en esa misma pantalla y pulse "Activar".
4. Entre con su mismo código y contraseña de siempre.
5. Opcional: "CREAR ACCESO DIRECTO.bat" pone un icono en el Escritorio.

Sincronizar con la nube: menú "Sincronizar con la nube".
  - Opción 1: Subir a la nube (necesita internet y que la nube funcione).
  - Opción 2: Descargar paquete y enviarlo a Kino.

Importante
  - Sus datos quedan SOLO en este equipo hasta que sincronice.
    Haga copia de la carpeta completa en un USB al final del día.
  - La licencia funciona solo en este equipo y tiene fecha de vencimiento.
  - El programa solo responde en este equipo (no es accesible desde la red).

Soporte: Kino Trace.

TXT;
}

function cmd_aplicar(array $a): void
{
    $clients = rtrim((string) ($a['clients'] ?? ''), '/\\');
    $paq = (string) ($a['paquete'] ?? '');
    if (!is_dir($clients) || !is_file($paq)) {
        kt_salir('Use: aplicar --clients <carpeta con los clientes> --paquete <archivo.ktsync> [--si]');
    }
    $tmp = kt_tmp_dir('ktapl');
    try {
        $p = kt_leer_paquete_sync($paq, $tmp);
        $pv = kt_previsualizar_sync($clients, $p);
        kt_info("Cliente {$p['manifiesto']['cliente']} · paquete {$p['manifiesto']['paquete_id']}");
        kt_info("Resultado: " . strtoupper($pv['modo']) . " — {$pv['motivo']}");
        kt_info(json_encode($pv['informe'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($pv['modo'] === 'ya_aplicado') {
            return;
        }
        if (empty($a['si']) && strtolower(kt_preguntar('¿Aplicar? (s/n): ')) !== 's') {
            kt_info('Cancelado.');
            return;
        }
        $r = kt_aplicar_sync($clients, $p, $pv['modo'], 'consola');
        kt_info("Aplicado. Respaldo previo: {$r['respaldo']}");
    } finally {
        kt_borrar_dir($tmp);
    }
}

function cmd_clave(array $a): void
{
    $app = rtrim((string) ($a['app'] ?? ''), '/\\');
    $cliente = (string) ($a['cliente'] ?? '');
    $central = "$app/clients/central.db";
    if (!is_file($central) || !kt_codigo_valido($cliente)) {
        kt_salir('Use: clave --app <carpeta app del paquete> --cliente <código>');
    }
    $clave = is_string($a['nueva'] ?? null) ? $a['nueva'] : kt_preguntar('Contraseña nueva (mín. 8 caracteres): ');
    if (strlen($clave) < 8) {
        kt_salir('Mínimo 8 caracteres.');
    }
    $db = kt_abrir_sqlite($central);
    $st = $db->prepare('UPDATE control_clientes SET password_hash = ? WHERE codigo = ?');
    $st->execute([password_hash($clave, PASSWORD_DEFAULT), $cliente]);
    kt_info($st->rowCount() ? 'Contraseña local actualizada (la de la nube no cambia).' : 'No se encontró ese cliente.');
}

// ---------------------------------------------------------------------------

if (!extension_loaded('sodium') || !class_exists('ZipArchive') || !extension_loaded('pdo_sqlite')) {
    kt_salir('Este PHP necesita las extensiones sodium, zip y pdo_sqlite.');
}
$a = kt_args($argv);
$cmd = $a['_'][0] ?? '';
$mapa = [
    'llaves' => 'cmd_llaves', 'licencia' => 'cmd_licencia', 'verificar' => 'cmd_verificar',
    'equipo' => 'cmd_equipo', 'exportar' => 'cmd_exportar', 'paquete' => 'cmd_paquete',
    'aplicar' => 'cmd_aplicar', 'clave' => 'cmd_clave',
];
if (!isset($mapa[$cmd])) {
    $ayuda = file(__FILE__);
    echo implode('', array_slice($ayuda, 2, 16));
    exit($cmd === '' ? 0 : 1);
}
$mapa[$cmd]($a);
