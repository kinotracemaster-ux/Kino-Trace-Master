<?php
/**
 * Importación Masiva CSV/Excel - KINO TRACE
 * 
 * Interfaz modernizada para importar datos desde archivos CSV o Excel.
 * Permite vincular códigos con documentos existentes.
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// For sidebar
$currentModule = 'excel_import';
$baseUrl = '../../';
$pageTitle = 'Importación Masiva (CSV)';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importación Masiva - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .import-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .upload-card {
            background: var(--bg-primary);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
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
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
            transition: color 0.3s;
        }

        .upload-card:hover .upload-icon {
            color: var(--accent-primary);
        }

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

        .stats-mini {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
        }

        .stat-mini {
            text-align: center;
        }

        .stat-mini .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-mini .label {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
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
                            class="text-2xl font-bold bg-gradient-to-r from-green-400 to-blue-500 bg-clip-text text-transparent">
                            📊 Importación Masiva desde CSV/Excel
                        </h2>
                        <p class="text-gray-400 mt-2">
                            Suba un archivo CSV o Excel para vincular códigos con sus documentos existentes.
                        </p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-mini" id="statsBox" style="display: none;">
                    <div class="stat-mini">
                        <div class="value" id="statTotal">0</div>
                        <div class="label">Total Documentos</div>
                    </div>
                    <div class="stat-mini">
                        <div class="value" id="statCodes">0</div>
                        <div class="label">Códigos en BD</div>
                    </div>
                    <div class="stat-mini">
                        <div class="value" id="statPending">0</div>
                        <div class="label">Pendientes</div>
                    </div>
                </div>

                <form id="importForm" class="import-section">
                    <div class="import-grid">
                        <!-- CSV/Excel Upload -->
                        <div class="upload-card" id="fileZone">
                            <input type="file" name="excel_file" accept=".csv,.xlsx" class="file-input" required
                                onchange="handleFileSelect(this)">
                            <div id="fileArea">
                                <div class="upload-icon">📊</div>
                                <h3 class="text-lg font-semibold mb-2">Archivo CSV o Excel</h3>
                                <p class="text-sm text-gray-500">Arrastre su archivo .csv o .xlsx aquí</p>
                                <p class="text-xs text-gray-600 mt-3">
                                    <strong>Formato esperado:</strong><br>
                                    Primera fila: <code>archivo, codigo</code><br>
                                    Datos: nombre del documento y código a vincular
                                </p>
                            </div>
                            <div id="fileName" class="file-info" style="display: none;"></div>
                        </div>
                    </div>

                    <div class="flex gap-4" style="display: flex; gap: 1rem;">
                        <button type="button" class="btn-process" id="processBtn" disabled>
                            <span class="btn-text">PROCESAR ARCHIVO</span>
                            <div class="loading-spinner hidden" id="spinner"></div>
                        </button>

                        <button type="button" class="btn-process" style="background: var(--accent-danger); width: auto;"
                            onclick="resetDatabase()">
                            🗑️ Limpiar Todo
                        </button>

                        <button type="button" class="btn-process"
                            style="background: var(--bg-tertiary); width: auto; color: var(--text-primary);"
                            onclick="showStats()">
                            📊 Estadísticas
                        </button>
                    </div>
                </form>

                <div class="console-output" id="consoleLog">
                    <div class="console-line">
                        <span class="console-time">[<?= date('H:i:s') ?>]</span>
                        <span>Sistema listo. Esperando archivo CSV o Excel...</span>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        function handleFileSelect(input) {
            const file = input.files[0];
            const card = document.getElementById('fileZone');
            const fileArea = document.getElementById('fileArea');
            const fileNameDisplay = document.getElementById('fileName');
            const btn = document.getElementById('processBtn');

            if (file) {
                card.classList.add('active');
                fileArea.style.opacity = '0.3';
                fileNameDisplay.textContent = '✓ ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                fileNameDisplay.style.display = 'block';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.filter = 'none';

                log('✓ Archivo seleccionado: ' + file.name, 'success');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('processBtn').addEventListener('click', (e) => {
                e.preventDefault();
                submitForm();
            });

            // Load initial stats
            showStats();
        });

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

        async function showStats() {
            try {
                const formData = new FormData();
                formData.append('action', 'stats');

                const response = await fetch('process.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    document.getElementById('statsBox').style.display = 'grid';
                    document.getElementById('statTotal').textContent = result.stats.total_docs || 0;
                    document.getElementById('statCodes').textContent = result.stats.total_codes || 0;
                    document.getElementById('statPending').textContent = result.stats.docs_without_codes || 0;

                    log('📊 Estadísticas actualizadas', 'info');
                }
            } catch (e) {
                log('Error obteniendo estadísticas: ' + e.message, 'error');
            }
        }

        async function submitForm() {
            const form = document.getElementById('importForm');
            const btn = document.getElementById('processBtn');
            const spinner = document.getElementById('spinner');

            btn.disabled = true;
            spinner.classList.remove('hidden');

            const formData = new FormData(form);
            formData.append('action', 'import');

            try {
                log('📤 Subiendo archivo...', 'info');

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.logs) {
                    result.logs.forEach(l => log(l.msg, l.type));
                }

                if (result.success) {
                    log('✅ Importación completada exitosamente', 'success');
                    if (result.stats) {
                        log(`📋 Resumen: ${result.stats.matched} encontrados, ${result.stats.codes_added} códigos agregados, ${result.stats.not_found} no encontrados`, 'info');
                    }

                    // Update stats
                    showStats();

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
    </script>
</body>

</html>