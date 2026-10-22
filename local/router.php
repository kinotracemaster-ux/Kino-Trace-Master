<?php
/**
 * local/router.php — Enrutador del servidor integrado de PHP en MODO LOCAL.
 *
 *     php -S 127.0.0.1:8765 -t <app> <app>/local/router.php
 *
 * php -S NO lee .htaccess, así que aquí se replican (y endurecen) esas reglas:
 *   - Solo atiende peticiones de este equipo con Host local.
 *   - Bloquea bases de datos, respaldos, herramientas, documentación y código interno.
 *   - Los PDFs y el logo del cliente se sirven SOLO con sesión iniciada de ese
 *     cliente y licencia válida (en la nube hoy se pueden abrir con el enlace).
 *   - Todo lo demás (.php, css, js, imágenes) lo resuelve el servidor normal.
 */

$ktRaiz = dirname(__DIR__);

function kt_router_cortar(int $codigo, string $texto): bool
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $texto;
    return true;
}

// 1. Solo este equipo (IP y Host)
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (!in_array($ip, ['127.0.0.1', '::1'], true) || !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) {
    return kt_router_cortar(403, 'Acceso permitido solo desde este equipo.');
}

// 2. Normalizar la ruta pedida
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (strpos($uri, "\0") !== false) {
    return kt_router_cortar(400, 'Solicitud inválida.');
}
$rel = ltrim(str_replace('\\', '/', $uri), '/');
// Windows ignora puntos y espacios al final de cada carpeta ("Admin-gestor./" = "Admin-gestor/")
$segs = array_map(fn($s) => rtrim($s, '. '), explode('/', $rel));
if (in_array('..', $segs, true)) {
    return kt_router_cortar(400, 'Solicitud inválida.');
}
$relN = strtolower(implode('/', $segs));

// 3. Carpetas y archivos que nunca se sirven
$prohibidos = ['admin-gestor', 'local/herramientas', 'local/construir', 'database_initial', 'backups', 'tests',
    'docs', 'vendor', 'src', 'helpers', 'includes', 'runtime', 'sync_recibir.php', 'local/modo_local'];
foreach ($prohibidos as $p) {
    if ($relN === $p || strpos($relN, $p . '/') === 0) {
        return kt_router_cortar(404, 'No encontrado.');
    }
}
foreach ($segs as $s) {
    if ($s !== '' && $s[0] === '.') {
        return kt_router_cortar(404, 'No encontrado.'); // .git, .env, local/.reloj ...
    }
}
if ($relN !== 'pwa/manifest.json'
    && preg_match('/\.(db|db-wal|db-shm|db-journal|sqlite|sql|zip|ktdata|ktsync|env|log|json|md|txt|csv|lock|ps1|bat|cmd|sh|ini|xml|dist|toml|bak|pem)$/', $relN)) {
    return kt_router_cortar(404, 'No encontrado.');
}

// 4. Archivos del cliente: solo con sesión de ese cliente y licencia válida
if (strpos($relN, 'clients/') === 0) {
    if (!preg_match('#^clients/([a-z0-9_]+)/(uploads/.+\.(pdf|png|jpe?g|gif|webp|tiff?)|logo\.(png|jpe?g|gif))$#', $relN, $m)) {
        return kt_router_cortar(404, 'No encontrado.');
    }
    require_once __DIR__ . '/licencia.php';
    if (!kt_lic_estado()['valida']) {
        return kt_router_cortar(403, 'Licencia no válida.');
    }
    require_once $ktRaiz . '/helpers/auth.php';
    if (!is_logged_in() || strtolower((string) $_SESSION['client_code']) !== $m[1]) {
        return kt_router_cortar(403, 'Inicie sesión para ver este archivo.');
    }
    $dirCliente = realpath($ktRaiz . '/clients/' . $m[1]);
    $archivo = realpath($ktRaiz . '/' . $rel);
    if ($dirCliente === false || $archivo === false || !is_file($archivo)
        || strpos(str_replace('\\', '/', $archivo), str_replace('\\', '/', $dirCliente) . '/') !== 0) {
        return kt_router_cortar(404, 'No encontrado.');
    }
    $tipos = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'];
    header('Content-Type: ' . ($tipos[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($archivo));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    return true;
}

// 5. Cualquier archivo existente debe estar dentro de la app (enlaces simbólicos, nombres cortos de Windows...)
$fs = realpath($ktRaiz . '/' . $rel);
if ($fs !== false) {
    $raizN = str_replace('\\', '/', realpath($ktRaiz));
    $fsN = str_replace('\\', '/', $fs);
    if ($fsN !== $raizN && strpos($fsN, $raizN . '/') !== 0) {
        return kt_router_cortar(404, 'No encontrado.');
    }
}

return false; // el servidor integrado sirve el archivo o ejecuta el .php
