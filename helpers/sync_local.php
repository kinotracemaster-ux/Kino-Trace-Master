<?php
/**
 * helpers/sync_local.php
 *
 * Núcleo del MODO LOCAL y de la SINCRONIZACIÓN con la nube.
 * Documentación completa: docs/modo_local/SINCRONIZACION.md
 *
 * Flujo:
 *   1. EXPORTAR  (nube o respaldo → paquete .ktdata): copia consistente de la BD
 *      del cliente + sus PDFs + una central.db con SOLO ese cliente. Se guarda la
 *      "huella" (hash lógico de documentos/códigos/vínculos) del momento del corte
 *      y se instalan triggers que registran cada cambio en kt_cambios.
 *   2. TRABAJAR en el PC: la app es la misma; los triggers anotan altas/cambios/bajas.
 *   3. SINCRONIZAR (PC → paquete .ktsync → nube):
 *      - Si la nube NO cambió desde el corte (huella nube == huella base):
 *        REEMPLAZO — la BD local pasa a ser la de la nube (con respaldo previo).
 *      - Si la nube SÍ cambió: FUSIÓN — solo se agregan documentos/códigos/vínculos
 *        nuevos; lo modificado o borrado en local se reporta como conflicto y no se toca.
 *
 * IMPORTANTE: este archivo NO incluye config.php. Todas las funciones reciben
 * rutas explícitas para que la herramienta de consola (local/herramientas/kt.php)
 * pueda trabajar sobre cualquier carpeta sin crear bases en el repo.
 */

const KT_SYNC_VERSION = 1;

/** Extensiones de archivo que pueden viajar dentro de un paquete. */
const KT_EXT_ARCHIVOS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'tif', 'tiff'];

/** Columnas que definen la huella (se excluye texto extraído: es derivado). */
const KT_COLUMNAS_HUELLA = [
    'documentos' => ['id', 'tipo', 'numero', 'fecha', 'proveedor', 'naviera', 'peso_kg', 'valor_usd',
        'ruta_archivo', 'original_path', 'hash_archivo', 'estado', 'notas', 'requiere_revision'],
    'codigos' => ['id', 'documento_id', 'codigo', 'descripcion', 'cantidad', 'valor_unitario', 'validado', 'alerta'],
    'vinculos' => ['id', 'documento_origen_id', 'documento_destino_id', 'tipo_vinculo'],
];

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

function kt_codigo_valido(string $codigo): bool
{
    return (bool) preg_match('/^[a-z0-9_]{1,64}$/', $codigo);
}

function kt_abrir_sqlite(string $ruta): PDO
{
    $db = new PDO('sqlite:' . $ruta, null, null, [PDO::ATTR_TIMEOUT => 15]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Misma representación de valores en cualquier versión de PHP (la huella depende de esto)
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    return $db;
}

function kt_tabla_existe(PDO $db, string $tabla): bool
{
    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$tabla]);
    return (bool) $st->fetchColumn();
}

function kt_columnas(PDO $db, string $tabla): array
{
    if (!kt_tabla_existe($db, $tabla)) {
        return [];
    }
    return $db->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
}

function kt_tmp_dir(string $prefijo): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefijo . '_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException("No se pudo crear carpeta temporal $dir");
    }
    return $dir;
}

function kt_borrar_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * Copia consistente de una BD SQLite (incluye lo que esté en el WAL).
 * El destino no debe existir.
 */
function kt_copia_consistente(string $origen, string $destino): void
{
    if (file_exists($destino)) {
        throw new RuntimeException("El destino ya existe: $destino");
    }
    $db = kt_abrir_sqlite($origen);
    $db->exec('VACUUM INTO ' . $db->quote($destino));
    $db = null;
}

/**
 * Ruta relativa segura (dentro de un paquete o de uploads/): sin "..", sin raíz,
 * sin unidades de Windows, sin barras invertidas ni caracteres de control.
 */
function kt_ruta_segura(string $ruta): bool
{
    if ($ruta === '' || strlen($ruta) > 400) {
        return false;
    }
    if (preg_match('/[\x00-\x1f\\\\:*?"<>|]/', $ruta) || $ruta[0] === '/') {
        return false;
    }
    foreach (explode('/', $ruta) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..' || rtrim($seg, '. ') !== $seg) {
            return false;
        }
    }
    return true;
}

function kt_ext_permitida(string $ruta): bool
{
    return in_array(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)), KT_EXT_ARCHIVOS, true);
}

function kt_ahora(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

// ---------------------------------------------------------------------------
// Huella lógica y rastreo de cambios
// ---------------------------------------------------------------------------

/**
 * Hash de los datos que importan (documentos, códigos, vínculos). Dos BDs con la
 * misma huella tienen el mismo contenido de negocio aunque el archivo sea distinto.
 */
function kt_huella(PDO $db): string
{
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $ctx = hash_init('sha256');
    foreach (KT_COLUMNAS_HUELLA as $tabla => $cols) {
        hash_update($ctx, "#$tabla\n");
        $existentes = array_values(array_intersect($cols, kt_columnas($db, $tabla)));
        if (!$existentes) {
            continue;
        }
        $st = $db->query('SELECT ' . implode(',', $existentes) . " FROM $tabla ORDER BY id");
        while ($fila = $st->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? "\0N" : (string) $v, $fila);
            hash_update($ctx, implode("\x1f", $vals) . "\x1e");
        }
    }
    return hash_final($ctx);
}

/**
 * Instala la tabla kt_cambios, kt_estado y los triggers que anotan cada alta,
 * modificación (solo de columnas de negocio) y baja.
 */
function kt_instalar_rastreo(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS kt_cambios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tabla TEXT NOT NULL,
        fila_id INTEGER NOT NULL,
        op TEXT NOT NULL,
        fecha TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS kt_estado (clave TEXT PRIMARY KEY, valor TEXT)');

    foreach (KT_COLUMNAS_HUELLA as $tabla => $cols) {
        if (!kt_tabla_existe($db, $tabla)) {
            continue;
        }
        $upd = array_values(array_diff(array_intersect($cols, kt_columnas($db, $tabla)), ['id']));
        $db->exec("CREATE TRIGGER IF NOT EXISTS kt_trg_{$tabla}_i AFTER INSERT ON $tabla BEGIN
            INSERT INTO kt_cambios (tabla, fila_id, op) VALUES ('$tabla', NEW.id, 'I'); END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS kt_trg_{$tabla}_d AFTER DELETE ON $tabla BEGIN
            INSERT INTO kt_cambios (tabla, fila_id, op) VALUES ('$tabla', OLD.id, 'D'); END");
        if ($upd) {
            $db->exec("CREATE TRIGGER IF NOT EXISTS kt_trg_{$tabla}_u AFTER UPDATE OF " . implode(',', $upd) . " ON $tabla BEGIN
                INSERT INTO kt_cambios (tabla, fila_id, op) VALUES ('$tabla', NEW.id, 'U'); END");
        }
    }
}

/** Quita triggers y tablas kt_* (la BD que vuelve a la nube no debe llevarlos). */
function kt_quitar_rastreo(PDO $db): void
{
    $triggers = $db->query("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'kt\\_trg\\_%' ESCAPE '\\'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $t) {
        $db->exec('DROP TRIGGER IF EXISTS "' . str_replace('"', '', $t) . '"');
    }
    $db->exec('DROP TABLE IF EXISTS kt_cambios');
    $db->exec('DROP TABLE IF EXISTS kt_estado');
}

function kt_estado_get(PDO $db, string $clave, ?string $def = null): ?string
{
    if (!kt_tabla_existe($db, 'kt_estado')) {
        return $def;
    }
    $st = $db->prepare('SELECT valor FROM kt_estado WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}

function kt_estado_set(PDO $db, string $clave, ?string $valor): void
{
    $db->prepare('INSERT OR REPLACE INTO kt_estado (clave, valor) VALUES (?, ?)')->execute([$clave, $valor]);
}

/**
 * Resume kt_cambios por fila: qué es nuevo, qué se modificó y qué se borró
 * respecto al corte.
 *
 * @return array{documentos: array, codigos: array, vinculos: array, max_cambio: int}
 */
function kt_resumen_cambios(PDO $db): array
{
    $vacio = ['nuevos' => [], 'modificados' => [], 'eliminados' => []];
    $res = ['documentos' => $vacio, 'codigos' => $vacio, 'vinculos' => $vacio, 'max_cambio' => 0];
    if (!kt_tabla_existe($db, 'kt_cambios')) {
        return $res;
    }
    $res['max_cambio'] = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM kt_cambios')->fetchColumn();

    $filas = $db->query("
        SELECT g.tabla, g.fila_id, p.op AS primera, u.op AS ultima
        FROM (SELECT tabla, fila_id, MIN(id) AS mn, MAX(id) AS mx FROM kt_cambios GROUP BY tabla, fila_id) g
        JOIN kt_cambios p ON p.id = g.mn
        JOIN kt_cambios u ON u.id = g.mx
        ORDER BY g.mn
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filas as $f) {
        $tabla = $f['tabla'];
        if (!isset($res[$tabla]) || !kt_tabla_existe($db, $tabla)) {
            continue;
        }
        $st = $db->prepare("SELECT 1 FROM $tabla WHERE id = ?");
        $st->execute([(int) $f['fila_id']]);
        $existe = (bool) $st->fetchColumn();
        $id = (int) $f['fila_id'];

        if ($f['primera'] === 'I') {
            if ($existe) {
                $res[$tabla]['nuevos'][] = $id;
            } // creado y borrado en local: no viaja
        } elseif ($existe) {
            $res[$tabla]['modificados'][] = $id;
        } else {
            $res[$tabla]['eliminados'][] = $id;
        }
    }
    return $res;
}

// ---------------------------------------------------------------------------
// Zip
// ---------------------------------------------------------------------------

function kt_zip_agregar_archivo(ZipArchive $zip, string $rutaFs, string $nombreZip): void
{
    $zip->addFile($rutaFs, $nombreZip);
    // Los PDF/imágenes ya vienen comprimidos: guardarlos tal cual es mucho más rápido
    if (kt_ext_permitida($nombreZip)) {
        $zip->setCompressionName($nombreZip, ZipArchive::CM_STORE);
    }
}

/** Extrae una entrada del zip por nombre exacto (sin depender del wrapper zip://). */
function kt_zip_extraer(ZipArchive $zip, string $nombre, string $destino): bool
{
    $in = $zip->getStream($nombre);
    if (!$in) {
        return false;
    }
    $out = fopen($destino, 'wb');
    if (!$out) {
        fclose($in);
        return false;
    }
    $ok = stream_copy_to_stream($in, $out) !== false;
    fclose($in);
    fclose($out);
    return $ok;
}

function kt_zip_agregar_dir(ZipArchive $zip, string $dirFs, string $prefijo): int
{
    if (!is_dir($dirFs)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirFs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dirFs) + 1));
        kt_zip_agregar_archivo($zip, $f->getPathname(), $prefijo . $rel);
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// 1. EXPORTAR (nube/respaldo → .ktdata)
// ---------------------------------------------------------------------------

/**
 * Genera el paquete de datos de UN cliente para trabajar en un PC.
 *
 * @param string $clientsDir Carpeta que contiene central.db y {codigo}/{codigo}.db
 * @param string $codigo     Código del cliente.
 * @param string $zipSalida  Ruta del .ktdata a generar.
 * @param string $origen     Texto libre: 'nube', 'respaldo 2026-02-10', etc.
 * @return array Manifiesto del paquete.
 */
function kt_exportar_datos(string $clientsDir, string $codigo, string $zipSalida, string $origen = 'nube'): array
{
    if (!kt_codigo_valido($codigo)) {
        throw new InvalidArgumentException('Código de cliente inválido');
    }
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $dbCliente = $dirCliente . DIRECTORY_SEPARATOR . $codigo . '.db';
    $central = $clientsDir . DIRECTORY_SEPARATOR . 'central.db';
    foreach ([$dbCliente, $central] as $f) {
        if (!is_file($f)) {
            throw new RuntimeException("No existe: $f");
        }
    }

    $tmp = kt_tmp_dir('ktexp');
    try {
        // --- BD del cliente: copia consistente + rastreo ---
        $copia = $tmp . DIRECTORY_SEPARATOR . $codigo . '.db';
        kt_copia_consistente($dbCliente, $copia);
        $db = kt_abrir_sqlite($copia);
        kt_quitar_rastreo($db); // por si viene de una exportación anterior
        $huella = kt_huella($db);
        $snapshot = bin2hex(random_bytes(8));
        $conteos = [];
        foreach (array_keys(KT_COLUMNAS_HUELLA) as $t) {
            $conteos[$t] = kt_tabla_existe($db, $t) ? (int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn() : 0;
        }
        kt_instalar_rastreo($db);
        foreach ([
            'cliente' => $codigo,
            'snapshot_id' => $snapshot,
            'huella_base' => $huella,
            'exportado_en' => kt_ahora(),
            'origen' => $origen,
        ] as $k => $v) {
            kt_estado_set($db, $k, $v);
        }
        $db = null;

        // --- central.db solo con este cliente y sin datos sensibles ---
        $centralCopia = $tmp . DIRECTORY_SEPARATOR . 'central.db';
        kt_copia_consistente($central, $centralCopia);
        $c = kt_abrir_sqlite($centralCopia);
        $st = $c->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        $existe = (int) $st->fetchColumn();
        $st = null; // cerrar el cursor: VACUUM falla si hay sentencias abiertas
        if (!$existe) {
            throw new RuntimeException("El cliente '$codigo' no está en central.db");
        }
        foreach ($c->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('control_clientes','pagina_publica','sqlite_sequence')")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $c->exec('DROP TABLE "' . str_replace('"', '', $t) . '"');
        }
        $c->prepare('DELETE FROM control_clientes WHERE codigo <> ?')->execute([$codigo]);
        if (kt_tabla_existe($c, 'pagina_publica')) {
            $c->prepare('DELETE FROM pagina_publica WHERE codigo <> ?')->execute([$codigo]);
        }
        $cols = kt_columnas($c, 'control_clientes');
        foreach (['password_plain', 'reset_token', 'reset_token_expiry'] as $col) {
            if (in_array($col, $cols, true)) {
                $c->exec("UPDATE control_clientes SET $col = NULL");
            }
        }
        $c->exec('VACUUM'); // borra físicamente las filas eliminadas (no quedan en páginas libres)
        $c = null;

        $manifiesto = [
            'tipo' => 'ktdata',
            'version' => KT_SYNC_VERSION,
            'cliente' => $codigo,
            'snapshot_id' => $snapshot,
            'huella_base' => $huella,
            'exportado_en' => kt_ahora(),
            'origen' => $origen,
            'conteos' => $conteos,
        ];

        $zip = new ZipArchive();
        if ($zip->open($zipSalida, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear $zipSalida");
        }
        $zip->addFromString('kt_manifest.json', json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($centralCopia, 'clients/central.db');
        $zip->addFile($copia, "clients/$codigo/$codigo.db");
        $manifiesto['archivos'] = kt_zip_agregar_dir($zip, $dirCliente . DIRECTORY_SEPARATOR . 'uploads', "clients/$codigo/uploads/");
        foreach (glob($dirCliente . DIRECTORY_SEPARATOR . 'logo.*') ?: [] as $logo) {
            kt_zip_agregar_archivo($zip, $logo, "clients/$codigo/" . basename($logo));
        }
        if (!$zip->close()) {
            throw new RuntimeException('Error cerrando el zip de exportación');
        }
        return $manifiesto;
    } finally {
        kt_borrar_dir($tmp);
    }
}

// ---------------------------------------------------------------------------
// 2. PAQUETE DE SINCRONIZACIÓN (PC → .ktsync)
// ---------------------------------------------------------------------------

/**
 * Crea el .ktsync con la BD local completa y SOLO los archivos de documentos
 * nuevos o modificados desde el corte.
 *
 * @param array $licencia Datos de la licencia activa (id, equipo) para trazabilidad.
 */
function kt_crear_paquete_sync(string $clientsDir, string $codigo, string $zipSalida, array $licencia = []): array
{
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $dbCliente = $dirCliente . DIRECTORY_SEPARATOR . $codigo . '.db';
    $uploads = $dirCliente . DIRECTORY_SEPARATOR . 'uploads';

    $tmp = kt_tmp_dir('ktsync');
    try {
        $copia = $tmp . DIRECTORY_SEPARATOR . $codigo . '.db';
        kt_copia_consistente($dbCliente, $copia);
        $db = kt_abrir_sqlite($copia);
        if (kt_estado_get($db, 'cliente') !== $codigo) {
            throw new RuntimeException('Esta base no fue exportada para modo local (falta kt_estado).');
        }
        $resumen = kt_resumen_cambios($db);
        $huellaLocal = kt_huella($db);

        // Archivos de documentos nuevos/modificados
        $archivos = [];
        $faltantes = [];
        $ids = array_merge($resumen['documentos']['nuevos'], $resumen['documentos']['modificados']);
        $st = $db->prepare('SELECT ruta_archivo FROM documentos WHERE id = ?');
        foreach ($ids as $id) {
            $st->execute([$id]);
            $ruta = str_replace('\\', '/', (string) $st->fetchColumn());
            if ($ruta === '' || $ruta === 'pending' || isset($archivos[$ruta])) {
                continue;
            }
            $fs = $uploads . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
            if (!kt_ruta_segura($ruta) || !kt_ext_permitida($ruta) || !is_file($fs)) {
                $faltantes[] = $ruta;
                continue;
            }
            $archivos[$ruta] = ['fs' => $fs, 'sha256' => hash_file('sha256', $fs), 'bytes' => filesize($fs)];
        }

        $manifiesto = [
            'tipo' => 'ktsync',
            'version' => KT_SYNC_VERSION,
            'paquete_id' => bin2hex(random_bytes(12)),
            'cliente' => $codigo,
            'snapshot_id' => kt_estado_get($db, 'snapshot_id'),
            'huella_base' => kt_estado_get($db, 'huella_base'),
            'huella_local' => $huellaLocal,
            'max_cambio' => $resumen['max_cambio'],
            'creado_en' => kt_ahora(),
            'licencia_id' => $licencia['id'] ?? null,
            'equipo' => $licencia['equipo'] ?? null,
            'resumen' => array_map(fn($t) => is_array($t) ? array_map('count', $t) : $t, $resumen),
            'archivos' => array_map(fn($a) => ['sha256' => $a['sha256'], 'bytes' => $a['bytes']], $archivos),
            'archivos_faltantes' => $faltantes,
        ];
        $db = null;

        $zip = new ZipArchive();
        if ($zip->open($zipSalida, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear $zipSalida");
        }
        $zip->addFromString('kt_manifest.json', json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($copia, "db/$codigo.db");
        foreach ($archivos as $ruta => $a) {
            kt_zip_agregar_archivo($zip, $a['fs'], 'uploads/' . $ruta);
        }
        if (!$zip->close()) {
            throw new RuntimeException('Error cerrando el paquete de sincronización');
        }
        return $manifiesto;
    } finally {
        kt_borrar_dir($tmp);
    }
}

/**
 * Tras una sincronización por REEMPLAZO la nube quedó igual a esta BD:
 * el nuevo punto de partida es la huella enviada y se limpia lo ya enviado.
 */
function kt_marcar_sincronizado(PDO $db, array $manifiesto): void
{
    $db->beginTransaction();
    kt_estado_set($db, 'huella_base', $manifiesto['huella_local']);
    kt_estado_set($db, 'ultima_sync', kt_ahora());
    kt_estado_set($db, 'ultima_sync_paquete', $manifiesto['paquete_id']);
    $db->prepare('DELETE FROM kt_cambios WHERE id <= ?')->execute([(int) $manifiesto['max_cambio']]);
    $db->commit();
}

// ---------------------------------------------------------------------------
// 3. LEER Y VALIDAR un .ktsync (lado nube)
// ---------------------------------------------------------------------------

/**
 * Abre y valida un paquete. Extrae la BD y los archivos a $tmp.
 * Lanza excepción ante cualquier inconsistencia (nombre peligroso, hash distinto, BD dañada...).
 *
 * @return array{manifiesto: array, db: string, archivos: array<string,string>}
 */
function kt_leer_paquete_sync(string $zipRuta, string $tmp): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('El archivo no es un paquete válido (zip ilegible).');
    }
    try {
        if ($zip->numFiles > 100000) {
            throw new RuntimeException('Paquete con demasiados archivos.');
        }
        $json = $zip->getFromName('kt_manifest.json');
        $m = $json ? json_decode($json, true) : null;
        if (!is_array($m) || ($m['tipo'] ?? '') !== 'ktsync' || (int) ($m['version'] ?? 0) !== KT_SYNC_VERSION) {
            throw new RuntimeException('Manifiesto ausente o de otra versión.');
        }
        $codigo = (string) ($m['cliente'] ?? '');
        if (!kt_codigo_valido($codigo) || !preg_match('/^[a-f0-9]{24}$/', (string) ($m['paquete_id'] ?? ''))) {
            throw new RuntimeException('Manifiesto con datos inválidos.');
        }
        foreach (['huella_base', 'huella_local'] as $k) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) ($m[$k] ?? ''))) {
                throw new RuntimeException("Manifiesto sin $k.");
            }
        }

        // Lista blanca de entradas (protección zip-slip)
        $esperados = ['kt_manifest.json' => true, "db/$codigo.db" => true];
        foreach (array_keys($m['archivos'] ?? []) as $ruta) {
            if (!kt_ruta_segura($ruta) || !kt_ext_permitida($ruta)) {
                throw new RuntimeException("Ruta no permitida en el paquete: $ruta");
            }
            $esperados['uploads/' . $ruta] = true;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (substr($nombre, -1) === '/') {
                continue; // carpeta
            }
            if (!isset($esperados[$nombre])) {
                throw new RuntimeException("Entrada inesperada en el paquete: $nombre");
            }
        }

        // BD
        $dbTmp = $tmp . DIRECTORY_SEPARATOR . $codigo . '.db';
        if (!kt_zip_extraer($zip, "db/$codigo.db", $dbTmp)) {
            throw new RuntimeException('El paquete no trae la base de datos.');
        }
        $db = kt_abrir_sqlite($dbTmp);
        if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('La base de datos del paquete está dañada.');
        }
        if (!kt_tabla_existe($db, 'documentos') || kt_estado_get($db, 'cliente') !== $codigo
            || kt_estado_get($db, 'snapshot_id') !== ($m['snapshot_id'] ?? null)) {
            throw new RuntimeException('La base del paquete no corresponde al cliente/corte indicado.');
        }
        if (kt_huella($db) !== $m['huella_local']) {
            throw new RuntimeException('La base del paquete no coincide con su manifiesto (alterada o incompleta).');
        }
        $db = null;

        // Archivos
        $archivos = [];
        foreach ($m['archivos'] ?? [] as $ruta => $info) {
            $destino = $tmp . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
            @mkdir(dirname($destino), 0700, true);
            if (!kt_zip_extraer($zip, 'uploads/' . $ruta, $destino)
                || !hash_equals((string) ($info['sha256'] ?? ''), hash_file('sha256', $destino))) {
                throw new RuntimeException("Archivo dañado o alterado en el paquete: $ruta");
            }
            $archivos[$ruta] = $destino;
        }

        return ['manifiesto' => $m, 'db' => $dbTmp, 'archivos' => $archivos];
    } finally {
        $zip->close();
    }
}

// ---------------------------------------------------------------------------
// 4. PREVISUALIZAR y APLICAR (lado nube)
// ---------------------------------------------------------------------------

function kt_registro_ruta(string $clientsDir, string $codigo): string
{
    return $clientsDir . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . '_sync' . DIRECTORY_SEPARATOR . 'registro.jsonl';
}

function kt_registro_leer(string $clientsDir, string $codigo): array
{
    $f = kt_registro_ruta($clientsDir, $codigo);
    if (!is_file($f)) {
        return [];
    }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $r = json_decode($l, true);
        if (is_array($r)) {
            $out[] = $r;
        }
    }
    return $out;
}

function kt_registro_agregar(string $clientsDir, string $codigo, array $entrada): void
{
    $f = kt_registro_ruta($clientsDir, $codigo);
    @mkdir(dirname($f), 0770, true);
    file_put_contents($f, json_encode($entrada, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Decide qué pasaría al aplicar el paquete, sin modificar nada.
 *
 * @return array{modo: string, motivo: string, informe: array}
 *   modo: 'reemplazo' | 'fusion' | 'ya_aplicado'
 */
function kt_previsualizar_sync(string $clientsDir, array $paquete): array
{
    $m = $paquete['manifiesto'];
    $codigo = $m['cliente'];
    $dbNube = $clientsDir . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . $codigo . '.db';
    if (!is_file($dbNube)) {
        throw new RuntimeException("El cliente '$codigo' no tiene base en este servidor.");
    }
    foreach (kt_registro_leer($clientsDir, $codigo) as $r) {
        if (($r['paquete_id'] ?? '') === $m['paquete_id'] && ($r['resultado'] ?? '') === 'aplicado') {
            return ['modo' => 'ya_aplicado', 'motivo' => 'Este paquete ya se aplicó el ' . $r['fecha'], 'informe' => []];
        }
    }

    $nube = kt_abrir_sqlite($dbNube);
    $huellaNube = kt_huella($nube);
    if ($huellaNube === $m['huella_local']) {
        return ['modo' => 'ya_aplicado', 'motivo' => 'La nube ya tiene exactamente estos datos.', 'informe' => []];
    }
    $local = kt_abrir_sqlite($paquete['db']);
    if ($huellaNube === $m['huella_base']) {
        $res = kt_resumen_cambios($local);
        return [
            'modo' => 'reemplazo',
            'motivo' => 'La nube no cambió desde que se sacó la copia local: se reemplaza por la versión del PC.',
            'informe' => [
                'docs_nuevos' => count($res['documentos']['nuevos']),
                'docs_modificados' => count($res['documentos']['modificados']),
                'docs_eliminados' => count($res['documentos']['eliminados']),
                'codigos_nuevos' => count($res['codigos']['nuevos']),
                'archivos' => count($paquete['archivos']),
            ],
        ];
    }

    // Fusión: simular dentro de una transacción y deshacer
    $nube->exec('BEGIN IMMEDIATE');
    try {
        $informe = kt_fusionar($nube, $local, $paquete, dirname($dbNube) . DIRECTORY_SEPARATOR . 'uploads');
    } finally {
        $nube->exec('ROLLBACK');
    }
    unset($informe['copias']);
    return [
        'modo' => 'fusion',
        'motivo' => 'La nube también cambió desde la copia local: solo se agregan altas; lo modificado/borrado en el PC queda como conflicto.',
        'informe' => $informe,
    ];
}

/**
 * Agrega a la BD de la nube las altas hechas en local (documentos, códigos, vínculos).
 * Debe llamarse dentro de una transacción abierta. No copia archivos: devuelve en
 * 'copias' la lista origen→destino para que el llamador los copie antes del COMMIT.
 */
function kt_fusionar(PDO $nube, PDO $local, array $paquete, string $uploadsNube): array
{
    $res = kt_resumen_cambios($local);
    $informe = [
        'docs_insertados' => 0, 'docs_duplicados' => 0,
        'codigos_insertados' => 0, 'vinculos_insertados' => 0,
        'conflictos' => [], 'copias' => [],
    ];
    $mapa = [];
    $nuevosDoc = array_flip($res['documentos']['nuevos']);

    // Resuelve el id en la nube de un documento local (nuevo → mapa; previo → mismo id si sigue existiendo)
    $existeNube = $nube->prepare('SELECT 1 FROM documentos WHERE id = ?');
    $idNube = function (int $idLocal) use (&$mapa, $nuevosDoc, $existeNube): ?int {
        if (isset($mapa[$idLocal])) {
            return $mapa[$idLocal];
        }
        if (isset($nuevosDoc[$idLocal])) {
            return null;
        }
        $existeNube->execute([$idLocal]);
        return $existeNube->fetchColumn() ? $idLocal : null;
    };

    // --- Documentos nuevos ---
    $cols = array_values(array_diff(array_intersect(kt_columnas($local, 'documentos'), kt_columnas($nube, 'documentos')), ['id']));
    $ins = $nube->prepare('INSERT INTO documentos (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $porHash = $nube->prepare("SELECT id FROM documentos WHERE hash_archivo = ? AND hash_archivo <> '' LIMIT 1");
    $porRuta = $nube->prepare("SELECT id FROM documentos WHERE ruta_archivo = ? AND ruta_archivo NOT IN ('', 'pending') LIMIT 1");
    $leer = $local->prepare('SELECT * FROM documentos WHERE id = ?');

    foreach ($res['documentos']['nuevos'] as $idLocal) {
        $leer->execute([$idLocal]);
        $fila = $leer->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            continue;
        }
        $dup = false;
        if (!empty($fila['hash_archivo'])) {
            $porHash->execute([$fila['hash_archivo']]);
            $dup = $porHash->fetchColumn();
        }
        if (!$dup) {
            $porRuta->execute([$fila['ruta_archivo']]);
            $dup = $porRuta->fetchColumn();
        }
        if ($dup) {
            $mapa[$idLocal] = (int) $dup;
            $informe['docs_duplicados']++;
            continue;
        }

        $ruta = str_replace('\\', '/', (string) $fila['ruta_archivo']);
        if (isset($paquete['archivos'][$ruta])) {
            $origen = $paquete['archivos'][$ruta];
            $destino = $uploadsNube . '/' . $ruta;
            // Si ya existe un archivo distinto con ese nombre en la nube, usar otro nombre
            if (is_file($destino) && hash_file('sha256', $destino) !== hash_file('sha256', $origen)) {
                $info = pathinfo($ruta);
                $base = ($info['dirname'] !== '.' ? $info['dirname'] . '/' : '') . $info['filename'];
                $n = 1;
                do {
                    $ruta = $base . '_local' . $n . '.' . $info['extension'];
                    $n++;
                } while (is_file($uploadsNube . '/' . $ruta));
                $fila['ruta_archivo'] = $ruta;
                $destino = $uploadsNube . '/' . $ruta;
            }
            $informe['copias'][] = ['origen' => $origen, 'destino' => $destino];
        }
        $ins->execute(array_map(fn($c) => $fila[$c], $cols));
        $mapa[$idLocal] = (int) $nube->lastInsertId();
        $informe['docs_insertados']++;
    }

    // --- Códigos nuevos (de documentos nuevos o previos) ---
    if (kt_tabla_existe($nube, 'codigos') && kt_tabla_existe($local, 'codigos')) {
        $colsC = array_values(array_diff(array_intersect(kt_columnas($local, 'codigos'), kt_columnas($nube, 'codigos')), ['id', 'documento_id']));
        $insC = $nube->prepare('INSERT INTO codigos (documento_id,' . implode(',', $colsC) . ') VALUES (?' . str_repeat(',?', count($colsC)) . ')');
        $hayC = $nube->prepare('SELECT 1 FROM codigos WHERE documento_id = ? AND codigo = ?');
        $leerC = $local->prepare('SELECT * FROM codigos WHERE id = ?');
        foreach ($res['codigos']['nuevos'] as $id) {
            $leerC->execute([$id]);
            $c = $leerC->fetch(PDO::FETCH_ASSOC);
            if (!$c) {
                continue;
            }
            $doc = $idNube((int) $c['documento_id']);
            if ($doc === null) {
                $informe['conflictos'][] = "Código '{$c['codigo']}' no se agregó: su documento ya no existe en la nube.";
                continue;
            }
            $hayC->execute([$doc, $c['codigo']]);
            if ($hayC->fetchColumn()) {
                continue;
            }
            $insC->execute(array_merge([$doc], array_map(fn($k) => $c[$k], $colsC)));
            $informe['codigos_insertados']++;
        }
    }

    // --- Vínculos nuevos ---
    if (kt_tabla_existe($nube, 'vinculos') && kt_tabla_existe($local, 'vinculos')) {
        $colsV = array_values(array_diff(array_intersect(kt_columnas($local, 'vinculos'), kt_columnas($nube, 'vinculos')),
            ['id', 'documento_origen_id', 'documento_destino_id']));
        $insV = $nube->prepare('INSERT INTO vinculos (documento_origen_id, documento_destino_id' . ($colsV ? ',' . implode(',', $colsV) : '')
            . ') VALUES (?,?' . str_repeat(',?', count($colsV)) . ')');
        $hayV = $nube->prepare('SELECT 1 FROM vinculos WHERE documento_origen_id = ? AND documento_destino_id = ? AND tipo_vinculo = ?');
        $leerV = $local->prepare('SELECT * FROM vinculos WHERE id = ?');
        foreach ($res['vinculos']['nuevos'] as $id) {
            $leerV->execute([$id]);
            $v = $leerV->fetch(PDO::FETCH_ASSOC);
            if (!$v) {
                continue;
            }
            $o = $idNube((int) $v['documento_origen_id']);
            $d = $idNube((int) $v['documento_destino_id']);
            if ($o === null || $d === null) {
                $informe['conflictos'][] = "Vínculo #{$id} no se agregó: uno de sus documentos ya no existe en la nube.";
                continue;
            }
            $hayV->execute([$o, $d, $v['tipo_vinculo'] ?? '']);
            if ($hayV->fetchColumn()) {
                continue;
            }
            $insV->execute(array_merge([$o, $d], array_map(fn($k) => $v[$k], $colsV)));
            $informe['vinculos_insertados']++;
        }
    }

    // --- Lo que NO se aplica en fusión ---
    $leerNum = $local->prepare('SELECT numero FROM documentos WHERE id = ?');
    foreach ($res['documentos']['modificados'] as $id) {
        $leerNum->execute([$id]);
        $informe['conflictos'][] = "Documento #$id (" . ($leerNum->fetchColumn() ?: 's/n') . ') fue modificado en el PC: revisar a mano en la nube.';
    }
    foreach ($res['documentos']['eliminados'] as $id) {
        $informe['conflictos'][] = "Documento #$id fue eliminado en el PC: no se borra en la nube (revisar a mano).";
    }
    $nMod = count($res['codigos']['modificados']) + count($res['codigos']['eliminados']);
    if ($nMod) {
        $informe['conflictos'][] = "$nMod código(s) de documentos existentes se modificaron o borraron en el PC: no se aplican.";
    }
    return $informe;
}

/** Respaldo consistente de la BD del cliente antes de tocarla. Devuelve la carpeta del respaldo. */
function kt_respaldar_cliente(string $clientsDir, string $codigo, string $etiqueta): string
{
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $dir = $dirCliente . DIRECTORY_SEPARATOR . '_respaldos' . DIRECTORY_SEPARATOR
        . gmdate('Ymd_His') . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($etiqueta));
    if (!mkdir($dir, 0770, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
    }
    kt_copia_consistente($dirCliente . DIRECTORY_SEPARATOR . $codigo . '.db', $dir . DIRECTORY_SEPARATOR . $codigo . '.db');
    return $dir;
}

/**
 * Reemplaza el archivo de BD de forma segura con WAL: vacía el WAL actual,
 * mueve el nuevo archivo y elimina -wal/-shm viejos (si quedaran, SQLite podría
 * aplicarlos sobre la base nueva y dañarla).
 */
function kt_reemplazar_bd(string $dbDestino, string $dbNueva): void
{
    $db = kt_abrir_sqlite($dbDestino);
    $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
    $db = null;
    $intermedio = $dbDestino . '.nuevo';
    if (!copy($dbNueva, $intermedio)) {
        throw new RuntimeException('No se pudo preparar la base nueva.');
    }
    if (!rename($intermedio, $dbDestino)) {
        @unlink($intermedio);
        throw new RuntimeException('No se pudo reemplazar la base.');
    }
    @unlink($dbDestino . '-wal');
    @unlink($dbDestino . '-shm');
}

/**
 * Aplica un paquete ya validado. Siempre respalda antes.
 *
 * @param string|null $modoConfirmado Si se indica, debe coincidir con lo que da la
 *        previsualización en este momento (evita aplicar algo distinto a lo revisado).
 */
function kt_aplicar_sync(string $clientsDir, array $paquete, ?string $modoConfirmado = null, string $quien = ''): array
{
    $m = $paquete['manifiesto'];
    $codigo = $m['cliente'];
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $dbNube = $dirCliente . DIRECTORY_SEPARATOR . $codigo . '.db';

    $lock = fopen($dirCliente . DIRECTORY_SEPARATOR . '.kt_sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Ya hay una sincronización en curso para este cliente.');
    }
    try {
        $prev = kt_previsualizar_sync($clientsDir, $paquete);
        if ($prev['modo'] === 'ya_aplicado') {
            return $prev + ['respaldo' => null];
        }
        if ($modoConfirmado !== null && $modoConfirmado !== $prev['modo']) {
            throw new RuntimeException("La situación cambió (ahora sería '{$prev['modo']}'). Revise de nuevo antes de aplicar.");
        }
        $respaldo = kt_respaldar_cliente($clientsDir, $codigo, 'antes_sync_' . $m['paquete_id']);
        $uploads = $dirCliente . DIRECTORY_SEPARATOR . 'uploads';

        if ($prev['modo'] === 'reemplazo') {
            // Copiar archivos (guardando en el respaldo cualquier archivo que se sobrescriba)
            foreach ($paquete['archivos'] as $ruta => $origen) {
                $destino = $uploads . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
                if (is_file($destino)) {
                    if (hash_file('sha256', $destino) === hash_file('sha256', $origen)) {
                        continue;
                    }
                    $bk = $respaldo . DIRECTORY_SEPARATOR . 'archivos' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
                    @mkdir(dirname($bk), 0770, true);
                    copy($destino, $bk);
                }
                @mkdir(dirname($destino), 0775, true);
                if (!copy($origen, $destino)) {
                    throw new RuntimeException("No se pudo copiar $ruta (la base no se tocó).");
                }
            }
            // BD: quitar rastreo y reemplazar
            $limpia = dirname($paquete['db']) . DIRECTORY_SEPARATOR . 'limpia.db';
            @unlink($limpia);
            $l = kt_abrir_sqlite($paquete['db']);
            kt_quitar_rastreo($l);
            $l->exec('VACUUM INTO ' . $l->quote($limpia));
            $l = null;
            kt_reemplazar_bd($dbNube, $limpia);
            $informe = $prev['informe'];
        } else {
            $nube = kt_abrir_sqlite($dbNube);
            $local = kt_abrir_sqlite($paquete['db']);
            $nube->exec('BEGIN IMMEDIATE');
            $copiados = [];
            try {
                $informe = kt_fusionar($nube, $local, $paquete, $uploads);
                foreach ($informe['copias'] as $c) {
                    @mkdir(dirname($c['destino']), 0775, true);
                    if (!copy($c['origen'], $c['destino'])) {
                        throw new RuntimeException('No se pudo copiar ' . basename($c['destino']));
                    }
                    $copiados[] = $c['destino'];
                }
                $nube->exec('COMMIT');
            } catch (Throwable $e) {
                $nube->exec('ROLLBACK');
                foreach ($copiados as $f) {
                    @unlink($f);
                }
                throw $e;
            }
            unset($informe['copias']);
        }

        kt_registro_agregar($clientsDir, $codigo, [
            'fecha' => kt_ahora(),
            'paquete_id' => $m['paquete_id'],
            'modo' => $prev['modo'],
            'resultado' => 'aplicado',
            'licencia_id' => $m['licencia_id'] ?? null,
            'equipo' => $m['equipo'] ?? null,
            'respaldo' => basename($respaldo),
            'por' => $quien,
            'informe' => $informe,
        ]);
        return ['modo' => $prev['modo'], 'motivo' => $prev['motivo'], 'informe' => $informe, 'respaldo' => basename($respaldo)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function kt_listar_respaldos(string $clientsDir, string $codigo): array
{
    $dir = $clientsDir . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . '_respaldos';
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
        if (is_file($d . DIRECTORY_SEPARATOR . $codigo . '.db')) {
            $out[] = basename($d);
        }
    }
    rsort($out);
    return $out;
}

/** Vuelve la BD del cliente a un respaldo (respaldando primero el estado actual). */
function kt_restaurar_respaldo(string $clientsDir, string $codigo, string $nombre, string $quien = ''): string
{
    if (!preg_match('/^[0-9]{8}_[0-9]{6}_[a-z0-9_]+$/', $nombre)) {
        throw new InvalidArgumentException('Nombre de respaldo inválido.');
    }
    $dirCliente = $clientsDir . DIRECTORY_SEPARATOR . $codigo;
    $origen = $dirCliente . DIRECTORY_SEPARATOR . '_respaldos' . DIRECTORY_SEPARATOR . $nombre . DIRECTORY_SEPARATOR . $codigo . '.db';
    if (!is_file($origen)) {
        throw new RuntimeException('El respaldo no existe.');
    }
    $actual = kt_respaldar_cliente($clientsDir, $codigo, 'antes_restaurar');
    kt_reemplazar_bd($dirCliente . DIRECTORY_SEPARATOR . $codigo . '.db', $origen);
    kt_registro_agregar($clientsDir, $codigo, [
        'fecha' => kt_ahora(), 'paquete_id' => null, 'modo' => 'restauracion',
        'resultado' => 'aplicado', 'respaldo' => $nombre, 'respaldo_previo' => basename($actual), 'por' => $quien,
    ]);
    return basename($actual);
}
