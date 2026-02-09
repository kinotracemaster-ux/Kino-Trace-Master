<?php
/**
 * Módulo de Indexación de PDFs
 * Permite indexar todos los documentos PDF pendientes para búsqueda full-text
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$baseUrl = '../../';
$pageTitle = 'Indexar Documentos';

// Contar documentos pendientes
$pendingCount = 0;
$totalPdfCount = 0;
// Only count strictly necessary
$countStmt = $db->query("SELECT COUNT(*) FROM documentos WHERE ruta_archivo LIKE '%.pdf'");
$totalPdfCount = $countStmt->fetchColumn();

// Count pending efficiently
$pendingStmt = $db->query("
    SELECT COUNT(*) FROM documentos 
    WHERE ruta_archivo LIKE '%.pdf' 
    AND (datos_extraidos IS NULL OR datos_extraidos = '' OR datos_extraidos NOT LIKE '%\"text\":%')
");
$pendingCount = $pendingStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indexar Documentos - KINO TRACE</title>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/styles.css">
    <style>
        .index-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .index-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--border-primary);
        }

        .index-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .index-header h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .index-header p {
            color: var(--text-secondary);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: var(--bg-tertiary);
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
        }

        .stat-box .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-box.pending .number {
            color: #f59e0b;
        }

        .stat-box.indexed .number {
            color: #10b981;
        }

        .stat-box .label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .action-area {
            text-align: center;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: var(--bg-tertiary);
            border-radius: 12px;
        }

        .controls-row {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .control-group label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .form-select,
        .form-check-input {
            padding: 0.5rem;
            border-radius: 6px;
            border: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .btn-index {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s;
        }

        .btn-index:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }

        .btn-index:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .progress-section {
            display: none;
        }

        .progress-section.active {
            display: block;
        }

        .progress-bar-container {
            background: var(--bg-tertiary);
            border-radius: 8px;
            height: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #f59e0b, #10b981);
            transition: width 0.3s ease;
        }

        .progress-text {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .log-section {
            margin-top: 1.5rem;
            max-height: 200px;
            overflow-y: auto;
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 1rem;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .log-entry {
            padding: 0.25rem 0;
            border-bottom: 1px solid var(--border-primary);
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-entry.success {
            color: #10b981;
        }

        .log-entry.error {
            color: #ef4444;
        }

        .log-entry.info {
            color: var(--text-secondary);
        }

        .complete-message {
            display: none;
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-radius: 12px;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .complete-message.show {
            display: block;
        }

        .complete-message h3 {
            color: #10b981;
            margin-bottom: 0.5rem;
        }

        .spinner {
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
                <div class="index-container">
                    <div class="index-card">
                        <div class="index-header">
                            <h1>📄 Indexar Documentos PDF</h1>
                            <p>Extrae el texto de los PDFs para habilitar la búsqueda full-text</p>
                        </div>

                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="number"><?= $totalPdfCount ?></div>
                                <div class="label">Total PDFs</div>
                            </div>
                            <div class="stat-box indexed">
                                <div class="number" id="indexedCount"><?= $totalPdfCount - $pendingCount ?></div>
                                <div class="label">Indexados</div>
                            </div>
                            <div class="stat-box pending">
                                <div class="number" id="pendingCount"><?= $pendingCount ?></div>
                                <div class="label">Pendientes</div>
                            </div>
                        </div>

                        <div class="action-area" id="actionArea">
                            <div class="controls-row">
                                <div class="control-group">
                                    <label for="batchSize">Tamaño del Lote:</label>
                                    <select id="batchSize" class="form-select">
                                        <option value="10">10 (Lento/Seguro)</option>
                                        <option value="20">20</option>
                                        <option value="50" selected>50 (Recomendado)</option>
                                        <option value="100">100 (Rápido)</option>
                                        <option value="150">150 (Máx)</option>
                                    </select>
                                </div>
                                <div class="control-group"
                                    style="flex-direction: row; align-items: center; gap: 0.75rem; margin-top: 1.5rem;">
                                    <input type="checkbox" id="forceReindex" class="form-check-input"
                                        style="width: 1.25rem; height: 1.25rem;">
                                    <label for="forceReindex" style="cursor: pointer; margin: 0;">Forzar reindexación de
                                        todos</label>
                                </div>
                            </div>

                            <button class="btn-index" id="startBtn" onclick="startIndexing()">
                                <span id="btnIcon">🚀</span>
                                <span id="btnText">Iniciar Indexación</span>
                            </button>
                            <p id="estimatedInfo"
                                style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.9rem;">
                                Se procesarán <?= $pendingCount ?> documentos.
                            </p>
                        </div>

                        <div class="progress-section" id="progressSection">
                            <div class="progress-bar-container">
                                <div class="progress-bar" id="progressBar"></div>
                            </div>
                            <div class="progress-text" id="progressText">Preparando...</div>

                            <div class="log-section" id="logSection"></div>
                        </div>

                        <div class="complete-message" id="completeMessage">
                            <h3>✅ ¡Indexación Completada!</h3>
                            <p id="completeSummary"></p>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem; text-align: center;">
                        <a href="../busqueda/#tab-consultar" class="btn btn-secondary" style="padding: 0.75rem 1.5rem;">
                            🔍 Ir a Búsqueda Full-Text
                        </a>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = '<?= $baseUrl ?>api.php';
        let totalDocs = <?= $totalPdfCount ?>;
        let initialPending = <?= $pendingCount ?>;
        let currentIndexed = <?= $totalPdfCount - $pendingCount ?>;

        let isRunning = false;

        // UI Updates based on selection
        const forceCheck = document.getElementById('forceReindex');
        const estInfo = document.getElementById('estimatedInfo');

        forceCheck.addEventListener('change', function () {
            if (this.checked) {
                estInfo.textContent = `⚠️ Se re-procesarán TODOS los ${totalDocs} documentos (más lento).`;
                estInfo.style.color = '#f59e0b';
            } else {
                estInfo.textContent = `Se procesarán ${initialPending} documentos pendientes.`;
                estInfo.style.color = 'var(--text-secondary)';
            }
        });

        function addLog(message, type = 'info') {
            const log = document.getElementById('logSection');
            const entry = document.createElement('div');
            entry.className = `log-entry ${type}`;
            entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            log.insertBefore(entry, log.firstChild);
        }

        async function startIndexing() {
            if (isRunning) return;
            isRunning = true;

            const btn = document.getElementById('startBtn');
            const batchSize = parseInt(document.getElementById('batchSize').value) || 50;
            const force = document.getElementById('forceReindex').checked;

            const progressSection = document.getElementById('progressSection');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const actionArea = document.getElementById('actionArea');

            // Lock UI
            btn.disabled = true;
            document.getElementById('batchSize').disabled = true;
            forceCheck.disabled = true;

            document.getElementById('btnIcon').innerHTML = '<div class="spinner"></div>';
            document.getElementById('btnText').textContent = force ? 'Reindexando TODO...' : 'Indexando...';

            progressSection.classList.add('active');
            addLog(`Iniciando: Lote=${batchSize}, Forzar=${force ? 'SÍ' : 'NO'}`, 'info');

            let processedCount = 0;
            let offset = 0;
            let keepGoing = true;
            let batchNum = 0;

            // If forcing, we process ALL docs by offset.
            // If NOT forcing, we process until pending == 0.

            const targetTotal = force ? totalDocs : initialPending;
            // Note: If NOT forcing, targetTotal might decrease dynamically, but we use it for progress bar estimation.

            while (keepGoing) {
                batchNum++;
                addLog(`Procesando lote #${batchNum} (Offset: ${offset})...`, 'info');

                try {
                    // Build URL
                    let url = `${apiUrl}?action=reindex_documents&batch=${batchSize}`;
                    if (force) {
                        url += `&force=1&offset=${offset}`;
                    } else {
                        // Regular mode: always grab the top X pending (offset usually 0, or implicitly handled by DB query)
                        // Actually, our API logic for pending uses 'LIMIT N', so offset isn't strictly needed if we just want "next N pending",
                        // BUT if we want to be safe against "stuck" docs, maybe. 
                        // However, standard logic is just "get next N".
                    }

                    const response = await fetch(url);
                    const result = await response.json();

                    if (!result.success) {
                        addLog(`Error en servidor: ${result.error || 'Desconocido'}`, 'error');
                        keepGoing = false;
                        break;
                    }

                    const docsInBatch = result.indexed + (result.errors ? result.errors.length : 0);
                    processedCount += docsInBatch;

                    // Update global counters
                    let newPending = result.pending; // accurate from server

                    if (force) {
                        // In force mode, we rely on offset
                        offset += batchSize;
                        // If we processed fewer than batchSize, we are likely at the end
                        // However, be careful: result.indexed might be 0 if all failed? 
                        // Safer to check if docsInBatch < batchSize or if offset >= totalDocs
                        if (docsInBatch < batchSize && offset >= totalDocs) {
                            keepGoing = false;
                        }
                        if (offset >= totalDocs) {
                            keepGoing = false;
                        }
                    } else {
                        // Standard mode
                        if (newPending === 0) {
                            keepGoing = false;
                        }
                        // Safety: if we didn't index anything and pending > 0, we might be stuck on broken docs
                        if (docsInBatch === 0 && newPending > 0) {
                            addLog(`⚠️ Alerta: No se procesaron documentos pero quedan ${newPending}. Posibles archivos faltantes.`, 'error');
                            keepGoing = false;
                        }
                    }

                    // Update UI Progress
                    // Estimation for progress bar calculate
                    let progressPercent = 0;
                    if (force) {
                        progressPercent = Math.min(100, Math.round((offset / totalDocs) * 100));
                        // Correction for end
                        if (!keepGoing) progressPercent = 100;
                    } else {
                        // Pending mode: (Initial - CurrentPending) / Initial
                        if (initialPending > 0) {
                            progressPercent = Math.min(100, Math.round(((initialPending - newPending) / initialPending) * 100));
                        } else {
                            progressPercent = 100;
                        }
                    }

                    progressBar.style.width = progressPercent + '%';

                    // Stats Update
                    document.getElementById('pendingCount').textContent = newPending;
                    // For "Indexed", if forceful, we just show total - pending usually
                    document.getElementById('indexedCount').textContent = totalDocs - newPending;

                    progressText.textContent = `${progressPercent}% | Lote: ${docsInBatch} docs`;

                    // Batch logs
                    if (result.indexed > 0) {
                        addLog(`✓ Lote completado: ${result.indexed} indexados.`, 'success');
                    }
                    if (result.errors && result.errors.length > 0) {
                        // Show first few errors only to avoid spam
                        result.errors.slice(0, 3).forEach(err => addLog(`✗ ${err}`, 'error'));
                        if (result.errors.length > 3) addLog(`... y ${result.errors.length - 3} errores más.`, 'error');
                    }

                } catch (error) {
                    addLog(`Error de red/timeout: ${error.message}`, 'error');
                    // In robust mode, maybe we want to retry? For now, break to avoid infinite loops
                    keepGoing = false;
                    break;
                }
            }

            // Finshed
            progressBar.style.width = '100%';
            progressBar.style.background = '#10b981';

            const finalPending = document.getElementById('pendingCount').textContent;

            document.getElementById('completeMessage').classList.add('show');
            document.getElementById('completeSummary').innerText =
                `Proceso finalizado.\nDocs procesados en esta sesión: ${processedCount}.\nPendientes actuales: ${finalPending}`;

            addLog('🏁 Proceso finalizado.', 'success');

            // Re-enable (optional, but usually we leave it done)
            // btn.disabled = false;
            document.getElementById('btnIcon').innerHTML = '✅';
            document.getElementById('btnText').textContent = 'Finalizado';
            actionArea.style.display = 'none'; // hide controls to look clean
        }
    </script>
</body>

</html>