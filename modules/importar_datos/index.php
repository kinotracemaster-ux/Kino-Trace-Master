<?php
/**
 * Importación Masiva - KINO TRACE
 * 
 * Soporta dos modos:
 * 1. CSV + ZIP: Formato CSV propio con archivos PDF en ZIP
 * 2. SQL + ZIP: Dump MySQL (phpMyAdmin) con PDFs en ZIP
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$currentModule = 'importar_masiva';
$baseUrl = '../../';
$pageTitle = 'Importación de Datos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        /* ── Tab Switcher ── */
        .tab-nav {
            display: flex;
            gap: 0;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 1rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-btn:hover {
            color: var(--text-primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .tab-btn.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
        }

        .tab-btn.active-sql {
            color: #10b981;
            border-bottom-color: #10b981;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* ── Upload Cards ── */
        .import-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .import-grid.single {
            grid-template-columns: 1fr;
            max-width: 500px;
        }

        .upload-card {
            background: var(--bg-primary);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .upload-card:hover {
            border-color: var(--accent-primary);
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.3);
        }

        .upload-card.active {
            border-color: var(--accent-success);
            background: rgba(16, 185, 129, 0.05);
        }

        .upload-card.sql-mode:hover {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }

        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-icon {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
            transition: color 0.3s;
        }

        .upload-card:hover .upload-icon {
            color: var(--accent-primary);
        }

        .upload-card.sql-mode:hover .upload-icon {
            color: #10b981;
        }

        /* ── Console ── */
        .console-output {
            background: #1e1e1e;
            color: #10b981;
            font-family: 'Fira Code', 'Courier New', monospace;
            padding: 1.5rem;
            border-radius: var(--radius-md);
            border: 1px solid #333;
            height: 350px;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.6;
            margin-top: 2rem;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .console-line {
            margin-bottom: 0.35rem;
            display: flex;
            gap: 0.5rem;
        }

        .console-time {
            color: #666;
            user-select: none;
            min-width: 65px;
        }

        /* ── Buttons ── */
        .btn-process {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: white;
            border: none;
            padding: 1rem 3rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 99px;
            cursor: pointer;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.4);
            transition: all 0.3s;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        .btn-process:hover:not(:disabled) {
            transform: scale(1.02);
            box-shadow: 0 0 30px rgba(59, 130, 246, 0.6);
        }

        .btn-process:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            filter: grayscale(1);
        }

        .btn-sql {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);
        }

        .btn-sql:hover:not(:disabled) {
            box-shadow: 0 0 30px rgba(16, 185, 129, 0.6);
        }

        .loading-spinner {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .file-info {
            margin-top: 1rem;
            padding: 0.75rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 8px;
            color: var(--accent-success);
            font-family: monospace;
            font-size: 0.875rem;
        }

        .format-spec {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .format-spec h4 {
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .format-spec code {
            background: rgba(59, 130, 246, 0.1);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .format-spec ul {
            margin: 0.5rem 0 0 1.25rem;
            padding: 0;
        }

        .format-spec li {
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .sql-badge {
            display: inline-block;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .optional-label {
            display: inline-block;
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="card mb-6">
                    <div class="card-header border-b border-gray-800 pb-4">
                        <h2
                            class="text-2xl font-bold bg-gradient-to-r from-purple-400 to-pink-500 bg-clip-text text-transparent">
                            📦 Importación de Datos
                        </h2>
                        <p class="text-gray-400 mt-2">
                            Importa documentos y códigos desde archivos CSV o SQL con sus PDFs en ZIP.
                        </p>
                    </div>
                </div>

                <!-- ═══ Tab Navigation ═══ -->
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="switchTab('csv')">
                        📊 CSV + ZIP
                    </button>
                    <button class="tab-btn" onclick="switchTab('sql')">
                        🗄️ SQL + ZIP <span class="sql-badge">NUEVO</span>
                    </button>
                </div>

                <!-- ═══════════════════════════════ -->
                <!-- ═══ TAB 1: CSV Import    ═══ -->
                <!-- ═══════════════════════════════ -->
                <div class="tab-content active" id="tab-csv">
                    <div class="format-spec">
                        <h4>📋 Formato CSV Requerido</h4>
                        <p style="font-size: 0.875rem; margin-bottom: 0.75rem;">El archivo debe tener las siguientes
                            columnas:</p>
                        <ul>
                            <li><code>nombre_pdf</code>: Nombre exacto del PDF (ej: <code>1748_KARDOO.pdf</code>) -
                                Clave
                                para enlazar</li>
                            <li><code>nombre_doc</code>: Número o nombre visible (ej: <code>KARDOO AGOSTO 2020A</code>)
                            </li>
                            <li><code>fecha</code>: Fecha del documento (formato: <code>YYYY-MM-DD</code>)</li>
                            <li><code>codigos</code>: Códigos separados por comas (ej: <code>K-353A, K-609</code>)</li>
                        </ul>
                    </div>

                    <form id="importFormCSV" class="import-section">
                        <div class="import-grid">
                            <!-- CSV Upload -->
                            <div class="upload-card" id="csvZone">
                                <input type="file" name="csv_file" accept=".csv" class="file-input" required
                                    onchange="handleFileSelect(this, 'csvArea', 'csvFileInfo')">
                                <div id="csvArea">
                                    <div class="upload-icon">📊</div>
                                    <h3 class="text-lg font-semibold mb-2">Archivo CSV</h3>
                                    <p class="text-sm text-gray-500">Arrastre su archivo .csv aquí</p>
                                    <p class="text-xs text-gray-600 mt-2">Contiene: Datos y Códigos</p>
                                </div>
                                <div id="csvFileInfo" class="file-info" style="display: none;"></div>
                            </div>

                            <!-- ZIP Upload -->
                            <div class="upload-card" id="csvZipZone">
                                <input type="file" name="zip_file" accept=".zip" class="file-input" required
                                    onchange="handleFileSelect(this, 'csvZipArea', 'csvZipFileInfo')">
                                <div id="csvZipArea">
                                    <div class="upload-icon">📦</div>
                                    <h3 class="text-lg font-semibold mb-2">Archivo ZIP</h3>
                                    <p class="text-sm text-gray-500">Arrastre su archivo .zip aquí</p>
                                    <p class="text-xs text-gray-600 mt-2">Contiene: PDFs nombrados</p>
                                </div>
                                <div id="csvZipFileInfo" class="file-info" style="display: none;"></div>
                            </div>
                        </div>

                        <div class="flex gap-4" style="display: flex; gap: 1rem;">
                            <button type="button" class="btn-process" id="csvProcessBtn" disabled onclick="submitCSV()">
                                <span class="btn-text">INICIAR IMPORTACIÓN CSV</span>
                                <div class="loading-spinner hidden" id="csvSpinner"></div>
                            </button>

                            <button type="button" class="btn-process"
                                style="background: var(--accent-danger); width: auto;" onclick="resetDatabase()">
                                🗑️ Limpiar Todo
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ═══════════════════════════════ -->
                <!-- ═══ TAB 2: SQL Import    ═══ -->
                <!-- ═══════════════════════════════ -->
                <div class="tab-content" id="tab-sql">
                    <!-- ── FASE 1: Importar SQL ── -->
                    <div id="sqlPhase1">
                        <div class="format-spec" style="border-color: rgba(16, 185, 129, 0.3);">
                            <h4>🗄️ Paso 1: Importar datos desde SQL</h4>
                            <p style="font-size: 0.875rem; margin-bottom: 0.75rem;">
                                Sube el archivo <code>.sql</code> exportado de phpMyAdmin con las tablas
                                <code>documents</code> y <code>codes</code>.
                            </p>
                            <p style="font-size: 0.8rem; color: var(--text-muted);">
                                Primero se importan los datos. Los PDFs se suben después por lotes.
                            </p>
                        </div>

                        <form id="importFormSQL" class="import-section">
                            <div class="import-grid single">
                                <div class="upload-card sql-mode" id="sqlZone">
                                    <input type="file" name="sql_file" accept=".sql" class="file-input" required
                                        onchange="handleFileSelect(this, 'sqlArea', 'sqlFileInfo'); validateSqlFiles();">
                                    <div id="sqlArea">
                                        <div class="upload-icon">🗄️</div>
                                        <h3 class="text-lg font-semibold mb-2">Archivo SQL</h3>
                                        <p class="text-sm text-gray-500">Arrastre su archivo .sql aquí</p>
                                        <p class="text-xs text-gray-600 mt-2">Exportación de phpMyAdmin</p>
                                    </div>
                                    <div id="sqlFileInfo" class="file-info" style="display: none;"></div>
                                </div>
                            </div>

                            <div style="display: flex; gap: 1rem;">
                                <button type="button" class="btn-process btn-sql" id="sqlProcessBtn" disabled
                                    onclick="submitSQL()">
                                    <span class="btn-text">🗄️ IMPORTAR DATOS SQL</span>
                                    <div class="loading-spinner hidden" id="sqlSpinner"></div>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ── FASE 2: Subir ZIPs por lotes ── -->
                    <div id="sqlPhase2" style="display: none;">
                        <div class="format-spec" style="border-color: rgba(59, 130, 246, 0.3);">
                            <h4>📦 Paso 2: Subir PDFs por lotes (ZIP)</h4>
                            <p style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                                Sube los PDFs en varios ZIPs más pequeños. Puedes repetir este paso las veces que necesites.
                            </p>
                            <p style="font-size: 0.8rem; color: var(--text-muted);">
                                Los PDFs se enlazarán automáticamente a los documentos importados por nombre de archivo.
                            </p>
                        </div>

                        <div id="pendingCounter" class="format-spec" style="border-color: rgba(251, 191, 36, 0.3); text-align: center; padding: 1rem; display: none;">
                            <span style="font-size: 1.1rem;">📊 Documentos sin PDF: <strong id="pendingCount" style="color: #fbbf24; font-size: 1.3rem;">0</strong></span>
                        </div>

                        <form id="zipBatchForm" class="import-section">
                            <div class="import-grid single">
                                <div class="upload-card" id="batchZipZone">
                                    <input type="file" name="zip_file" accept=".zip" class="file-input" required
                                        onchange="handleFileSelect(this, 'batchZipArea', 'batchZipFileInfo'); validateBatchZip();">
                                    <div id="batchZipArea">
                                        <div class="upload-icon">📦</div>
                                        <h3 class="text-lg font-semibold mb-2">ZIP con PDFs (Lote)</h3>
                                        <p class="text-sm text-gray-500">Arrastre un ZIP aquí (máx. ~400MB por lote)</p>
                                        <p class="text-xs text-gray-600 mt-2">Repita para subir todos los lotes</p>
                                    </div>
                                    <div id="batchZipFileInfo" class="file-info" style="display: none;"></div>
                                </div>
                            </div>

                            <div style="display: flex; gap: 1rem;">
                                <button type="button" class="btn-process" id="batchZipBtn" disabled
                                    onclick="submitBatchZip()">
                                    <span class="btn-text">📦 SUBIR Y ENLAZAR ESTE LOTE</span>
                                    <div class="loading-spinner hidden" id="batchSpinner"></div>
                                </button>

                                <button type="button" class="btn-process"
                                    style="background: var(--accent-danger); width: auto;" onclick="resetDatabase()">
                                    🗑️ Limpiar Todo
                                </button>
                            </div>
                        </form>

                        <div id="batchHistory" style="margin-top: 1rem; display: none;">
                            <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--text-muted);">📋 Lotes subidos:</h4>
                            <div id="batchList" style="font-size: 0.85rem;"></div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Shared Console ═══ -->
                <div class="console-output" id="consoleLog">
                    <div class="console-line">
                        <span class="console-time">[<?= date('H:i:s') ?>]</span>
                        <span>Sistema listo. Seleccione el modo de importación (CSV o SQL)...</span>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // ── Tab Switching ──
        function switchTab(mode) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active', 'active-sql'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            if (mode === 'csv') {
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                document.getElementById('tab-csv').classList.add('active');
                log('📊 Modo CSV seleccionado', 'info');
            } else {
                document.querySelectorAll('.tab-btn')[1].classList.add('active', 'active-sql');
                document.getElementById('tab-sql').classList.add('active');
                log('🗄️ Modo SQL seleccionado', 'info');
            }
        }

        // ── File Selection ──
        function handleFileSelect(input, areaId, nameId) {
            const file = input.files[0];
            const area = document.getElementById(areaId);
            const nameDisplay = document.getElementById(nameId);
            const card = area.parentElement;

            if (file) {
                card.classList.add('active');
                area.style.opacity = '0.3';
                nameDisplay.textContent = '✓ ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                nameDisplay.style.display = 'block';
                log('✓ Archivo seleccionado: ' + file.name, 'success');
            }
            validateCsvFiles();
        }

        // ── Validation ──
        function validateCsvFiles() {
            const csvInput = document.querySelector('#importFormCSV input[name="csv_file"]');
            const zipInput = document.querySelector('#importFormCSV input[name="zip_file"]');
            const btn = document.getElementById('csvProcessBtn');

            if (csvInput && zipInput && csvInput.files.length > 0 && zipInput.files.length > 0) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.filter = 'none';
            } else if (btn) {
                btn.disabled = true;
            }
        }

        function validateSqlFiles() {
            const sqlInput = document.querySelector('#importFormSQL input[name="sql_file"]');
            const btn = document.getElementById('sqlProcessBtn');

            if (sqlInput && sqlInput.files.length > 0) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.filter = 'none';
            } else if (btn) {
                btn.disabled = true;
            }
        }

        // ── Console Logger ──
        function log(msg, type = 'info') {
            const consoleLog = document.getElementById('consoleLog');
            if (!consoleLog) return;

            const line = document.createElement('div');
            line.className = 'console-line';

            const time = new Date().toLocaleTimeString('es-ES', { hour12: false });

            let color = '#ccc';
            if (type === 'info') color = '#60a5fa';
            if (type === 'success') color = '#34d399';
            if (type === 'error') color = '#f87171';
            if (type === 'warning') color = '#fbbf24';

            line.innerHTML = `
                <span class="console-time">[${time}]</span>
                <span style="color: ${color}">${msg}</span>
            `;

            consoleLog.appendChild(line);
            consoleLog.scrollTop = consoleLog.scrollHeight;
        }

        // ── Reset Database ──
        async function resetDatabase() {
            if (!confirm('⚠️ ¿ESTÁS SEGURO?\n\nEsto borrará TODOS los documentos y códigos de la base de datos actual.')) return;

            try {
                const formData = new FormData();
                formData.append('action', 'reset');

                log('🔄 Limpiando base de datos...', 'warning');

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.logs) result.logs.forEach(l => log(l.msg, l.type));

                if (result.success) {
                    log('✅ Base de datos limpiada correctamente', 'success');
                    setTimeout(() => window.location.reload(), 2000);
                }
            } catch (e) {
                log('❌ Error al limpiar: ' + e.message, 'error');
            }
        }

        // ── Submit CSV Import ──
        async function submitCSV() {
            const form = document.getElementById('importFormCSV');
            const btn = document.getElementById('csvProcessBtn');
            const spinner = document.getElementById('csvSpinner');

            btn.disabled = true;
            spinner.classList.remove('hidden');

            const formData = new FormData(form);
            formData.append('action', 'import');

            try {
                log('📤 Subiendo archivos CSV + ZIP...', 'info');

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.logs) {
                    result.logs.forEach(l => log(l.msg, l.type));
                }

                if (result.success) {
                    log('✅ Importación CSV completada exitosamente', 'success');
                    btn.innerHTML = '<span class="btn-text">NUEVA IMPORTACIÓN</span>';
                    btn.disabled = false;
                    btn.onclick = function () { window.location.reload(); };
                } else {
                    log('❌ Error: ' + (result.error || 'Desconocido'), 'error');
                    btn.disabled = false;
                }

            } catch (err) {
                log('❌ Error de conexión: ' + err.message, 'error');
                btn.disabled = false;
            } finally {
                spinner.classList.add('hidden');
            }
        }

        // ── Submit SQL Import (Phase 1 → Phase 2) ──
        let batchCount = 0;

        async function submitSQL() {
            const form = document.getElementById('importFormSQL');
            const btn = document.getElementById('sqlProcessBtn');
            const spinner = document.getElementById('sqlSpinner');

            btn.disabled = true;
            spinner.classList.remove('hidden');

            const formData = new FormData(form);

            try {
                log('📤 Subiendo archivo SQL...', 'info');
                log('⏳ Parseando e importando datos (esto puede tomar un momento)...', 'warning');

                const response = await fetch('import_sql.php', {
                    method: 'POST',
                    body: formData
                });

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    log('❌ Respuesta inválida del servidor: ' + text.substring(0, 200), 'error');
                    btn.disabled = false;
                    return;
                }

                if (result.logs) {
                    result.logs.forEach(l => log(l.msg, l.type));
                }

                if (result.success) {
                    log('🎉 ¡Datos SQL importados! Ahora puedes subir los PDFs por lotes.', 'success');
                    
                    // Transition to Phase 2
                    document.getElementById('sqlPhase1').style.display = 'none';
                    document.getElementById('sqlPhase2').style.display = 'block';
                    
                    log('📦 Paso 2: Sube los PDFs en ZIPs de ~400MB o menos.', 'info');
                } else {
                    log('❌ Error: ' + (result.error || 'Desconocido'), 'error');
                    btn.disabled = false;
                }

            } catch (err) {
                log('❌ Error de conexión: ' + err.message, 'error');
                btn.disabled = false;
            } finally {
                spinner.classList.add('hidden');
            }
        }

        // ── Validate Batch ZIP ──
        function validateBatchZip() {
            const zipInput = document.querySelector('#zipBatchForm input[name="zip_file"]');
            const btn = document.getElementById('batchZipBtn');

            if (zipInput && zipInput.files.length > 0) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.filter = 'none';
            } else if (btn) {
                btn.disabled = true;
            }
        }

        // ── Submit Batch ZIP (Phase 2, repeatable) ──
        async function submitBatchZip() {
            const form = document.getElementById('zipBatchForm');
            const btn = document.getElementById('batchZipBtn');
            const spinner = document.getElementById('batchSpinner');

            btn.disabled = true;
            spinner.classList.remove('hidden');

            const formData = new FormData(form);
            const fileName = formData.get('zip_file')?.name || 'ZIP';

            try {
                batchCount++;
                log(`📤 Subiendo lote #${batchCount}: ${fileName}...`, 'info');
                log('⏳ Extrayendo y enlazando PDFs...', 'warning');

                const response = await fetch('link_zip.php', {
                    method: 'POST',
                    body: formData
                });

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    log('❌ Respuesta inválida: ' + text.substring(0, 200), 'error');
                    btn.disabled = false;
                    return;
                }

                if (result.logs) {
                    result.logs.forEach(l => log(l.msg, l.type));
                }

                if (result.success) {
                    // Update pending counter
                    if (result.pending !== undefined) {
                        document.getElementById('pendingCounter').style.display = 'block';
                        const countEl = document.getElementById('pendingCount');
                        countEl.textContent = result.pending;
                        countEl.style.color = result.pending > 0 ? '#fbbf24' : '#34d399';
                    }

                    // Add to batch history
                    const historyDiv = document.getElementById('batchHistory');
                    const listDiv = document.getElementById('batchList');
                    historyDiv.style.display = 'block';
                    const entry = document.createElement('div');
                    entry.innerHTML = `<span style="color: #34d399;">✅</span> Lote #${batchCount}: ${fileName}`;
                    entry.style.marginBottom = '0.25rem';
                    listDiv.appendChild(entry);

                    // Reset the file input for another batch
                    form.reset();
                    const area = document.getElementById('batchZipArea');
                    const info = document.getElementById('batchZipFileInfo');
                    area.style.opacity = '1';
                    info.style.display = 'none';
                    document.getElementById('batchZipZone').classList.remove('active');

                    if (result.pending === 0) {
                        log('🎉 ¡TODOS los documentos tienen PDF enlazado! Importación completa.', 'success');
                        btn.innerHTML = '<span class="btn-text">✅ IMPORTACIÓN COMPLETA</span>';
                    } else {
                        log(`📦 Puedes subir el siguiente lote. Faltan ${result.pending} documentos.`, 'info');
                        btn.disabled = true; // Re-enable when new file selected
                    }
                } else {
                    log('❌ Error: ' + (result.error || 'Desconocido'), 'error');
                    btn.disabled = false;
                }

            } catch (err) {
                log('❌ Error de conexión: ' + err.message, 'error');
                btn.disabled = false;
            } finally {
                spinner.classList.add('hidden');
            }
        }
    </script>
</body>

</html>