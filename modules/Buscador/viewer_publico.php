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

// Preparar términos a resaltar
$termsToHighlight = [];
if (!empty($searchTerm)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_unique(array_filter(array_map('trim', $splitTerms)));
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
    <script src="https://cdn.jsdelivr.net/npm/mark.js/dist/mark.min.js"></script>
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

        .text-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            opacity: 1;
            line-height: 1;
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
            cursor: text;
        }

        /* Resaltado amarillo-dorado — mix-blend-mode solo en mark */
        .text-layer mark {
            padding: 0;
            margin: 0;
            border-radius: 3px;
            color: transparent;
            mix-blend-mode: multiply;
            background-color: var(--hl-color) !important;
            outline: 1.5px solid var(--hl-border);
            transition: background-color 0.15s;
        }

        /* Marca activa (navegación actual) */
        .text-layer mark.hl-active {
            background-color: rgba(255, 120, 0, 0.80) !important;
            outline: 2px solid rgba(200, 80, 0, 0.9);
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

        /* OCR overlay highlights */
        .ocr-hl-overlay div {
            border-radius: 4px;
            outline: 1.5px solid var(--hl-border);
        }

        .ocr-hl-overlay div.hl-active {
            background: rgba(255, 120, 0, 0.75) !important;
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

            .text-layer mark {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
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
        const termsToHighlight = <?= json_encode(array_values($termsToHighlight)) ?>;
        const clientCode = '<?= addslashes($clientCode) ?>';
        const docId = <?= $documentId ?>;
        const container = document.getElementById('pdfContainer');
        // El scale se calcula dinámicamente en renderPage según el ancho disponible
        const BASE_SCALE = 1.5;

        let pdfDoc = null;
        let highlightDone = false;

        const pagesRendering = new Set();
        const ocrCache = new Map();

        // ── Navegación entre ocurrencias ──────────────────────────────
        let allMarks = [];   // { el, type:'mark'|'ocr-div' }
        let currentIdx = -1;

        function buildMarkList() {
            allMarks = [];
            // Recolectar <mark> del text-layer
            document.querySelectorAll('.text-layer mark').forEach(el => {
                allMarks.push({ el, type: 'mark' });
            });
            // Recolectar divs del overlay OCR
            document.querySelectorAll('.ocr-hl-overlay div').forEach(el => {
                // Calcular posición absoluta en la página para ordenar verticalmente
                const rect = el.getBoundingClientRect();
                allMarks.push({ el, type: 'ocr-div', top: rect.top + window.scrollY });
            });
            // Ordenar por posición vertical en el documento
            allMarks.sort((a, b) => {
                const ra = a.el.getBoundingClientRect();
                const rb = b.el.getBoundingClientRect();
                const topA = ra.top + window.scrollY;
                const topB = rb.top + window.scrollY;
                return topA - topB;
            });
        }

        function updateNavBar() {
            const navBar = document.getElementById('navBar');
            const counter = document.getElementById('navCounter');
            const total = document.getElementById('navTotal');
            const btnPrev = document.getElementById('btnPrev');
            const btnNext = document.getElementById('btnNext');

            if (allMarks.length === 0) {
                navBar.classList.remove('visible');
                return;
            }

            navBar.classList.add('visible');
            counter.textContent = currentIdx >= 0 ? `${currentIdx + 1} / ${allMarks.length}` : `0 / ${allMarks.length}`;
            total.textContent = `${allMarks.length} coincidencia${allMarks.length !== 1 ? 's' : ''}`;
            btnPrev.disabled = currentIdx <= 0;
            btnNext.disabled = currentIdx >= allMarks.length - 1;
        }

        function setActive(idx) {
            // Quitar activo previo
            document.querySelectorAll('.hl-active').forEach(el => el.classList.remove('hl-active'));

            if (idx < 0 || idx >= allMarks.length) return;
            currentIdx = idx;
            const item = allMarks[idx];
            item.el.classList.add('hl-active');
            item.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            updateNavBar();
        }

        function navPrev() { if (currentIdx > 0) setActive(currentIdx - 1); }
        function navNext() { if (currentIdx < allMarks.length - 1) setActive(currentIdx + 1); }

        // Teclado: ← →
        document.addEventListener('keydown', e => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') navNext();
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') navPrev();
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

        // ── Carga PDF ─────────────────────────────────────────────────
        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;
                container.innerHTML = '';

                for (let i = 1; i <= numPages; i++) {
                    createPlaceholder(i, numPages);
                }

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && !entry.target.dataset.rendered) {
                            const pageNum = parseInt(entry.target.dataset.pageNum);
                            entry.target.dataset.rendered = 'true';
                            renderPage(pageNum, entry.target);
                        }
                    });
                }, { root: null, rootMargin: '400px', threshold: 0.05 });

                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

                // Renderizar primera página
                const p1 = document.getElementById('pub-page-1');
                if (p1) {
                    await renderPage(1, p1);
                    p1.dataset.rendered = 'true';
                }

                // AUTO-HIGHLIGHT con retry inteligente
                if (termsToHighlight.length > 0) {
                    waitForTextLayerAndHighlight();
                }

            } catch (err) {
                console.error("Error:", err);
                container.innerHTML = `<p style="color:red; padding:40px; text-align:center;">Error al cargar el documento: ${err.message}</p>`;
            }
        }

        // Retry hasta que el text-layer de página 1 tenga spans (máx 4 intentos)
        function waitForTextLayerAndHighlight(attempt = 0) {
            const tl = document.querySelector('#pub-page-1 .text-layer');
            const hasSpans = tl && tl.querySelectorAll('span').length > 0;
            if (hasSpans || attempt >= 4) {
                highlightCode();
            } else {
                setTimeout(() => waitForTextLayerAndHighlight(attempt + 1), 400 + attempt * 200);
            }
        }

        function createPlaceholder(pageNum, total) {
            const outer = document.createElement('div');
            // min-height adaptado al ancho de pantalla para evitar placeholders gigantes en móvil
            const phHeight = Math.min(600, Math.round(window.innerWidth * 1.3));
            outer.innerHTML = `
                <div id="pub-page-${pageNum}" class="pdf-page-wrapper" data-page-num="${pageNum}"
                     style="min-height:${phHeight}px; width:100%; display:flex; align-items:center; justify-content:center; background:#fafafa;">
                    <span style="color:#999; font-size:14px;">Cargando página ${pageNum}...</span>
                </div>
                <div class="page-number">Página ${pageNum} de ${total}</div>
            `;
            container.appendChild(outer);
        }

        async function renderPage(pageNum, wrapper) {
            if (pagesRendering.has(pageNum)) return;
            pagesRendering.add(pageNum);

            try {
                const page = await pdfDoc.getPage(pageNum);

                // Calcular scale dinámico: ajusta el PDF al ancho disponible del contenedor
                const pdfViewer = document.querySelector('.pdf-viewer');
                const containerWidth = pdfViewer ? pdfViewer.clientWidth - 8 : window.innerWidth - 8;
                const defaultViewport = page.getViewport({ scale: 1 });
                const fitScale = containerWidth / defaultViewport.width;
                // Usar el menor entre BASE_SCALE y fitScale para no agrandar en desktop pero sí escalar en móvil
                const scale = Math.min(BASE_SCALE, fitScale);

                const viewport = page.getViewport({ scale });

                wrapper.innerHTML = '';
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';
                wrapper.style.minHeight = 'auto';
                wrapper.style.display = 'block';

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                wrapper.appendChild(canvas);

                await page.render({ canvasContext: ctx, viewport }).promise;

                const textContent = await page.getTextContent();
                if (textContent.items && textContent.items.length > 0) {
                    const textDiv = document.createElement('div');
                    textDiv.className = 'text-layer';
                    textDiv.style.width = viewport.width + 'px';
                    textDiv.style.height = viewport.height + 'px';
                    wrapper.appendChild(textDiv);

                    await pdfjsLib.renderTextLayer({
                        textContent,
                        container: textDiv,
                        viewport,
                        textDivs: []
                    }).promise;
                }

            } catch (err) {
                console.error("Error página " + pageNum, err);
            } finally {
                pagesRendering.delete(pageNum);
            }
        }

        // ── RESALTADO PRINCIPAL ───────────────────────────────────────
        async function highlightCode() {
            if (!pdfDoc || termsToHighlight.length === 0) return;

            const btn = document.getElementById('btnResaltar');
            const headerCode = document.getElementById('headerCode');

            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Buscando...'; }
            if (headerCode) headerCode.classList.add('searching');

            highlightDone = false;
            allMarks = [];
            currentIdx = -1;

            const numPages = pdfDoc.numPages;
            setProgress(0, numPages);

            for (let pageNum = 1; pageNum <= numPages; pageNum++) {

                const wrapper = document.getElementById(`pub-page-${pageNum}`);
                if (!wrapper) { setProgress(pageNum, numPages); continue; }

                // Asegurar que la página esté renderizada
                if (!wrapper.dataset.rendered || wrapper.dataset.rendered === 'placeholder') {
                    wrapper.dataset.rendered = 'true';
                    await renderPage(pageNum, wrapper);
                }

                // Esperar renderizado activo
                while (pagesRendering.has(pageNum)) {
                    await new Promise(r => setTimeout(r, 80));
                }

                // ── Capa 1: Mark.js en text-layer ──
                const textLayer = wrapper.querySelector('.text-layer');
                let foundInTextLayer = false;

                if (textLayer) {
                    foundInTextLayer = await new Promise(resolve => {
                        const instance = new Mark(textLayer);
                        instance.mark(termsToHighlight, {
                            element: 'mark',
                            accuracy: 'partially',
                            separateWordSearch: false,
                            done: () => {
                                const marks = textLayer.querySelectorAll('mark');
                                resolve(marks.length > 0);
                            }
                        });
                    });

                    if (foundInTextLayer) {
                        highlightDone = true;
                    }
                }

                // ── Capa 2: OCR fallback si no encontró con text-layer ──
                if (!foundInTextLayer) {
                    try {
                        const termsStr = encodeURIComponent(termsToHighlight.join(','));
                        const resp = await fetch(`ocr_text_public.php?cliente=${clientCode}&doc=${docId}&page=${pageNum}&terms=${termsStr}`);
                        const result = await resp.json();

                        if (result.success && result.highlights && result.highlights.length > 0 && result.image_width > 0) {
                            const canvas = wrapper.querySelector('canvas');
                            if (!canvas) { setProgress(pageNum, numPages); continue; }

                            const scaleX = canvas.width / result.image_width;
                            const scaleY = canvas.height / result.image_height;

                            const oldOverlay = wrapper.querySelector('.ocr-hl-overlay');
                            if (oldOverlay) oldOverlay.remove();

                            const overlay = document.createElement('div');
                            overlay.className = 'ocr-hl-overlay';
                            overlay.style.cssText = `
                                position: absolute; top: 0; left: 0;
                                width: ${canvas.width}px; height: ${canvas.height}px;
                                pointer-events: none; z-index: 5;
                            `;

                            result.highlights.forEach((hl, idx) => {
                                const padX = Math.max(hl.h * 0.10, 2);
                                const padY = Math.max(hl.h * 0.15, 2);
                                const rect = document.createElement('div');
                                rect.style.cssText = `
                                    position: absolute;
                                    left:   ${(hl.x - padX) * scaleX}px;
                                    top:    ${(hl.y - padY) * scaleY}px;
                                    width:  ${(hl.w + padX * 2) * scaleX}px;
                                    height: ${(hl.h + padY * 2) * scaleY}px;
                                    background: var(--hl-color);
                                    mix-blend-mode: multiply;
                                    animation: hlFadeIn 0.2s ease-out ${idx * 30}ms both;
                                `;
                                overlay.appendChild(rect);
                            });

                            wrapper.appendChild(overlay);
                            highlightDone = true;
                        }
                    } catch (e) {
                        console.warn(`OCR fallback error pág ${pageNum}:`, e);
                    }
                }

                setProgress(pageNum, numPages);
            }

            // Construir lista de marks ordenada y activar primera
            buildMarkList();
            if (allMarks.length > 0) {
                setActive(0);
            }
            updateNavBar();

            // Feedback en botón
            if (btn) {
                if (highlightDone) {
                    btn.innerHTML = `✅ ${allMarks.length} hallado${allMarks.length !== 1 ? 's' : ''}`;
                    btn.style.background = '#15803d';
                } else {
                    btn.innerHTML = '❌ No encontrado';
                    btn.style.background = '#dc2626';
                }
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = '🔍 Resaltar';
                    btn.style.background = '#16a34a';
                }, 4000);
            }

            if (headerCode) headerCode.classList.remove('searching');
        }

        loadPDF();
    </script>
</body>

</html>