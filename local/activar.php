<?php
/**
 * local/activar.php — Activación / renovación de la licencia en este equipo.
 *
 * El cliente ve su "código de equipo", se lo envía a Kino, y pega aquí la
 * licencia (texto KTL1....) que Kino le devuelve. Ver docs/modo_local/PROCESO.md.
 */
require_once __DIR__ . '/../config.php';

if (!defined('KT_MODO_LOCAL')) {
    http_response_code(404);
    exit('No disponible.');
}

$equipo = kt_equipo_codigo();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = trim((string) ($_POST['licencia'] ?? ''));
    $dec = kt_lic_decodificar($texto);
    if (!$dec['ok']) {
        $error = $dec['error'];
    } else {
        $ev = kt_lic_evaluar($dec['datos']);
        $st = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ? AND activo = 1');
        $st->execute([$dec['datos']['cliente']]);
        if (!$ev['valida']) {
            $error = $ev['motivo'];
        } elseif (!(int) $st->fetchColumn()) {
            $error = 'Esta licencia es para otro cliente (' . $dec['datos']['cliente'] . ').';
        } elseif (file_put_contents(kt_lic_ruta(), preg_replace('/\s+/', '', $texto)) === false) {
            $error = 'No se pudo guardar la licencia. ¿La carpeta del programa es de solo lectura?';
        } else {
            header('Location: ../login.php');
            exit;
        }
    }
}

$estado = KT_LICENCIA;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activar equipo - KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .kt-card { background: #fff; max-width: 560px; width: 100%; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); }
        .kt-card h1 { font-size: 1.3rem; margin: 0 0 .25rem; }
        .kt-muted { color: #64748b; font-size: .9rem; }
        .kt-equipo { font-family: ui-monospace, Consolas, monospace; font-size: 1.35rem; letter-spacing: .05em; background: #f1f5f9; border-radius: 8px; padding: .75rem 1rem; text-align: center; margin: .5rem 0; user-select: all; }
        .kt-paso { margin: 1.25rem 0 .5rem; font-weight: 600; }
        textarea { width: 100%; min-height: 110px; font-family: ui-monospace, Consolas, monospace; font-size: .8rem; padding: .6rem; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
        .kt-error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: .75rem 1rem; margin: 1rem 0; }
        .kt-ok { background: #f0fdf4; color: #166534; border-radius: 8px; padding: .75rem 1rem; margin: 1rem 0; }
        .kt-btn { display: inline-block; background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.2rem; font-size: .95rem; cursor: pointer; text-decoration: none; }
        .kt-btn.sec { background: #e2e8f0; color: #0f172a; }
    </style>
</head>
<body>
<div class="kt-card">
    <h1>KINO TRACE — Modo local</h1>
    <p class="kt-muted">Copia de trabajo en este equipo. Los datos se guardan solo aquí hasta sincronizar con la nube.</p>

    <?php if ($estado['valida']): ?>
        <div class="kt-ok">
            Equipo activado para <strong><?= htmlspecialchars($estado['datos']['cliente']) ?></strong>.
            Licencia vigente hasta el <strong><?= htmlspecialchars($estado['datos']['vence']) ?></strong>
            (<?= (int) $estado['dias'] ?> días).
        </div>
        <a class="kt-btn" href="../login.php">Ir a la aplicación</a>
        <p class="kt-paso">¿Le enviaron una licencia nueva (renovación)? Péguela abajo.</p>
    <?php else: ?>
        <div class="kt-error"><?= htmlspecialchars($estado['motivo']) ?></div>
        <p class="kt-paso">1. Envíe este código de equipo a Kino (WhatsApp o correo):</p>
    <?php endif; ?>

    <div class="kt-equipo"><?= htmlspecialchars($equipo ?: 'No se pudo leer el código de este equipo') ?></div>
    <button type="button" class="kt-btn sec" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($equipo) ?>'); this.textContent='Copiado ✓';">Copiar código</button>

    <?php if ($error): ?>
        <div class="kt-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <p class="kt-paso"><?= $estado['valida'] ? 'Licencia nueva:' : '2. Pegue aquí la licencia que le devuelvan (empieza con KTL1.):' ?></p>
        <textarea name="licencia" required placeholder="KTL1.xxxxxxxx.yyyyyyyy"></textarea>
        <p style="margin-top: 1rem;"><button type="submit" class="kt-btn">Activar</button></p>
    </form>
    <p class="kt-muted">La licencia solo funciona en este equipo. Si copia la carpeta a otro PC, allí pedirá un código nuevo.</p>
</div>
</body>
</html>
