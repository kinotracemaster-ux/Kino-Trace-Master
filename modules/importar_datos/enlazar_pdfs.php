<?php
/**
 * Enlazar PDFs - subir los PDF de documentos que ya existen (sin crear documentos).
 *
 * Para clientes importados por SQL/Excel cuyos documentos quedaron "pending" (sin PDF).
 * Antes había que subir un ZIP entero en una sola petición (lento, límite de tamaño, y si
 * se cortaba había que empezar de nuevo) o usar "Subida por Lote", que crea documentos
 * NUEVOS (duplicados) y extrae códigos de cada PDF.
 *
 * Aquí el navegador abre el ZIP o la carpeta en el propio PC (zip.js, sin subirlo entero):
 *   1. action=plan   → manda solo los NOMBRES; el servidor dice cuáles corresponden a un
 *                      documento sin PDF (mismas reglas que el enlazador de ZIP).
 *   2. action=subir  → sube de a un PDF (3 a la vez) solo los que tienen documento; cada
 *                      uno se guarda en uploads/sql_import/ y se enlaza en el momento.
 * Si se corta, se vuelve a elegir el mismo ZIP: los ya enlazados ya no salen en el plan.
 * No crea documentos ni extrae códigos (los códigos ya vienen de la importación).
 */

require_once __DIR__ . '/../../helpers/session_init.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/csrf_protection.php';

if (!isset($_SESSION['client_code'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sesión expirada: vuelve a iniciar sesión.']);
        exit;
    }
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];

/** El enlazador escribe un registro con logMsg(); aquí no se usa. */
if (!function_exists('logMsg')) {
    function logMsg($msg, $type = 'info')
    {
    }
}
require_once __DIR__ . '/../../helpers/pdf_linker.php';

/** Nombre de archivo seguro (sin carpetas ni caracteres de control), o '' si no sirve. */
function kt_enlazar_nombre(string $nombre): string
{
    $base = basename(str_replace('\\', '/', $nombre));
    $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
    if ($base === '' || $base === '.' || $base === '..' || strcasecmp(pathinfo($base, PATHINFO_EXTENSION), 'pdf') !== 0) {
        return '';
    }
    return mb_strlen($base, 'UTF-8') > 200 ? '' : $base;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', '0');
    set_time_limit(300);
    $responder = function (array $r, int $code = 200): void {
        http_response_code($code);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!CsrfProtection::validate($_POST['csrf_token'] ?? null)) {
        $responder(['ok' => false, 'error' => 'La página caducó: recárgala.'], 403);
    }
    session_write_close(); // no bloquear las subidas en paralelo de la misma sesión

    try {
        $db = open_client_db($clientCode);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'plan') {
            $nombres = json_decode((string) ($_POST['nombres'] ?? '[]'), true);
            if (!is_array($nombres)) {
                $responder(['ok' => false, 'error' => 'Lista de archivos no válida.'], 400);
            }
            $idx = kt_linker_indices($db);
            $usados = [];
            $plan = [];
            foreach (array_slice($nombres, 0, 50000) as $n) {
                $base = kt_enlazar_nombre((string) $n);
                if ($base === '') {
                    $plan[] = null;
                    continue;
                }
                $m = kt_linker_emparejar($idx, $usados, $base);
                if ($m['id'] !== null) {
                    $usados[$m['id']] = true;
                }
                $plan[] = $m['id'];
            }
            $pendientes = (int) $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();
            $responder(['ok' => true, 'plan' => $plan, 'pendientes' => $pendientes]);
        }

        if ($action === 'subir') {
            $f = $_FILES['pdf'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $responder(['ok' => false, 'error' => 'No llegó el archivo (código ' . (int) ($f['error'] ?? -1) . ').'], 400);
            }
            $base = kt_enlazar_nombre((string) ($_POST['nombre'] ?? $f['name']));
            if ($base === '') {
                $responder(['ok' => false, 'error' => 'Solo se aceptan archivos .pdf'], 400);
            }
            $cabecera = (string) @file_get_contents($f['tmp_name'], false, null, 0, 1024);
            if (strpos($cabecera, '%PDF') === false) {
                $responder(['ok' => false, 'error' => 'El archivo no es un PDF válido.'], 400);
            }

            // Documento: se vuelve a calcular aquí (no se confía en el id que manda el navegador)
            $m = kt_linker_emparejar(kt_linker_indices($db), [], $base);
            if ($m['id'] === null) {
                $responder(['ok' => true, 'estado' => $m['exacto'] ? 'ya_enlazado' : 'sin_documento', 'nombre' => $base]);
            }

            $dir = CLIENTS_DIR . '/' . sanitize_code($clientCode) . '/uploads/sql_import';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear la carpeta de PDFs.');
            }
            $destino = $dir . '/' . $base;
            if (!(is_file($destino) && filesize($destino) === (int) $f['size'])) {
                if (!move_uploaded_file($f['tmp_name'], $destino)) {
                    throw new RuntimeException('No se pudo guardar el PDF en el servidor.');
                }
            }

            // Solo si sigue sin PDF (dos subidas a la vez para el mismo documento: gana una)
            $st = $db->prepare("UPDATE documentos SET ruta_archivo = ?, original_path = ?
                WHERE id = ? AND (ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = '')");
            $st->execute(['sql_import/' . $base, stripTimestamp($base), $m['id']]);
            $numero = $db->query('SELECT numero FROM documentos WHERE id = ' . (int) $m['id'])->fetchColumn();
            $responder([
                'ok' => true,
                'estado' => $st->rowCount() > 0 ? 'enlazado' : 'ya_enlazado',
                'nombre' => $base,
                'doc' => ['id' => (int) $m['id'], 'numero' => (string) $numero],
            ]);
        }

        $responder(['ok' => false, 'error' => 'Acción no válida.'], 400);
    } catch (Throwable $e) {
        error_log('enlazar_pdfs: ' . $e->getMessage());
        $responder(['ok' => false, 'error' => 'Error del servidor: ' . $e->getMessage()], 500);
    }
}

$db = open_client_db($clientCode);
$pendientes = (int) $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''")->fetchColumn();
$totalDocs = (int) $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn();

$currentModule = 'importar';
$baseUrl = '../../';
$pageTitle = 'Enlazar PDFs';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enlazar PDFs - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .ep-card { background: var(--bg-secondary, #fff); border: 1px solid var(--border-color); border-radius: var(--radius-lg, 12px); padding: 1.25rem; margin-bottom: 1rem; }
        .ep-stats { display: flex; gap: 1rem; flex-wrap: wrap; }
        .ep-stat { flex: 1 1 140px; background: var(--bg-primary); border-radius: var(--radius-md, 8px); padding: .75rem 1rem; }
        .ep-stat b { display: block; font-size: 1.5rem; }
        .ep-pick { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .75rem; }
        .ep-pick label { cursor: pointer; }
        .ep-pick input { display: none; }
        .ep-bar { height: 12px; background: var(--bg-primary); border-radius: 6px; overflow: hidden; margin: .75rem 0 .25rem; }
        .ep-bar div { height: 100%; width: 0; background: var(--accent-success, #16a34a); transition: width .2s; }
        .ep-log { max-height: 320px; overflow: auto; font-family: monospace; font-size: .85rem; background: var(--bg-primary); border-radius: var(--radius-md, 8px); padding: .75rem; white-space: pre-wrap; word-break: break-word; }
        .ep-ok { color: #15803d; } .ep-warn { color: #b45309; } .ep-err { color: #dc2626; }
        .ep-muted { color: var(--text-muted); font-size: .9rem; }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="ep-card">
                    <h3 style="margin-top:0">📎 Enlazar PDFs a documentos que ya existen</h3>
                    <p class="ep-muted">Elige el ZIP o la carpeta con los PDF. Se abre <b>en tu computador</b> y solo
                        se suben los PDF que corresponden a un documento sin PDF, de a uno. <b>No crea documentos
                        nuevos ni extrae códigos.</b> Si se corta, vuelve a elegir el mismo ZIP: sigue con lo que falta.</p>
                    <div class="ep-stats">
                        <div class="ep-stat"><span class="ep-muted">Documentos</span><b><?= number_format($totalDocs, 0, ',', '.') ?></b></div>
                        <div class="ep-stat"><span class="ep-muted">Sin PDF</span><b id="epPend"><?= number_format($pendientes, 0, ',', '.') ?></b></div>
                    </div>
                    <div class="ep-pick">
                        <label class="btn btn-primary">📦 Elegir ZIP
                            <input type="file" id="epZip" accept=".zip,application/zip">
                        </label>
                        <label class="btn btn-secondary">📁 Elegir carpeta
                            <input type="file" id="epDir" webkitdirectory multiple>
                        </label>
                        <label class="btn btn-secondary">📄 Elegir PDFs
                            <input type="file" id="epPdfs" accept=".pdf,application/pdf" multiple>
                        </label>
                    </div>
                </div>

                <div class="ep-card" id="epProgreso" hidden>
                    <div id="epEstado">Preparando…</div>
                    <div class="ep-bar"><div id="epFill"></div></div>
                    <div class="ep-muted" id="epCuenta"></div>
                    <div style="margin-top:.75rem"><button class="btn btn-secondary" id="epParar" type="button">⏹ Detener</button></div>
                    <div class="ep-log" id="epLog" style="margin-top:.75rem"></div>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/vendor/zipjs/zip-no-worker-inflate.min.js"></script>
    <script>
    (() => {
        const CSRF = <?= json_encode(CsrfProtection::getToken()) ?>;
        const PARALELO = 3;
        const $ = id => document.getElementById(id);
        let detener = false;

        const log = (txt, cls) => {
            const d = document.createElement('div');
            if (cls) d.className = cls;
            d.textContent = txt;
            $('epLog').prepend(d);
        };

        async function post(datos, intentos = 3) {
            for (let i = 0; ; i++) {
                try {
                    const fd = new FormData();
                    fd.append('csrf_token', CSRF);
                    for (const [k, v] of Object.entries(datos)) {
                        if (Array.isArray(v)) fd.append(k, v[0], v[1]); else fd.append(k, v);
                    }
                    const r = await fetch('enlazar_pdfs.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    let j;
                    try { j = await r.json(); } catch (e) { throw new Error('Respuesta no válida del servidor (' + r.status + ')'); }
                    if (r.status === 401 || r.status === 403) throw Object.assign(new Error(j.error || 'Sin permiso'), { fatal: true });
                    if (!j.ok) throw new Error(j.error || 'Error');
                    return j;
                } catch (e) {
                    if (e.fatal || i >= intentos - 1) throw e;
                    await new Promise(res => setTimeout(res, 1500 * (i + 1)));
                }
            }
        }

        // Cada fuente da una lista { nombre, leer(): Promise<Blob> }
        async function desdeZip(file) {
            zip.configure({ useWebWorkers: false });
            const lector = new zip.ZipReader(new zip.BlobReader(file));
            const entradas = await lector.getEntries();
            return entradas
                .filter(e => !e.directory && /\.pdf$/i.test(e.filename))
                .map(e => ({
                    nombre: e.filename.split('/').pop(),
                    leer: () => e.getData(new zip.BlobWriter('application/pdf')),
                }));
        }
        function desdeArchivos(files) {
            return [...files].filter(f => /\.pdf$/i.test(f.name)).map(f => ({ nombre: f.name, leer: async () => f }));
        }

        async function procesar(obtener) {
            detener = false;
            $('epProgreso').hidden = false;
            $('epLog').textContent = '';
            $('epFill').style.width = '0';
            $('epParar').disabled = false;
            document.querySelectorAll('.ep-pick input').forEach(i => i.disabled = true);
            try {
                $('epEstado').textContent = '📂 Leyendo la lista de archivos…';
                const archivos = await obtener();
                if (!archivos.length) { $('epEstado').textContent = 'No se encontraron PDF.'; return; }

                $('epEstado').textContent = `🔎 Buscando a qué documento corresponde cada uno de los ${archivos.length} PDF…`;
                const plan = await post({ action: 'plan', nombres: JSON.stringify(archivos.map(a => a.nombre)) });
                const tareas = archivos.filter((a, i) => plan.plan[i] !== null);
                const sobran = archivos.length - tareas.length;
                log(`${archivos.length} PDF en total · ${tareas.length} tienen documento sin PDF · ${sobran} no se suben (ya enlazados o sin documento)`);
                if (!tareas.length) { $('epEstado').textContent = '✅ No hay nada que subir: ningún PDF corresponde a un documento sin PDF.'; return; }

                let hechos = 0, enlazados = 0, fallidos = 0, siguiente = 0;
                const pintar = () => {
                    $('epFill').style.width = Math.round(hechos / tareas.length * 100) + '%';
                    $('epCuenta').textContent = `${hechos} / ${tareas.length} · ✅ ${enlazados} enlazados` + (fallidos ? ` · ❌ ${fallidos} con error` : '');
                };
                $('epEstado').textContent = '⬆️ Subiendo y enlazando…';
                pintar();
                const trabajador = async () => {
                    while (!detener && siguiente < tareas.length) {
                        const t = tareas[siguiente++];
                        try {
                            const blob = await t.leer();
                            const r = await post({ action: 'subir', nombre: t.nombre, pdf: [blob, t.nombre] });
                            if (r.estado === 'enlazado') { enlazados++; log(`✅ ${t.nombre} → ${r.doc.numero}`, 'ep-ok'); }
                            else log(`↷ ${t.nombre}: ${r.estado === 'ya_enlazado' ? 'su documento ya tiene PDF' : 'sin documento'}`, 'ep-warn');
                        } catch (e) {
                            if (e.fatal) { detener = true; log('❌ ' + e.message, 'ep-err'); return; }
                            fallidos++; log(`❌ ${t.nombre}: ${e.message}`, 'ep-err');
                        }
                        hechos++; pintar();
                    }
                };
                await Promise.all(Array.from({ length: PARALELO }, trabajador));
                $('epEstado').textContent = detener
                    ? '⏹ Detenido. Vuelve a elegir el mismo ZIP para seguir con lo que falta.'
                    : `✅ Listo: ${enlazados} PDF enlazados` + (fallidos ? `, ${fallidos} con error (vuelve a elegir el ZIP para reintentarlos).` : '.');
                const fin = await post({ action: 'plan', nombres: '[]' });
                $('epPend').textContent = fin.pendientes.toLocaleString('es-CO');
            } catch (e) {
                $('epEstado').textContent = '❌ ' + e.message;
            } finally {
                $('epParar').disabled = true;
                document.querySelectorAll('.ep-pick input').forEach(i => { i.disabled = false; i.value = ''; });
            }
        }

        $('epZip').addEventListener('change', e => e.target.files[0] && procesar(() => desdeZip(e.target.files[0])));
        $('epDir').addEventListener('change', e => e.target.files.length && procesar(async () => desdeArchivos(e.target.files)));
        $('epPdfs').addEventListener('change', e => e.target.files.length && procesar(async () => desdeArchivos(e.target.files)));
        $('epParar').addEventListener('click', () => { detener = true; $('epParar').disabled = true; });
    })();
    </script>
</body>

</html>
