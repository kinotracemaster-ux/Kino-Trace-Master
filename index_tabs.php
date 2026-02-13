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
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/search_engine.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$stats = get_search_stats($db);

// For sidebar
$currentModule = 'gestor';
$baseUrl = '../../';
$pageTitle = 'Gestor de Documentos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Documentos - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        /* Additional styles for this module */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .result-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            transition: all var(--transition-fast);
        }

        .result-card:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .result-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .result-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .codes-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .summary-box {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .summary-box.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .suggestions-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
        }

        .suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .suggestion-item:hover {
            background: var(--bg-tertiary);
        }

        .upload-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
            transition: all var(--transition-fast);
            cursor: pointer;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--accent-primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .upload-zone-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1rem;
            color: var(--text-muted);
        }

        .file-selected {
            color: var(--accent-success);
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

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

                <!-- Tabs -->
                <div class="card">
                    <div class="tabs" id="mainTabs">
                        <button class="tab active" data-tab="buscar">Buscar</button>
                        <button class="tab" data-tab="subir">Subir</button>
                        <button class="tab" data-tab="consultar">Consultar</button>
                        <button class="tab" data-tab="codigo">Búsqueda por Código</button>
                    </div>

                    <!-- Tab: Buscar -->
                    <div class="tab-content active" id="tab-buscar">
                        <h3 style="margin-bottom: 1rem;">Búsqueda Inteligente</h3>
                        <p class="text-muted mb-4">Pega aquí tus códigos o un bloque de texto (ej: desde Excel). Solo se
                            usará la <strong>primera columna</strong> de cada línea para buscar.</p>

                        <form id="searchForm">
                            <div class="form-group">
                                <textarea class="form-textarea" id="codesInput" rows="6" placeholder="ABC123
ABC123    descripción    otro dato
XYZ789    texto adicional
COD001
..."></textarea>
                            </div>
                            <div class="flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    Buscar
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="clearSearch()">Limpiar</button>
                            </div>
                        </form>

                        <div id="searchLoading" class="loading hidden">
                            <div class="spinner"></div>
                            <p>Buscando documentos...</p>
                        </div>

                        <div id="searchResults" class="hidden mt-4">
                            <div id="searchSummary"></div>
                            <div id="documentList" class="results-list"></div>
                        </div>
                    </div>

                    <!-- Tab: Subir -->
                    <div class="tab-content" id="tab-subir">
                        <h3 style="margin-bottom: 1rem;">Subir Documento</h3>

                        <form id="uploadForm">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label">Tipo de documento</label>
                                    <select class="form-select" name="tipo" id="docTipo" required>
                                        <option value="manifiesto">Manifiesto</option>
                                        <option value="declaracion">Declaración</option>
                                        <option value="factura">Factura</option>
                                        <option value="documento">Otro</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Número</label>
                                    <input type="text" class="form-input" name="numero" id="docNumero"
                                        placeholder="Ej: MAN-2024-001" required>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label">Fecha</label>
                                    <input type="date" class="form-input" name="fecha" id="docFecha"
                                        value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Proveedor (opcional)</label>
                                    <input type="text" class="form-input" name="proveedor" id="docProveedor"
                                        placeholder="Nombre del proveedor">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Archivo PDF</label>
                                <div class="upload-zone" id="uploadZone">
                                    <div class="upload-zone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                        </svg>
                                    </div>
                                    <p>Arrastra un archivo aquí o haz clic para seleccionar</p>
                                    <p class="text-muted" style="font-size: 0.75rem;">PDF, máximo 10MB</p>
                                    <input type="file" id="fileInput" name="file" accept=".pdf" style="display: none;">
                                </div>
                                <p id="fileName" class="hidden file-selected mt-2"></p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Códigos (uno por línea)</label>
                                <textarea class="form-textarea" name="codes" id="docCodes" rows="4"
                                    placeholder="Ingresa los códigos asociados..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" id="uploadBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                Subir Documento
                            </button>
                        </form>
                    </div>

                    <!-- Tab: Consultar -->
                    <div class="tab-content" id="tab-consultar">
                        <div class="flex justify-between items-center mb-4">
                            <h3>Lista de Documentos</h3>
                            <div class="flex gap-2">
                                <select class="form-select" id="filterTipo" style="width: auto;">
                                    <option value="">Todos los tipos</option>
                                    <option value="manifiesto">Manifiestos</option>
                                    <option value="declaracion">Declaraciones</option>
                                    <option value="factura">Facturas</option>
                                    <option value="documento">Documentos</option>
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
                                        placeholder="🔍 Buscar texto dentro de los PDFs..." style="width: 100%;">
                                </div>
                                <button class="btn btn-primary" onclick="searchFulltext()" id="fulltextBtn">
                                    Buscar en Contenido
                                </button>
                                <button class="btn btn-secondary" onclick="reindexDocuments()" id="reindexBtn"
                                    title="Indexar PDFs sin texto extraído">
                                    🔄 Indexar Pendientes
                                </button>
                                <button class="btn btn-secondary" onclick="reindexDocuments(true)" id="reindexAllBtn"
                                    title="Re-procesar TODOS los documentos">
                                    ⚡ Re-indexar TODO
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
                                            <th>Número</th>
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

                    <!-- Tab: Búsqueda por Código -->
                    <div class="tab-content" id="tab-codigo">
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
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = '../../api.php';
        const clientCode = '<?= $code ?>';
        let currentPage = 1;

        // ============ Tabs ============
        document.querySelectorAll('#mainTabs .tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('#mainTabs .tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');

                if (tab.dataset.tab === 'consultar') {
                    loadDocuments();
                }
            });
        });

        // ============ Search Tab ============
        document.getElementById('searchForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const codes = document.getElementById('codesInput').value.trim();
            if (!codes) {
                alert('Ingresa al menos un código');
                return;
            }

            document.getElementById('searchLoading').classList.remove('hidden');
            document.getElementById('searchResults').classList.add('hidden');

            try {
                const formData = new FormData();
                formData.append('action', 'search');
                formData.append('codes', codes);

                const response = await fetch(apiUrl, { method: 'POST', body: formData });
                const result = await response.json();

                document.getElementById('searchLoading').classList.add('hidden');
                showSearchResults(result);
            } catch (error) {
                document.getElementById('searchLoading').classList.add('hidden');
                alert('Error en la búsqueda: ' + error.message);
            }
        });

        function showSearchResults(result) {
            document.getElementById('searchResults').classList.remove('hidden');

            const coveredCount = result.total_covered || 0;
            const totalSearched = result.total_searched || 0;
            const notFound = result.not_found || [];

            let summaryHtml = `
                <div class="summary-box${notFound.length > 0 ? ' warning' : ''}">
                    <strong>${coveredCount}/${totalSearched}</strong> códigos encontrados en 
                    <strong>${result.documents?.length || 0}</strong> documento(s)
                    ${notFound.length > 0 ? `
                        <div style="margin-top: 0.5rem;">
                            <span style="color: var(--accent-danger);">No encontrados:</span>
                            <div class="codes-list">
                                ${notFound.map(c => `<span class="code-tag" style="background: rgba(239,68,68,0.1); color: var(--accent-danger);">${c}</span>`).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
            document.getElementById('searchSummary').innerHTML = summaryHtml;

            if (!result.documents || result.documents.length === 0) {
                document.getElementById('documentList').innerHTML = '<p class="text-muted">No se encontraron documentos.</p>';
                return;
            }

            let html = '';
            for (const doc of result.documents) {
                // Construir ruta del PDF correctamente
                const pdfUrl = doc.ruta_archivo ? `modules/resaltar/download.php?doc=${doc.id}` : '';

                // Get the first matched code for highlighting
                const firstCode = (doc.matched_codes && doc.matched_codes[0]) || (doc.codes && doc.codes[0]) || '';

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha}</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        <div class="result-meta">${doc.proveedor || ''}</div>
                        <div class="codes-list">
                            ${(doc.matched_codes || doc.codes || []).map(c => `<span class="code-tag">${c}</span>`).join('')}
                        </div>
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="../modules/resaltar/viewer.php?doc=${doc.id}" class="btn btn-primary" style="padding: 0.5rem 1rem;">👁️ Ver Documento</a>
                            ${pdfUrl ? `<button onclick="openHighlighter('../resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(firstCode)}')" class="btn btn-secondary" style="padding: 0.5rem 1rem; background: #fbbf24; color: #000;">🖍️ Resaltar</button>` : ''}
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 Ver PDF</a>` : ''}
                        </div>
                    </div>
                `;
            }
            document.getElementById('documentList').innerHTML = html;
        }

        function clearSearch() {
            document.getElementById('codesInput').value = '';
            document.getElementById('searchResults').classList.add('hidden');
        }

        // ============ Upload Tab ============
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');

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

        function showFileName() {
            if (fileInput.files.length) {
                document.getElementById('fileName').textContent = '✓ ' + fileInput.files[0].name;
                document.getElementById('fileName').classList.remove('hidden');
            }
        }

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!fileInput.files.length) {
                alert('Selecciona un archivo PDF');
                return;
            }

            const btn = document.getElementById('uploadBtn');
            btn.disabled = true;
            btn.textContent = 'Subiendo...';

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('tipo', document.getElementById('docTipo').value);
            formData.append('numero', document.getElementById('docNumero').value);
            formData.append('fecha', document.getElementById('docFecha').value);
            formData.append('proveedor', document.getElementById('docProveedor').value);
            formData.append('codes', document.getElementById('docCodes').value);
            formData.append('file', fileInput.files[0]);

            try {
                const response = await fetch(apiUrl, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert('Documento subido correctamente');
                    document.getElementById('uploadForm').reset();
                    document.getElementById('fileName').classList.add('hidden');
                } else {
                    alert('Error: ' + (result.error || 'Error desconocido'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg> Subir Documento`;
            }
        });

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
                            <a href="../modules/resaltar/viewer.php?doc=${doc.id}" class="btn btn-primary btn-icon" title="Ver Documento" target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </a>` : ''}
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

        // ============ Full-Text Search in PDFs ============
        const fulltextInput = document.getElementById('fulltextSearch');

        fulltextInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') searchFulltext();
        });

        async function searchFulltext() {
            const query = fulltextInput.value.trim();
            if (query.length < 3) {
                alert('Ingresa al menos 3 caracteres');
                return;
            }

            const btn = document.getElementById('fulltextBtn');
            btn.disabled = true;
            btn.textContent = 'Buscando...';

            try {
                const response = await fetch(`${apiUrl}?action=fulltext_search&query=${encodeURIComponent(query)}`);
                const result = await response.json();

                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';

                if (result.error) {
                    alert(result.error);
                    return;
                }

                showFulltextResults(result);
            } catch (error) {
                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';
                alert('Error: ' + error.message);
            }
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
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="../resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(result.query)}" class="btn btn-primary" style="padding: 0.5rem 1rem;">👁️ Ver Documento</a>
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 Original</a>` : ''}
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

        async function reindexDocuments(force = false) {
            if (isIndexing) return;

            if (force && !confirm('¿Estás seguro de re-indexar TODOS los documentos? Esto puede tardar varios minutos y consumirá recursos del servidor (OCR activado).')) {
                return;
            }

            isIndexing = true;
            const btn = force ? document.getElementById('reindexAllBtn') : document.getElementById('reindexBtn');
            const originalText = btn.textContent;

            btn.disabled = true;
            btn.textContent = '⏳ Procesando...';

            const status = document.getElementById('indexStatus');
            status.innerHTML = 'Iniciando proceso de indexación...';

            let totalIndexedSession = 0;

            // First call to get initial pending count
            let pending = 999;
            let batchNum = 0;
            const batchSize = force ? 50 : 10;

            while (pending > 0) {
                batchNum++;
                const offset = (batchNum - 1) * batchSize;
                status.innerHTML = `🔄 Procesando lote #${batchNum}... (Indexados: ${totalIndexedSession})`;

                try {
                    const url = `${apiUrl}?action=reindex_documents&batch=${batchSize}` + (force ? `&force=true&offset=${offset}` : '');
                    const response = await fetch(url);
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

                    // Stop conditions
                    if (!force && result.indexed === 0 && pending > 0) {
                        status.innerHTML += ` <span style="color: var(--warning);">(${pending} archivos no encontrados o ilegibles)</span>`;
                        break;
                    }

                    if (force && result.indexed === 0) {
                        status.innerHTML = `✅ Proceso finalizado. ${totalIndexedSession} documentos procesados.`;
                        break;
                    }

                    if (!force && pending === 0) {
                        status.innerHTML = `✅ ¡Completado! Pendientes finalizados.`;
                        break;
                    }

                } catch (error) {
                    status.innerHTML = `❌ Error de red: ${error.message}`;
                    break;
                }
            }

            btn.disabled = false;
            btn.textContent = originalText;
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
                    // Construir ruta del PDF correctamente
                    const pdfUrl = doc.ruta_archivo ? `modules/resaltar/download.php?doc=${doc.id}` : '';

                    return `
                        <div class="result-card">
                            <div class="result-header">
                                <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                                <span class="result-meta">${doc.fecha}</span>
                            </div>
                            <div class="result-title">${doc.numero}</div>
                 <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="../modules/resaltar/viewer.php?doc=${doc.id}" class="btn btn-primary" style="padding: 0.5rem 1rem;">👁️ Ver Documento</a>
                                ${pdfUrl ? `<button onclick="openHighlighter('../resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(code)}')" class="btn btn-secondary" style="padding: 0.5rem 1rem; background: #fbbf24; color: #000;">🖍️ Resaltar</button>` : ''}
                                ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 Ver PDF</a>` : ''}
                            </div>
                        </div >
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

        function toggleTableCodes(e, docId) {
            if (e) e.preventDefault();
            const row = document.getElementById(`codes-row-${docId}`);
            const btn = document.getElementById(`btn-codes-${docId}`);

            if (row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                if (btn) {
                    btn.textContent = 'Ocultar Códigos';
                    btn.style.backgroundColor = '#d1d5db';
                    btn.style.color = '#1f2937';
                }
            } else {
                row.classList.add('hidden');
                if (btn) {
                    btn.textContent = 'Ver Códigos';
                    btn.style.backgroundColor = '';
                    btn.style.color = '';
                }
            }
        }
    </script>
    <script>
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
                    style="border: 3px solid #f3f3f3; border-top: 3px solid #fbbf24; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 0 auto;">
                </div>
            </div>
        </div>
    </div>
</body>

</html>