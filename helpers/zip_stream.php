<?php
/**
 * helpers/zip_stream.php
 *
 * Manda un ZIP al navegador MIENTRAS lo arma: sin archivo temporal y sin comprimir (los
 * PDF ya vienen comprimidos; comprimirlos de nuevo era lo lento). El primer byte sale
 * enseguida y, como el tamaño se sabe de antemano, el navegador muestra el avance.
 *
 * Formato ZIP clásico, sin ZIP64 ni "data descriptor", para que lo abra cualquier
 * programa (Windows, Mac, 7-Zip): cada ZIP debe quedar bajo 4 GB y 65.535 archivos.
 *
 * Cada entrada: ['ruta' => archivo en disco, 'nombre' => ruta dentro del ZIP, 'tamano' => bytes]
 */

const KT_ZIP_MAX_BYTES = 0xFFFFFFFF;
const KT_ZIP_MAX_ARCHIVOS = 0xFFFF;
const KT_ZIP_BLOQUE = 1048576;

/** Tamaño exacto del ZIP que arma kt_zip_enviar() (para Content-Length). */
function kt_zip_tamano(array $entradas): int
{
    $total = 22; // fin del directorio central
    foreach ($entradas as $e) {
        $total += 30 + 46 + 2 * strlen($e['nombre']) + $e['tamano'];
    }
    return $total;
}

/** Recorre exactamente $tamano bytes del archivo (con ceros si se achicó) y se los pasa a $fn. */
function kt_zip_leer(string $ruta, int $tamano, callable $fn): void
{
    $leidos = 0;
    $f = @fopen($ruta, 'rb');
    if ($f) {
        while ($leidos < $tamano) {
            $b = fread($f, min(KT_ZIP_BLOQUE, $tamano - $leidos));
            if ($b === false || $b === '') {
                break;
            }
            $fn($b);
            $leidos += strlen($b);
        }
        fclose($f);
    }
    // El tamaño ya se anunció: si el archivo cambió entre la lista y el envío, el ZIP sigue
    // siendo válido (solo ese archivo sale incompleto)
    while ($leidos < $tamano) {
        $n = min(KT_ZIP_BLOQUE, $tamano - $leidos);
        $fn(str_repeat("\0", $n));
        $leidos += $n;
    }
}

/** Hora y fecha en formato MS-DOS (el que usa el ZIP). */
function kt_zip_fecha_dos(int $ts): array
{
    $d = getdate(max($ts, mktime(0, 0, 0, 1, 1, 1980)));
    return [
        ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
        (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
    ];
}

/**
 * Escribe el ZIP con $salida (por defecto, al navegador) a medida que lee los archivos.
 * Devuelve false si el navegador cortó la descarga.
 */
function kt_zip_enviar(array $entradas, ?callable $salida = null): bool
{
    if (count($entradas) > KT_ZIP_MAX_ARCHIVOS || kt_zip_tamano($entradas) > KT_ZIP_MAX_BYTES) {
        throw new RuntimeException('El ZIP supera 4 GB o 65.535 archivos: hay que dividirlo en partes.');
    }
    $alNavegador = $salida === null;
    $salida = $salida ?? function (string $b): void {
        echo $b;
        flush();
    };

    $offset = 0;
    $central = '';
    foreach ($entradas as $e) {
        if ($alNavegador && connection_aborted()) {
            return false;
        }
        $nombre = $e['nombre'];
        $tamano = (int) $e['tamano'];
        // Bit 11: nombre en UTF-8 (tildes y Ñ se ven bien en Windows y Mac)
        $flags = (preg_match('/[^\x00-\x7F]/', $nombre) && mb_check_encoding($nombre, 'UTF-8')) ? 0x0800 : 0;
        [$hora, $fecha] = kt_zip_fecha_dos((int) (@filemtime($e['ruta']) ?: time()));

        $h = hash_init('crc32b');
        kt_zip_leer($e['ruta'], $tamano, function ($b) use ($h) {
            hash_update($h, $b);
        });
        $crc = (int) hexdec(hash_final($h));

        $salida(pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, 0, $hora, $fecha, $crc, $tamano, $tamano, strlen($nombre), 0) . $nombre);
        kt_zip_leer($e['ruta'], $tamano, $salida);

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, 0, $hora, $fecha, $crc, $tamano, $tamano,
            strlen($nombre), 0, 0, 0, 0, 0x20, $offset) . $nombre;
        $offset += 30 + strlen($nombre) + $tamano;
    }
    $n = count($entradas);
    $salida($central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $offset, 0));
    return true;
}
