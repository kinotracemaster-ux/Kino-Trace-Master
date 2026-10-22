<?php
/**
 * local/sincronizar.php — Enviar a la nube lo trabajado en este equipo.
 *
 * Dos caminos (ver docs/modo_local/SINCRONIZACION.md):
 *   A. "Subir a la nube": arma el paquete .ktsync y lo envía a <nube>/sync_recibir.php
 *      con el código y la contraseña del cliente. La nube lo aplica y responde.
 *   B. "Descargar paquete": para cuando no hay internet o la nube no responde;
 *      el archivo se le envía a Kino y el administrador lo aplica desde su panel.
 */
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/sync_local.php';

if (!defined('KT_MODO_LOCAL')) {
    http_response_code(404);
    exit('No disponible.');
}
if (!is_logged_in()) {
    header('Location: ../login.php');
    exit;
}

$codigo = $_SESSION['client_code'];
$dbRuta = CLIENTS_DIR . DIRECTORY_SEPARATOR . $codigo . DIRECTORY_SEPARATOR . $codigo . '.db';
$lic = KT_LICENCIA;
$nubeDefecto = getenv('KT_NUBE_URL') ?: 'https://kino-trace.com';
$resultado = null;
$error = '';

/** Envía el paquete a la nube por HTTPS. */
function kt_subir_a_nube(string $url, string $codigo, string $password, string $paquete): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Falta la extensión curl de PHP en este paquete.'];
    }
    $ch = curl_init(rtrim($url, '/') . '/sync_recibir.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'codigo' => $codigo,
            'password' => $password,
            'paquete' => new CURLFile($paquete, 'application/zip', 'paquete.ktsync'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 1800,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'error' => "No se pudo conectar con la nube ($err). Use \"Descargar paquete\" y envíelo a Kino."];
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => "La nube respondió algo inesperado (HTTP $http). Use \"Descargar paquete\"."];
    }
    return $j;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La página expiró. Recárguela e intente de nuevo.';
    } else {
        $tmp = kt_tmp_dir('ktenvio');
        $paquete = $tmp . DIRECTORY_SEPARATOR . 'paquete.ktsync';
        try {
            $manifiesto = kt_crear_paquete_sync(CLIENTS_DIR, $codigo, $paquete, $lic['datos']);
            if (($_POST['accion'] ?? '') === 'descargar') {
                $nombre = 'sync_' . $codigo . '_' . date('Ymd_His') . '.ktsync';
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $nombre . '"');
                header('Content-Length: ' . filesize($paquete));
                readfile($paquete);
                kt_borrar_dir($tmp);
                exit;
            }
            $url = trim((string) ($_POST['nube'] ?? ''));
            $hostUrl = strtolower((string) parse_url($url, PHP_URL_HOST));
            $esLocal = in_array($hostUrl, ['127.0.0.1', 'localhost'], true);
            if (!preg_match('#^https://#i', $url) && !($esLocal && preg_match('#^http://#i', $url))) {
                throw new RuntimeException('La dirección de la nube debe empezar con https://');
            }
            $resultado = kt_subir_a_nube($url, $codigo, (string) ($_POST['password'] ?? ''), $paquete);
            if (!empty($resultado['ok']) && in_array($resultado['resultado'] ?? '', ['aplicado', 'ya_aplicado'], true)) {
                $db = kt_abrir_sqlite($dbRuta);
                if (($resultado['modo'] ?? '') === 'reemplazo' || ($resultado['resultado'] ?? '') === 'ya_aplicado') {
                    // La nube quedó igual a este equipo: nuevo punto de partida
                    kt_marcar_sincronizado($db, $manifiesto);
                } else {
                    kt_estado_set($db, 'ultima_sync', kt_ahora());
                    kt_estado_set($db, 'ultima_sync_paquete', $manifiesto['paquete_id']);
                }
                $db = null;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            kt_borrar_dir($tmp);
        }
    }
}

$db = kt_abrir_sqlite($dbRuta);
$resumen = kt_resumen_cambios($db);
$estado = [
    'exportado_en' => kt_estado_get($db, 'exportado_en'),
    'origen' => kt_estado_get($db, 'origen'),
    'ultima_sync' => kt_estado_get($db, 'ultima_sync'),
];
$db = null;
$hayCambios = array_sum(array_map(fn($t) => array_sum(array_map('count', $t)), array_diff_key($resumen, ['max_cambio' => 0]))) > 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizar con la nube - KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body { background: #f1f5f9; padding: 1rem; }
        .kt-wrap { max-width: 760px; margin: 0 auto; }
        .kt-card { background: #fff; border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 4px 16px rgba(15, 23, 42, .06); }
        .kt-card h2 { font-size: 1.05rem; margin: 0 0 .75rem; }
        .kt-muted { color: #64748b; font-size: .88rem; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        td, th { padding: .4rem .5rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
        input[type=text], input[type=password] { width: 100%; padding: .6rem; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
        label { display: block; margin: .75rem 0 .25rem; font-weight: 600; font-size: .9rem; }
        .kt-btn { display: inline-block; background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.2rem; font-size: .95rem; cursor: pointer; text-decoration: none; }
        .kt-btn.sec { background: #e2e8f0; color: #0f172a; }
        .kt-error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        .kt-warn { background: #fffbeb; color: #92400e; border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1rem; }
        ul { margin: .5rem 0 0 1.2rem; }
    </style>
</head>
<body>
<div class="kt-wrap">
    <p><a href="../index.php">← Volver a la aplicación</a></p>
    <h1 style="font-size: 1.35rem;">Sincronizar con la nube</h1>

    <?php if ($error): ?>
        <div class="kt-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($resultado !== null): ?>
        <?php if (!empty($resultado['ok'])): ?>
            <div class="kt-ok">
                <strong><?= htmlspecialchars($resultado['mensaje'] ?? 'Sincronización completada.') ?></strong>
                <?php if (!empty($resultado['informe'])): ?>
                    <ul>
                        <?php foreach ($resultado['informe'] as $k => $v): ?>
                            <?php if (!is_array($v)): ?><li><?= htmlspecialchars(str_replace('_', ' ', $k)) ?>: <?= (int) $v ?></li><?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php if (!empty($resultado['informe']['conflictos'])): ?>
                <div class="kt-warn">
                    <strong>Revisar en la nube (no se aplicaron solos):</strong>
                    <ul><?php foreach ($resultado['informe']['conflictos'] as $c): ?><li><?= htmlspecialchars($c) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="kt-error"><?= htmlspecialchars($resultado['error'] ?? 'La nube rechazó el paquete.') ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="kt-card">
        <h2>Estado de esta copia</h2>
        <table>
            <tr><td>Cliente</td><td><strong><?= htmlspecialchars($codigo) ?></strong></td></tr>
            <tr><td>Copia tomada</td><td><?= htmlspecialchars((string) $estado['exportado_en']) ?> (<?= htmlspecialchars((string) $estado['origen']) ?>)</td></tr>
            <tr><td>Última sincronización</td><td><?= htmlspecialchars($estado['ultima_sync'] ?: 'Nunca') ?></td></tr>
            <tr><td>Licencia</td><td>vence <?= htmlspecialchars($lic['datos']['vence'] ?? '') ?> (<?= (int) $lic['dias'] ?> días) · equipo <?= htmlspecialchars($lic['datos']['equipo'] ?? '') ?></td></tr>
        </table>
    </div>

    <div class="kt-card">
        <h2>Cambios pendientes de enviar</h2>
        <table>
            <tr><th></th><th>Nuevos</th><th>Modificados</th><th>Eliminados</th></tr>
            <?php foreach (['documentos' => 'Documentos', 'codigos' => 'Códigos', 'vinculos' => 'Vínculos'] as $t => $nom): ?>
                <tr><td><?= $nom ?></td>
                    <td><?= count($resumen[$t]['nuevos']) ?></td>
                    <td><?= count($resumen[$t]['modificados']) ?></td>
                    <td><?= count($resumen[$t]['eliminados']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php if (!$hayCambios): ?>
            <p class="kt-muted">No hay cambios desde la última sincronización.</p>
        <?php endif; ?>
    </div>

    <div class="kt-card">
        <h2>Opción 1 — Subir a la nube ahora</h2>
        <p class="kt-muted">Necesita internet y que la nube esté funcionando. Use la misma contraseña con la que entra a la aplicación.</p>
        <form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Enviando… no cierre esta ventana';">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="accion" value="subir">
            <label>Dirección de la nube</label>
            <input type="text" name="nube" value="<?= htmlspecialchars($nubeDefecto) ?>">
            <label>Contraseña</label>
            <input type="password" name="password" required autocomplete="current-password">
            <p style="margin-top: 1rem;"><button type="submit" class="kt-btn">Subir a la nube</button></p>
        </form>
    </div>

    <div class="kt-card">
        <h2>Opción 2 — Descargar paquete y enviarlo a Kino</h2>
        <p class="kt-muted">Si no hay internet o la nube no responde. Se descarga un archivo <code>.ktsync</code>; envíelo a Kino por un medio seguro (Drive compartido, USB). El administrador lo aplica desde su panel.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="accion" value="descargar">
            <button type="submit" class="kt-btn sec">Descargar paquete</button>
        </form>
    </div>
</div>
</body>
</html>
