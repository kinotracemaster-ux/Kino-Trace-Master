<?php
/**
 * Visor Público de PDF Resaltado - KINO TRACE
 *
 * Versión simplificada sin autenticación para uso público.
 * Solo muestra el PDF con el código resaltado, sin opciones de edición.
 */

// NO requiere sesión de usuario - es público
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/subdomain.php';

// SEGURIDAD: Validar y sanitizar código de cliente
$clientInput = isset($_GET['cliente']) ? $_GET['cliente'] : '';
if (empty($clientInput)) {
    $clientInput = getClientFromSubdomain() ?? '';
}
$clientCode = validate_public_client($clientInput, false);

$db = open_client_db($clientCode);

// Cargar datos de página pública para footer
$ppData = [];
if (isset($centralDb)) {
    $ppStmt = $centralDb->prepare('SELECT * FROM pagina_publica WHERE codigo = ? LIMIT 1');
    $ppStmt->execute([$clientCode]);
    $ppData = $ppStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$ppFooterTexto = $ppData['footer_texto'] ?? '';
$ppFooterUrl = $ppData['footer_url'] ?? '';

// Get parameters
$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

if ($documentId <= 0) {
    die('Documento no especificado');
}

// Obtener documento
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

// Resolver ruta del PDF
$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$pdfPath = resolve_pdf_path($clientCode, $document);

if (!$pdfPath || !file_exists($pdfPath)) {
    die('Archivo PDF no encontrado');
}

$relativePath = str_replace($uploadsDir, '', $pdfPath);
$baseUrl = '../../';
if ($relativePath === $pdfPath || str_starts_with($relativePath, DIRECTORY_SEPARATOR) === false && str_contains($relativePath, 'uploads/client_')) {
    $projectRoot = realpath(__DIR__ . '/../../');
    $realPdfPath = realpath($pdfPath);
    $relativePath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $realPdfPath);
    $relativePath = str_replace('\\', '/', $relativePath);
    $pdfUrl = $baseUrl . $relativePath;
} else {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $pdfUrl = $baseUrl . 'clients/' . $clientCode . '/uploads/' . $relativePath;
}

// Preparar términos a resaltar. Solo se separa por coma, punto y coma o salto
// de línea: los espacios y guiones son parte del código ("DS 230166" es UN
// código; antes se partía en "DS" y "230166" y se resaltaba cualquier "DS").
$termsToHighlight = [];
if (!empty($searchTerm)) {
    $splitTerms = preg_split('/[,;\t\n\r]+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_values(array_unique(array_filter(array_map('trim', $splitTerms))));
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento - <?= htmlspecialchars($document['numero']) ?></title>
    <?php
    $clientConfig = get_client_config($clientCode);
    $cP = $clientConfig['color_primario'] ?? '#c41e3a';
    $cS = $clientConfig['color_secundario'] ?? '#333';
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <style>
        :root {
            --primary-color:
                <?= $cP ?>
            ;
            --secondary-color:
                <?= $cS ?>
            ;
            --hl-color: rgba(250, 200, 0, 0.72);
            --hl-border: rgba(200, 150, 0, 0.85);
            --nav-bg: #1e293b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Header ── */
        .public-header {
            background: white;
            padding: 13px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 200;
        }

        .header-title {
            font-size: 17px;
            font-weight: 600;
            color: #333;
        }

        .header-badge {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-right: 10px;
        }

        .header-code {
            background: #16a34a;
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        .header-code.searching {
            animation: pulseGreen 1s ease-in-out infinite;
        }

        @keyframes pulseGreen {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.6);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(22, 163, 74, 0);
            }
        }

        /* ── Barra de progreso ── */
        #progressBar {
            position: sticky;
            top: 57px;
            z-index: 190;
            height: 3px;
            background: #e5e7eb;
            display: none;
        }

        #progressFill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #16a34a, #4ade80);
            transition: width 0.3s ease;
            border-radius: 0 2px 2px 0;
        }

        /* ── Barra de navegación flotante ── */
        #navBar {
            display: none;
            position: sticky;
            top: 60px;
            z-index: 180;
            background: var(--nav-bg);
            color: white;
            padding: 8px 16px;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 14px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }

        #navBar.visible {
            display: flex;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            padding: 5px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.28);
        }

        .nav-btn:disabled {
            opacity: 0.4;
            cursor: default;
        }

        #navCounter {
            font-weight: 700;
            font-size: 15px;
            min-width: 80px;
            text-align: center;
        }

        #navTotal {
            font-size: 13px;
            color: #94a3b8;
        }

        /* ── Visor PDF ── */
        .pdf-viewer {
            max-width: 900px;
            margin: 20px auto;
            padding: 0 20px 40px;
            overflow-x: hidden;
        }

        .pdf-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .pdf-page-wrapper {
            position: relative;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            background: white;
            border-radius: 4px;
            max-width: 100%;
            overflow: hidden;
        }

        .pdf-page-wrapper canvas {
            display: block;
            border-radius: 4px;
            max-width: 100%;
            height: auto !important;
        }

        .page-number {
            text-align: center;
            font-size: 14px;
            color: #888;
            margin-top: 10px;
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 60px;
            color: #666;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Resaltados (rectángulos sobre el dibujo de la hoja, igual que el visor interno) */
        .ocr-hl-overlay div {
            position: absolute;
            border-radius: 3px;
            background: var(--hl-color);
            mix-blend-mode: multiply;
            outline: 1.5px solid var(--hl-border);
            animation: hlFadeIn 0.2s ease-out both;
        }

        .ocr-hl-overlay div.hl-active {
            background: rgba(255, 120, 0, 0.75);
            outline: 2px solid rgba(200, 80, 0, 0.95);
        }

        /* Footer */
        .public-footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: 13px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }

        .public-footer a {
            color: var(--primary-color);
            text-decoration: none;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .header-title {
                font-size: 14px;
            }

            .public-header {
                flex-wrap: wrap;
                gap: 8px;
                padding: 10px 12px;
            }

            .header-badge {
                font-size: 11px;
                padding: 3px 8px;
            }

            .pdf-viewer {
                padding: 0 4px 20px;
                margin: 10px auto;
            }

            #navBar {
                gap: 6px;
                font-size: 12px;
                padding: 6px 8px;
                flex-wrap: wrap;
                justify-content: center;
            }

            .nav-btn {
                padding: 5px 10px;
                font-size: 12px;
            }

            #navCounter {
                min-width: 60px;
                font-size: 13px;
            }
        }

        /* Print */
        @media print {

            .public-header,
            .public-footer,
            .page-number,
            #navBar,
            #progressBar {
                display: none !important;
            }

            body {
                background: white;
            }

            .pdf-page-wrapper {
                box-shadow: none;
                page-break-after: always;
            }

            .ocr-hl-overlay div {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        @keyframes hlFadeIn {
            from {
                opacity: 0;
                transform: scaleY(0.8);
            }

            to {
                opacity: 1;
                transform: scaleY(1);
            }
        }

        @keyframes hlPulse {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(250, 200, 0, 0.7);
            }

            50% {
                transform: scale(1.04);
                box-shadow: 0 0 0 5px rgba(250, 200, 0, 0);
            }
        }
    </style>
</head>

<body>
    <!-- Header simple -->
    <header class="public-header">
        <div>
            <span class="header-badge"><?= strtoupper($document['tipo']) ?></span>
            <span class="header-title"><?= htmlspecialchars($document['numero']) ?></span>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if (!empty($searchTerm)): ?>
                <span class="header-code" id="headerCode">
                    Código: <?= htmlspecialchars(strtoupper($searchTerm)) ?>
                </span>
                <button id="btnResaltar" onclick="highlightCode()"
                    style="background:#16a34a; color:white; border:none; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:4px; transition:all 0.2s;"
                    onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                    🔍 Resaltar
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- Barra de progreso -->
    <div id="progressBar">
        <div id="progressFill"></div>
    </div>

    <!-- Barra de navegación flotante (aparece tras resaltar) -->
    <div id="navBar">
        <button class="nav-btn" id="btnPrev" onclick="navPrev()">← Anterior</button>
        <span id="navCounter">–</span>
        <button class="nav-btn" id="btnNext" onclick="navNext()">Siguiente →</button>
        <span id="navTotal"></span>
    </div>

    <!-- Visor PDF -->
    <div class="pdf-viewer">
        <div id="pdfContainer" class="pdf-container">
            <div class="loading">
                <div class="spinner"></div>
                <p>Cargando documento...</p>
            </div>
        </div>
    </div>

    <!-- Footer dinámico -->
    <footer class="public-footer">
        <?php if ($ppFooterTexto): ?>
            <?= htmlspecialchars($ppFooterTexto) ?><br>
        <?php else: ?>
            <?= htmlspecialchars($clientCode) ?><br>
        <?php endif; ?>
        <?php if ($ppFooterUrl): ?>
            <a href="<?= htmlspecialchars($ppFooterUrl) ?>" target="_blank"><?= htmlspecialchars($ppFooterUrl) ?></a>
        <?php endif; ?>
    </footer>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const termsToHighlight = <?= json_encode(array_values($termsToHighlight)) ?>.map(String).map(s => s.trim()).filter(Boolean);
        const clientCode = '<?= addslashes($clientCode) ?>';
        const docId = <?= $documentId ?>;
        const container = document.getElementById('pdfContainer');

        // ============================================
        // RESALTADO RÁPIDO (mismo motor que modules/resaltar/viewer.php)
        // ============================================
        // 1. Se busca el código en la capa de texto del PDF con PDF.js, en el
        //    navegador (milisegundos, sin servidor).
        // 2. Solo las hojas escaneadas (menos de MIN_TEXT_CHARS caracteres) van al
        //    servidor (ocr_text_public.php → pdftotext / OCR, con caché).
        // 3. Los resaltados son rectángulos encima del dibujo de la hoja.
        // El código se compara sin mayúsculas, tildes ni separadores (espacio,
        // guion, punto, coma...), igual que la búsqueda: "BL-1258" también
        // resalta "BL1258".
        const AUTO_HIGHLIGHT = true;   // resaltar solo al abrir (como el visor interno)
        const MIN_TEXT_CHARS = 150;    // = OCR_HL_MIN_TEXT_CHARS (helpers/ocr_highlight.php)
        const SERVER_CONCURRENCY = 3;  // peticiones simultáneas al servidor (OCR es costoso)
        const BASE_SCALE = 1.5;

        let pdfDoc = null;
        let pageScale = BASE_SCALE;     // ajustada al ancho de pantalla en loadPDF
        let highlightOn = false;        // se encendió el resaltado
        let scanPromise = null;
        let userNavigated = false;

        const pagesRendering = new Set();
        const highlightCache = new Map();   // pageNum -> Promise<resultado>
        const pageResults = new Map();      // pageNum -> resultado con coincidencias
        const abortCtl = new AbortController();
        const sleep = ms => new Promise(r => setTimeout(r, ms));
        const measureCtx = document.createElement('canvas').getContext('2d');

        // ── Normalización (misma regla que code_match_key en helpers/tenant.php) ──
        const SEP_RE = /[\s\-.,;:_\/ ]/;
        const normChar = ch => ch.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        const keyOf = str => { let o = ''; for (const ch of str) if (!SEP_RE.test(ch)) o += normChar(ch); return o; };
        const termKeys = termsToHighlight.map(term => ({ term, key: keyOf(term) })).filter(t => t.key);

        // ── Búsqueda en la capa de texto (navegador) ──────────────────
        // Coordenadas en puntos PDF (escala 1): mismo formato que el servidor.
        async function findInTextLayer(pageNum) {
            const page = await pdfDoc.getPage(pageNum);
            const vp = page.getViewport({ scale: 1 });
            const tc = await page.getTextContent();

            // Texto continuo sin separadores + mapa carácter → (item, posición).
            // Une los saltos de línea, así un código partido al final de línea
            // ("M:" + "8278") también se encuentra.
            let stream = '';
            const map = [];
            let textChars = 0;
            tc.items.forEach((item, idx) => {
                const s = item.str || '';
                for (let k = 0; k < s.length; k++) {
                    const ch = s[k];
                    if (/\s/.test(ch)) continue;
                    textChars++;
                    if (SEP_RE.test(ch)) continue;
                    for (const c of normChar(ch)) { stream += c; map.push([idx, k]); }
                }
            });

            const highlights = [];
            const matches = [];
            for (const { term, key } of termKeys) {
                let from = 0, pos;
                while ((pos = stream.indexOf(key, from)) !== -1) {
                    from = pos + key.length;
                    if (!edgeOk(tc, map[pos], -1, key[0]) || !edgeOk(tc, map[pos + key.length - 1], 1, key[key.length - 1])) continue;
                    // Un rectángulo por fragmento (item) que toca la coincidencia
                    const segments = [];
                    for (let c = pos; c < pos + key.length; c++) {
                        const [idx, k] = map[c];
                        const last = segments[segments.length - 1];
                        if (last && last.idx === idx) last.end = k + 1;
                        else segments.push({ idx, start: k, end: k + 1 });
                    }
                    const matchNum = matches.length;
                    for (const seg of segments) {
                        const box = itemSubstringBox(tc, tc.items[seg.idx], seg.start, seg.end, vp);
                        if (box) highlights.push({ ...box, term, match: matchNum });
                    }
                    matches.push({ term });
                }
            }

            return {
                success: true, source: 'text', textChars,
                matches, match_count: matches.length, highlights,
                image_width: vp.width, image_height: vp.height
            };
        }

        // Bordes del código: no puede ir pegado a otra letra/número ("11251" no es
        // "1251") ni ser parte de una cifra con puntos ("1.251.000" no es "1251").
        // El borde de un fragmento de texto cuenta como separación.
        const isAlnum = ch => /[\p{L}\p{N}]/u.test(ch);
        const isDigit = ch => /\p{N}/u.test(ch);
        function edgeOk(tc, [idx, k], dir, termEdge) {
            const s = tc.items[idx].str || '';
            let j = k + dir, skipped = 0;
            while (j >= 0 && j < s.length && /[.,]/.test(s[j])) { j += dir; skipped++; }
            if (j < 0 || j >= s.length) return true;
            const ch = s[j];
            if (!isAlnum(ch)) return true;
            return skipped > 0 && !(isDigit(ch) && isDigit(termEdge));
        }

        // Caja (en puntos) de item.str[start:end], proporcional al ancho medido con su fuente
        function itemSubstringBox(tc, item, start, end, vp) {
            const tx = pdfjsLib.Util.transform(vp.transform, item.transform);
            const fontHeight = Math.hypot(tx[2], tx[3]);
            if (!fontHeight || !item.width) return null;
            const style = tc.styles[item.fontName] || {};
            const ascent = style.ascent ? style.ascent : (style.descent ? 1 + style.descent : 0.8);
            const itemWidth = item.width * vp.scale;

            const s = item.str;
            measureCtx.font = `100px ${style.fontFamily || 'sans-serif'}`;
            const full = measureCtx.measureText(s).width || s.length;
            const pre = start > 0 ? measureCtx.measureText(s.slice(0, start)).width : 0;
            const sub = measureCtx.measureText(s.slice(start, end)).width;

            return {
                x: tx[4] + itemWidth * (pre / full),
                y: tx[5] - fontHeight * ascent,
                w: Math.max(itemWidth * (sub / full), 2),
                h: fontHeight
            };
        }

        // ── Servidor (solo hojas escaneadas) ──────────────────────────
        function createLimiter(max) {
            let active = 0;
            const queue = [];
            const next = () => {
                if (active >= max || queue.length === 0) return;
                active++;
                const { fn, resolve, reject } = queue.shift();
                fn().then(resolve, reject).finally(() => { active--; next(); });
            };
            return fn => new Promise((resolve, reject) => { queue.push({ fn, resolve, reject }); next(); });
        }
        const serverLimit = createLimiter(SERVER_CONCURRENCY);

        // Reintenta si el servidor está ocupado o limita peticiones (429)
        function fetchServerHighlights(pageNum) {
            return serverLimit(async () => {
                const params = new URLSearchParams({
                    cliente: clientCode, doc: docId, page: pageNum, terms: termsToHighlight.join(',')
                });
                for (let attempt = 0; attempt < 4; attempt++) {
                    let resp;
                    try {
                        resp = await fetch(`ocr_text_public.php?${params}`, { signal: abortCtl.signal });
                    } catch (e) {
                        if (e.name === 'AbortError') return null;
                        if (attempt < 3) { await sleep(1500 * (attempt + 1)); continue; }
                        throw e;
                    }
                    if (resp.status === 429) {
                        const wait = parseInt(resp.headers.get('Retry-After'), 10) || 5;
                        await sleep(Math.min(wait, 60) * 1000);
                        continue;
                    }
                    let result = null;
                    try { result = await resp.json(); } catch (e) { result = null; }
                    if ((!result || !result.success) && attempt < 3) {
                        await sleep(1500 * (attempt + 1));
                        continue;
                    }
                    return result;
                }
                return { success: false, error: 'Servidor ocupado' };
            });
        }

        window.addEventListener('beforeunload', () => abortCtl.abort());

        async function computeHighlights(pageNum) {
            const textResult = await findInTextLayer(pageNum);
            if (textResult.textChars >= MIN_TEXT_CHARS || textResult.match_count > 0) return textResult;
            // Hoja escaneada → servidor. Si falla, quedarse con lo poco que haya en texto.
            const server = await fetchServerHighlights(pageNum).catch(() => null);
            return (server && server.success) ? server : textResult;
        }

        // Única puerta de entrada para los resaltados de una hoja
        function getHighlightsForPage(pageNum) {
            if (!highlightCache.has(pageNum)) {
                const promise = computeHighlights(pageNum).catch(e => {
                    highlightCache.delete(pageNum);
                    throw e;
                });
                highlightCache.set(pageNum, promise);
            }
            return highlightCache.get(pageNum);
        }

        // ── Carga del PDF ─────────────────────────────────────────────
        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;

                // Escala según el ancho disponible (no agranda en desktop, sí ajusta en móvil)
                const firstPage = await pdfDoc.getPage(1);
                const vp1 = firstPage.getViewport({ scale: 1 });
                const pdfViewer = document.querySelector('.pdf-viewer');
                const containerWidth = (pdfViewer ? pdfViewer.clientWidth : window.innerWidth) - 8;
                pageScale = Math.min(BASE_SCALE, containerWidth / vp1.width);
                const phW = vp1.width * pageScale, phH = vp1.height * pageScale;

                // Esqueletos con el tamaño real de la hoja 1 (evita saltos de scroll)
                container.innerHTML = '';
                for (let i = 1; i <= numPages; i++) createPlaceholder(i, numPages, phW, phH);

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && !entry.target.dataset.rendered) {
                            renderPage(parseInt(entry.target.dataset.pageNum), entry.target);
                        }
                    });
                }, { root: null, rootMargin: '800px', threshold: 0.01 });
                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

                const p1 = document.getElementById('pub-page-1');
                const firstRender = p1 ? renderPage(1, p1) : Promise.resolve();

                if (AUTO_HIGHLIGHT && termKeys.length > 0) {
                    // Arranca cuando la hoja 1 ya está pedida (el worker de PDF.js atiende en orden)
                    firstRender.then(() => highlightCode());
                }
            } catch (err) {
                console.error('Error:', err);
                container.innerHTML = '';
                const p = document.createElement('p');
                p.style.cssText = 'color:red; padding:40px; text-align:center;';
                p.textContent = 'Error al cargar el documento: ' + err.message;
                container.appendChild(p);
            }
        }

        function createPlaceholder(pageNum, total, w, h) {
            const outer = document.createElement('div');
            outer.innerHTML = `
                <div id="pub-page-${pageNum}" class="pdf-page-wrapper" data-page-num="${pageNum}"
                     style="width:${w}px; height:${h}px; display:flex; align-items:center; justify-content:center; background:#fafafa;">
                    <span style="color:#999; font-size:14px;">Cargando página ${pageNum}...</span>
                </div>
                <div class="page-number">Página ${pageNum} de ${total}</div>
            `;
            container.appendChild(outer);
        }

        async function renderPage(pageNum, wrapper) {
            if (wrapper.dataset.rendered || pagesRendering.has(pageNum)) return;
            pagesRendering.add(pageNum);
            wrapper.dataset.rendered = 'pending';

            try {
                // Pedir la búsqueda ANTES de dibujar: queda primera en la cola del worker
                const hlPromise = highlightOn ? getHighlightsForPage(pageNum).catch(() => null) : null;

                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale: pageScale });

                // Pantallas de alta densidad (Retina / AMOLED): canvas × DPR para nitidez
                const dpr = window.devicePixelRatio || 1;
                const cssWidth = viewport.width;
                const cssHeight = viewport.height;

                const canvas = document.createElement('canvas');
                canvas.width = Math.round(cssWidth * dpr);
                canvas.height = Math.round(cssHeight * dpr);
                canvas.style.width = cssWidth + 'px';
                canvas.style.height = cssHeight + 'px';
                const ctx = canvas.getContext('2d');
                ctx.scale(dpr, dpr);

                await page.render({ canvasContext: ctx, viewport }).promise;

                wrapper.innerHTML = '';
                wrapper.style.width = cssWidth + 'px';
                wrapper.style.height = cssHeight + 'px';
                wrapper.style.display = 'block';
                wrapper.appendChild(canvas);
                wrapper.dataset.rendered = 'true';

                if (hlPromise) hlPromise.then(r => paintHighlights(pageNum, r));
                else if (pageResults.has(pageNum)) paintHighlights(pageNum, pageResults.get(pageNum));
            } catch (err) {
                console.error('Error página ' + pageNum, err);
                delete wrapper.dataset.rendered; // reintentar al volver a verla
            } finally {
                pagesRendering.delete(pageNum);
            }
        }

        // Dibuja los rectángulos de una hoja. Idempotente: reemplaza el overlay anterior.
        function paintHighlights(pageNum, result) {
            if (!result || !result.success || !result.highlights || !result.highlights.length) return;
            if (!(result.image_width > 0)) return;
            const wrapper = document.getElementById(`pub-page-${pageNum}`);
            const canvas = wrapper && wrapper.querySelector('canvas');
            if (!canvas) return;

            // Tamaño CSS del canvas (no el físico × DPR)
            const cssW = parseFloat(canvas.style.width) || canvas.width;
            const cssH = parseFloat(canvas.style.height) || canvas.height;
            const sx = cssW / result.image_width;
            const sy = cssH / result.image_height;

            const old = wrapper.querySelector('.ocr-hl-overlay');
            if (old) old.remove();

            const overlay = document.createElement('div');
            overlay.className = 'ocr-hl-overlay';
            overlay.style.cssText = `position:absolute; top:0; left:0; width:${cssW}px; height:${cssH}px; pointer-events:none; z-index:5;`;

            const active = navItems[currentIdx];
            const frag = document.createDocumentFragment();
            result.highlights.forEach((hl, i) => {
                const padX = Math.max(hl.h * 0.12, 1.5);
                const padY = Math.max(hl.h * 0.18, 1.5);
                const rect = document.createElement('div');
                rect.dataset.match = hl.match ?? i;
                rect.title = hl.term || '';
                rect.style.left = ((hl.x - padX) * sx) + 'px';
                rect.style.top = ((hl.y - padY) * sy) + 'px';
                rect.style.width = ((hl.w + padX * 2) * sx) + 'px';
                rect.style.height = ((hl.h + padY * 2) * sy) + 'px';
                if (active && active.page === pageNum && String(active.match) === rect.dataset.match) {
                    rect.classList.add('hl-active');
                }
                frag.appendChild(rect);
            });
            overlay.appendChild(frag);
            wrapper.appendChild(overlay);
        }

        // ── Navegación entre coincidencias (por datos, no por DOM) ────
        let navItems = [];   // { page, match, y, x }
        let currentIdx = -1;

        function rebuildNavItems() {
            const prev = navItems[currentIdx];
            navItems = [];
            [...pageResults.keys()].sort((a, b) => a - b).forEach(page => {
                const r = pageResults.get(page);
                const byMatch = new Map();
                r.highlights.forEach((hl, i) => {
                    const m = hl.match ?? i;
                    if (!byMatch.has(m)) byMatch.set(m, { page, match: m, y: hl.y, x: hl.x });
                });
                [...byMatch.values()].sort((a, b) => (a.y - b.y) || (a.x - b.x)).forEach(it => navItems.push(it));
            });
            currentIdx = prev ? navItems.findIndex(it => it.page === prev.page && it.match === prev.match) : -1;
        }

        function updateNavBar(scanning) {
            const navBar = document.getElementById('navBar');
            if (navItems.length === 0) { navBar.classList.remove('visible'); return; }
            navBar.classList.add('visible');
            const n = navItems.length;
            document.getElementById('navCounter').textContent = `${currentIdx >= 0 ? currentIdx + 1 : 0} / ${n}`;
            document.getElementById('navTotal').textContent =
                `${n} coincidencia${n !== 1 ? 's' : ''}` + (scanning ? ' · buscando…' : '');
            document.getElementById('btnPrev').disabled = currentIdx <= 0;
            document.getElementById('btnNext').disabled = currentIdx >= n - 1;
        }

        async function setActive(idx) {
            if (idx < 0 || idx >= navItems.length) return;
            currentIdx = idx;
            const item = navItems[idx];
            updateNavBar(!!scanPromise);

            const wrapper = document.getElementById(`pub-page-${item.page}`);
            if (!wrapper) return;
            if (wrapper.dataset.rendered !== 'true') {
                wrapper.scrollIntoView({ block: 'start' });
                await renderPage(item.page, wrapper);
                while (pagesRendering.has(item.page)) await sleep(50);
            }
            const r = pageResults.get(item.page);
            if (r) paintHighlights(item.page, r);
            document.querySelectorAll('.ocr-hl-overlay div.hl-active').forEach(el => el.classList.remove('hl-active'));
            const rects = wrapper.querySelectorAll(`.ocr-hl-overlay div[data-match="${item.match}"]`);
            rects.forEach(el => el.classList.add('hl-active'));
            if (rects[0]) rects[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function navPrev() { userNavigated = true; if (currentIdx > 0) setActive(currentIdx - 1); }
        function navNext() { userNavigated = true; if (currentIdx < navItems.length - 1) setActive(currentIdx + 1); }

        document.addEventListener('keydown', e => {
            if (!navItems.length) return;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); navNext(); }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); navPrev(); }
        });

        // ── Progreso ──────────────────────────────────────────────────
        function setProgress(cur, total) {
            const bar = document.getElementById('progressBar');
            const fill = document.getElementById('progressFill');
            if (total <= 0) { bar.style.display = 'none'; return; }
            bar.style.display = 'block';
            fill.style.width = Math.round((cur / total) * 100) + '%';
            if (cur >= total) setTimeout(() => { bar.style.display = 'none'; }, 600);
        }

        // ── RESALTADO PRINCIPAL (radar sobre todas las hojas) ─────────
        function highlightCode() {
            if (!pdfDoc || termKeys.length === 0) return;
            if (scanPromise) return scanPromise;

            // Ya se buscó: solo volver a la primera coincidencia
            if (highlightOn) {
                if (navItems.length) { userNavigated = true; setActive(0); }
                showResult();
                return;
            }

            highlightOn = true;
            const btn = document.getElementById('btnResaltar');
            const headerCode = document.getElementById('headerCode');
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Buscando...'; }
            if (headerCode) headerCode.classList.add('searching');

            const total = pdfDoc.numPages;
            let scanned = 0;
            setProgress(0, total);

            const processPage = async (pageNum) => {
                try {
                    const result = await getHighlightsForPage(pageNum);
                    if (result && result.success && result.highlights && result.highlights.length > 0) {
                        pageResults.set(pageNum, result);
                        paintHighlights(pageNum, result);
                        rebuildNavItems();
                        // Llevar la vista a la primera coincidencia (si el usuario no se movió)
                        if (currentIdx < 0 && !userNavigated) setActive(0);
                    }
                } catch (e) {
                    console.warn(`Resaltado: error en página ${pageNum}`, e);
                } finally {
                    scanned++;
                    setProgress(scanned, total);
                    if (btn) btn.innerHTML = `⏳ ${scanned}/${total}`;
                    updateNavBar(true);
                }
            };

            // Cola en orden de página con 3 trabajadores: el texto se resuelve al
            // instante y las hojas escaneadas (servidor) no frenan a las demás
            let nextPage = 1;
            const worker = async () => { while (nextPage <= total) await processPage(nextPage++); };
            scanPromise = Promise.all(Array.from({ length: Math.min(3, total) }, worker)).then(() => {
                scanPromise = null;
                if (headerCode) headerCode.classList.remove('searching');
                updateNavBar(false);
                showResult();
            });
            return scanPromise;
        }

        function showResult() {
            const btn = document.getElementById('btnResaltar');
            if (!btn) return;
            const n = navItems.length;
            if (n > 0) {
                btn.innerHTML = `✅ ${n} hallado${n !== 1 ? 's' : ''}`;
                btn.style.background = '#15803d';
            } else {
                btn.innerHTML = '❌ No encontrado';
                btn.style.background = '#dc2626';
            }
            btn.disabled = false;
            clearTimeout(showResult._t);
            showResult._t = setTimeout(() => {
                btn.innerHTML = n > 0 ? '🔍 Ir al primero' : '🔍 Resaltar';
                btn.style.background = '#16a34a';
            }, 4000);
        }

        // Si el usuario se desplaza por su cuenta, no arrastrarlo a la coincidencia
        ['wheel', 'touchmove'].forEach(ev => window.addEventListener(ev, () => { userNavigated = true; }, { passive: true }));

        loadPDF();
    </script>
</body>

</html>