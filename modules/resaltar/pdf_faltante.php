<?php
/**
 * Pantalla "No se encontró el PDF" de un documento.
 *
 * download.php y viewer.php mandan aquí cuando el PDF de un documento no aparece. En vez
 * de un error, ofrece dos salidas:
 *   - Buscar en el servidor: enlaza un PDF que ya está subido (mismas reglas que
 *     Gestor → 🔍 Huérfanos → Re-enlazar, pero solo para este documento).
 *   - Subir el PDF: lo guarda y lo enlaza a este documento.
 * Al lograrlo vuelve a la página de origen, que ya muestra el PDF.
 */

require_once __DIR__ . '/../../helpers/session_init.php';
require_once __DIR__ . '/../../autoload.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$documentId = (int) ($_REQUEST['doc'] ?? 0);

// Solo se vuelve a las páginas que mandan aquí (ruta relativa, sin saltos de línea)
$volver = (string) ($_REQUEST['volver'] ?? '');
if (!preg_match('#^(download|viewer)\.php\?\S*$#', $volver)) {
    $volver = 'download.php?doc=' . $documentId;
}

$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$document) {
    die('Documento no encontrado');
}

// Si el PDF ya aparece (por ejemplo, se arregló en otra pestaña), mostrarlo
if (resolve_pdf_path($clientCode, $document)) {
    header('Location: ' . $volver);
    exit;
}

$sinEnlace = in_array($document['ruta_archivo'], [null, '', 'pending'], true);
$csrfField = CsrfProtection::tokenField(); // antes de cualquier salida: puede abrir la sesión
$aviso = '';

/**
 * Extrae el texto del PDF recién enlazado si el documento no lo tiene, para que entre en
 * "Buscar en Contenido". Si falla, queda para "Indexar pendientes".
 */
$indexar = function () use ($db, $clientCode, $documentId): void {
    $st = $db->prepare('SELECT * FROM documentos WHERE id = ?');
    $st->execute([$documentId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    $datos = json_decode((string) ($doc['datos_extraidos'] ?? ''), true);
    if (is_array($datos) && !empty($datos['text'])) {
        return;
    }
    $ruta = resolve_pdf_path($clientCode, $doc);
    if (!$ruta) {
        return;
    }
    try {
        load_helper('pdf_extractor');
        $nivel = error_reporting();
        error_reporting($nivel & ~E_NOTICE & ~E_WARNING);
        $ext = extract_codes_from_pdf($ruta);
        error_reporting($nivel);
        if (!empty($ext['success']) && !empty($ext['text'])) {
            $json = json_encode([
                'text' => mb_strcut($ext['text'], 0, 50000, 'UTF-8'),
                'auto_codes' => $ext['codes'] ?? [],
                'indexed_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $db->prepare('UPDATE documentos SET datos_extraidos = ? WHERE id = ?')->execute([$json, $documentId]);
        }
    } catch (Throwable $e) {
        error_log("pdf_faltante: no se pudo indexar el documento #$documentId: " . $e->getMessage());
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Más grande que post_max_size: PHP descarta todo el formulario
        $aviso = 'El PDF es demasiado grande (máximo 10 MB).';
    } elseif (!CsrfProtection::validate($_POST['csrf_token'] ?? null)) {
        $aviso = 'La página venció. Recárguela e intente de nuevo.';
    } elseif ($accion === 'buscar') {
        require_once __DIR__ . '/../../helpers/pdf_linker.php';
        $r = relinkFromServer($db, CLIENTS_DIR . "/{$clientCode}/uploads", true, $sinEnlace ? [] : [$documentId], $documentId);
        if ($r['enlaces']) {
            $indexar();
            header('Location: ' . $volver);
            exit;
        }
        $aviso = $r['conflictos']
            ? 'Hay un PDF con el nombre de este documento, pero ya está enlazado a otro documento. Súbalo con la opción de abajo.'
            : 'No hay en el servidor ningún PDF para este documento. Súbalo con la opción de abajo.';
    } elseif ($accion === 'subir') {
        $archivo = $_FILES['pdf'] ?? null;
        if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            $aviso = 'Elija el archivo PDF.';
        } elseif (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $aviso = 'El PDF es demasiado grande (máximo 10 MB).';
        } else {
            $carpeta = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $document['tipo'])) ?: 'otros';
            $res = SecureFileUploader::secureMove($archivo, $clientCode, $carpeta);
            if (isset($res['error'])) {
                $aviso = $res['error'];
            } else {
                // Si el indexador lo había marcado "Archivo no encontrado", se limpia la marca
                $db->prepare("UPDATE documentos SET ruta_archivo = ?, hash_archivo = ?,
                    datos_extraidos = CASE WHEN datos_extraidos LIKE '%\"error\":%' AND datos_extraidos NOT LIKE '%\"text\":%' THEN NULL ELSE datos_extraidos END
                    WHERE id = ?")->execute([$res['path'], $res['hash'], $documentId]);
                $indexar();
                header('Location: ' . $volver);
                exit;
            }
        }
    }
}

$cfg = get_client_config($clientCode) ?: [];
$color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['color_primario'] ?? '')) ? $cfg['color_primario'] : '#8b1c1c';
$esperado = trim((string) ($document['original_path'] ?? ''));
if ($esperado === '' && !$sinEnlace) {
    $esperado = basename((string) $document['ruta_archivo']);
}
$h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
// doc y volver van en la URL: si el archivo supera post_max_size, PHP descarta todo el cuerpo del POST
$accionUrl = 'pdf_faltante.php?doc=' . $documentId . '&volver=' . rawurlencode($volver);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF no encontrado — <?= $h($document['numero']) ?></title>
    <style>
        :root { --color: <?= $color ?>; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #1e293b; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 680px; margin: 0 auto; padding: 32px 16px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); }
        h1 { font-size: 1.3rem; margin: 0 0 6px; }
        h2 { font-size: 1.05rem; margin: 0 0 6px; }
        .muted { color: #64748b; font-size: .92rem; line-height: 1.45; }
        .doc { font-weight: 600; margin: 10px 0 4px; }
        .archivo { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .8rem; background: #f1f5f9; padding: 8px 10px; border-radius: 8px; word-break: break-all; margin-top: 8px; }
        .aviso { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        button { background: var(--color); color: #fff; border: 0; border-radius: 9px; padding: 11px 18px; font-size: .95rem; font-weight: 600; cursor: pointer; margin-top: 12px; }
        button:disabled { opacity: .6; cursor: wait; }
        input[type=file] { display: block; width: 100%; margin-top: 12px; font-size: .9rem; }
        a.volver { color: #475569; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>📄 No se encontró el PDF de este documento</h1>
        <div class="doc"><?= $h($document['numero']) ?></div>
        <div class="muted"><?= $h($document['tipo']) ?> · <?= $h($document['fecha']) ?> · documento #<?= (int) $document['id'] ?></div>
        <p class="muted"><?= $sinEnlace
            ? 'Este documento se importó sin su PDF.'
            : 'El PDF que tenía enlazado ya no está en el servidor.' ?></p>
        <?php if ($esperado !== ''): ?>
            <div class="muted">Archivo que corresponde:</div>
            <div class="archivo"><?= $h($esperado) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($aviso !== ''): ?>
        <div class="aviso"><?= $h($aviso) ?></div>
    <?php endif; ?>

    <form class="card" method="post" action="<?= $h($accionUrl) ?>" onsubmit="ocupado(this, 'Buscando…')">
        <h2>🔍 Buscar en el servidor</h2>
        <div class="muted">Busca entre los PDF que ya están subidos uno que corresponda a este documento y lo enlaza. No hay que subir nada.</div>
        <?= $csrfField ?>
        <input type="hidden" name="accion" value="buscar">
        <button type="submit">🔍 Buscar en el servidor</button>
    </form>

    <form class="card" method="post" action="<?= $h($accionUrl) ?>" enctype="multipart/form-data" onsubmit="ocupado(this, 'Subiendo…')">
        <h2>⬆️ Subir el PDF</h2>
        <div class="muted">Si no está en el servidor, súbalo desde su computador (PDF de hasta 10 MB). Queda enlazado a este documento.</div>
        <?= $csrfField ?>
        <input type="hidden" name="accion" value="subir">
        <input type="file" name="pdf" accept="application/pdf,.pdf" required>
        <button type="submit">⬆️ Subir y enlazar</button>
    </form>

    <a class="volver" href="../../index.php" onclick="if (history.length > 1) { history.back(); return false; }">← Volver</a>
</main>
<script>
    function ocupado(form, texto) {
        const b = form.querySelector('button');
        b.textContent = texto;
        setTimeout(() => { b.disabled = true; }, 0); // deshabilitar después de enviar
    }
</script>
</body>
</html>
