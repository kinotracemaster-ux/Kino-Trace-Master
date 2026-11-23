<?php
/**
 * Búsquedas que no encontraron nada.
 *
 * Es la lista más honesta de dónde falla el buscador: cada código que alguien buscó y no
 * dio ningún documento. Sirve para tres cosas: ver qué códigos piden los clientes y no
 * están cargados, detectar errores de escritura o de OCR que una búsqueda tolerante podría
 * rescatar, y armar la lista de pruebas de calidad con búsquedas REALES.
 *
 * Qué se guarda: el código tal como se escribió (máx. 60 caracteres), de dónde vino
 * (interno / publico / voraz), cuántas veces y cuándo. Nada de quién buscó ni su IP.
 * Vive en la base de cada cliente (tabla busquedas_sin_resultado, migración 3), así que
 * un cliente nunca ve ni mezcla lo de otro y sale en sus respaldos.
 *
 * Nunca puede romper una búsqueda: todo va dentro de try/catch y, si la tabla no existe
 * todavía (base sin migrar), simplemente no guarda.
 * Tope: 2.000 filas (se borran las más viejas) y nada más antiguo que 90 días, así un robot
 * que pruebe códigos al azar en el buscador público no llena la base.
 */

const KT_BSR_MAX_FILAS = 2000;
const KT_BSR_DIAS = 90;
const KT_BSR_ORIGENES = ['interno', 'publico', 'voraz'];

/**
 * Anota que $codigo se buscó y no dio resultados.
 * $db debe poder escribir (la base del cliente abierta con open_client_db()).
 */
function kt_busqueda_sin_resultado(PDO $db, string $codigo, string $origen): void
{
    kt_busquedas_sin_resultado($db, [$codigo], $origen);
}

/** Igual que la anterior para varios códigos de una vez (búsqueda voraz), en una sola transacción. */
function kt_busquedas_sin_resultado(PDO $db, array $codigos, string $origen): void
{
    if (!in_array($origen, KT_BSR_ORIGENES, true)) {
        return;
    }
    try {
        $filas = [];
        foreach ($codigos as $c) {
            $c = trim((string) $c);
            $clave = code_match_key($c);
            // Muy corto o muy largo = ruido (o alguien pegando un párrafo), no un código
            if (strlen($clave) < 3 || strlen($clave) > 60 || strlen($c) > 60 || preg_match('/[\x00-\x08\x0B-\x1F]/', $c)) {
                continue;
            }
            $filas[$clave] = $c;
            if (count($filas) >= 500) {
                break; // una pegada enorme no debe ser una escritura enorme
            }
        }
        if (!$filas) {
            return;
        }
        $ahora = date('Y-m-d H:i:s');
        // Si otro proceso está escribiendo (p. ej. indexando), no esperar el minuto completo
        // que PDO deja por defecto: esto es secundario y la búsqueda no debe pagarlo.
        $espera = (int) $db->query('PRAGMA busy_timeout')->fetchColumn();
        $db->exec('PRAGMA busy_timeout = 250');
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->exec('BEGIN IMMEDIATE');
        }
        $st = $db->prepare('INSERT INTO busquedas_sin_resultado (clave, origen, codigo, veces, primera, ultima)
            VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT(clave, origen) DO UPDATE SET veces = veces + 1, ultima = excluded.ultima, codigo = excluded.codigo');
        foreach ($filas as $clave => $c) {
            $st->execute([$clave, $origen, $c, $ahora, $ahora]);
        }
        kt_bsr_podar($db);
        if ($propia) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        try {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
        } catch (Throwable $e2) {
        }
        // sin tabla (base vieja) o base ocupada: no guardar nunca es mejor que romper la búsqueda
    } finally {
        if (isset($espera)) {
            try {
                $db->exec('PRAGMA busy_timeout = ' . $espera);
            } catch (Throwable $e) {
            }
        }
    }
}

/** Borra lo muy viejo y deja como mucho KT_BSR_MAX_FILAS (las más recientes). */
function kt_bsr_podar(PDO $db): void
{
    $db->prepare('DELETE FROM busquedas_sin_resultado WHERE ultima < ?')
        ->execute([date('Y-m-d H:i:s', time() - KT_BSR_DIAS * 86400)]);
    $n = (int) $db->query('SELECT COUNT(*) FROM busquedas_sin_resultado')->fetchColumn();
    if ($n > KT_BSR_MAX_FILAS) {
        $db->exec('DELETE FROM busquedas_sin_resultado WHERE id IN (
            SELECT id FROM busquedas_sin_resultado ORDER BY ultima ASC, id ASC LIMIT ' . ($n - KT_BSR_MAX_FILAS + 200) . ')');
    }
}

/**
 * Lo más buscado sin resultado en los últimos $dias días, de más a menos veces.
 * Deja fuera lo que YA existe (se buscó antes de cargar el documento): es historia, no un hueco.
 *
 * @return array<int, array{codigo:string, origen:string, veces:int, ultima:string}>
 */
function kt_busquedas_sin_resultado_top(PDO $db, int $dias = 30, int $max = 20): array
{
    try {
        $st = $db->prepare('SELECT clave, codigo, origen, veces, ultima FROM busquedas_sin_resultado
            WHERE ultima >= ? ORDER BY veces DESC, ultima DESC LIMIT ?');
        $st->execute([date('Y-m-d H:i:s', time() - $dias * 86400), $max * 4]);
        $existe = $db->prepare('SELECT 1 FROM codigos WHERE ' . code_match_sql('codigo') . ' = ? LIMIT 1');
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existe->execute([$r['clave']]);
            if ($existe->fetchColumn()) {
                continue;
            }
            $out[] = ['codigo' => $r['codigo'], 'origen' => $r['origen'], 'veces' => (int) $r['veces'], 'ultima' => $r['ultima']];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return []; // la base todavía no tiene la tabla
    }
}
