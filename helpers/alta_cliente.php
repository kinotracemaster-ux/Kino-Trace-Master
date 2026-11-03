<?php
/**
 * Alta de un cliente en UN paso y su verificación.
 *
 * kt_alta_cliente() crea todo lo que un cliente necesita para entrar el mismo día:
 * fila en central.db (contraseña, correo, marca), carpeta, base con el esquema al día,
 * subdominio y funciones activas. Antes de crear nada valida que el código y el
 * subdominio no choquen con otro cliente y que no exista una carpeta vieja con
 * datos (si existía, el cliente nuevo heredaba la base de otro). Si algo falla a
 * mitad, deshace lo creado: no quedan clientes a medias.
 *
 * kt_verificar_cliente() revisa que el cliente funcione de verdad (la usa el alta
 * y el panel admin): base al día, carpeta escribible, contraseña, subdominio que
 * apunta a él, sin marca ni datos de otro cliente.
 */

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/subdomain.php';
require_once __DIR__ . '/funciones.php';

/** Subdominios que no se pueden usar para un cliente. */
const KT_SUBDOMINIOS_RESERVADOS = ['admin', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'cdn', 'static', 'pruebas', 'test', 'soporte'];

/** Limpia un subdominio: minúsculas, letras, números y guiones (sin guion al inicio o final). */
function kt_subdominio_limpio(string $sub): string
{
    return trim(preg_replace('/[^a-z0-9-]+/', '', strtolower(trim($sub))), '-');
}

/**
 * ¿Puede $codigo usar el subdominio $sub? Devuelve null si sí, o el motivo si no.
 * Un subdominio choca con el `subdominio` de otro cliente y también con el CÓDIGO
 * de otro (resolveClientCode usa el código cuando no hay subdominio propio).
 */
function kt_subdominio_problema(PDO $centralDb, string $sub, string $codigo): ?string
{
    if ($sub === '' || strlen($sub) < 2 || strlen($sub) > 40) {
        return 'El subdominio debe tener entre 2 y 40 letras, números o guiones.';
    }
    if (in_array($sub, KT_SUBDOMINIOS_RESERVADOS, true)) {
        return "El subdominio «{$sub}» está reservado.";
    }
    $st = $centralDb->prepare('SELECT codigo FROM control_clientes
        WHERE codigo <> ? AND (subdominio = ? OR (codigo = ? AND (subdominio IS NULL OR subdominio = \'\')))');
    $st->execute([$codigo, $sub, $sub]);
    $otro = $st->fetchColumn();
    return $otro ? "El subdominio «{$sub}» ya lo usa el cliente «{$otro}»." : null;
}

/**
 * Crea un cliente completo. $d: codigo, nombre, password, email, titulo,
 * color_primario, color_secundario, subdominio (vacío = el código), funciones
 * (lista de claves de KT_FUNCIONES activas; si no se pasa, todas).
 *
 * @return array{ok:bool, codigo:string, error:?string, chequeos:array, ms:int}
 */
function kt_alta_cliente(array $d): array
{
    global $centralDb;
    $t0 = microtime(true);
    $codigo = sanitize_code((string) ($d['codigo'] ?? ''));
    $nombre = trim((string) ($d['nombre'] ?? ''));
    $password = (string) ($d['password'] ?? '');
    $email = trim((string) ($d['email'] ?? ''));
    $titulo = trim((string) ($d['titulo'] ?? '')) ?: $nombre;
    $colorP = preg_match('/^#[0-9a-f]{6}$/i', (string) ($d['color_primario'] ?? '')) ? $d['color_primario'] : '#3b82f6';
    $colorS = preg_match('/^#[0-9a-f]{6}$/i', (string) ($d['color_secundario'] ?? '')) ? $d['color_secundario'] : '#64748b';
    $sub = kt_subdominio_limpio((string) ($d['subdominio'] ?? '')) ?: $codigo;
    $funciones = isset($d['funciones']) ? array_values(array_intersect((array) $d['funciones'], array_keys(KT_FUNCIONES)))
        : array_keys(KT_FUNCIONES);
    $falla = fn(string $e) => ['ok' => false, 'codigo' => $codigo, 'error' => $e, 'chequeos' => [],
        'ms' => (int) round((microtime(true) - $t0) * 1000)];

    // --- 1. Validar (antes de tocar nada)
    if ($codigo === '' || strlen($codigo) < 2 || strlen($codigo) > 30) {
        return $falla('El código debe tener entre 2 y 30 letras, números o guion bajo.');
    }
    if ($codigo === 'admin' || in_array($codigo, KT_SUBDOMINIOS_RESERVADOS, true)) {
        return $falla("El código «{$codigo}» está reservado.");
    }
    if ($nombre === '') {
        return $falla('Falta el nombre del cliente.');
    }
    if (strlen($password) < 6) {
        return $falla('La contraseña debe tener al menos 6 caracteres.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $falla('El correo no es válido.');
    }
    $st = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
    $st->execute([$codigo]);
    if ((int) $st->fetchColumn() > 0) {
        return $falla("Ya existe un cliente con el código «{$codigo}».");
    }
    if ($email !== '') {
        $st = $centralDb->prepare('SELECT codigo FROM control_clientes WHERE lower(email) = lower(?)');
        $st->execute([$email]);
        if ($otro = $st->fetchColumn()) {
            return $falla("El correo ya lo usa el cliente «{$otro}» (el inicio de sesión por correo no sabría a quién abrir).");
        }
    }
    $dir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $codigo;
    if (file_exists($dir)) {
        return $falla("Ya existe la carpeta clients/{$codigo} (restos de un cliente anterior). El cliente nuevo heredaría esos datos: "
            . 'restaure o borre esa carpeta primero, o use otro código.');
    }
    if (is_file(BASE_DIR . "/database_initial/{$codigo}/{$codigo}.db")) {
        return $falla("El código «{$codigo}» es de una plantilla con datos de ejemplo: use otro código.");
    }
    if ($p = kt_subdominio_problema($centralDb, $sub, $codigo)) {
        return $falla($p);
    }

    // --- 2. Crear (si algo falla, deshacer todo)
    try {
        create_client_structure($codigo, $nombre, password_hash($password, PASSWORD_DEFAULT), $titulo, $colorP, $colorS, $email);
        $centralDb->prepare('UPDATE control_clientes SET subdominio = ?, password_plain = ? WHERE codigo = ?')
            ->execute([$sub === $codigo ? null : $sub, $password, $codigo]);
        kt_funciones_guardar($centralDb, $codigo, $funciones);
    } catch (Throwable $e) {
        kt_alta_deshacer($codigo);
        return $falla('No se pudo crear el cliente (no quedó nada a medias): ' . $e->getMessage());
    }

    // --- 3. Verificar
    $chequeos = kt_verificar_cliente($codigo, $password);
    $ok = !in_array(false, array_column($chequeos, 'ok'), true);
    return ['ok' => $ok, 'codigo' => $codigo, 'error' => $ok ? null : 'El cliente se creó pero no pasó todas las verificaciones.',
        'chequeos' => $chequeos, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
}

/** Borra lo que creó un alta fallida: fila en central.db, funciones y carpeta. */
function kt_alta_deshacer(string $codigo): void
{
    global $centralDb;
    $codigo = sanitize_code($codigo);
    if ($codigo === '' || $codigo === 'admin') {
        return;
    }
    foreach (['control_clientes', 'funciones_cliente', 'pagina_publica'] as $tabla) {
        try {
            $centralDb->prepare("DELETE FROM {$tabla} WHERE codigo = ?")->execute([$codigo]);
        } catch (Throwable $e) {
        }
    }
    $dir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $codigo;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

/**
 * Revisa que un cliente funcione. Cada chequeo: ['que' => texto, 'ok' => bool, 'detalle' => texto].
 * Si se pasa $password, también comprueba que esa contraseña abra la cuenta.
 */
function kt_verificar_cliente(string $codigo, ?string $password = null): array
{
    global $centralDb;
    $codigo = sanitize_code($codigo);
    $r = [];
    $add = function (string $que, bool $ok, string $detalle = '') use (&$r) {
        $r[] = ['que' => $que, 'ok' => $ok, 'detalle' => $detalle];
    };

    $st = $centralDb->prepare('SELECT * FROM control_clientes WHERE codigo = ?');
    $st->execute([$codigo]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    $add('Registrado en central.db y activo', $fila && (int) $fila['activo'] === 1);
    if (!$fila) {
        return $r;
    }

    if ($password !== null) {
        $add('La contraseña abre la cuenta', password_verify($password, (string) $fila['password_hash']));
    }

    $docs = null;
    try {
        $db = open_client_db($codigo);
        $v = (int) $db->query('PRAGMA user_version')->fetchColumn();
        $add('Base de datos con el esquema al día', $v >= client_schema_version(), "v{$v}");
        $docs = (int) $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
        $add('Base sana (quick_check)', (string) $db->query('PRAGMA quick_check')->fetchColumn() === 'ok');
        $db = null;
    } catch (Throwable $e) {
        $add('Base de datos con el esquema al día', false, $e->getMessage());
    }

    $up = CLIENTS_DIR . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . 'uploads';
    $add('Carpeta de documentos lista para subir PDFs', is_dir($up) && is_writable($up));

    $sub = (string) ($fila['subdominio'] ?: $codigo);
    $resuelto = resolveClientCode($sub);
    $add('El subdominio abre este cliente', $resuelto === $codigo, $sub . '.' . APP_BASE_DOMAIN
        . ($resuelto && $resuelto !== $codigo ? " (abre «{$resuelto}»)" : ''));

    $titulo = trim((string) ($fila['titulo'] ?? ''));
    $st = $centralDb->prepare('SELECT codigo FROM control_clientes WHERE codigo <> ? AND lower(trim(titulo)) = lower(?) AND trim(titulo) <> \'\'');
    $st->execute([$codigo, $titulo]);
    $otros = $st->fetchAll(PDO::FETCH_COLUMN);
    $add('Su marca no es la de otro cliente', !$otros, $otros ? 'mismo título que ' . implode(', ', $otros) : $titulo);

    $ajenas = [];
    foreach (glob(CLIENTS_DIR . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . '*.db') ?: [] as $f) {
        if (basename($f) !== $codigo . '.db') {
            $ajenas[] = basename($f);
        }
    }
    $add('Sin bases de otros clientes en su carpeta', !$ajenas, implode(', ', $ajenas));

    $apagadas = array_keys(array_filter(kt_funciones_cliente($codigo), fn($on) => !$on));
    $add('Funciones', true, $apagadas ? 'apagadas: ' . implode(', ', array_map(fn($f) => KT_FUNCIONES[$f]['nombre'], $apagadas)) : 'todas activas');
    if ($docs !== null) {
        $add('Documentos en su base', true, number_format($docs));
    }
    return $r;
}
