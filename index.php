<?php
/**
 * Gestor de Documentos - KINO TRACE
 *
 * Main document management interface with tabs:
 * - Buscar: Intelligent search
 * - Subir: Upload documents
 * - Consultar: List all documents
 * - Búsqueda por Código: Single code search
 */
session_start();
// Prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/search_engine.php';
require_once __DIR__ . '/helpers/csrf_protection.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$stats = get_search_stats($db);

// For sidebar - Nueva estructura
$currentSection = 'voraz';
$baseUrl = './';
$pageTitle = 'Gestor de Documentos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= CsrfProtection::metaTag() ?>
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <?php
    // Inyectar colores personalizados del cliente
    $clientConfig = get_client_config($code);
    if ($clientConfig) {
        $cP = $clientConfig['color_primario'] ?? '';
        $cS = $clientConfig['color_secundario'] ?? '';
        if ($cP && $cS) {
            echo "<style>:root { --accent-primary: {$cP} !important; --accent-secondary: {$cS} !important; --accent-primary-hover: {$cP} !important; --client-color: {$cP}; }</style>";
        }
    }
    ?>
    <style>
        /* Contenedor de acciones voraz */
        .voraz-actions-container {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        /* Botón: Resaltar Todos */
        .btn-voraz-highlight {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-voraz-highlight:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        /* Botón: PDF Unificado */
        .btn-voraz-unified {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 25px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(245, 87, 108, 0.4);
        }

        .btn-voraz-unified:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 87, 108, 0.6);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .voraz-actions-container {
                flex-direction: column;
            }
        }

        /* Tag para códigos faltantes */
        .code-tag.missing {
            background-color: #ff6b6b;
            color: white;
        }

        /* ========================================
           ESTILOS EXCLUSIVOS PARA BÚSQUEDA VORAZ
           No afectan otros elementos de la app
           ======================================== */

        /* Contenedor de resumen */
        .voraz-summary-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .voraz-summary-box h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
        }

        .voraz-summary-box p {
            margin: 0;
            font-size: 16px;
        }

        /* Contenedor del botón unificado */
        .voraz-unified-container {
            text-align: center;
            margin: 20px 0;
        }

        /* Botón PDF Unificado */
        .voraz-btn-unified {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 30px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(245, 87, 108, 0.4);
        }

        .voraz-btn-unified:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(245, 87, 108, 0.6);
        }

        /* Alerta de códigos no encontrados */
        .voraz-alert-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }

        .voraz-code-missing {
            display: inline-block;
            background: #ff6b6b;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            margin: 3px;
            font-weight: 600;
        }

        /* Tarjeta de resultado */
        .voraz-result-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .voraz-result-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }

        /* Header de la tarjeta */
        .voraz-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .voraz-badge {
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .voraz-date {
            color: #999;
            font-size: 14px;
        }

        /* Título del documento */
        .voraz-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }

        /* Lista de códigos */
        .voraz-codes-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .voraz-code-tag {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #a5d6a7;
        }

        /* Acciones de la tarjeta */
        .voraz-card-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        /* Botón resaltar todos */
        .voraz-btn-highlight {
            flex: 1;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(17, 153, 142, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .voraz-btn-highlight:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(17, 153, 142, 0.5);
        }

        /* Botón original */
        .voraz-btn-original {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .voraz-btn-original:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .voraz-card-actions {
                flex-direction: column;
            }

            .voraz-btn-highlight,
            .voraz-btn-original {
                width: 100%;
            }
        }

        /* Animaciones para notificaciones */
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
    </style>
</head>


<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/includes/header.php'; ?>

            <div class="page-content">
                <!-- Stats Bar -->
                <div class="stats-grid" style="margin-bottom: 1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['total_documents']) ?></div>
                            <div class="stat-label">Documentos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['unique_codes']) ?></div>
                            <div class="stat-label">Códigos Únicos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['validated_codes']) ?></div>
                            <div class="stat-label">Validados</div>
                        </div>
                    </div>
                </div>

                <!-- Secciones (sin tabs, controladas desde sidebar) -->
                <div class="card">

                    <!-- Sección: Búsqueda Voraz -->
                    <div class="section-content active" id="section-voraz">
                        <h3 style="margin-bottom: 1rem;">🎯 Búsqueda Voraz Inteligente</h3>
                        <p class="text-muted mb-4">Pega un bloque de texto con códigos. El sistema extraerá
                            automáticamente la primera columna y buscará esos códigos.</p>

                        <div class="form-group">
                            <label class="form-label">Texto con códigos (se extraerá la primera columna)</label>
                            <textarea class="form-textarea" id="bulkInput" rows="10" placeholder="Pega aquí tu texto. Ejemplo:

COD001    Descripción del producto 1
COD002    Otra descripción aquí
COD003    Más productos...

Se extraerán solo los códigos de la izquierda."></textarea>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" class="btn btn-primary" onclick="processBulkSearch()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Extraer y Buscar Códigos
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearBulkSearch()">Limpiar</button>
                        </div>

                        <div id="bulkLoading" class="loading hidden">
                            <div class="spinner"></div>
                            <p>Extrayendo códigos y buscando...</p>
                        </div>

                        <div id="extractedCodesPreview" class="hidden mt-4">
                            <div class="summary-box">
                                <h4 style="margin-bottom: 0.75rem;">📋 Códigos Extraídos</h4>
                                <div id="extractedCodesList" class="codes-list"></div>
                            </div>
                        </div>

                        <div id="bulkResults" class="hidden mt-4">
                            <div id="bulkSummary"></div>
                            <div id="bulkDocumentList" class="results-list"></div>
                        </div>
                    </div>

                    <!-- Sección: Subir -->
                    <div class="section-content" id="section-subir">
                        <div id="subir-loading" class="loading">
                            <div class="spinner"></div>
                            <p>Cargando módulo de subida...</p>
                        </div>
                        <iframe id="subir-iframe" src="modules/subir/"
                            style="width: 100%; height: 800px; border: none; display: none;"
                            onload="document.getElementById('subir-loading').style.display='none'; this.style.display='block';"></iframe>
                    </div>


                    <!-- Sección: Consultar -->
                    <div class="section-content" id="section-consultar">
                        <div class="flex justify-between items-center mb-4">
                            <h3>Lista de Documentos</h3>
                            <div class="flex gap-2">
                                <select class="form-select" id="filterTipo" style="width: auto;">
                                    <option value="">Todos los tipos</option>
                                    <option value="declaracion">📄 Declaraciones</option>
                                    <option value="factura">💰 Facturas</option>
                                    <option value="otro">📁 Otros</option>
                                </select>
                                <button class="btn btn-secondary" onclick="downloadCSV()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    CSV
                                </button>
                            </div>
                        </div>

                        <!-- Búsqueda Full-Text en PDFs -->
                        <div class="summary-box"
                            style="margin-bottom: 1rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(59, 130, 246, 0.1));">
                            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <input type="text" class="form-input" id="fulltextSearch"
                                        placeholder="🔍 Buscar palabras en PDFs y nombres de documentos..."
                                        style="width: 100%;">
                                </div>
                                <button class="btn btn-primary" onclick="searchFulltext()" id="fulltextBtn">
                                    Buscar en Contenido
                                </button>
                                <button class="btn btn-secondary" onclick="reindexDocuments()" id="reindexBtn"
                                    title="Indexar PDFs sin texto extraído">
                                    🔄 Indexar Pendientes
                                </button>
                            </div>
                            <div id="indexStatus"
                                style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-muted);"></div>
                        </div>

                        <!-- Resultados de búsqueda full-text -->
                        <div id="fulltextResults" class="hidden">
                            <div class="summary-box" style="margin-bottom: 1rem;">
                                <span id="fulltextSummary"></span>
                                <button class="btn btn-secondary" style="float: right; padding: 0.25rem 0.5rem;"
                                    onclick="clearFulltext()">✕ Limpiar</button>
                            </div>
                            <div id="fulltextList" class="results-list"></div>
                        </div>

                        <div id="documentsLoading" class="loading">
                            <div class="spinner"></div>
                            <p>Cargando documentos...</p>
                        </div>

                        <div id="documentsTable" class="hidden">
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Nombre</th>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Códigos</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="documentsTbody"></tbody>
                                </table>
                            </div>
                            <div id="pagination" class="flex justify-between items-center mt-4"></div>
                        </div>
                    </div>

                    <!-- Sección: Búsqueda por Código -->
                    <div class="section-content" id="section-codigo">
                        <h3 style="margin-bottom: 1rem;">Búsqueda por Código</h3>
                        <p class="text-muted mb-4">Busca un código específico con autocompletado.</p>

                        <div class="form-group" style="position: relative; max-width: 400px;">
                            <input type="text" class="form-input" id="singleCodeInput"
                                placeholder="Escribe un código...">
                            <div id="suggestions" class="suggestions-dropdown hidden"></div>
                        </div>

                        <div id="singleCodeResults" class="hidden mt-4">
                            <h4 class="mb-3">Documentos encontrados:</h4>
                            <div id="singleCodeList" class="results-list"></div>
                        </div>
                    </div>

                    <!-- Sección: Backup -->
                    <div class="section-content" id="section-backup">
                        <div class="loading">
                            <div class="spinner"></div>
                            <p>Cargando backup...</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = 'api.php';
        const clientCode = '<?= $code ?>';
        let currentPage = 1;

        // ============ Sections (controlled from sidebar) ============
        // La función switchSection está definida en sidebar.php
        // Este código mantiene compatibilidad con el antiguo sistema

        // Función de compatibilidad para código que usaba switchTab
        function switchTab(tabName) {
            // Mapear nombres de tab a secciones
            const section = tabName === 'codigo' ? 'codigo' : tabName;
            if (typeof switchSection === 'function') {
                switchSection(section);
            }
        }

        // Initialize section from URL
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const sectionParam = urlParams.get('section') || urlParams.get('tab');
            if (sectionParam && typeof switchSection === 'function') {
                switchSection(sectionParam);
            }
        });


        // ============ Upload Tab ============
        // NOTE: Upload functionality is now handled by iframe in section-subir
        // This code is kept for backwards compatibility but only runs if elements exist
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');

        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', () => fileInput.click());
            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadZone.classList.add('dragover');
            });
            uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    showFileName();
                }
            });

            fileInput.addEventListener('change', showFileName);
        }

        function showFileName() {
            if (fileInput && fileInput.files.length) {
                const fileNameEl = document.getElementById('fileName');
                if (fileNameEl) {
                    fileNameEl.textContent = '✓ ' + fileInput.files[0].name;
                    fileNameEl.classList.remove('hidden');
                }
            }
        }

        function resetUploadForm() {
            const form = document.getElementById('uploadForm');
            const uploadZone = document.getElementById('uploadZone');
            const fileNameDisplay = document.getElementById('fileName');

            if (!form || !uploadZone || !fileNameDisplay) return;

            // Reset form
            form.reset();

            // Clear edit mode flags
            delete form.dataset.editId;
            delete form.dataset.currentFile;

            // Reset upload zone
            const pEl = uploadZone.querySelector('p');
            if (pEl) pEl.textContent = 'Arrastra un archivo PDF o haz clic para seleccionar';
            uploadZone.style.borderColor = '';
            uploadZone.style.background = '';

            // Reset file name display
            fileNameDisplay.classList.add('hidden');
            fileNameDisplay.style.color = '';

            // Reset title and button
            const titleEl = document.querySelector('#tab-subir h3');
            if (titleEl) titleEl.textContent = 'Subir Documento';
            const uploadBtn = document.getElementById('uploadBtn');
            if (uploadBtn) {
                uploadBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Subir Documento
                `;
            }
        }

        // Only attach submit handler if form exists
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const form = e.target;
                const isEditMode = !!form.dataset.editId;

                // In edit mode, file is optional; in create mode, it's required
                if (!isEditMode && (!fileInput || !fileInput.files.length)) {
                    alert('Selecciona un archivo PDF');
                    return;
                }

                const btn = document.getElementById('uploadBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = isEditMode ? 'Guardando...' : 'Subiendo...';
                }

                const formData = new FormData();
                formData.append('action', isEditMode ? 'update' : 'upload');
                formData.append('tipo', document.getElementById('docTipo')?.value || '');
                formData.append('numero', document.getElementById('docNumero')?.value || '');
                formData.append('fecha', document.getElementById('docFecha')?.value || '');
                formData.append('proveedor', document.getElementById('docProveedor')?.value || '');
                formData.append('codes', document.getElementById('docCodes')?.value || '');

                // Add document ID if editing
                if (isEditMode) {
                    formData.append('id', form.dataset.editId);
                    formData.append('current_file', form.dataset.currentFile);
                }

                // Add file only if a new one was selected
                if (fileInput && fileInput.files.length) {
                    formData.append('file', fileInput.files[0]);
                }

                // CSRF Token in Body (Fallback)
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                formData.append('csrf_token', token);

                try {
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-CSRF-TOKEN': token
                        }
                    });
                    const result = await response.json();

                    if (result.success) {
                        alert(isEditMode ? 'Documento actualizado correctamente' : 'Documento subido correctamente');
                        resetUploadForm();

                        // Switch to Consultar tab and reload documents
                        switchTab('consultar');
                        loadDocuments();
                    } else if (result.warning) {
                        // Handle duplicate file warning
                        alert('El documento PDF que quieres subir ya existe en la base de datos.');
                    } else if (result.error) {
                        alert('Error: ' + result.error);
                    } else {
                        alert('Error: Respuesta inesperada del servidor');
                    }
                } catch (error) {
                    alert('Error: ' + error.message);
                } finally {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg> Subir Documento`;
                    }
                }
            });
        }

        // ============ Consultar Tab ============
        async function loadDocuments(page = 1, tipo = '') {
            document.getElementById('documentsLoading').classList.remove('hidden');
            document.getElementById('documentsTable').classList.add('hidden');

            try {
                const params = new URLSearchParams({
                    action: 'list',
                    page: page,
                    per_page: 20,
                    tipo: tipo
                });

                const response = await fetch(apiUrl + '?' + params);
                const result = await response.json();

                document.getElementById('documentsLoading').classList.add('hidden');
                document.getElementById('documentsTable').classList.remove('hidden');

                renderDocumentsTable(result);
            } catch (error) {
                document.getElementById('documentsLoading').classList.add('hidden');
                alert('Error: ' + error.message);
            }
        }

        function renderDocumentsTable(result) {
            const tbody = document.getElementById('documentsTbody');

            if (!result.data || result.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No hay documentos</td></tr>';
                return;
            }

            tbody.innerHTML = result.data.map(doc => `
                <tr>
                    <td><span class="badge badge-primary">${doc.tipo.toUpperCase()}</span></td>
                    <td>${doc.numero}</td>
                    <td>${doc.fecha}</td>
                    <td>${doc.proveedor || '-'}</td>
                    <td><span class="code-tag">${doc.codes.length}</span></td>
                    <td>
                        <div class="flex gap-2">
                            <button type="button" id="btn-codes-${doc.id}" class="btn btn-secondary btn-sm" title="Ver Códigos" onclick="toggleTableCodes(event, ${doc.id})">
                                Ver Códigos
                            </button>
                            ${doc.ruta_archivo ? `
                            <a href="modules/resaltar/download.php?doc=${doc.id}" class="btn btn-secondary btn-sm" title="Ver PDF Original" target="_blank">
                                Ver PDF Original
                            </a>` : ''}

                            <button class="btn btn-secondary btn-icon" title="Editar" onclick="editDoc(${doc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button class="btn btn-secondary btn-icon" title="Eliminar" onclick="deleteDoc(${doc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr id="codes-row-${doc.id}" class="hidden" style="background-color: var(--bg-tertiary);">
                    <td colspan="6">
                         <div style="padding: 1rem;">
                            <strong>Códigos vinculados:</strong>
                            <div class="codes-list" style="margin-top: 0.5rem; max-height: 400px; overflow-y: auto; display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; background: white; padding: 0.5rem; border: 1px solid #f0f0f0; border-radius: 4px;">
                                ${doc.codes.map(c => `<div style="font-family: inherit; font-size: 0.9rem; padding: 2px 0; color: #374151; width: 100%; display: block;">${c}</div>`).join('')}
                            </div>
                        </div>
                    </td>
                </tr>
            `).join('');

            // Pagination
            const pagination = document.getElementById('pagination');
            pagination.innerHTML = `
                <span class="text-muted">Página ${result.page} de ${result.last_page}</span>
                <div class="flex gap-2">
                    <button class="btn btn-secondary" ${result.page <= 1 ? 'disabled' : ''} onclick="loadDocuments(${result.page - 1})">Anterior</button>
                    <button class="btn btn-secondary" ${result.page >= result.last_page ? 'disabled' : ''} onclick="loadDocuments(${result.page + 1})">Siguiente</button>
                </div>
            `;
        }

        document.getElementById('filterTipo').addEventListener('change', (e) => {
            loadDocuments(1, e.target.value);
        });

        async function editDoc(id) {
            try {
                // Get document details using correct API action
                const response = await fetch(apiUrl + '?action=get&id=' + id);
                const doc = await response.json();

                if (!doc || doc.error) {
                    alert('Error al cargar documento: ' + (doc.error || 'No encontrado'));
                    return;
                }

                // Check if we have the inline form elements
                const docTipo = document.getElementById('docTipo');
                const uploadForm = document.getElementById('uploadForm');

                if (!docTipo || !uploadForm) {
                    // Redirect to the subir module with edit parameter through iframe
                    switchTab('subir');
                    const subirIframe = document.getElementById('subir-iframe');
                    if (subirIframe) {
                        subirIframe.src = 'modules/subir/?edit=' + id;
                    }
                    return;
                }

                // Switch to Subir tab
                switchTab('subir');

                // Fill form with document data
                docTipo.value = doc.tipo;
                const docNumero = document.getElementById('docNumero');
                if (docNumero) docNumero.value = doc.numero;
                const docFecha = document.getElementById('docFecha');
                if (docFecha) docFecha.value = doc.fecha;
                const docProveedor = document.getElementById('docProveedor');
                if (docProveedor) docProveedor.value = doc.proveedor || '';
                // Convert codes array to newline-separated text
                const docCodes = document.getElementById('docCodes');
                if (docCodes) docCodes.value = (doc.codes || []).join('\n');

                // Show current PDF filename and make upload optional
                const uploadZone = document.getElementById('uploadZone');
                const fileNameDisplay = document.getElementById('fileName');

                if (doc.ruta_archivo && fileNameDisplay && uploadZone) {
                    fileNameDisplay.textContent = `📄 PDF actual: ${doc.ruta_archivo}`;
                    fileNameDisplay.classList.remove('hidden');
                    fileNameDisplay.style.color = 'var(--accent-success)';

                    // Update upload zone text
                    const pEl = uploadZone.querySelector('p');
                    if (pEl) pEl.textContent = 'PDF actual cargado. Arrastra uno nuevo solo si deseas reemplazarlo';
                    uploadZone.style.borderColor = 'var(--accent-success)';
                    uploadZone.style.background = 'rgba(16, 185, 129, 0.05)';
                }

                // Update form title and button
                const titleEl = document.querySelector('#tab-subir h3');
                if (titleEl) titleEl.textContent = 'Editar Documento';
                const uploadBtn = document.getElementById('uploadBtn');
                if (uploadBtn) {
                    uploadBtn.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Guardar Cambios
                    `;
                }

                // Store doc ID and current file path for update
                uploadForm.dataset.editId = id;
                uploadForm.dataset.currentFile = doc.ruta_archivo || '';


            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function deleteDoc(id) {
            if (!confirm('¿Eliminar este documento?')) return;

            try {
                const response = await fetch(apiUrl + '?action=delete&id=' + id);
                const result = await response.json();

                if (result.success) {
                    loadDocuments();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function downloadCSV() {
            // Simple CSV export
            const tipo = document.getElementById('filterTipo').value;
            window.open(apiUrl + '?action=export_csv&tipo=' + tipo, '_blank');
        }

        // ============ Toggle Codes Row ============
        function toggleTableCodes(event, docId) {
            event.preventDefault();
            const codesRow = document.getElementById('codes-row-' + docId);
            const btn = document.getElementById('btn-codes-' + docId);

            if (codesRow.classList.contains('hidden')) {
                codesRow.classList.remove('hidden');
                btn.textContent = 'Ocultar Códigos';
                btn.classList.add('btn-primary');
                btn.classList.remove('btn-secondary');
            } else {
                codesRow.classList.add('hidden');
                btn.textContent = 'Ver Códigos';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-secondary');
            }
        }

        // ============ Full-Text Search in PDFs ============
        const fulltextInput = document.getElementById('fulltextSearch');

        fulltextInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') searchFulltext();
        });

        async function searchFulltext() {
            const query = fulltextInput.value.trim();

            console.log('[🔍 SEARCH] Iniciando búsqueda fulltext...', { query });

            if (query.length < 3) {
                console.warn('[⚠️ SEARCH] Query muy corto:', query.length, 'caracteres');
                showNotification('Ingresa al menos 3 caracteres', 'warning');
                return;
            }

            const btn = document.getElementById('fulltextBtn');
            btn.disabled = true;
            btn.textContent = 'Buscando...';

            try {
                console.log('[📡 SEARCH] Haciendo petición a API...', `${apiUrl}?action=fulltext_search`);

                const response = await fetch(`${apiUrl}?action=fulltext_search&query=${encodeURIComponent(query)}`);

                console.log('[📥 SEARCH] Respuesta recibida:', {
                    status: response.status,
                    statusText: response.statusText,
                    ok: response.ok
                });

                const result = await response.json();
                console.log('[✅ SEARCH] Datos parseados:', result);

                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';

                if (result.error) {
                    console.error('[❌ SEARCH] Error en respuesta:', result.error);
                    showNotification('Error: ' + result.error, 'error');
                    return;
                }

                console.log('[🎯 SEARCH] Mostrando resultados...');
                showFulltextResults(result);
            } catch (error) {
                console.error('[💥 SEARCH] Excepción capturada:', error);
                console.error('[💥 SEARCH] Stack trace:', error.stack);

                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';
                showNotification('Error de conexión: ' + error.message, 'error');
            }
        }

        // Sistema de notificaciones no-bloqueante
        function showNotification(message, type = 'info') {
            // Crear o reutilizar contenedor
            let container = document.getElementById('notification-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notification-container';
                container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
                document.body.appendChild(container);
            }

            const notification = document.createElement('div');
            const colors = {
                info: '#3b82f6',
                success: '#10b981',
                warning: '#f59e0b',
                error: '#ef4444'
            };

            notification.style.cssText = `
                background: ${colors[type] || colors.info};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                margin-bottom: 10px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                animation: slideIn 0.3s ease-out;
                max-width: 400px;
            `;
            notification.textContent = message;

            container.appendChild(notification);

            // Auto-remover después de 5 segundos
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        function showFulltextResults(result) {
            document.getElementById('fulltextResults').classList.remove('hidden');
            document.getElementById('documentsTable').classList.add('hidden');
            document.getElementById('documentsLoading').classList.add('hidden');

            document.getElementById('fulltextSummary').innerHTML =
                `<strong>${result.count}</strong> documento(s) contienen "<strong>${result.query}</strong>"`;

            if (result.results.length === 0) {
                document.getElementById('fulltextList').innerHTML =
                    '<p class="text-muted">No se encontraron coincidencias. Prueba indexar los documentos primero.</p>';
                return;
            }

            let html = '';
            for (const doc of result.results) {
                const pdfUrl = doc.ruta_archivo ? `modules/resaltar/download.php?doc=${doc.id}` : '';

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha} · ${doc.occurrences} coincidencia(s)</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        <!-- Snippet oculto para usuario final 
                        ${doc.snippet ? `<div class="result-meta" style="margin-top: 0.5rem; font-style: italic; background: rgba(255,235,59,0.1); padding: 0.5rem; border-radius: 4px;">"${doc.snippet}"</div>` : ''}
                        -->
                        <div class="result-actions" style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(result.query)}" 
                               class="btn btn-primary btn-sm" target="_blank">
                                👁️ Ver Documento
                            </a>
                            
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary btn-sm" style="white-space: nowrap;">📄 Original</a>` : ''}

                            <button onclick="editDoc(${doc.id})" class="btn btn-sm" style="background: #f59e0b; color: white;" title="Editar">
                                ✏️
                            </button>

                            <button onclick="deleteDoc(${doc.id})" class="btn btn-sm" style="background: #ef4444; color: white;" title="Eliminar">
                                🗑️
                            </button>
                        </div>
                    </div>
                `;
            }
            document.getElementById('fulltextList').innerHTML = html;
        }

        function clearFulltext() {
            document.getElementById('fulltextResults').classList.add('hidden');
            document.getElementById('documentsTable').classList.remove('hidden');
            fulltextInput.value = '';
            loadDocuments();
        }

        let isIndexing = false;
        let totalIndexedSession = 0;

        async function reindexDocuments() {
            if (isIndexing) return;
            isIndexing = true;

            const btn = document.getElementById('reindexBtn');
            const status = document.getElementById('indexStatus');

            btn.disabled = true;
            btn.innerHTML = '⏳ Indexando...';
            totalIndexedSession = 0;

            // First call to get initial pending count
            let pending = 999;
            let batchNum = 0;

            while (pending > 0) {
                batchNum++;
                status.innerHTML = `🔄 Procesando lote #${batchNum}... (Indexados: ${totalIndexedSession})`;

                try {
                    const response = await fetch(`${apiUrl}?action=reindex_documents&batch=10`);
                    const result = await response.json();

                    if (!result.success) {
                        status.innerHTML = `❌ Error: ${result.error || 'Error desconocido'}`;
                        break;
                    }

                    totalIndexedSession += result.indexed;
                    pending = result.pending;

                    status.innerHTML = `✅ Indexados: ${totalIndexedSession}, Pendientes: ${pending}`;

                    if (result.errors && result.errors.length > 0) {
                        console.log('Errores de indexación:', result.errors);
                    }

                    // If nothing was indexed but still pending, files are missing
                    if (result.indexed === 0 && pending > 0) {
                        status.innerHTML += ` <span style="color: var(--warning);">(${pending} archivos no encontrados)</span>`;
                        break;
                    }
                } catch (error) {
                    status.innerHTML = `❌ Error de red: ${error.message}`;
                    break;
                }
            }

            if (pending === 0) {
                status.innerHTML = `✅ ¡Completado! ${totalIndexedSession} documentos indexados`;
            }

            btn.disabled = false;
            btn.innerHTML = '🔄 Indexar Pendientes';
            isIndexing = false;
        }

        // ============ Single Code Search Tab ============
        let debounceTimer;
        const singleCodeInput = document.getElementById('singleCodeInput');
        const suggestionsDiv = document.getElementById('suggestions');

        singleCodeInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            const term = e.target.value.trim();

            if (term.length < 2) {
                suggestionsDiv.classList.add('hidden');
                return;
            }

            debounceTimer = setTimeout(async () => {
                try {
                    const response = await fetch(`${apiUrl}?action=suggest&term=${encodeURIComponent(term)}`);
                    const suggestions = await response.json();

                    if (suggestions.length > 0) {
                        suggestionsDiv.innerHTML = suggestions.map(s =>
                            `<div class="suggestion-item" onclick="selectCode('${s}')">${s}</div>`
                        ).join('');
                        suggestionsDiv.classList.remove('hidden');
                    } else {
                        suggestionsDiv.classList.add('hidden');
                    }
                } catch (e) {
                    suggestionsDiv.classList.add('hidden');
                }
            }, 300);
        });

        singleCodeInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchSingleCode(singleCodeInput.value.trim());
            }
        });

        async function selectCode(code) {
            singleCodeInput.value = code;
            suggestionsDiv.classList.add('hidden');
            searchSingleCode(code);
        }

        async function searchSingleCode(code) {
            if (!code) return;

            try {
                const response = await fetch(`${apiUrl}?action=search_by_code&code=${encodeURIComponent(code)}`);
                const result = await response.json();

                document.getElementById('singleCodeResults').classList.remove('hidden');

                if (!result.documents || result.documents.length === 0) {
                    document.getElementById('singleCodeList').innerHTML = '<p class="text-muted">No se encontraron documentos con este código.</p>';
                    return;
                }

                document.getElementById('singleCodeList').innerHTML = result.documents.map(doc => {
                    return `
                        <div class="result-card">
                            <div class="result-header">
                                <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                                <span class="result-meta">${doc.fecha}</span>
                            </div>
                            <div class="result-title">${doc.numero}</div>
                            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <button onclick="openHighlighter('modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(code)}')" class="btn btn-success" style="padding: 0.5rem 1rem; background: #038802;">🖍️ Resaltar</button>
                                <a href="modules/resaltar/download.php?doc=${doc.id}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 Ver PDF</a>
                            </div>
                        </div>
                    `;
                }).join('');
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        document.addEventListener('click', (e) => {
            if (!suggestionsDiv.contains(e.target) && e.target !== singleCodeInput) {
                suggestionsDiv.classList.add('hidden');
            }
        });

        // ============ Toggle Codes Display ============
        function toggleCodes(docId) {
            const codesDiv = document.getElementById('codes-' + docId);
            const icon = document.getElementById('icon-' + docId);

            if (codesDiv.style.display === 'none') {
                codesDiv.style.display = 'block';
                icon.textContent = '▼';
            } else {
                codesDiv.style.display = 'none';
                icon.textContent = '▶';
            }
        }



        function toggleTableCodes(e, docId) {
            if (e) e.preventDefault();
            const row = document.getElementById(`codes-row-${docId}`);
            const btn = document.getElementById(`btn-codes-${docId}`);

            if (row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                btn.textContent = 'Ocultar Códigos';
                btn.style.backgroundColor = '#d1d5db'; // Un gris más oscuro para indicar activo
                btn.style.color = '#1f2937';
            } else {
                row.classList.add('hidden');
                btn.textContent = 'Ver Códigos';
                btn.style.backgroundColor = '';
                btn.style.color = '';
            }
        }

        // ============ Delete Document ============
        async function confirmDelete(docId, docNumero) {
            if (!confirm(`¿Estás seguro de eliminar el documento "${docNumero}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }

            try {
                const response = await fetch(`${apiUrl}?action=delete\u0026id=${docId}`, {
                    method: 'POST'
                });
                const result = await response.json();

                if (result.error) {
                    alert('Error: ' + result.error);
                    return;
                }

                alert('✅ Documento eliminado correctamente');

                // Reload documents list
                loadDocuments();
            } catch (error) {
                alert('Error al eliminar: ' + error.message);
            }
        }

        // ============ Búsqueda Voraz Inteligente ============

        function extractFirstColumn(text) {
            const lines = text.trim().split('\n');
            const codes = [];

            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed === '') continue;

                let code = '';
                if (trimmed.includes('\t')) {
                    code = trimmed.split('\t')[0].trim();
                } else if (trimmed.match(/\s{2,}/)) {
                    code = trimmed.split(/\s{2,}/)[0].trim();
                } else {
                    code = trimmed.split(/\s+/)[0].trim();
                }

                if (code.length > 0) codes.push(code);
            }

            return [...new Set(codes)];
        }

        async function processBulkSearch() {
            const input = document.getElementById('bulkInput').value;
            if (!input.trim()) {
                alert('Por favor pega el texto con códigos');
                return;
            }

            const extractedCodes = extractFirstColumn(input);
            if (extractedCodes.length === 0) {
                alert('No se pudieron extraer códigos del texto pegado');
                return;
            }

            document.getElementById('extractedCodesList').innerHTML = extractedCodes.map(c =>
                `<span class="code-tag">${c}</span>`
            ).join('');
            document.getElementById('extractedCodesPreview').classList.remove('hidden');
            document.getElementById('bulkLoading').classList.remove('hidden');

            try {
                const formData = new FormData();
                formData.append('codes', extractedCodes.join('\n'));

                const response = await fetch(`${apiUrl}?action=search`, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                document.getElementById('bulkLoading').classList.add('hidden');
                if (result.error) {
                    alert(result.error);
                    return;
                }
                showBulkResults(result, extractedCodes);
            } catch (error) {
                document.getElementById('bulkLoading').classList.add('hidden');
                alert('Error: ' + error.message);
            }
        }

        /**
         * Muestra resultados de búsqueda VORAZ (Limpio y reconstruido)
         */
        function showBulkResults(result, searchedCodes) {
            const resultsDiv = document.getElementById('bulkResults');

            if (!result.documents || result.documents.length === 0) {
                resultsDiv.innerHTML = '<div class="alert alert-info">No se encontraron documentos.</div>';
                resultsDiv.classList.remove('hidden');
                return;
            }

            const totalDocs = result.documents.length;

            // Header simplificado
            let html = `
                <div style="background: #eef2ff; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c7d2fe;">
                    <h3 style="margin-top:0; color: #4338ca;">Resultados Voraz</h3>
                    <p style="margin:0; color: #374151;"><strong>${result.total_covered || 0}</strong> códigos encontrados en <strong>${totalDocs}</strong> documentos.</p>
                </div>
            `;

            html += `
                <div style="text-align: center; margin-bottom: 2rem;">
                     <!-- Botón: Generar PDF Unificado - BLUE -->
                     <button onclick='voraz_generateUnifiedPDF(${escapeForJSON(result.documents)}, ${escapeForJSON(searchedCodes)})'
                             class="btn btn-primary"
                             style="padding: 0.75rem 1.5rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                        📄 Generar PDF Unificado
                     </button>
                </div>
            `;

            // Advertencia de no encontrados
            if (result.not_found && result.not_found.length > 0) {
                html += `
                    <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                        <strong>No encontrados:</strong> ${result.not_found.join(', ')}
                    </div>
                `;
            }

            // Renderizar documentos (Estilo estándar)
            html += '<div style="display: grid; gap: 1rem;">';

            for (const doc of result.documents) {
                // Obtener TODOS los códigos para el resaltador
                // ⭐ Unir SOLO los códigos mostrados en la tarjeta (matched) para los badges
                const docCodes = doc.matched_codes || doc.codes || [];

                // ⭐ Para el botón "Resaltar", el usuario quiere ver TODOS los códigos del doc ("traer los 3 códigos").
                // Usamos all_codes si existe, si no, fallback a docCodes.
                const allCodesStr = doc.all_codes || docCodes.join(',');

                html += `
                    <div class="result-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div class="result-header" style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                            <span class="badge badge-primary">${(doc.tipo || 'DOC').toUpperCase()}</span>
                            <span class="result-meta" style="color: #6b7280; font-size: 0.875rem;">${doc.fecha || ''}</span>
                        </div>
                        
                        <div class="result-title" style="font-weight: 700; font-size: 1.1rem; color: #111827; margin-bottom: 0.5rem;">
                            ${doc.numero || 'Sin título'}
                        </div>
                        
                        <div style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; border: 1px solid #f3f4f6; margin-bottom: 1rem;">
                            <small style="color: #6b7280; display: block; margin-bottom: 0.25rem;">Códigos encontrados:</small>
                            ${docCodes.map(c => `<span class="code-tag" style="display: inline-block; background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; margin-right: 4px; margin-bottom: 4px;">${c}</span>`).join('')}
                        </div>
                        
                        <div style="display: flex; gap: 0.75rem;">
                            <!-- Botón reconstruido para resaltar TODOS -->
                            <button onclick='voraz_highlightAllCodes("${doc.id}", "${escapeForAttr(doc.ruta_archivo)}", "${allCodesStr}", ${escapeForJSON(docCodes)})' 
                                    class="btn btn-success" 
                                    style="background-color: #059669; border: none; color: white; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500;">
                                🖍️ Resaltar (${doc.all_codes ? doc.all_codes.split(',').length : docCodes.length})
                            </button>
                            
                            <a href="clients/${clientCode}/uploads/${doc.ruta_archivo}" target="_blank" 
                               class="btn btn-secondary"
                               style="background-color: white; border: 1px solid #d1d5db; color: #374151; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.875rem;">
                                📄 Original
                            </a>
                        </div>
                    </div>
                `;
            }

            html += '</div>';

            resultsDiv.innerHTML = html;
            resultsDiv.classList.remove('hidden');
        }

        // ========== FUNCIONES AUXILIARES (no afectan otras partes) ==========

        function escapeForAttr(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/'/g, '&#39;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function escapeForJSON(obj) {
            return JSON.stringify(obj)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'");
        }

        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Abre el viewer con TODOS los códigos del documento
         * NO afecta otros botones "Resaltar" de la app
         */
        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Abre el viewer con TODOS los códigos del documento
         * NO afecta otros botones "Resaltar" de la app
         */
        function voraz_highlightAllCodes(docId, filePath, codesStr, matchedCodesStr = '') {
            // Prioridad absoluta al ID (Soberanía del ID)
            // Si tenemos ID, no deberíamos depender de filePath para nada crítico,
            // pero lo enviamos por compatibilidad backward si el viewer lo requiere.

            console.log('🔍 VORAZ: Abriendo resaltador:', { docId, codesStr, matchedCodesStr });

            // Construir parámetros
            // 'term' = Códigos Encontrados (Hits) -> Naranja
            // 'codes' = Todos los códigos (Contexto) -> Verde (la diferencia se calcula en viewer)
            const params = new URLSearchParams({
                doc: docId,
                codes: codesStr,          // Contexto
                term: matchedCodesStr,    // Hits
                voraz_mode: 'true',
                strict_mode: 'true'       // Activar lógica de doble color
            });

            // Si por alguna razón no hay ID (caso raro), usamos file
            if (!docId && filePath) {
                params.append('file', filePath);
            }

            const url = `modules/resaltar/viewer.php?${params.toString()}`;
            window.open(url, '_blank');
        }

        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Genera PDF unificado con todos los documentos encontrados
         * NO afecta otras funciones de la app
         */
        async function voraz_generateUnifiedPDF(documents, allCodes) {
            console.log('🔍 VORAZ: Generando PDF unificado...', {
                documents: documents.length,
                codes: allCodes.length
            });

            // Validar datos
            if (!documents || documents.length === 0) {
                alert('❌ No hay documentos para unificar');
                return;
            }

            if (!allCodes || allCodes.length === 0) {
                alert('❌ No hay códigos para resaltar');
                return;
            }

            // Mostrar loading con ID único para voraz
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'voraz-unified-loading';
            loadingDiv.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                            background: rgba(0,0,0,0.85); display: flex; align-items: center; 
                            justify-content: center; z-index: 99999; flex-direction: column;">
                    <div style="background: white; padding: 40px 50px; border-radius: 15px; 
                                text-align: center; max-width: 450px; box-shadow: 0 10px 50px rgba(0,0,0,0.3);">
                        <div class="voraz-spinner" style="border: 5px solid #f3f3f3; 
                             border-top: 5px solid #667eea; border-radius: 50%; 
                             width: 60px; height: 60px; animation: spin 1s linear infinite;
                             margin: 0 auto 20px;"></div>
                        <h3 style="margin: 0 0 10px 0; color: #333; font-size: 20px;">
                            🔍 Generando PDF Unificado (Búsqueda Voraz)
                        </h3>
                        <p style="margin: 0 0 20px 0; color: #666;">
                            Procesando ${documents.length} documentos con ${allCodes.length} códigos
                        </p>
                        <div style="width: 100%; height: 25px; background: #eee; border-radius: 12px; 
                             overflow: hidden;">
                            <div id="voraz-progress-fill" style="width: 0%; height: 100%; 
                                 background: linear-gradient(90deg, #667eea, #764ba2); 
                                 transition: width 0.5s ease;"></div>
                        </div>
                        <p id="voraz-progress-text" style="margin-top: 10px; color: #999; font-size: 14px;">
                            Iniciando...
                        </p>
                    </div>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            document.body.appendChild(loadingDiv);

            // Simular progreso
            let progress = 0;
            const progressEl = document.getElementById('voraz-progress-fill');
            const progressText = document.getElementById('voraz-progress-text');

            const progressInterval = setInterval(() => {
                progress += 5;
                if (progress <= 90) {
                    progressEl.style.width = progress + '%';
                    if (progress < 30) progressText.textContent = 'Cargando documentos...';
                    else if (progress < 60) progressText.textContent = 'Combinando PDFs...';
                    else progressText.textContent = 'Finalizando...';
                }
            }, 300);

            try {
                // Preparar datos
                const payload = {
                    documents: documents,
                    codes: allCodes,
                    mode: 'voraz' // ⭐ Identificador único
                };

                console.log('🔍 VORAZ: Enviando solicitud:', payload);

                // Llamar al backend
                const response = await fetch('modules/resaltar/generate_unified.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-Voraz-Mode': 'true' // Header especial para identificar
                    },
                    body: JSON.stringify(payload)
                });

                console.log('🔍 VORAZ: Response status:', response.status);

                // Leer respuesta
                const responseText = await response.text();
                console.log('🔍 VORAZ: Response text:', responseText);

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('🔍 VORAZ: Error parseando JSON:', jsonError);
                    throw new Error(`Respuesta inválida del servidor:\n${responseText.substring(0, 300)}`);
                }

                console.log('🔍 VORAZ: Resultado parseado:', result);

                // Completar progreso
                clearInterval(progressInterval);
                progressEl.style.width = '100%';
                progressText.textContent = '¡Completado!';

                if (result.success) {
                    // Esperar para mostrar el 100%
                    await new Promise(resolve => setTimeout(resolve, 800));

                    // Abrir PDF unificado
                    const params = new URLSearchParams({
                        file: result.unified_pdf_path,
                        codes: allCodes.join(','),
                        mode: 'unified',
                        voraz_mode: 'true'
                    });

                    const url = `modules/resaltar/viewer.php?${params.toString()}`;
                    window.open(url, '_blank');

                    // Cerrar loading
                    document.body.removeChild(loadingDiv);

                    // Mensaje de éxito
                    alert(`✅ PDF Unificado generado exitosamente!\n\n` +
                        `📄 ${result.page_count} páginas totales\n` +
                        `📁 ${result.document_count} documentos combinados\n` +
                        `🔍 ${allCodes.length} códigos resaltados`);

                } else {
                    throw new Error(result.error || 'Error desconocido al generar PDF');
                }

            } catch (error) {
                clearInterval(progressInterval);
                console.error('🔍 VORAZ: Error completo:', error);

                alert(`❌ Error al generar PDF unificado:\n\n${error.message}\n\n` +
                    `Revisa la consola del navegador (F12) para más detalles.`);

            } finally {
                // Asegurar que se quite el loading
                const loadingElement = document.getElementById('voraz-unified-loading');
                if (loadingElement && loadingElement.parentNode) {
                    document.body.removeChild(loadingElement);
                }
            }
        }


        function clearBulkSearch() {
            document.getElementById('bulkInput').value = '';
            document.getElementById('extractedCodesPreview').classList.add('hidden');
            document.getElementById('bulkResults').classList.add('hidden');
        }
        // ============ Highlighter Modal Function ============
        function openHighlighter(url) {
            // Show modal
            const modal = document.getElementById('highlighterModal');
            modal.classList.remove('hidden');

            // Open in new tab
            window.open(url, '_blank');

            // Auto-hide modal after 3 seconds
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 3000);
        }

        /**
         * Abre el viewer con navegación entre múltiples documentos
         * Resalta TODOS los códigos buscados en cada documento
         */
        function voraz_openMultiViewer(documents, allCodes) {
            // Crear estructura de datos para el viewer

            const viewerData = {
                documents: documents.map(d => ({
                    ruta: d.ruta_archivo, // Usar ruta_archivo como espera el viewer
                    ...d
                })),
                searchCodes: allCodes,
                currentIndex: 0
            };

            // Guardar en sessionStorage para que el viewer los reciba
            sessionStorage.setItem('voraz_viewer_data', JSON.stringify(viewerData));

            // Abrir el viewer especial para búsqueda voraz
            const firstDoc = documents[0];
            const queryParams = new URLSearchParams({
                // El viewer espera 'doc' (ID) o 'file' (ruta). Usaremos doc ID si es posible para mantener consistencia,
                // pero la logica nueva del viewer usa 'file' para los siguientes. 
                // Usaremos la logica existente para el primero.
                doc: firstDoc.id,
                codes: allCodes.join(','),
                mode: 'voraz_multi',
                total: documents.length
            });

            openHighlighter(`modules/resaltar/viewer.php?${queryParams.toString()}`);
        }

        /**
         * Genera un PDF unificado combinando todos los documentos
         * y resalta todos los códigos buscados
         */

    </script>

    <!-- Highlighter Loading Modal -->
    <div id="highlighterModal" class="hidden"
        style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999;">
        <div
            style="background: white; padding: 2rem 3rem; border-radius: 12px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🖍️</div>
            <h3 style="margin: 0 0 0.5rem 0; color: #333;">Estamos resaltando...</h3>
            <p style="margin: 0; color: #666;">¡Espera un momento!</p>
            <div style="margin-top: 1rem;">
                <div class="spinner"
                    style="border: 3px solid #f3f3f3; border-top: 3px solid #038802; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 0 auto;">
                </div>
            </div>
        </div>
    </div>
</body>

</html>