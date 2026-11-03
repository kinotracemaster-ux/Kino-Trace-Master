<?php
/**
 * helpers/respaldo.php — Respaldos automáticos, restauración de un cliente y prueba semanal.
 *
 * Qué guarda (carpeta KT_RESPALDO_DIR, por defecto clients/_respaldos):
 *   central/AAAAMMDD_HHMMSS_etiqueta.db.gz          usuarios, marca y subdominio de todos los clientes
 *   clientes/{codigo}/bd/AAAAMMDD_HHMMSS_etiqueta.db.gz   copia consistente de la base (VACUUM INTO)
 *   clientes/{codigo}/archivos/...                  espejo de la carpeta del cliente (PDFs, logo).
 *                                                   Solo se agregan archivos nuevos o cambiados y
 *                                                   NUNCA se borra nada del espejo: un PDF borrado
 *                                                   por error sigue recuperable. En el mismo disco
 *                                                   usa enlaces duros: no ocupa espacio extra.
 *   estado.json                                     último respaldo, última prueba y sus resultados
 *
 * Fuera del volumen (opcional): si están las variables KT_S3_* (Cloudflare R2 o cualquier
 * S3), cada respaldo sube las bases nuevas y los archivos que aún no estén en la nube.
 * Sin eso, los respaldos protegen contra borrados y daños, pero no contra perder el volumen.
 *
 * Retención de bases: todas las de los últimos 14 días, luego una por semana hasta 8 semanas.
 *
 * Igual que helpers/sync_local.php (del que reutiliza utilidades), NO incluye config.php:
 * todas las funciones reciben rutas explícitas, así los tests trabajan en carpetas temporales.
 */

require_once __DIR__ . '/sync_local.php';

const KT_RESP_DIAS_TODOS = 14;
const KT_RESP_SEMANAS = 8;
const KT_RESP_CADA_HORAS = 20;      // respaldo automático si el último tiene más de esto
const KT_RESP_PRUEBA_DIAS = 7;      // prueba de restauración automática cada semana

/** Carpetas y archivos de la carpeta de un cliente que no van al espejo. */
const KT_RESP_EXCLUIR = ['_respaldos', '_sync', 'cache'];

// ---------------------------------------------------------------------------
// Rutas y estado
// ---------------------------------------------------------------------------

function kt_resp_dir(string $clientsDir): string
{
    $env = getenv('KT_RESPALDO_DIR');
    return $env !== false && $env !== '' ? rtrim($env, '/\\') : $clientsDir . DIRECTORY_SEPARATOR . '_respaldos';
}

function kt_resp_estado(string $respDir): array
{
    $f = $respDir . '/estado.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function kt_resp_estado_guardar(string $respDir, array $estado): void
{
    @mkdir($respDir, 0770, true);
    $tmp = $respDir . '/estado.json.tmp';
    file_put_contents($tmp, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $respDir . '/estado.json');
}

/**
 * Códigos de cliente a respaldar: los de control_clientes más cualquier carpeta que tenga
 * su propia base (así un cliente que quedó fuera de central.db no se pierde).
 */
function kt_resp_clientes(string $clientsDir, ?string $centralDb = null): array
{
    $codigos = [];
    $centralDb = $centralDb ?? $clientsDir . '/central.db';
    if (is_file($centralDb)) {
        try {
            $c = kt_abrir_sqlite($centralDb);
            $codigos = $c->query('SELECT codigo FROM control_clientes')->fetchAll(PDO::FETCH_COLUMN);
            $c = null;
        } catch (Throwable $e) {
            // central dañada: seguimos con las carpetas
        }
    }
    foreach (glob($clientsDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $cod = basename($d);
        if (is_file("$d/$cod.db")) {
            $codigos[] = $cod;
        }
    }
    $codigos = array_values(array_unique(array_filter(array_map('strval', $codigos), 'kt_codigo_valido')));
    sort($codigos);
    return $codigos;
}

function kt_resp_nombre_valido(string $nombre): bool
{
    return (bool) preg_match('/^[0-9]{8}_[0-9]{6}_[a-z0-9_]+\.db\.gz$/', $nombre);
}

// ---------------------------------------------------------------------------
// Piezas
// ---------------------------------------------------------------------------

function kt_resp_gzip(string $origen, string $destino): void
{
    $in = fopen($origen, 'rb');
    $out = gzopen($destino . '.tmp', 'wb6');
    if (!$in || !$out) {
        throw new RuntimeException("No se pudo comprimir $origen");
    }
    while (!feof($in)) {
        gzwrite($out, (string) fread($in, 1 << 20));
    }
    fclose($in);
    gzclose($out);
    rename($destino . '.tmp', $destino);
}

function kt_resp_gunzip(string $origen, string $destino): void
{
    $in = gzopen($origen, 'rb');
    $out = fopen($destino, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException("No se pudo descomprimir $origen");
    }
    while (!gzeof($in)) {
        $buf = gzread($in, 1 << 20);
        if ($buf === false) {
            throw new RuntimeException("Respaldo dañado: $origen");
        }
        fwrite($out, $buf);
    }
    gzclose($in);
    fclose($out);
}

/** Copia consistente y comprimida de una base SQLite. Devuelve la ruta del .db.gz. */
function kt_resp_copiar_bd(string $dbOrigen, string $dirDestino, string $etiqueta): string
{
    @mkdir($dirDestino, 0770, true);
    $etiqueta = preg_replace('/[^a-z0-9_]/', '', strtolower($etiqueta)) ?: 'manual';
    $destino = $dirDestino . '/' . gmdate('Ymd_His') . '_' . $etiqueta . '.db.gz';
    // Dos respaldos en el mismo segundo: no pisar el anterior
    for ($i = 2; file_exists($destino); $i++) {
        $destino = $dirDestino . '/' . gmdate('Ymd_His') . '_' . $etiqueta . $i . '.db.gz';
    }
    $tmp = kt_tmp_dir('ktresp');
    try {
        kt_copia_consistente($dbOrigen, "$tmp/copia.db");
        kt_resp_gzip("$tmp/copia.db", $destino);
    } finally {
        kt_borrar_dir($tmp);
    }
    return $destino;
}

/**
 * Espejo incremental: copia a $destino los archivos de $origen que falten o cambiaron
 * (tamaño o fecha). No borra nada del destino.
 *
 * @return array{archivos:int, copiados:int, bytes:int}
 */
function kt_resp_espejo(string $origen, string $destino, string $codigo): array
{
    $r = ['archivos' => 0, 'copiados' => 0, 'bytes' => 0];
    if (!is_dir($origen)) {
        return $r;
    }
    $base = strlen(rtrim($origen, '/\\')) + 1;
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f) use ($base, $codigo) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
                $primero = explode('/', $rel)[0];
                if (in_array($primero, KT_RESP_EXCLUIR, true)) {
                    return false;
                }
                // La base del cliente va por kt_resp_copiar_bd (copia consistente), no aquí
                return !preg_match('/^' . preg_quote($codigo, '/') . '\.db(-wal|-shm|-journal|\.nuevo)?$/', $rel);
            }
        )
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = substr($f->getPathname(), $base);
        $dst = $destino . DIRECTORY_SEPARATOR . $rel;
        $r['archivos']++;
        if (is_file($dst) && filesize($dst) === $f->getSize() && filemtime($dst) >= $f->getMTime()) {
            continue;
        }
        @mkdir(dirname($dst), 0770, true);
        @unlink($dst . '.tmp');
        // Enlace duro si se puede (mismo disco): no ocupa espacio extra y, si el PDF original
        // se borra, el del espejo sigue ahí. Si no (otro disco), copia normal.
        $enlazado = @link($f->getPathname(), $dst . '.tmp');
        if (!$enlazado && !copy($f->getPathname(), $dst . '.tmp')) {
            throw new RuntimeException("No se pudo copiar $rel");
        }
        if (!rename($dst . '.tmp', $dst)) {
            throw new RuntimeException("No se pudo guardar $rel");
        }
        if (!$enlazado) {
            touch($dst, $f->getMTime());
        }
        $r['enlazados'] = ($r['enlazados'] ?? 0) + ($enlazado ? 1 : 0);
        $r['copiados']++;
        $r['bytes'] += $f->getSize();
    }
    return $r;
}

/** Borra bases viejas: deja todas las de los últimos 14 días y una por semana hasta 8 semanas. */
function kt_resp_podar(string $dirBd, ?int $ahora = null): int
{
    $ahora = $ahora ?? time();
    $semanasVistas = [];
    $borradas = 0;
    $archivos = glob($dirBd . '/*.db.gz') ?: [];
    rsort($archivos); // más nuevos primero
    foreach ($archivos as $f) {
        $ts = DateTime::createFromFormat('Ymd_His', substr(basename($f), 0, 15), new DateTimeZone('UTC'));
        if (!$ts) {
            continue;
        }
        $edadDias = ($ahora - $ts->getTimestamp()) / 86400;
        if ($edadDias <= KT_RESP_DIAS_TODOS) {
            continue;
        }
        $semana = $ts->format('o-W');
        if ($edadDias <= KT_RESP_SEMANAS * 7 && !isset($semanasVistas[$semana])) {
            $semanasVistas[$semana] = true;
            continue;
        }
        @unlink($f);
        $borradas++;
    }
    return $borradas;
}

/** @return array<int, array{nombre:string, bytes:int, fecha:string}> más nuevos primero */
function kt_resp_listar(string $clientsDir, string $codigo): array
{
    $out = [];
    $dir = kt_resp_dir($clientsDir) . "/clientes/$codigo/bd";
    foreach (glob($dir . '/*.db.gz') ?: [] as $f) {
        $n = basename($f);
        $ts = DateTime::createFromFormat('Ymd_His', substr($n, 0, 15), new DateTimeZone('UTC'));
        $out[] = ['nombre' => $n, 'bytes' => (int) filesize($f), 'fecha' => $ts ? $ts->format('Y-m-d H:i') . ' UTC' : ''];
    }
    usort($out, fn($a, $b) => strcmp($b['nombre'], $a['nombre']));
    return $out;
}

// ---------------------------------------------------------------------------
// Respaldar
// ---------------------------------------------------------------------------

/** Respalda un cliente: base comprimida + espejo de archivos. */
function kt_resp_cliente(string $clientsDir, string $codigo, string $etiqueta = 'diario'): array
{
    if (!kt_codigo_valido($codigo)) {
        throw new InvalidArgumentException('Código de cliente inválido.');
    }
    $t0 = microtime(true);
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $respCli = kt_resp_dir($clientsDir) . "/clientes/$codigo";
    $info = ['ok' => false, 'bd' => null, 'bd_bytes' => 0];
    $dbPath = "$dirCliente/$codigo.db";
    if (is_file($dbPath)) {
        $gz = kt_resp_copiar_bd($dbPath, "$respCli/bd", $etiqueta);
        $info['bd'] = basename($gz);
        $info['bd_bytes'] = (int) filesize($gz);
        kt_resp_podar("$respCli/bd");
    }
    $info += kt_resp_espejo($dirCliente, "$respCli/archivos", $codigo);
    // Carpeta antigua de PDFs (clients/uploads/client_{codigo}/), que resolve_pdf_path aún acepta
    $legacy = $clientsDir . "/uploads/client_$codigo";
    if (is_dir($legacy)) {
        $l = kt_resp_espejo($legacy, "$respCli/archivos_antiguos", $codigo);
        $info['archivos'] += $l['archivos'];
        $info['copiados'] += $l['copiados'];
        $info['bytes'] += $l['bytes'];
    }
    $info['ok'] = true;
    $info['ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $info;
}

/**
 * Respalda todo: central.db y cada cliente; luego sube a la nube si está configurada.
 * Un cliente que falla no detiene a los demás. Guarda el resultado en estado.json.
 */
function kt_resp_todo(string $clientsDir, string $etiqueta = 'diario'): array
{
    $respDir = kt_resp_dir($clientsDir);
    $t0 = microtime(true);
    $res = ['fecha' => gmdate('c'), 'etiqueta' => $etiqueta, 'central' => null, 'clientes' => [], 'errores' => 0];
    try {
        if (is_file("$clientsDir/central.db")) {
            $res['central'] = basename(kt_resp_copiar_bd("$clientsDir/central.db", "$respDir/central", $etiqueta));
            kt_resp_podar("$respDir/central");
        }
    } catch (Throwable $e) {
        $res['central'] = 'ERROR: ' . $e->getMessage();
        $res['errores']++;
    }
    foreach (kt_resp_clientes($clientsDir) as $codigo) {
        try {
            $res['clientes'][$codigo] = kt_resp_cliente($clientsDir, $codigo, $etiqueta);
        } catch (Throwable $e) {
            $res['clientes'][$codigo] = ['ok' => false, 'error' => $e->getMessage()];
            $res['errores']++;
        }
    }
    if (kt_s3_configurado()) {
        try {
            $res['nube'] = kt_s3_sincronizar($respDir);
        } catch (Throwable $e) {
            $res['nube'] = ['ok' => false, 'error' => $e->getMessage()];
            $res['errores']++;
        }
    } else {
        $res['nube'] = ['ok' => false, 'error' => 'No configurada (variables KT_S3_*)'];
    }
    $res['ms'] = (int) round((microtime(true) - $t0) * 1000);
    $estado = kt_resp_estado($respDir);
    $estado['ultimo_respaldo'] = $res;
    kt_resp_estado_guardar($respDir, $estado);
    return $res;
}

// ---------------------------------------------------------------------------
// Restaurar
// ---------------------------------------------------------------------------

/**
 * Restaura un cliente desde un respaldo: primero respalda el estado actual
 * ("antes_restaurar"), revisa que la copia esté sana, reemplaza la base y devuelve
 * los archivos que falten (no pisa ni borra los que ya están).
 *
 * @param string|null $destinoClientsDir Otra carpeta de clientes donde restaurar
 *        (la prueba semanal restaura en una carpeta temporal). Por defecto, la real.
 */
function kt_resp_restaurar(string $clientsDir, string $codigo, string $nombre, ?string $destinoClientsDir = null): array
{
    if (!kt_codigo_valido($codigo) || !kt_resp_nombre_valido($nombre)) {
        throw new InvalidArgumentException('Cliente o respaldo inválido.');
    }
    $t0 = microtime(true);
    $respCli = kt_resp_dir($clientsDir) . "/clientes/$codigo";
    $gz = "$respCli/bd/$nombre";
    if (!is_file($gz)) {
        throw new RuntimeException('Ese respaldo no existe.');
    }
    $destino = $destinoClientsDir ?? $clientsDir;
    $dirCliente = $destino . DIRECTORY_SEPARATOR . $codigo;
    $dbDestino = "$dirCliente/$codigo.db";

    $tmp = kt_tmp_dir('ktrest');
    try {
        kt_resp_gunzip($gz, "$tmp/$codigo.db");
        $chk = kt_abrir_sqlite("$tmp/$codigo.db");
        $integridad = (string) $chk->query('PRAGMA integrity_check')->fetchColumn();
        if ($integridad !== 'ok') {
            throw new RuntimeException("El respaldo $nombre está dañado ($integridad). No se tocó nada.");
        }
        $conteo = kt_resp_conteo($chk);
        $chk = null;

        $previo = null;
        if ($destinoClientsDir === null && is_file($dbDestino)) {
            $previo = basename(kt_resp_copiar_bd($dbDestino, "$respCli/bd", 'antes_restaurar'));
        }
        @mkdir($dirCliente, 0770, true);
        if (is_file($dbDestino)) {
            kt_reemplazar_bd($dbDestino, "$tmp/$codigo.db");
        } else {
            copy("$tmp/$codigo.db", $dbDestino);
        }
    } finally {
        kt_borrar_dir($tmp);
    }

    $archivos = kt_resp_devolver_archivos("$respCli/archivos", $dirCliente);
    if (is_dir("$respCli/archivos_antiguos")) {
        $a = kt_resp_devolver_archivos("$respCli/archivos_antiguos", $destino . "/uploads/client_$codigo");
        $archivos['devueltos'] += $a['devueltos'];
        $archivos['ya_estaban'] += $a['ya_estaban'];
    }

    return [
        'respaldo' => $nombre,
        'respaldo_previo' => $previo,
        'documentos' => $conteo['documentos'],
        'codigos' => $conteo['codigos'],
        'archivos_devueltos' => $archivos['devueltos'],
        'archivos_ya_estaban' => $archivos['ya_estaban'],
        'ms' => (int) round((microtime(true) - $t0) * 1000),
    ];
}

/** Copia del espejo a la carpeta del cliente los archivos que falten. */
function kt_resp_devolver_archivos(string $espejo, string $destino): array
{
    $r = ['devueltos' => 0, 'ya_estaban' => 0];
    if (!is_dir($espejo)) {
        return $r;
    }
    $base = strlen(rtrim($espejo, '/\\')) + 1;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($espejo, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -4) === '.tmp') {
            continue;
        }
        $dst = $destino . DIRECTORY_SEPARATOR . substr($f->getPathname(), $base);
        if (is_file($dst)) {
            $r['ya_estaban']++;
            continue;
        }
        @mkdir(dirname($dst), 0770, true);
        if (!copy($f->getPathname(), $dst)) {
            throw new RuntimeException('No se pudo devolver ' . $f->getFilename());
        }
        $r['devueltos']++;
    }
    return $r;
}

function kt_resp_conteo(PDO $db): array
{
    $n = fn(string $t) => kt_tabla_existe($db, $t) ? (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn() : 0;
    return ['documentos' => $n('documentos'), 'codigos' => $n('codigos')];
}

// ---------------------------------------------------------------------------
// Prueba de restauración
// ---------------------------------------------------------------------------

/**
 * Para cada cliente, restaura su respaldo más reciente en una carpeta temporal y comprueba:
 * la base abre y está sana, tiene los mismos documentos y códigos que el respaldo, y cada
 * PDF que la base menciona está en el espejo. Nunca toca los datos reales.
 */
function kt_resp_probar(string $clientsDir): array
{
    $respDir = kt_resp_dir($clientsDir);
    $res = ['fecha' => gmdate('c'), 'ok' => true, 'clientes' => []];
    foreach (kt_resp_clientes($clientsDir) as $codigo) {
        $lista = kt_resp_listar($clientsDir, $codigo);
        if (!$lista && !is_file("$clientsDir/$codigo/$codigo.db")) {
            // Registrado en central.db pero nunca tuvo base: nada que proteger
            $res['clientes'][$codigo] = ['ok' => true, 'nota' => 'Sin base de datos todavía'];
            continue;
        }
        if (!$lista) {
            $res['clientes'][$codigo] = ['ok' => false, 'error' => 'Sin respaldos'];
            $res['ok'] = false;
            continue;
        }
        $tmp = kt_tmp_dir('ktprueba');
        try {
            // Solo la base: los PDFs se verifican contra el espejo sin copiarlos (pueden ser GB)
            $espejoReal = "$respDir/clientes/$codigo/archivos";
            $r = kt_resp_restaurar_solo_bd($clientsDir, $codigo, $lista[0]['nombre'], $tmp);
            $db = kt_abrir_sqlite("$tmp/$codigo.db");
            $faltan = [];
            if (kt_tabla_existe($db, 'documentos')) {
                foreach ($db->query("SELECT ruta_archivo FROM documentos WHERE ruta_archivo IS NOT NULL AND ruta_archivo <> ''")->fetchAll(PDO::FETCH_COLUMN) as $ruta) {
                    if (!kt_resp_ruta_en_espejo($espejoReal, "$respDir/clientes/$codigo/archivos_antiguos", (string) $ruta, $codigo)) {
                        $faltan[] = $ruta;
                    }
                }
            }
            $db = null;
            $res['clientes'][$codigo] = [
                'ok' => true,
                'respaldo' => $lista[0]['nombre'],
                'documentos' => $r['documentos'],
                'codigos' => $r['codigos'],
                'pdfs_sin_copia' => count($faltan),
                'ejemplos_sin_copia' => array_slice($faltan, 0, 5),
                'ms' => $r['ms'],
            ];
        } catch (Throwable $e) {
            $res['clientes'][$codigo] = ['ok' => false, 'error' => $e->getMessage()];
            $res['ok'] = false;
        } finally {
            kt_borrar_dir($tmp);
        }
    }
    $estado = kt_resp_estado($respDir);
    $estado['ultima_prueba'] = $res;
    kt_resp_estado_guardar($respDir, $estado);
    return $res;
}

function kt_resp_restaurar_solo_bd(string $clientsDir, string $codigo, string $nombre, string $dirTmp): array
{
    $t0 = microtime(true);
    $gz = kt_resp_dir($clientsDir) . "/clientes/$codigo/bd/$nombre";
    kt_resp_gunzip($gz, "$dirTmp/$codigo.db");
    $db = kt_abrir_sqlite("$dirTmp/$codigo.db");
    $integridad = (string) $db->query('PRAGMA integrity_check')->fetchColumn();
    if ($integridad !== 'ok') {
        throw new RuntimeException("Base dañada en $nombre: $integridad");
    }
    $c = kt_resp_conteo($db);
    $db = null;
    return $c + ['ms' => (int) round((microtime(true) - $t0) * 1000)];
}

/**
 * ¿Está en el espejo el PDF que menciona la base? Las rutas guardadas tienen varias formas
 * (relativa a uploads/, a la carpeta del cliente, con "clients/{codigo}/" delante o absoluta):
 * se prueba cada una y, si no, se busca por nombre de archivo.
 */
function kt_resp_ruta_en_espejo(string $espejo, string $espejoAntiguo, string $ruta, string $codigo): bool
{
    static $porNombre = [];
    $ruta = str_replace('\\', '/', $ruta);
    $candidatos = [$ruta, "uploads/$ruta"];
    if (preg_match('#clients/' . preg_quote($codigo, '#') . '/(.+)$#', $ruta, $m)) {
        $candidatos[] = $m[1];
    }
    if (preg_match('#uploads/client_' . preg_quote($codigo, '#') . '/(.+)$#', $ruta, $m)) {
        if (is_file("$espejoAntiguo/{$m[1]}")) {
            return true;
        }
    }
    foreach ($candidatos as $c) {
        if (kt_ruta_segura(ltrim($c, '/')) && is_file($espejo . '/' . ltrim($c, '/'))) {
            return true;
        }
    }
    // Último intento: por nombre de archivo en cualquier subcarpeta
    if (!isset($porNombre[$espejo])) {
        $porNombre[$espejo] = [];
        foreach ([$espejo, $espejoAntiguo] as $d) {
            if (!is_dir($d)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
                $porNombre[$espejo][$f->getFilename()] = true;
            }
        }
    }
    return isset($porNombre[$espejo][basename($ruta)]);
}

// ---------------------------------------------------------------------------
// Automático (lo llama tools/respaldo.php cada hora desde el contenedor)
// ---------------------------------------------------------------------------

/** Respalda si hace más de KT_RESP_CADA_HORAS y prueba si hace más de KT_RESP_PRUEBA_DIAS. */
function kt_resp_auto(string $clientsDir, ?int $ahora = null): array
{
    $ahora = $ahora ?? time();
    $respDir = kt_resp_dir($clientsDir);
    @mkdir($respDir, 0770, true);
    $lock = fopen("$respDir/.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['hecho' => [], 'nota' => 'Otro respaldo está en curso'];
    }
    try {
        $hecho = [];
        $estado = kt_resp_estado($respDir);
        $ultimo = strtotime((string) ($estado['ultimo_respaldo']['fecha'] ?? '')) ?: 0;
        if ($ahora - $ultimo > KT_RESP_CADA_HORAS * 3600) {
            kt_resp_todo($clientsDir, 'diario');
            $hecho[] = 'respaldo';
        }
        $estado = kt_resp_estado($respDir);
        $prueba = strtotime((string) ($estado['ultima_prueba']['fecha'] ?? '')) ?: 0;
        if ($ahora - $prueba > KT_RESP_PRUEBA_DIAS * 86400) {
            kt_resp_probar($clientsDir);
            $hecho[] = 'prueba';
        }
        return ['hecho' => $hecho];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---------------------------------------------------------------------------
// Nube S3 / Cloudflare R2 (opcional)
//   KT_S3_ENDPOINT  p. ej. https://<cuenta>.r2.cloudflarestorage.com
//   KT_S3_BUCKET    nombre del bucket
//   KT_S3_KEY / KT_S3_SECRET   credenciales (en R2: "API token" de tipo S3)
//   KT_S3_REGION    "auto" en R2 (por defecto)
// ---------------------------------------------------------------------------

function kt_s3_config(): ?array
{
    $c = [
        'endpoint' => rtrim((string) getenv('KT_S3_ENDPOINT'), '/'),
        'bucket' => (string) getenv('KT_S3_BUCKET'),
        'key' => (string) getenv('KT_S3_KEY'),
        'secret' => (string) getenv('KT_S3_SECRET'),
        'region' => (string) (getenv('KT_S3_REGION') ?: 'auto'),
    ];
    return $c['endpoint'] && $c['bucket'] && $c['key'] && $c['secret'] ? $c : null;
}

function kt_s3_configurado(): bool
{
    return kt_s3_config() !== null;
}

/**
 * Firma AWS Signature V4 (cabecera Authorization). Separada para poder probarla con el
 * ejemplo oficial de AWS sin red.
 */
function kt_s3_firmar(string $metodo, string $url, array $headers, string $hashCuerpo, string $key, string $secret, string $region, string $amzDate): string
{
    $p = parse_url($url);
    $ruta = $p['path'] ?? '/';
    $ruta = implode('/', array_map(fn($s) => rawurlencode(rawurldecode($s)), explode('/', $ruta)));
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        ksort($q);
        $partes = [];
        foreach ($q as $k => $v) {
            $partes[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $query = implode('&', $partes);
    }
    $h = [];
    foreach ($headers as $k => $v) {
        $h[strtolower($k)] = trim((string) preg_replace('/\s+/', ' ', (string) $v));
    }
    ksort($h);
    $canonHeaders = '';
    foreach ($h as $k => $v) {
        $canonHeaders .= "$k:$v\n";
    }
    $firmados = implode(';', array_keys($h));
    $canon = "$metodo\n$ruta\n$query\n$canonHeaders\n$firmados\n$hashCuerpo";
    $fecha = substr($amzDate, 0, 8);
    $alcance = "$fecha/$region/s3/aws4_request";
    $aFirmar = "AWS4-HMAC-SHA256\n$amzDate\n$alcance\n" . hash('sha256', $canon);
    $k = hash_hmac('sha256', $fecha, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    $firma = hash_hmac('sha256', $aFirmar, $k);
    return "AWS4-HMAC-SHA256 Credential=$key/$alcance, SignedHeaders=$firmados, Signature=$firma";
}

/** Petición S3. $archivoSubir: PUT desde archivo; $archivoBajar: GET a archivo. */
function kt_s3_pedir(string $metodo, string $clave, array $query = [], ?string $archivoSubir = null, ?string $archivoBajar = null): array
{
    $c = kt_s3_config();
    if (!$c) {
        throw new RuntimeException('Nube no configurada.');
    }
    $url = $c['endpoint'] . '/' . $c['bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($clave, '/'))));
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $amzDate = gmdate('Ymd\THis\Z');
    $hash = 'UNSIGNED-PAYLOAD';
    $headers = ['host' => parse_url($url, PHP_URL_HOST), 'x-amz-content-sha256' => $hash, 'x-amz-date' => $amzDate];
    $auth = kt_s3_firmar($metodo, $url, $headers, $hash, $c['key'], $c['secret'], $c['region'], $amzDate);
    $ch = curl_init($url);
    $h = ["Authorization: $auth", "x-amz-content-sha256: $hash", "x-amz-date: $amzDate"];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 3600, CURLOPT_CONNECTTIMEOUT => 20]);
    $fIn = $fOut = null;
    if ($archivoSubir !== null) {
        $fIn = fopen($archivoSubir, 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fIn, CURLOPT_INFILESIZE => filesize($archivoSubir)]);
    }
    if ($archivoBajar !== null) {
        @mkdir(dirname($archivoBajar), 0770, true);
        $fOut = fopen($archivoBajar . '.tmp', 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fOut);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $cuerpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fIn) {
        fclose($fIn);
    }
    if ($fOut) {
        fclose($fOut);
        $codigo === 200 ? rename($archivoBajar . '.tmp', $archivoBajar) : @unlink($archivoBajar . '.tmp');
    }
    if ($codigo < 200 || $codigo >= 300) {
        throw new RuntimeException("Nube: $metodo $clave → HTTP $codigo $err " . (is_string($cuerpo) ? substr($cuerpo, 0, 200) : ''));
    }
    return ['codigo' => $codigo, 'cuerpo' => is_string($cuerpo) ? $cuerpo : ''];
}

/** @return array<string,int> clave => tamaño de todo lo que hay en el bucket */
function kt_s3_listar(string $prefijo = ''): array
{
    $out = [];
    $token = null;
    do {
        $q = ['list-type' => '2', 'prefix' => $prefijo];
        if ($token) {
            $q['continuation-token'] = $token;
        }
        $r = kt_s3_pedir('GET', '', $q);
        $xml = simplexml_load_string($r['cuerpo']);
        if ($xml === false) {
            throw new RuntimeException('Nube: respuesta de listado ilegible.');
        }
        foreach ($xml->Contents as $o) {
            $out[(string) $o->Key] = (int) $o->Size;
        }
        $token = ((string) $xml->IsTruncated === 'true') ? (string) $xml->NextContinuationToken : null;
    } while ($token);
    return $out;
}

/** Sube a la nube todo lo de la carpeta de respaldos que aún no esté allá (mismo tamaño). */
function kt_s3_sincronizar(string $respDir): array
{
    $t0 = microtime(true);
    $remoto = kt_s3_listar();
    $r = ['ok' => true, 'subidos' => 0, 'bytes' => 0, 'en_nube' => count($remoto)];
    $base = strlen(rtrim($respDir, '/\\')) + 1;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($respDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
        if (!$f->isFile() || $rel[0] === '.' || substr($rel, -4) === '.tmp' || $rel === 'estado.json') {
            continue;
        }
        if (($remoto[$rel] ?? -1) === $f->getSize()) {
            continue;
        }
        kt_s3_pedir('PUT', $rel, [], $f->getPathname());
        $r['subidos']++;
        $r['bytes'] += $f->getSize();
    }
    kt_s3_pedir('PUT', 'estado.json', [], "$respDir/estado.json");
    $r['ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $r;
}

/** Baja de la nube lo que falte en la carpeta de respaldos (para recuperar tras perder el volumen). */
function kt_s3_bajar_todo(string $respDir): array
{
    $r = ['bajados' => 0, 'bytes' => 0];
    foreach (kt_s3_listar() as $clave => $tam) {
        if (!kt_ruta_segura($clave)) {
            continue;
        }
        $dst = $respDir . '/' . $clave;
        if (is_file($dst) && filesize($dst) === $tam) {
            continue;
        }
        kt_s3_pedir('GET', $clave, [], null, $dst);
        $r['bajados']++;
        $r['bytes'] += $tam;
    }
    return $r;
}
