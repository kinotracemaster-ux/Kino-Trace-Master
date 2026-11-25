<?php
/**
 * Tope diario de consultas a la IA (Gemini) por cliente.
 *
 * Cada consulta a Gemini se cobra. Sin tope, un cliente (o un robot con una sesión) podría
 * gastar sin límite. El conteo vive en la base de cada cliente (tabla uso_ia, migración 4):
 * un cliente nunca gasta el cupo de otro. Guarda solo el día y cuántas consultas, nada de
 * lo preguntado ni de quién preguntó.
 *
 * Tope por defecto: 200 al día por cliente. Se cambia con la variable KT_IA_LIMITE_DIARIO
 * (0 = IA sin consultas, es decir apagada). Si la tabla no existe todavía, no bloquea.
 */

const KT_IA_LIMITE_DEFECTO = 200;

function kt_ia_limite_diario(): int
{
    $v = getenv('KT_IA_LIMITE_DIARIO');
    return ($v !== false && $v !== '' && ctype_digit($v)) ? (int) $v : KT_IA_LIMITE_DEFECTO;
}

/**
 * Intenta gastar una consulta de IA de hoy. Devuelve true si todavía hay cupo (y la cuenta),
 * false si el cliente ya llegó al tope del día.
 */
function kt_ia_consumir(PDO $db): bool
{
    $limite = kt_ia_limite_diario();
    $dia = gmdate('Y-m-d');
    try {
        $db->beginTransaction();
        $db->prepare('INSERT OR IGNORE INTO uso_ia (dia, consultas) VALUES (?, 0)')->execute([$dia]);
        $up = $db->prepare('UPDATE uso_ia SET consultas = consultas + 1 WHERE dia = ? AND consultas < ?');
        $up->execute([$dia, $limite]);
        $hay = $up->rowCount() > 0;
        $db->commit();
        // Limpieza: nada de más de 90 días
        if ($hay && random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM uso_ia WHERE dia < ?')->execute([gmdate('Y-m-d', time() - 90 * 86400)]);
        }
        return $hay;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return true; // si la tabla no existe (base sin migrar) no se bloquea la IA
    }
}

/** Consultas de IA gastadas hoy (para mostrar en el panel). */
function kt_ia_usadas_hoy(PDO $db): int
{
    try {
        $st = $db->prepare('SELECT consultas FROM uso_ia WHERE dia = ?');
        $st->execute([gmdate('Y-m-d')]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}
