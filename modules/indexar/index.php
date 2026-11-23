<?php
/**
 * Módulo de Indexación de PDFs
 * Permite indexar todos los documentos PDF pendientes para búsqueda full-text
 */
require_once __DIR__ . '/../../helpers/session_init.php';
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
    AND (datos_extraidos IS NULL OR datos_extraidos = ''
         OR (datos_extraidos NOT LIKE '%\"text\":%' AND datos_extraidos NOT LIKE '%\"error\":%'))
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
                                        <option value="10">10</option>
                                        <option value="20" selected>20 (Recomendado)</option>
                                        <option value="50">50</option>
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
        let offsetGuardado = 0; // en "Forzar", para continuar donde quedó tras un corte

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

        const sleep = ms => new Promise(r => setTimeout(r, ms));

        // Una petición al servidor. Devuelve el JSON o lanza un error claro.
        // El servidor trabaja ~25 s por petición; 90 s es margen de sobra.
        async function pedirLote(url) {
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 90000);
            try {
                const response = await fetch(url, { signal: ctrl.signal, credentials: 'same-origin' });
                const raw = await response.text();
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    if (/login|iniciar sesi/i.test(raw)) throw new Error('La sesión se cerró. Vuelva a iniciar sesión y presione Iniciar otra vez.');
                    throw new Error(`El servidor no respondió bien (HTTP ${response.status}: ${raw.trim().substring(0, 60) || 'vacío'})`);
                }
                return data;
            } catch (e) {
                if (e.name === 'AbortError') throw new Error('El servidor tardó demasiado en responder');
                throw e;
            } finally {
                clearTimeout(timer);
            }
        }

        function setFinal(tipo, titulo, texto) {
            const box = document.getElementById('completeMessage');
            const colores = { ok: '#10b981', aviso: '#f59e0b', error: '#ef4444' };
            box.querySelector('h3').textContent = titulo;
            box.querySelector('h3').style.color = colores[tipo];
            box.style.borderColor = colores[tipo];
            box.style.background = colores[tipo] + '1a';
            document.getElementById('completeSummary').innerText = texto;
            box.classList.add('show');
            document.getElementById('progressBar').style.background = colores[tipo];
        }

        async function startIndexing() {
            if (isRunning) return;
            isRunning = true;

            const btn = document.getElementById('startBtn');
            const batchSize = parseInt(document.getElementById('batchSize').value) || 20;
            const force = document.getElementById('forceReindex').checked;

            const progressSection = document.getElementById('progressSection');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const actionArea = document.getElementById('actionArea');

            btn.disabled = true;
            document.getElementById('batchSize').disabled = true;
            forceCheck.disabled = true;
            document.getElementById('completeMessage').classList.remove('show');
            progressBar.style.background = '';

            document.getElementById('btnIcon').innerHTML = '<div class="spinner"></div>';
            document.getElementById('btnText').textContent = force ? 'Reindexando TODO...' : 'Indexando...';

            progressSection.classList.add('active');
            addLog(`Iniciando: hasta ${batchSize} documentos por vuelta, Forzar=${force ? 'SÍ' : 'NO'}. Los PDF escaneados se leen con OCR página por página (puede tardar).`, 'info');

            const MAX_FALLOS_SEGUIDOS = 4;
            let processedCount = 0, indexedCount = 0, errorCount = 0;
            let offset = force ? offsetGuardado : 0, vuelta = 0, fallosSeguidos = 0, sinAvance = 0;
            let ultimoParcial = null;
            let estado = 'corriendo'; // 'ok' | 'detenido' | 'atascado'
            let ultimoError = '';
            let newPending = initialPending;

            while (estado === 'corriendo') {
                vuelta++;
                let url = `${apiUrl}?action=reindex_documents&batch=${batchSize}`;
                if (force) url += `&force=1&offset=${offset}`;

                let result;
                try {
                    result = await pedirLote(url);
                    if (!result.success) throw new Error(result.error || 'Error desconocido del servidor');
                    fallosSeguidos = 0;
                } catch (error) {
                    fallosSeguidos++;
                    ultimoError = error.message;
                    if (/sesión se cerró/.test(error.message) || fallosSeguidos >= MAX_FALLOS_SEGUIDOS) {
                        addLog(`✗ ${error.message}`, 'error');
                        estado = 'detenido';
                        break;
                    }
                    const espera = 5 * fallosSeguidos;
                    addLog(`⚠️ ${error.message}. Reintento ${fallosSeguidos}/${MAX_FALLOS_SEGUIDOS - 1} en ${espera} s (lo ya leído queda guardado)...`, 'error');
                    await sleep(espera * 1000);
                    continue;
                }

                const avanceParcial = result.partial && (!ultimoParcial || result.partial.id !== ultimoParcial.id || result.partial.pagina !== ultimoParcial.pagina);
                ultimoParcial = result.partial || null;
                processedCount += result.processed || 0;
                indexedCount += result.indexed || 0;
                errorCount += (result.errors || []).length;
                newPending = result.pending;
                if (force) { offset = result.next_offset ?? (offset + batchSize); offsetGuardado = offset; }

                if (result.indexed > 0) addLog(`✓ ${result.indexed} indexados en esta vuelta (${result.seconds ?? '?'} s).`, 'success');
                if (result.errors && result.errors.length > 0) {
                    result.errors.slice(0, 3).forEach(err => addLog(`✗ ${err}`, 'error'));
                    if (result.errors.length > 3) addLog(`... y ${result.errors.length - 3} errores más.`, 'error');
                }
                if (result.partial) {
                    const p = result.partial;
                    addLog(`📄 Documento #${p.id} escaneado: leídas ${p.pagina - 1}${p.paginas ? ' de ' + p.paginas : ''} páginas, sigue...`, 'info');
                }

                // ¿Terminó? ¿Está atascado?
                if (result.done) {
                    estado = 'ok';
                } else if ((result.processed || 0) === 0 && !avanceParcial) {
                    sinAvance++;
                    if (sinAvance >= 2) {
                        addLog(`⚠️ No hubo avance en ${sinAvance} vueltas seguidas; quedan ${newPending} pendientes.`, 'error');
                        estado = 'atascado';
                    }
                } else {
                    sinAvance = 0;
                }

                // Progreso
                let pct;
                if (force) pct = totalDocs > 0 ? Math.round((Math.min(offset, totalDocs) / totalDocs) * 100) : 100;
                else pct = initialPending > 0 ? Math.round(((initialPending - newPending) / initialPending) * 100) : 100;
                pct = Math.max(0, Math.min(100, pct));
                if (estado === 'ok') pct = 100;
                progressBar.style.width = pct + '%';
                document.getElementById('pendingCount').textContent = newPending;
                document.getElementById('indexedCount').textContent = totalDocs - newPending;
                progressText.textContent = `${pct}% | Vuelta ${vuelta} | Indexados en esta sesión: ${indexedCount}`;
            }

            const resumen = `Indexados en esta sesión: ${indexedCount}.\nCon error (archivo faltante o PDF ilegible): ${errorCount}.\nPendientes ahora: ${newPending}.`;
            if (estado === 'ok' && errorCount === 0) {
                setFinal('ok', '✅ ¡Indexación completada!', resumen);
                addLog('🏁 Proceso finalizado.', 'success');
                actionArea.style.display = 'none';
            } else if (estado === 'ok') {
                setFinal('aviso', '⚠️ Indexación terminada con errores', resumen + '\nLos documentos con error no se vuelven a intentar salvo con "Forzar".');
                addLog('🏁 Proceso finalizado con errores.', 'error');
                actionArea.style.display = 'none';
            } else {
                const motivo = estado === 'atascado'
                    ? 'El proceso dejó de avanzar.'
                    : `Se detuvo por un problema de conexión: ${ultimoError}`;
                setFinal('error', '❌ La indexación NO terminó', `${motivo}\n${resumen}\nLo ya leído quedó guardado: presione "Iniciar" otra vez y sigue donde quedó.`);
                addLog('⛔ Proceso detenido sin terminar.', 'error');
                btn.disabled = false;
                document.getElementById('batchSize').disabled = false;
                forceCheck.disabled = false;
                document.getElementById('btnIcon').innerHTML = '🔁';
                document.getElementById('btnText').textContent = 'Continuar indexación';
                initialPending = newPending;
            }

            if (estado === 'ok') {
                document.getElementById('btnIcon').innerHTML = '✅';
                document.getElementById('btnText').textContent = 'Finalizado';
            }
            isRunning = false;
        }
    </script>
</body>

</html>