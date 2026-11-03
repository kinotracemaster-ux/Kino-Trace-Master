<?php
/**
 * Admin-gestor/respaldos.php — Respaldos de todos los clientes.
 *
 * - Ver cuándo fue el último respaldo automático y la última prueba de restauración.
 * - Respaldar todo ahora.
 * - Restaurar UN cliente desde cualquiera de sus respaldos (antes guarda el estado actual).
 *
 * Lógica en helpers/respaldo.php · guía en docs/RESPALDOS.md
 */
require_once __DIR__ . '/../helpers/session_init.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';
require_once __DIR__ . '/../helpers/subdomain.php';
require_once __DIR__ . '/../helpers/respaldo.php';

// Como panel.php, pero solo con la clave maestra (is_admin): la sesión del cliente "kino"
// no basta para restaurar bases de otros clientes
$sub = getSubdomain();
if ($sub !== null && $sub !== 'admin') {
    http_response_code(403);
    exit('Acceso denegado desde este subdominio.');
}
if (empty($_SESSION['is_admin'])) {
    header('Location: login.php');
    exit;
}

$respDir = kt_resp_dir(CLIENTS_DIR);
$mensaje = '';
$error = '';
$csrf = (string) ($_SESSION['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    ignore_user_abort(true);
    $accion = (string) ($_POST['accion'] ?? '');
    if (!$csrf || !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La página expiró. Recárguela.';
    } else {
        try {
            if ($accion === 'respaldar') {
                $r = kt_resp_todo(CLIENTS_DIR, 'manual');
                $mensaje = 'Respaldo hecho en ' . round($r['ms'] / 1000, 1) . ' s: ' . count($r['clientes']) . ' clientes'
                    . ($r['errores'] ? ", {$r['errores']} con error (ver abajo)" : ', sin errores') . '.';
            } elseif ($accion === 'probar') {
                $r = kt_resp_probar(CLIENTS_DIR);
                $mensaje = $r['ok'] ? 'Prueba de restauración correcta para todos los clientes.' : 'La prueba encontró problemas (ver abajo).';
            } elseif ($accion === 'restaurar') {
                $cliente = sanitize_code((string) ($_POST['cliente'] ?? ''));
                $nombre = (string) ($_POST['respaldo'] ?? '');
                if ((string) ($_POST['confirmar'] ?? '') !== $cliente) {
                    throw new RuntimeException('Para restaurar escriba el código del cliente exactamente.');
                }
                $r = kt_resp_restaurar(CLIENTS_DIR, $cliente, $nombre);
                $mensaje = "Cliente $cliente restaurado desde $nombre en " . round($r['ms'] / 1000, 1) . " s: "
                    . "{$r['documentos']} documentos, {$r['codigos']} códigos, {$r['archivos_devueltos']} archivos devueltos. "
                    . "El estado anterior quedó guardado como {$r['respaldo_previo']} por si hay que deshacer.";
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$estado = kt_resp_estado($respDir);
$ult = $estado['ultimo_respaldo'] ?? null;
$prueba = $estado['ultima_prueba'] ?? null;
$clientes = $centralDb->query("SELECT codigo, nombre FROM control_clientes ORDER BY nombre")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (kt_resp_clientes(CLIENTS_DIR) as $c) {
    $clientes[$c] = $clientes[$c] ?? $c;
}

function kt_hace(?string $iso): string
{
    $t = $iso ? strtotime($iso) : 0;
    if (!$t) {
        return 'nunca';
    }
    $s = time() - $t;
    if ($s < 3600) return 'hace ' . max(1, intdiv($s, 60)) . ' min';
    if ($s < 172800) return 'hace ' . intdiv($s, 3600) . ' h';
    return 'hace ' . intdiv($s, 86400) . ' días';
}
function kt_mb(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
}
$ultViejo = !$ult || (time() - strtotime($ult['fecha'])) > 36 * 3600;
$pruebaVieja = !$prueba || (time() - strtotime($prueba['fecha'])) > 9 * 86400;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldos - Gestor KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body { background: #f1f5f9; padding: 1rem; }
        .kt-wrap { max-width: 980px; margin: 0 auto; }
        .kt-card { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; box-shadow: 0 4px 16px rgba(15, 23, 42, .06); }
        .kt-card h2 { font-size: 1.05rem; margin: 0 0 .75rem; }
        .kt-muted { color: #64748b; font-size: .88rem; }
        .kt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem; }
        .kt-stat { border: 1px solid #e2e8f0; border-radius: 10px; padding: .75rem 1rem; }
        .kt-stat b { display: block; font-size: 1.05rem; }
        .kt-bien { color: #166534; } .kt-mal { color: #b91c1c; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        td, th { padding: .45rem .5rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        .kt-error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-warn { background: #fffbeb; color: #92400e; border-radius: 8px; padding: .75rem 1rem; margin: .75rem 0; }
        .kt-acciones { display: flex; gap: .5rem; flex-wrap: wrap; }
        .kt-restaurar { display: flex; gap: .4rem; flex-wrap: wrap; align-items: center; }
        .kt-restaurar select, .kt-restaurar input { padding: .3rem .4rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: .85rem; }
        .kt-restaurar input { width: 9rem; }
        @media (max-width: 640px) { td, th { display: block; border: 0; padding: .2rem 0; } tr { display: block; border-bottom: 1px solid #e2e8f0; padding: .5rem 0; } thead { display: none; } }
    </style>
</head>
<body>
<div class="kt-wrap">
    <p><a href="panel.php">← Volver al gestor</a></p>
    <h1 style="font-size: 1.35rem;">Respaldos</h1>
    <p class="kt-muted">Se respalda todo solo cada día y cada semana se prueba que los respaldos se puedan restaurar. Guía: <code>docs/RESPALDOS.md</code></p>

    <?php if ($error): ?><div class="kt-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($mensaje): ?><div class="kt-ok"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <div class="kt-card">
        <div class="kt-grid">
            <div class="kt-stat">Último respaldo
                <b class="<?= $ultViejo || ($ult['errores'] ?? 0) ? 'kt-mal' : 'kt-bien' ?>"><?= htmlspecialchars(kt_hace($ult['fecha'] ?? null)) ?></b>
                <span class="kt-muted"><?= $ult ? (int) $ult['errores'] . ' errores · ' . round(($ult['ms'] ?? 0) / 1000, 1) . ' s' : 'Aún no hay respaldos' ?></span>
            </div>
            <div class="kt-stat">Última prueba de restauración
                <b class="<?= $pruebaVieja || !($prueba['ok'] ?? false) ? 'kt-mal' : 'kt-bien' ?>"><?= htmlspecialchars(kt_hace($prueba['fecha'] ?? null)) ?></b>
                <span class="kt-muted"><?= $prueba ? (($prueba['ok'] ?? false) ? 'Todo se pudo restaurar' : 'Con problemas') : 'Aún no se probó' ?></span>
            </div>
            <div class="kt-stat">Copia fuera del servidor
                <?php $nube = $ult['nube'] ?? null; ?>
                <b class="<?= ($nube['ok'] ?? false) ? 'kt-bien' : 'kt-mal' ?>"><?= kt_s3_configurado() ? (($nube['ok'] ?? false) ? 'Al día' : 'Con error') : 'No configurada' ?></b>
                <span class="kt-muted"><?= kt_s3_configurado() ? htmlspecialchars((string) ($nube['error'] ?? (($nube['subidos'] ?? 0) . ' archivos subidos la última vez'))) : 'Si se pierde el volumen de Railway se pierden también los respaldos' ?></span>
            </div>
        </div>
        <?php if (!kt_s3_configurado()): ?>
            <div class="kt-warn">Falta la copia fuera del servidor. Configure las variables <code>KT_S3_*</code> (Cloudflare R2) como explica <code>docs/RESPALDOS.md</code>.</div>
        <?php endif; ?>
        <div class="kt-acciones" style="margin-top: .75rem;">
            <form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Respaldando… (puede tardar)';">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="respaldar">
                <button class="btn btn-primary" type="submit">Respaldar todo ahora</button>
            </form>
            <form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Probando…';">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="probar">
                <button class="btn btn-secondary" type="submit">Probar restauración ahora</button>
            </form>
        </div>
    </div>

    <div class="kt-card">
        <h2>Clientes</h2>
        <p class="kt-muted">Restaurar devuelve la base del cliente al momento del respaldo y repone los archivos que falten. Antes guarda el estado actual, así que se puede deshacer restaurando ese respaldo ("antes_restaurar"). Los demás clientes no se tocan.</p>
        <table>
            <thead><tr><th>Cliente</th><th>Último respaldo</th><th>Prueba</th><th>Restaurar</th></tr></thead>
            <tbody>
            <?php foreach ($clientes as $codigo => $nombre):
                $lista = kt_resp_listar(CLIENTS_DIR, (string) $codigo);
                $ru = $ult['clientes'][$codigo] ?? null;
                $pr = $prueba['clientes'][$codigo] ?? null; ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $nombre) ?></strong><br><span class="kt-muted"><?= htmlspecialchars((string) $codigo) ?></span></td>
                    <td>
                        <?php if ($lista): ?>
                            <?= htmlspecialchars($lista[0]['fecha']) ?><br>
                            <span class="kt-muted"><?= count($lista) ?> <?= count($lista) === 1 ? 'copia' : 'copias' ?> · base <?= kt_mb($lista[0]['bytes']) ?><?= $ru && isset($ru['archivos']) ? ' · ' . (int) $ru['archivos'] . ' archivos' : '' ?></span>
                        <?php else: ?><span class="kt-muted">Sin respaldos</span><?php endif; ?>
                        <?php if ($ru && empty($ru['ok'])): ?><br><span class="kt-mal"><?= htmlspecialchars((string) ($ru['error'] ?? 'Error')) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$pr): ?><span class="kt-muted">—</span>
                        <?php elseif (!empty($pr['ok']) && isset($pr['documentos'])): ?>
                            <span class="kt-bien">✓</span> <?= (int) $pr['documentos'] ?> docs, <?= (int) $pr['codigos'] ?> códigos
                            <?php if (!empty($pr['pdfs_sin_copia'])): ?><br><span class="kt-mal"><?= (int) $pr['pdfs_sin_copia'] ?> PDF sin copia (el documento ya no tenía su archivo)</span><?php endif; ?>
                        <?php elseif (!empty($pr['ok'])): ?><span class="kt-muted"><?= htmlspecialchars((string) ($pr['nota'] ?? 'OK')) ?></span>
                        <?php else: ?><span class="kt-mal"><?= htmlspecialchars((string) ($pr['error'] ?? 'Falló')) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lista): ?>
                            <form method="post" class="kt-restaurar" onsubmit="return confirm('Se reemplazará la base de <?= htmlspecialchars((string) $codigo) ?> por el respaldo elegido (el estado actual queda guardado). ¿Continuar?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="restaurar">
                                <input type="hidden" name="cliente" value="<?= htmlspecialchars((string) $codigo) ?>">
                                <select name="respaldo">
                                    <?php foreach ($lista as $r): ?>
                                        <option value="<?= htmlspecialchars($r['nombre']) ?>"><?= htmlspecialchars($r['fecha'] . ' · ' . preg_replace('/^\d{8}_\d{6}_|\.db\.gz$/', '', $r['nombre'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="confirmar" placeholder="escriba <?= htmlspecialchars((string) $codigo) ?>" autocomplete="off" required>
                                <button class="btn btn-secondary" type="submit">Restaurar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
