<?php
/**
 * Importación SQL + ZIP
 * Permite subir una estructura SQL completa y vincular documentos PDF.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];

// Logica de sidebar
$currentModule = 'importar_sql';
$baseUrl = '../../';
$pageTitle = 'Importación Avanzada';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importación Avanzada - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .import-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .upload-card {
            background: var(--bg-primary);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
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
            font-size: 3rem;
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
            font-family: 'Fira Code', monospace;
            padding: 1.5rem;
            border-radius: var(--radius-md);
            border: 1px solid #333;
            height: 300px;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-top: 2rem;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .console-line {
            margin-bottom: 0.25rem;
            display: flex;
            gap: 0.5rem;
        }

        .console-time {
            color: #666;
            user-select: none;
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

        .btn-process:hover {
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
                            class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-purple-500 bg-clip-text text-transparent">
                            Sistema de Importación Integral
                        </h2>
                        <p class="text-gray-400 mt-2">
                            Importe estructura SQL y documentos masivos simultáneamente. El sistema vinculará
                            automáticamente los datos.
                        </p>
                    </div>
                </div>

                <form id="importForm" class="import-section">
                    <div class="import-grid">
                        <!-- SQL Upload -->
                        <div class="upload-card" id="sqlZone">
                            <input type="file" name="sql_file" accept=".sql" class="file-input" required
                                onchange="handleFileSelect(this, 'sqlArea', 'sqlName')">
                            <div id="sqlArea">
                                <div class="upload-icon">💾</div>
                                <h3 class="text-lg font-semibold mb-2">Archivo SQL</h3>
                                <p class="text-sm text-gray-500">Arrastre su archivo .sql aquí</p>
                                <p class="text-xs text-gray-600 mt-2">Contiene: Estructura y Datos</p>
                            </div>
                            <div id="sqlName" class="file-info hidden mt-4 text-blue-400 font-mono text-sm"></div>
                        </div>

                        <!-- ZIP Upload -->
                        <div class="upload-card" id="zipZone">
                            <input type="file" name="zip_file" accept=".zip" class="file-input" required
                                onchange="handleFileSelect(this, 'zipArea', 'zipName')">
                            <div id="zipArea">
                                <div class="upload-icon">📦</div>
                                <h3 class="text-lg font-semibold mb-2">Documentos ZIP</h3>
                                <p class="text-sm text-gray-500">Arrastre su archivo .zip aquí</p>
                                <p class="text-xs text-gray-600 mt-2">Contiene: PDFs nombrados</p>
                            </div>
                            <div id="zipName" class="file-info hidden mt-4 text-purple-400 font-mono text-sm"></div>
                        </div>
                    </div>

                    <div class="flex gap-4" style="display: flex; gap: 1rem;">
                        <button type="button" class="btn-process" id="processBtn" disabled>
                            <span class="btn-text">INICIAR IMPORTACIÓN</span>
                            <div class="loading-spinner hidden" id="spinner"></div>
                        </button>

                        <button type="button" class="btn-process" style="background: var(--accent-danger); width: auto;"
                            onclick="resetDatabase()">
                            🗑️ Limpiar Todo
                        </button>

                        <button type="button" class="btn-process"
                            style="background: var(--bg-tertiary); width: auto; color: var(--text-primary);"
                            onclick="showDebugInfo()">
                            🔍 Diagnóstico
                        </button>
                    </div>
                </form>

                <div class="console-output" id="consoleLog">
                    <div class="console-line">
                        <span class="console-time">[
                            <?= date('H:i:s') ?>]
                        </span>
                        <span>Sistema listo para importar. Esperando archivos...</span>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        function handleFileSelect(input, areaId, nameId) {
            const file = input.files[0];
            const area = document.getElementById(areaId);
            const nameDisplay = document.getElementById(nameId);
            const card = area.parentElement;

            if (file) {
                card.classList.add('active');
                area.style.opacity = '0.3';
                nameDisplay.textContent = '✓ ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
                nameDisplay.classList.remove('hidden');
            }
            validateFiles();
        }

        function validateFiles() {
            const sqlInput = document.querySelector('input[name="sql_file"]');
            const zipInput = document.querySelector('input[name="zip_file"]');
            const btn = document.getElementById('processBtn');

            if (sqlInput.files.length > 0 && zipInput.files.length > 0) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.filter = 'none';
            } else {
                btn.disabled = true;
                btn.style.opacity = '0.7';
                btn.style.filter = 'grayscale(1)';
            }
        }

        // Attach event listeners safely after DOM load
        document.addEventListener('DOMContentLoaded', () => {
            // Initial state
            validateFiles();

            // Attach click handler via code, not HTML attribute
            document.getElementById('processBtn').addEventListener('click', (e) => {
                e.preventDefault();
                submitForm();
            });
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

                log('Limpiando base de datos...', 'info');

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.logs) result.logs.forEach(l => log(l.msg, l.type));

                if (result.success) {
                    setTimeout(() => window.location.reload(), 2000);
                }
            } catch (e) {
                log('Error al limpiar: ' + e.message, 'error');
            }
        }

        async function showDebugInfo() {
            try {
                const formData = new FormData();
                formData.append('action', 'debug_info');
                const response = await fetch('process.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.logs) result.logs.forEach(l => log(l.msg, l.type));
            } catch (e) {
                log('Error debug: ' + e.message, 'error');
            }
        }

        async function submitForm() {
            const form = document.getElementById('importForm');
            const btn = document.getElementById('processBtn');
            const spinner = document.getElementById('spinner');

            btn.disabled = true;
            spinner.classList.remove('hidden');

            const formData = new FormData(form);

            try {
                log('Iniciando carga de archivos...', 'info');

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.logs) {
                    result.logs.forEach(l => log(l.msg, l.type));
                }

                if (result.success) {
                    log('Importación completada con éxito. Revise el resumen arriba.', 'success');
                    // setTimeout(() => window.location.reload(), 4000); // Eliminado para persistir resultados
                    btn.innerHTML = '<span class="btn-text">NUEVA IMPORTACIÓN</span>';
                    btn.disabled = false;
                    btn.onclick = function () { window.location.reload(); };
                } else {
                    log('Error fatal: ' + (result.error || 'Desconocido'), 'error');
                    btn.disabled = false;
                }

            } catch (err) {
                log('Error de conexión: ' + err.message, 'error');
                btn.disabled = false;
            } finally {
                spinner.classList.add('hidden');
            }
        }
    </script>
</body>

</html>