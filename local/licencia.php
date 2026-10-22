<?php
/**
 * local/licencia.php — Licencia del MODO LOCAL (anticopia).
 * Detalle: docs/modo_local/SEGURIDAD.md
 *
 * Una licencia es un texto que Kino entrega al cliente:
 *
 *     KTL1.<datos en base64url>.<firma Ed25519 en base64url>
 *
 * - La firma se hace con la LLAVE PRIVADA de Kino, que nunca sale del equipo del
 *   administrador. El paquete solo lleva la llave pública (local/clave_publica.txt),
 *   que sirve para verificar pero no para fabricar licencias.
 * - Los datos dicen para qué cliente es, para qué EQUIPO (código KT-XXXX-…) y hasta
 *   cuándo vale. Si se copia la carpeta a otro PC, el código de equipo no coincide
 *   y la app no abre.
 *
 * Este archivo es independiente (no usa config.php) porque también lo usan
 * local/router.php y la herramienta de consola.
 */

const KT_LIC_PREFIJO = 'KTL1';

function kt_b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function kt_b64u_dec(string $txt)
{
    $txt = strtr($txt, '-_', '+/');
    $txt .= str_repeat('=', (4 - strlen($txt) % 4) % 4);
    return base64_decode($txt, true);
}

/** Identificador crudo del equipo (no se muestra; solo se usa su hash). */
function kt_equipo_id_crudo(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $out = (string) @shell_exec('reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>nul');
        if (preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-fA-F-]{20,})/', $out, $m)) {
            return $m[1];
        }
        $out = trim((string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID" 2>nul'));
        return preg_match('/^[0-9A-Fa-f-]{20,}$/', $out) ? $out : '';
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        $out = (string) @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
        return preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', $out, $m) ? $m[1] : '';
    }
    foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
        if (is_readable($f) && ($v = trim((string) file_get_contents($f))) !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * Código de equipo que ve el cliente: KT-XXXX-XXXX-XXXX-XXXX.
 * Se guarda en la carpeta temporal DEL SISTEMA (no en el paquete) para no
 * consultar el registro de Windows en cada petición.
 */
function kt_equipo_codigo(): string
{
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kt_equipo_' . substr(hash('sha256', __DIR__), 0, 12);
    if (is_file($cache) && filemtime($cache) > time() - 600) {
        $v = trim((string) @file_get_contents($cache));
        if (preg_match('/^KT(-[0-9A-F]{4}){4}$/', $v)) {
            return $v;
        }
    }
    $crudo = kt_equipo_id_crudo();
    if ($crudo === '') {
        return '';
    }
    $h = strtoupper(substr(hash('sha256', 'kinotrace-equipo|' . strtolower(trim($crudo))), 0, 16));
    $codigo = 'KT-' . implode('-', str_split($h, 4));
    @file_put_contents($cache, $codigo);
    return $codigo;
}

function kt_lic_ruta(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'licencia.txt';
}

function kt_lic_clave_publica(): ?string
{
    $f = __DIR__ . DIRECTORY_SEPARATOR . 'clave_publica.txt';
    if (!is_file($f)) {
        return null;
    }
    $pk = base64_decode(trim((string) file_get_contents($f)), true);
    return ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $pk : null;
}

/** Firma una licencia (solo en el equipo del administrador, con la llave privada). */
function kt_lic_firmar(array $datos, string $llavePrivada): string
{
    $payload = kt_b64u_enc(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $firma = sodium_crypto_sign_detached(KT_LIC_PREFIJO . '.' . $payload, $llavePrivada);
    return KT_LIC_PREFIJO . '.' . $payload . '.' . kt_b64u_enc($firma);
}

/**
 * Verifica la firma y devuelve los datos.
 *
 * @return array{ok: bool, error?: string, datos?: array}
 */
function kt_lic_decodificar(string $texto, ?string $clavePublica = null): array
{
    $texto = preg_replace('/\s+/', '', $texto);
    $partes = explode('.', $texto);
    if (count($partes) !== 3 || $partes[0] !== KT_LIC_PREFIJO) {
        return ['ok' => false, 'error' => 'El texto no tiene formato de licencia (debe empezar con KTL1.).'];
    }
    $pk = $clavePublica ?? kt_lic_clave_publica();
    if ($pk === null) {
        return ['ok' => false, 'error' => 'Falta la llave pública del paquete (local/clave_publica.txt).'];
    }
    $firma = kt_b64u_dec($partes[2]);
    if ($firma === false || strlen($firma) !== SODIUM_CRYPTO_SIGN_BYTES
        || !sodium_crypto_sign_verify_detached($firma, $partes[0] . '.' . $partes[1], $pk)) {
        return ['ok' => false, 'error' => 'La licencia no es auténtica o fue modificada.'];
    }
    $datos = json_decode((string) kt_b64u_dec($partes[1]), true);
    if (!is_array($datos) || (int) ($datos['v'] ?? 0) !== 1
        || empty($datos['id']) || empty($datos['cliente']) || empty($datos['equipo'])
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['vence'] ?? ''))) {
        return ['ok' => false, 'error' => 'La licencia está incompleta.'];
    }
    return ['ok' => true, 'datos' => $datos];
}

/**
 * Reglas de vigencia: equipo, vencimiento y reloj.
 *
 * @return array{valida: bool, motivo: string, datos: array, dias: int}
 */
function kt_lic_evaluar(array $datos): array
{
    $r = ['valida' => false, 'motivo' => '', 'datos' => $datos, 'dias' => 0];

    if ($datos['equipo'] !== '*') {
        $equipo = kt_equipo_codigo();
        if ($equipo === '' || !hash_equals($datos['equipo'], $equipo)) {
            $r['motivo'] = 'Esta licencia es de otro equipo. Código de este equipo: ' . ($equipo ?: 'no disponible');
            return $r;
        }
    }

    // Reloj: si alguien atrasa la fecha del PC para estirar la licencia, se nota
    $relojF = __DIR__ . DIRECTORY_SEPARATOR . '.reloj';
    $ultimo = is_file($relojF) ? (int) @file_get_contents($relojF) : 0;
    if ($ultimo > 0 && time() < $ultimo - 86400) {
        $r['motivo'] = 'La fecha del equipo está atrasada respecto al último uso (' . date('Y-m-d', $ultimo) . '). Corrija la fecha.';
        return $r;
    }
    if (time() > $ultimo + 3600) {
        @file_put_contents($relojF, (string) time());
    }

    $fin = strtotime($datos['vence'] . ' 23:59:59');
    $r['dias'] = (int) floor(($fin - time()) / 86400);
    if ($fin < time()) {
        $r['motivo'] = 'La licencia venció el ' . $datos['vence'] . '. Pida una renovación a Kino.';
        return $r;
    }
    $r['valida'] = true;
    return $r;
}

/** Estado de la licencia instalada en este paquete. */
function kt_lic_estado(): array
{
    $f = kt_lic_ruta();
    if (!is_file($f)) {
        return ['valida' => false, 'motivo' => 'Este equipo todavía no está activado.', 'datos' => [], 'dias' => 0];
    }
    $dec = kt_lic_decodificar((string) file_get_contents($f));
    if (!$dec['ok']) {
        return ['valida' => false, 'motivo' => $dec['error'], 'datos' => [], 'dias' => 0];
    }
    return kt_lic_evaluar($dec['datos']);
}
