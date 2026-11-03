<?php
/**
 * Clave maestra del administrador (variable de entorno ADMIN_SECRET).
 *
 * Antes, si ADMIN_SECRET no estaba configurada, la clave era un número de 4 cifras escrito
 * en el código (cualquiera con el repo la conocía, y 4 cifras se adivinan en minutos).
 * Ahora:
 *  - sin ADMIN_SECRET, o con menos de KT_ADMIN_SECRET_MIN caracteres, el acceso admin
 *    queda apagado (con un mensaje claro de qué configurar);
 *  - la comparación es de tiempo constante (hash_equals);
 *  - tras KT_ADMIN_MAX_FALLOS intentos fallidos desde la misma IP, esa IP espera
 *    KT_ADMIN_BLOQUEO_SEG antes de volver a intentar.
 */

const KT_ADMIN_SECRET_MIN = 12;
const KT_ADMIN_MAX_FALLOS = 5;
const KT_ADMIN_BLOQUEO_SEG = 900; // 15 minutos

/** Clave configurada, o null si falta o es demasiado corta. */
function kt_admin_secret(): ?string
{
    $s = (string) getenv('ADMIN_SECRET');
    return strlen($s) >= KT_ADMIN_SECRET_MIN ? $s : null;
}

function kt_admin_intentos_archivo(): string
{
    $dir = (defined('CLIENTS_DIR') ? CLIENTS_DIR : __DIR__ . '/../clients') . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/admin_intentos.json';
}

/** @return array<string, array{fallos:int, desde:int}> */
function kt_admin_intentos_leer(): array
{
    $f = kt_admin_intentos_archivo();
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function kt_admin_intentos_guardar(array $d): void
{
    $ahora = time();
    foreach ($d as $k => $v) {
        if ($ahora - (int) ($v['desde'] ?? 0) > KT_ADMIN_BLOQUEO_SEG) {
            unset($d[$k]);
        }
    }
    @file_put_contents(kt_admin_intentos_archivo(), json_encode($d), LOCK_EX);
}

/**
 * Verifica un intento de clave admin.
 *
 * @return string 'ok' | 'incorrecta' | 'bloqueado' | 'no_configurado'
 */
function kt_admin_verificar(string $intento, ?string $ip = null): string
{
    $secreto = kt_admin_secret();
    if ($secreto === null) {
        return 'no_configurado';
    }

    $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'desconocida');
    $clave = hash('sha256', $ip);
    $datos = kt_admin_intentos_leer();
    $reg = $datos[$clave] ?? null;
    if ($reg && (time() - (int) $reg['desde']) > KT_ADMIN_BLOQUEO_SEG) {
        $reg = null;
    }
    if ($reg && (int) $reg['fallos'] >= KT_ADMIN_MAX_FALLOS) {
        return 'bloqueado';
    }

    if (hash_equals($secreto, $intento)) {
        unset($datos[$clave]);
        kt_admin_intentos_guardar($datos);
        return 'ok';
    }

    $datos[$clave] = [
        'fallos' => (int) ($reg['fallos'] ?? 0) + 1,
        'desde' => (int) ($reg['desde'] ?? time()),
    ];
    kt_admin_intentos_guardar($datos);
    return 'incorrecta';
}

/** Mensaje para mostrar en el formulario según el resultado de kt_admin_verificar(). */
function kt_admin_mensaje(string $resultado): string
{
    switch ($resultado) {
        case 'no_configurado':
            return 'Acceso admin desactivado: falta configurar ADMIN_SECRET (mínimo '
                . KT_ADMIN_SECRET_MIN . ' caracteres) en las variables de Railway.';
        case 'bloqueado':
            return 'Demasiados intentos fallidos. Espera 15 minutos.';
        default:
            return 'Código de acceso incorrecto';
    }
}
