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

// Obtener cliente: primero por parámetro, luego por subdominio
$clientCode = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
if (empty($clientCode)) {
    $clientCode = getClientFromSubdomain() ?? '';
}
if (empty($clientCode)) {
    die('Cliente no especificado');
}

// Verificar que el cliente existe
$clientDir = CLIENTS_DIR . "/{$clientCode}";
if (!is_dir($clientDir)) {
    die('Cliente no encontrado');
}

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
// If the path wasn't inside uploadsDir, build URL relative to project root
if ($relativePath === $pdfPath || str_starts_with($relativePath, DIRECTORY_SEPARATOR) === false && str_contains($relativePath, 'uploads/client_')) {
    // Legacy path or path outside standard uploads dir → use relative to project root
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
    <title>Documento -
        <?= htmlspecialchars($document['numero']) ?>
    </title>
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
        }

        /* Header simple */
        .public-header {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title {
            font-size: 18px;
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

        /* Contenedor del PDF */
        .pdf-viewer {
            max-width: 900px;
            margin: 20px auto;
            padding: 0 20px 40px;
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
        }

        .pdf-page-wrapper canvas {
            display: block;
            border-radius: 4px;
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
            mix-blend-mode: multiply;
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
            cursor: text;
        }

        /* Resaltado verde */
        .text-layer mark {
            padding: 0;
            margin: 0;
            border-radius: 0;
            color: transparent;
            mix-blend-mode: multiply;
            background-color: rgba(22, 101, 52, 0.55) !important;
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

            .pdf-viewer {
                padding: 0 10px 20px;
            }
        }

        /* Print styles */
        @media print {

            .public-header,
            .public-footer,
            .page-number {
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
        }
    </style>
</head>

<body>
    <!-- Header simple -->
    <header class="public-header">
        <div>
            <span class="header-badge">
                <?= strtoupper($document['tipo']) ?>
            </span>
            <span class="header-title">
                <?= htmlspecialchars($document['numero']) ?>
            </span>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if (!empty($searchTerm)): ?>
                <span class="header-code">Código:
                    <?= htmlspecialchars(strtoupper($searchTerm)) ?>
                </span>
                <button id="btnResaltar" onclick="highlightCode()"
                    style="background:#16a34a; color:white; border:none; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:4px; transition:all 0.2s;"
                    onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                    🔍 Resaltar Código
                </button>
            <?php endif; ?>
        </div>
    </header>

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
        const scale = 1.5;
        let pdfDoc = null;
        let highlightFound = false;

        const pagesRendering = new Set();
        const ocrCache = new Map();

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

                const p1 = document.getElementById('pub-page-1');
                if (p1) { renderPage(1, p1); p1.dataset.rendered = 'true'; }

            } catch (err) {
                console.error("Error:", err);
                container.innerHTML = `<p style="color:red; padding:40px; text-align:center;">Error al cargar el documento: ${err.message}</p>`;
            }
        }

        function createPlaceholder(pageNum, total) {
            const outer = document.createElement('div');
            outer.innerHTML = `
                <div id="pub-page-${pageNum}" class="pdf-page-wrapper" data-page-num="${pageNum}" 
                     style="min-height:600px; display:flex; align-items:center; justify-content:center; background:#fafafa;">
                    <span style="color:#999; font-size:14px;">Cargando página ${pageNum}...</span>
                </div>
                <div class="page-number">Página ${pageNum} de ${total}</div>
            `;
            container.appendChild(outer);
        }

        // Renderizar página SIN resaltar (solo canvas + text layer)
        async function renderPage(pageNum, wrapper) {
            if (pagesRendering.has(pageNum)) return;
            pagesRendering.add(pageNum);

            try {
                const page = await pdfDoc.getPage(pageNum);
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

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // Crear text layer (para poder marcar después)
                const textContent = await page.getTextContent();
                if (textContent.items && textContent.items.length > 0) {
                    const textDiv = document.createElement('div');
                    textDiv.className = 'text-layer';
                    textDiv.style.width = viewport.width + 'px';
                    textDiv.style.height = viewport.height + 'px';
                    wrapper.appendChild(textDiv);

                    await pdfjsLib.renderTextLayer({
                        textContent: textContent,
                        container: textDiv,
                        viewport: viewport,
                        textDivs: []
                    }).promise;
                }

            } catch (err) {
                console.error("Error página " + pageNum, err);
            } finally {
                pagesRendering.delete(pageNum);
            }
        }

        // RESALTAR CÓDIGO: busca página por página, resalta solo el primero
        async function highlightCode() {
            if (!pdfDoc || termsToHighlight.length === 0) return;

            const btn = document.getElementById('btnResaltar');
            btn.disabled = true;
            btn.innerHTML = '⏳ Buscando...';
            highlightFound = false;

            const numPages = pdfDoc.numPages;

            for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                if (highlightFound) break;

                const wrapper = document.getElementById(`pub-page-${pageNum}`);
                if (!wrapper) continue;

                // Asegurar que la página esté renderizada
                if (!wrapper.dataset.rendered || wrapper.dataset.rendered !== 'true') {
                    wrapper.dataset.rendered = 'true';
                    await renderPage(pageNum, wrapper);
                }

                // Esperar a que termine de renderizar
                while (pagesRendering.has(pageNum)) {
                    await new Promise(r => setTimeout(r, 100));
                }

                // Intentar Mark.js en text layer
                const textLayer = wrapper.querySelector('.text-layer');
                if (textLayer) {
                    const found = await new Promise(resolve => {
                        const instance = new Mark(textLayer);
                        instance.mark(termsToHighlight, {
                            element: "mark",
                            accuracy: "partially",
                            separateWordSearch: false,
                            done: () => {
                                const marks = textLayer.querySelectorAll('mark');
                                resolve(marks.length > 0);
                            }
                        });
                    });

                    if (found) {
                        highlightFound = true;
                        const firstMark = textLayer.querySelector('mark');
                        setTimeout(() => firstMark.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
                        break;
                    }
                }

                // Fallback OCR si no encontró con text layer
                try {
                    const termsStr = encodeURIComponent(termsToHighlight.join(','));
                    const resp = await fetch(`ocr_text_public.php?cliente=${clientCode}&doc=${docId}&page=${pageNum}&terms=${termsStr}`);
                    const result = await resp.json();

                    if (result.success && result.highlights && result.highlights.length > 0 && result.image_width > 0) {
                        highlightFound = true;

                        const canvas = wrapper.querySelector('canvas');
                        if (!canvas) break;

                        const scaleX = canvas.width / result.image_width;
                        const scaleY = canvas.height / result.image_height;

                        const overlay = document.createElement('div');
                        overlay.style.cssText = `
                            position: absolute; top: 0; left: 0;
                            width: ${canvas.width}px; height: ${canvas.height}px;
                            pointer-events: none; z-index: 5;
                        `;

                        // Solo el primer highlight
                        const hl = result.highlights[0];
                        const rect = document.createElement('div');
                        rect.style.cssText = `
                            position: absolute;
                            left: ${hl.x * scaleX}px; top: ${hl.y * scaleY}px;
                            width: ${hl.w * scaleX}px; height: ${hl.h * scaleY}px;
                            background: rgba(22, 101, 52, 0.45);
                            mix-blend-mode: multiply;
                            border-radius: 2px;
                            box-shadow: 0 0 8px rgba(22, 101, 52, 0.6);
                        `;
                        overlay.appendChild(rect);
                        wrapper.appendChild(overlay);

                        setTimeout(() => wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' }), 200);
                        break;
                    }
                } catch (e) {
                    console.warn(`OCR fallback error pág ${pageNum}:`, e);
                }
            }

            if (highlightFound) {
                btn.innerHTML = '✅ Código encontrado';
                btn.style.background = '#15803d';
            } else {
                btn.innerHTML = '❌ No encontrado';
                btn.style.background = '#dc2626';
            }

            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '🔍 Resaltar Código';
                btn.style.background = '#16a34a';
            }, 3000);
        }

        loadPDF();
    </script>
</body>

</html>