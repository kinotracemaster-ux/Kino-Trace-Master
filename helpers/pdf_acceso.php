<?php
/**
 * PDFs privados: enlaces firmados que vencen.
 *
 * Antes los PDFs se servían como archivos estáticos (clients/{codigo}/uploads/...): quien tuviera
 * la dirección los bajaba sin sesión, para siempre. Ahora .htaccess bloquea esas rutas y los
 * visores reciben un enlace a pdf.php firmado con HMAC que solo sirve para ESE archivo y vence.
 *
 * - Clave: variable KT_PDF_KEY o, si no está, una aleatoria guardada en clients/.clave_pdf.json
 *   (bloqueada por web). Cambiarla invalida los enlaces vigentes, nada más.
 * - El vencimiento se redondea a la hora: durante esa hora el enlace es el mismo, así el
 *   navegador sigue usando su caché del PDF (el resaltado no se vuelve más lento).
 * - pdf.php además comprueba que el archivo esté dentro de la carpeta de SU cliente
 *   (pdf_path_belongs_to_client) y que sea un PDF.
 *
 * Requiere config.php (CLIENTS_DIR) y helpers/tenant.php.
 */

const KT_PDF_TTL_INTERNO = 12 * 3600;  // visor interno (con sesión)
const KT_PDF_TTL_PUBLICO = 4 * 3600;   // buscador público

function kt_pdf_clave(): string
{
    static $clave = null;
    if ($clave !== null) {
        return $clave;
    }
    $env = (string) getenv('KT_PDF_KEY');
    if (strlen($env) >= 32) {
        return $clave = $env;
    }
    $f = CLIENTS_DIR . DIRECTORY_SEPARATOR . '.clave_pdf.json';
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d) || strlen((string) ($d['clave'] ?? '')) < 32) {
        $d = ['clave' => bin2hex(random_bytes(32)), 'creada' => gmdate('c')];
        // link() falla si el archivo ya existe: si otro proceso la creó a la vez, se usa la suya
        if (@file_put_contents($f . '.tmp', json_encode($d)) !== false && @link($f . '.tmp', $f)) {
            @chmod($f, 0640);
        }
        @unlink($f . '.tmp');
        $leida = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (is_array($leida) && strlen((string) ($leida['clave'] ?? '')) >= 32) {
            $d = $leida;
        }
    }
    return $clave = (string) $d['clave'];
}

/** Ruta del archivo relativa a CLIENTS_DIR (con "/"), o null si está fuera. */
function kt_pdf_ruta_relativa(string $absoluta): ?string
{
    $real = realpath($absoluta);
    $base = realpath(CLIENTS_DIR);
    if ($real === false || $base === false) {
        return null;
    }
    $real = str_replace('\\', '/', $real);
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    return strpos($real, $base) === 0 ? substr($real, strlen($base)) : null;
}

function kt_pdf_firma(string $rel, int $vence): string
{
    return substr(hash_hmac('sha256', $rel . '|' . $vence, kt_pdf_clave()), 0, 32);
}

/**
 * Enlace firmado a un PDF del disco. $base = cómo llegar a la raíz de la app desde la página
 * que lo usa (p. ej. '../../' desde modules/resaltar/). null si el archivo no está en clients/.
 */
function kt_pdf_url(string $absoluta, int $ttl = KT_PDF_TTL_INTERNO, string $base = '../../', bool $descargar = false): ?string
{
    $rel = kt_pdf_ruta_relativa($absoluta);
    if ($rel === null) {
        return null;
    }
    $vence = (int) (ceil((time() + $ttl) / 3600) * 3600);
    return $base . 'pdf.php?' . http_build_query(['r' => $rel, 'e' => $vence, 's' => kt_pdf_firma($rel, $vence)] + ($descargar ? ['d' => 1] : []), '', '&', PHP_QUERY_RFC3986);
}

/**
 * Valida un enlace. Devuelve la ruta absoluta del PDF, o un código HTTP (int) si no vale.
 *
 * @return string|int
 */
function kt_pdf_validar(string $rel, int $vence, string $firma, ?int $ahora = null)
{
    $ahora = $ahora ?? time();
    if ($rel === '' || $firma === '' || !hash_equals(kt_pdf_firma($rel, $vence), $firma)) {
        return 403;
    }
    if ($vence < $ahora) {
        return 410;
    }
    if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'pdf' || strpos($rel, "\0") !== false) {
        return 403;
    }
    // Dueño del archivo: clients/{codigo}/... o la carpeta antigua clients/uploads/client_{codigo}/...
    if (preg_match('#^uploads/client_([a-z0-9_]+)/#', $rel, $m) || preg_match('#^([a-z0-9_]+)/#', $rel, $m)) {
        $codigo = $m[1];
    } else {
        return 403;
    }
    $abs = CLIENTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) {
        return 404;
    }
    if (!pdf_path_belongs_to_client($abs, $codigo)) {
        return 403;
    }
    return $abs;
}

/** Envía un PDF con soporte de rangos (PDF.js pide trozos: no hay que bajarlo entero para ver la hoja 1). */
function kt_pdf_enviar(string $abs, bool $descargar = false): void
{
    $tam = (int) filesize($abs);
    $mtime = (int) filemtime($abs);
    $etag = '"' . md5($abs . '|' . $tam . '|' . $mtime) . '"';
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $nombre = str_replace(['"', "\r", "\n"], '', basename($abs));
    header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        return;
    }
    $ini = 0;
    $fin = $tam - 1;
    $rango = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($rango !== '' && $tam > 0) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($rango), $m) || ($m[1] === '' && $m[2] === '')) {
            http_response_code(416);
            header("Content-Range: bytes */$tam");
            return;
        }
        if ($m[1] === '') {                 // últimos N bytes
            $ini = max(0, $tam - (int) $m[2]);
        } else {
            $ini = (int) $m[1];
            $fin = $m[2] === '' ? $tam - 1 : min((int) $m[2], $tam - 1);
        }
        if ($ini > $fin || $ini >= $tam) {
            http_response_code(416);
            header("Content-Range: bytes */$tam");
            return;
        }
        http_response_code(206);
        header("Content-Range: bytes $ini-$fin/$tam");
    }
    $largo = $fin - $ini + 1;
    header('Content-Length: ' . max(0, $largo));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $largo <= 0) {
        return;
    }
    $f = fopen($abs, 'rb');
    fseek($f, $ini);
    while ($largo > 0 && !feof($f)) {
        $trozo = fread($f, (int) min(1 << 16, $largo));
        if ($trozo === false || $trozo === '') {
            break;
        }
        echo $trozo;
        $largo -= strlen($trozo);
        if (connection_aborted()) {
            break;
        }
    }
    fclose($f);
}
