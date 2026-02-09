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

// Obtener cliente desde parámetro
$clientCode = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
if (empty($clientCode)) {
    die('Cliente no especificado');
}

// Verificar que el cliente existe
$clientDir = CLIENTS_DIR . "/{$clientCode}";
if (!is_dir($clientDir)) {
    die('Cliente no encontrado');
}

$db = open_client_db($clientCode);

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
$pdfUrl = $baseUrl . 'clients/' . $clientCode . '/uploads/' . $relativePath;

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
        <?php if (!empty($searchTerm)): ?>
            <span class="header-code">Código:
                <?= htmlspecialchars(strtoupper($searchTerm)) ?>
            </span>
        <?php endif; ?>
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

    <!-- Footer -->
    <footer class="public-footer">
        KINO COMPANY S.A.S - Importador directo de relojería y otros productos<br>
        <a href="https://kinocompanysas.kyte.site/es" target="_blank">kinocompanysas.kyte.site</a>
    </footer>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const termsToHighlight = <?= json_encode(array_values($termsToHighlight)) ?>;
        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;
        let hasScrolledToMark = false;

        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;
                container.innerHTML = '';

                for (let i = 1; i <= numPages; i++) {
                    await renderPage(i);
                }

            } catch (err) {
                console.error("Error:", err);
                container.innerHTML = `<p style="color:red; padding:40px; text-align:center;">Error al cargar el documento: ${err.message}</p>`;
            }
        }

        async function renderPage(pageNum) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                // Contenedor de página
                const pageWrapper = document.createElement('div');
                pageWrapper.className = 'pdf-page-wrapper';
                pageWrapper.style.width = viewport.width + 'px';
                pageWrapper.style.height = viewport.height + 'px';

                // Canvas
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                pageWrapper.appendChild(canvas);

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // Capa de texto
                const textDiv = document.createElement('div');
                textDiv.className = 'text-layer';
                textDiv.style.width = viewport.width + 'px';
                textDiv.style.height = viewport.height + 'px';
                pageWrapper.appendChild(textDiv);

                const textContent = await page.getTextContent();

                if (textContent.items && textContent.items.length > 0) {
                    await pdfjsLib.renderTextLayer({
                        textContent: textContent,
                        container: textDiv,
                        viewport: viewport,
                        textDivs: []
                    }).promise;

                    // Resaltar términos
                    if (termsToHighlight.length > 0) {
                        const instance = new Mark(textDiv);
                        instance.mark(termsToHighlight, {
                            element: "mark",
                            accuracy: "partially",
                            separateWordSearch: false,
                            done: () => {
                                // Auto-scroll al primer resaltado
                                if (!hasScrolledToMark) {
                                    const firstMark = textDiv.querySelector('mark');
                                    if (firstMark) {
                                        hasScrolledToMark = true;
                                        setTimeout(() => {
                                            firstMark.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }, 300);
                                    }
                                }
                            }
                        });
                    }
                }

                container.appendChild(pageWrapper);

                // Número de página
                const pageNumDiv = document.createElement('div');
                pageNumDiv.className = 'page-number';
                pageNumDiv.textContent = `Página ${pageNum} de ${pdfDoc.numPages}`;
                container.appendChild(pageNumDiv);

            } catch (err) {
                console.error("Error página " + pageNum, err);
            }
        }

        // Iniciar carga
        loadPDF();
    </script>
</body>

</html>