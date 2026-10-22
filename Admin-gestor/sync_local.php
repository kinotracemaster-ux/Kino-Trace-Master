<?php
/**
 * Admin-gestor/sync_local.php — Modo local: panel del administrador (nube).
 * Documentación: docs/modo_local/PROCESO.md
 *
 * - Exportar el paquete de datos (.ktdata) de un cliente para armar su copia local.
 * - Recibir a mano un paquete .ktsync (cuando el PC no pudo subirlo), revisarlo y aplicarlo.
 * - Ver el historial de sincronizaciones y restaurar respaldos.
 */
require_once __DIR__ . '/../helpers/session_init.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';
require_once __DIR__ . '/../helpers/subdomain.php';
require_once __DIR__ . '/../helpers/sync_local.php';

// Mismas reglas de acceso que panel.php
$sub = getSubdomain();
if ($sub !== null && $sub !== 'admin') {
    http_response_code(403);
    exit('Acceso denegado desde este subdominio.');
}
if (!isset($_SESSION['client_code']) || !in_array($_SESSION['client_code'], ['admin', 'kino'], true)) {
    header('Location: login.php');
    exit;
}
$quien = 'admin:' . $_SESSION['client_code'];

$clientes = $centralDb->query("SELECT codigo, nombre FROM control_clientes WHERE codigo NOT IN ('admin') ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$codigos = array_column($clientes, 'codigo');
$cliente = sanitize_code((string) ($_REQUEST['cliente'] ?? ''));
if (!in_array($cliente, $codigos, true)) {
    $cliente = '';
}
$dirRecibidos = $cliente ? CLIENTS_DIR . "/$cliente/_sync/recibidos" : '';

$mensaje = '';
$error = '';
$previa = null;      // ['archivo' => ..., 'manifiesto' => ..., 'prev' => ...]
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    $accion = (string) ($_POST['accion'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La página expiró. Recárguela.';
    } elseif (!$cliente) {
        $error = 'Seleccione un cliente.';
    } else {
        $tmp = kt_tmp_dir('ktadmin');
        try {
            if ($accion === 'exportar') {
                $zip = $tmp . '/datos.ktdata';
                kt_exportar_datos(CLIENTS_DIR, $cliente, $zip, 'nube ' . gmdate('Y-m-d H:i') . ' UTC');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $cliente . '_' . date('Ymd_His') . '.ktdata"');
                header('Content-Length: ' . filesize($zip));
                readfile($zip);
                kt_borrar_dir($tmp);
                exit;
            }

            if ($accion === 'subir') {
                $f = $_FILES['paquete'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new RuntimeException('No llegó el archivo (código ' . (int) ($f['error'] ?? -1) . ').');
                }
                $p = kt_leer_paquete_sync($f['tmp_name'], $tmp);
                if ($p['manifiesto']['cliente'] !== $cliente) {
                    throw new RuntimeException('El paquete es del cliente ' . $p['manifiesto']['cliente'] . ', no de ' . $cliente . '.');
                }
                @mkdir($dirRecibidos, 0770, true);
                $nombre = gmdate('Ymd_His') . '_' . $p['manifiesto']['paquete_id'] . '.ktsync';
                if (!move_uploaded_file($f['tmp_name'], "$dirRecibidos/$nombre")) {
                    throw new RuntimeException('No se pudo guardar el paquete.');
                }
                $previa = ['archivo' => $nombre, 'manifiesto' => $p['manifiesto'], 'prev' => kt_previsualizar_sync(CLIENTS_DIR, $p)];
            }

            if ($accion === 'revisar' || $accion === 'aplicar') {
                $nombre = (string) ($_POST['archivo'] ?? '');
                if (!preg_match('/^\d{8}_\d{6}_[a-f0-9]{24}\.ktsync$/', $nombre) || !is_file("$dirRecibidos/$nombre")) {
                    throw new RuntimeException('Paquete no encontrado.');
                }
                $p = kt_leer_paquete_sync("$dirRecibidos/$nombre", $tmp);
                if ($accion === 'revisar') {
                    $previa = ['archivo' => $nombre, 'manifiesto' => $p['manifiesto'], 'prev' => kt_previsualizar_sync(CLIENTS_DIR, $p)];
                } else {
                    $resultado = kt_aplicar_sync(CLIENTS_DIR, $p, (string) ($_POST['modo'] ?? ''), $quien);
                    $mensaje = 'Paquete aplicado (' . $resultado['modo'] . ').'
                        . ($resultado['respaldo'] ? ' Respaldo previo: ' . $resultado['respaldo'] : '');
                }
            }

            if ($accion === 'restaurar') {
                $previo = kt_restaurar_respaldo(CLIENTS_DIR, $cliente, (string) ($_POST['respaldo'] ?? ''), $quien);
                $mensaje = 'Base restaurada. El estado anterior quedó guardado como ' . $previo . '.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            kt_borrar_dir($tmp);
        }
    }
}

$registro = $cliente ? array_reverse(kt_registro_leer(CLIENTS_DIR, $cliente)) : [];
$respaldos = $cliente ? kt_listar_respaldos(CLIENTS_DIR, $cliente) : [];
$recibidos = $cliente && is_dir($dirRecibidos) ? array_map('basename', glob("$dirRecibidos/*.ktsync") ?: []) : [];
rsort($recibidos);
$aplicados = array_column(array_filter($registro, fn($r) => ($r['resultado'] ?? '') === 'aplicado'), 'paquete_id');
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modo local - Gestor KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body { background: #f1f5f9; padding: 1rem; }
        .kt-wrap { max-width: 980px; margin: 0 auto; }
        .kt-card { background: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; box-shadow: 0 4px 16px rgba(15, 23, 42, .06); }
        .kt-card h2 { font-size: 1.05rem; margin: 0 0 .75rem; }
        .kt-muted { color: #64748b; font-size: .88rem; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        td, th { padding: .4rem .5rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        .kt-error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-warn { background: #fffbeb; color: #92400e; border-radius: 8px; padding: .75rem 1rem; margin: .75rem 0; }
        .kt-inline { display: inline; }
        code { font-size: .8rem; }
    </style>
</head>
<body>
<div class="kt-wrap">
    <p><a href="panel.php">← Volver al gestor</a></p>
    <h1 style="font-size: 1.35rem;">Modo local — copias de trabajo en PC</h1>
    <p class="kt-muted">Guía completa: <code>docs/modo_local/PROCESO.md</code></p>

    <?php if ($error): ?><div class="kt-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($mensaje): ?><div class="kt-ok"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <div class="kt-card">
        <form method="get">
            <label><strong>Cliente:</strong></label>
            <select name="cliente" onchange="this.form.submit()">
                <option value="">— seleccione —</option>
                <?php foreach ($clientes as $c): ?>
                    <option value="<?= htmlspecialchars($c['codigo']) ?>" <?= $c['codigo'] === $cliente ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nombre'] . ' (' . $c['codigo'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($cliente): ?>
        <div class="kt-card">
            <h2>1. Exportar paquete de datos (.ktdata)</h2>
            <p class="kt-muted">Copia de la base y los PDFs de <strong><?= htmlspecialchars($cliente) ?></strong> para armar su paquete local con
                <code>php local/herramientas/kt.php paquete</code>. Contiene datos del cliente: guárdelo y envíelo como información confidencial.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="cliente" value="<?= htmlspecialchars($cliente) ?>">
                <input type="hidden" name="accion" value="exportar">
                <button class="btn btn-primary" type="submit">Descargar .ktdata</button>
            </form>
        </div>

        <div class="kt-card">
            <h2>2. Recibir un paquete de sincronización (.ktsync)</h2>
            <p class="kt-muted">Para cuando el PC no pudo subirlo solo. Primero se revisa; nada cambia hasta pulsar "Aplicar".</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="cliente" value="<?= htmlspecialchars($cliente) ?>">
                <input type="hidden" name="accion" value="subir">
                <input type="file" name="paquete" accept=".ktsync" required>
                <button class="btn btn-secondary" type="submit">Subir y revisar</button>
            </form>

            <?php if ($previa): $pv = $previa['prev']; ?>
                <div class="kt-warn">
                    <strong>Revisión de <?= htmlspecialchars($previa['archivo']) ?></strong><br>
                    Generado <?= htmlspecialchars($previa['manifiesto']['creado_en']) ?> · equipo <?= htmlspecialchars((string) ($previa['manifiesto']['equipo'] ?? '—')) ?><br>
                    Resultado: <strong><?= htmlspecialchars(strtoupper($pv['modo'])) ?></strong> — <?= htmlspecialchars($pv['motivo']) ?>
                    <?php if ($pv['informe']): ?>
                        <ul>
                            <?php foreach ($pv['informe'] as $k => $v): ?>
                                <?php if (is_array($v)): ?>
                                    <?php foreach ($v as $c): ?><li><?= htmlspecialchars($c) ?></li><?php endforeach; ?>
                                <?php else: ?>
                                    <li><?= htmlspecialchars(str_replace('_', ' ', $k)) ?>: <?= (int) $v ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($pv['modo'] !== 'ya_aplicado'): ?>
                        <form method="post" onsubmit="return confirm('Se hará un respaldo y luego se aplicará. ¿Continuar?');">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="cliente" value="<?= htmlspecialchars($cliente) ?>">
                            <input type="hidden" name="accion" value="aplicar">
                            <input type="hidden" name="archivo" value="<?= htmlspecialchars($previa['archivo']) ?>">
                            <input type="hidden" name="modo" value="<?= htmlspecialchars($pv['modo']) ?>">
                            <button class="btn btn-primary" type="submit">Aplicar (<?= htmlspecialchars($pv['modo']) ?>)</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($resultado && !empty($resultado['informe']['conflictos'])): ?>
                <div class="kt-warn"><strong>Revisar a mano:</strong>
                    <ul><?php foreach ($resultado['informe']['conflictos'] as $c): ?><li><?= htmlspecialchars($c) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if ($recibidos): ?>
                <table>
                    <tr><th>Paquetes recibidos</th><th>Estado</th><th></th></tr>
                    <?php foreach (array_slice($recibidos, 0, 20) as $r): $pid = substr($r, 16, 24); ?>
                        <tr>
                            <td><code><?= htmlspecialchars($r) ?></code></td>
                            <td><?= in_array($pid, $aplicados, true) ? 'aplicado' : 'sin aplicar' ?></td>
                            <td>
                                <form method="post" class="kt-inline">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="cliente" value="<?= htmlspecialchars($cliente) ?>">
                                    <input type="hidden" name="accion" value="revisar">
                                    <input type="hidden" name="archivo" value="<?= htmlspecialchars($r) ?>">
                                    <button class="btn btn-secondary btn-xs" type="submit">Revisar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="kt-card">
            <h2>3. Historial</h2>
            <?php if (!$registro): ?>
                <p class="kt-muted">Sin sincronizaciones todavía.</p>
            <?php else: ?>
                <table>
                    <tr><th>Fecha (UTC)</th><th>Modo</th><th>Quién</th><th>Detalle</th></tr>
                    <?php foreach (array_slice($registro, 0, 30) as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['fecha']) ?></td>
                            <td><?= htmlspecialchars((string) $r['modo']) ?></td>
                            <td><?= htmlspecialchars((string) ($r['por'] ?? '')) ?></td>
                            <td class="kt-muted">
                                <?php foreach (($r['informe'] ?? []) as $k => $v): if (!is_array($v)): ?>
                                    <?= htmlspecialchars(str_replace('_', ' ', $k)) ?>: <?= (int) $v ?> ·
                                <?php endif; endforeach; ?>
                                <?php if (!empty($r['informe']['conflictos'])): ?><?= count($r['informe']['conflictos']) ?> conflicto(s) ·<?php endif; ?>
                                respaldo <code><?= htmlspecialchars((string) ($r['respaldo'] ?? '')) ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="kt-card">
            <h2>4. Respaldos automáticos</h2>
            <p class="kt-muted">Se crea uno antes de cada sincronización o restauración. Restaurar vuelve la base a ese momento
                (los PDFs nuevos quedan en disco, no se borran).</p>
            <?php if (!$respaldos): ?>
                <p class="kt-muted">No hay respaldos.</p>
            <?php else: ?>
                <table>
                    <?php foreach (array_slice($respaldos, 0, 20) as $b): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($b) ?></code></td>
                            <td>
                                <form method="post" class="kt-inline" onsubmit="return confirm('¿Volver la base de <?= htmlspecialchars($cliente) ?> a este respaldo?');">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="cliente" value="<?= htmlspecialchars($cliente) ?>">
                                    <input type="hidden" name="accion" value="restaurar">
                                    <input type="hidden" name="respaldo" value="<?= htmlspecialchars($b) ?>">
                                    <button class="btn btn-danger btn-xs" type="submit">Restaurar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
