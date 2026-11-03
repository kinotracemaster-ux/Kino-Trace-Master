<?php
/**
 * Funciones que se prenden o apagan por cliente (desde el panel admin).
 *
 * Todo cliente tiene todas las funciones activas salvo que el admin apague alguna:
 * en central.db (tabla funciones_cliente) solo se guardan las que cambian.
 * La regla se aplica en el SERVIDOR (kt_guardia_funciones), no solo ocultando
 * el enlace del menú: una función apagada responde 403 aunque se escriba la URL.
 *
 * Para agregar una función nueva: sumarla a KT_FUNCIONES con sus rutas y/o
 * acciones de api.php. Nada más (el panel y la guardia la toman de aquí).
 */

const KT_FUNCIONES = [
    'buscador_publico' => [
        'nombre' => 'Buscador público',
        'detalle' => 'Página pública donde cualquiera busca por código sin iniciar sesión.',
        'rutas' => ['modules/Buscador/'],
        'acciones' => [],
    ],
    'subir' => [
        'nombre' => 'Subir documentos',
        'detalle' => 'Subir PDFs uno a uno o en un ZIP.',
        'rutas' => ['modules/subir/', 'modules/lote/'],
        'acciones' => ['upload', 'update', 'extract_codes'],
    ],
    'trazabilidad' => [
        'nombre' => 'Trazabilidad',
        'detalle' => 'Vincular y validar manifiestos contra declaraciones.',
        'rutas' => ['modules/trazabilidad/'],
        'acciones' => [],
    ],
    'importar' => [
        'nombre' => 'Importar y mantener datos',
        'detalle' => 'Excel, SQL, sincronizar, indexar y restaurar desde el menú Administrador.',
        'rutas' => ['modules/excel_import/', 'modules/importar/', 'modules/importar_datos/', 'modules/sincronizar/',
            'modules/indexar/', 'modules/restaurar_sistema/', 'modules/mantenimiento/'],
        'acciones' => ['reindex_documents'],
    ],
    'ia' => [
        'nombre' => 'Asistente IA (Gemini)',
        'detalle' => 'Extracción y chat con inteligencia artificial.',
        'rutas' => [],
        'acciones' => ['ai_extract', 'ai_chat', 'smart_chat'],
    ],
];

/** Crea la tabla si falta (la migración 2 de central.db también la crea). */
function kt_funciones_tabla(PDO $centralDb): void
{
    $centralDb->exec('CREATE TABLE IF NOT EXISTS funciones_cliente (
        codigo TEXT NOT NULL,
        funcion TEXT NOT NULL,
        activa INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY (codigo, funcion)
    )');
}

/**
 * Estado de cada función para un cliente: ['subir' => true, 'ia' => false, ...].
 * Si algo falla al leer, todo queda activo (nunca dejar a un cliente sin su app
 * por un error de lectura).
 */
function kt_funciones_cliente(string $codigo): array
{
    $estado = array_fill_keys(array_keys(KT_FUNCIONES), true);
    global $centralDb;
    if ($centralDb instanceof PDO) {
        try {
            $st = $centralDb->prepare('SELECT funcion, activa FROM funciones_cliente WHERE codigo = ?');
            $st->execute([$codigo]);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $f => $activa) {
                if (isset($estado[$f])) {
                    $estado[$f] = (bool) $activa;
                }
            }
        } catch (Throwable $e) {
            // tabla aún no creada: todo activo
        }
    }
    return $estado;
}

function kt_funcion_activa(string $codigo, string $funcion): bool
{
    return kt_funciones_cliente($codigo)[$funcion] ?? true;
}

/**
 * Guarda qué funciones tiene el cliente. $activas: lista de claves de KT_FUNCIONES
 * que quedan prendidas; las demás se apagan.
 */
function kt_funciones_guardar(PDO $centralDb, string $codigo, array $activas): void
{
    kt_funciones_tabla($centralDb);
    $st = $centralDb->prepare('INSERT INTO funciones_cliente (codigo, funcion, activa) VALUES (?, ?, ?)
        ON CONFLICT(codigo, funcion) DO UPDATE SET activa = excluded.activa');
    foreach (array_keys(KT_FUNCIONES) as $f) {
        $st->execute([$codigo, $f, in_array($f, $activas, true) ? 1 : 0]);
    }
}

/** Qué función protege esta ruta (relativa a la raíz de la app) o esta acción de api.php. */
function kt_funcion_de(string $rutaRelativa, string $accion = ''): ?string
{
    $rutaRelativa = ltrim(str_replace('\\', '/', $rutaRelativa), '/');
    foreach (KT_FUNCIONES as $clave => $f) {
        foreach ($f['rutas'] as $prefijo) {
            if (strncasecmp($rutaRelativa, $prefijo, strlen($prefijo)) === 0) {
                return $clave;
            }
        }
        if ($accion !== '' && $rutaRelativa === 'api.php' && in_array($accion, $f['acciones'], true)) {
            return $clave;
        }
    }
    return null;
}

/**
 * Corta la petición (403) si la página o acción pertenece a una función apagada
 * para el cliente con sesión. La llaman config.php y session_init.php (lo que
 * cargue segundo); el buscador público lo revisa validate_public_client().
 * El admin con clave maestra no tiene restricciones.
 */
function kt_guardia_funciones(): void
{
    static $hecho = false;
    if ($hecho || PHP_SAPI === 'cli' || !defined('BASE_DIR')) {
        return;
    }
    $codigo = (string) ($_SESSION['client_code'] ?? '');
    if ($codigo === '' || !empty($_SESSION['is_admin'])) {
        return;
    }
    $hecho = true;

    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $base = realpath(BASE_DIR) ?: BASE_DIR;
    if ($script === '' || strpos($script, $base . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    $rel = substr($script, strlen($base) + 1);
    $accion = is_string($_REQUEST['action'] ?? null) ? $_REQUEST['action'] : '';
    $funcion = kt_funcion_de($rel, $accion);
    if ($funcion === null || kt_funcion_activa($codigo, $funcion)) {
        return;
    }
    kt_funcion_negada($funcion, $rel === 'api.php' || str_ends_with($rel, 'process.php') || str_starts_with($rel, 'modules/Buscador/api'));
}

function kt_funcion_negada(string $funcion, bool $json): void
{
    $nombre = KT_FUNCIONES[$funcion]['nombre'] ?? $funcion;
    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => "La función «{$nombre}» no está activa para esta cuenta."], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Función no activa</title></head><body style="font-family:system-ui,sans-serif;text-align:center;padding:3rem 1rem;color:#334155">'
            . '<h2>🔒 ' . htmlspecialchars($nombre) . '</h2><p>Esta función no está activa para esta cuenta.</p>'
            . '<p>Si la necesita, pídala al administrador.</p></body></html>';
    }
    exit;
}
