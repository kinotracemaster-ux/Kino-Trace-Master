<?php
/**
 * Main Dashboard - KINO TRACE
 *
 * Central hub with sidebar navigation and quick access to all modules.
 * Shows statistics and recent documents for the logged-in client.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/search_engine.php';
require_once __DIR__ . '/../../helpers/gemini_ai.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$stats = get_search_stats($db);

// Get recent documents
$recentDocs = $db->query("
    SELECT d.id, d.tipo, d.numero, d.fecha, d.proveedor, 
           (SELECT COUNT(*) FROM codigos WHERE documento_id = d.id) as code_count
    FROM documentos d
    ORDER BY d.fecha_creacion DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// For sidebar
$currentModule = 'dashboard';
$baseUrl = '../../';
$pageTitle = 'Dashboard';

// Get client name/title and detect logo
$dashboardClientName = 'KINO TRACE';
$dashboardClientTitle = '';
if (isset($centralDb)) {
    $infoStmt = $centralDb->prepare('SELECT nombre, titulo FROM control_clientes WHERE codigo = ? LIMIT 1');
    $infoStmt->execute([$code]);
    $clientRow = $infoStmt->fetch(PDO::FETCH_ASSOC);
    if ($clientRow) {
        $dashboardClientName = $clientRow['nombre'];
        $dashboardClientTitle = $clientRow['titulo'] ?: $clientRow['nombre'];
    }
}

// Detect client logo
$dashboardLogo = '';
foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
    $logoFile = __DIR__ . '/../../clients/' . $code . '/logo.' . $ext;
    if (file_exists($logoFile)) {
        $dashboardLogo = '../../clients/' . $code . '/logo.' . $ext;
        break;
    }
}

// Count by type
$docsByType = $db->query("
    SELECT tipo, COUNT(*) as cnt
    FROM documentos
    GROUP BY tipo
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Count documents needing indexing (for notification)
$pendingIndex = 0;
$allDocsStmt = $db->query("SELECT datos_extraidos FROM documentos WHERE ruta_archivo LIKE '%.pdf'");
while ($row = $allDocsStmt->fetch(PDO::FETCH_ASSOC)) {
    $data = json_decode($row['datos_extraidos'] ?? '', true);
    if (empty($data['text']) || strlen($data['text'] ?? '') < 100) {
        $pendingIndex++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <!-- Client Logo + Title Hero -->
                <div
                    style="text-align: center; margin-bottom: 1.5rem; padding: 1.5rem 1rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-lg);">
                    <h2 style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                        <?= htmlspecialchars($dashboardClientTitle) ?>
                    </h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0;">Gestor Documental</p>
                    <?php if ($dashboardLogo): ?>
                        <img src="<?= htmlspecialchars($dashboardLogo) ?>"
                            alt="<?= htmlspecialchars($dashboardClientTitle) ?>"
                            style="max-height: 80px; max-width: 200px; object-fit: contain; margin-top: 0.75rem; border-radius: 8px;">
                    <?php endif; ?>
                </div>
                <?php if ($pendingIndex > 0): ?>
                    <!-- Indexing Notification Banner -->
                    <div id="indexBanner"
                        style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <span style="font-size: 1.5rem;">📄</span>
                            <div>
                                <strong><?= $pendingIndex ?> documento(s)</strong> necesitan indexarse para búsqueda
                                full-text
                                <div style="font-size: 0.8rem; opacity: 0.9;">Los documentos indexados permiten buscar texto
                                    dentro de los PDFs</div>
                            </div>
                        </div>
                        <button onclick="autoIndex()" id="autoIndexBtn"
                            style="background: white; color: #d97706; border: none; padding: 0.75rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                            🚀 Indexar Ahora
                        </button>
                    </div>
                    <div id="indexProgress"
                        style="display: none; background: var(--bg-secondary); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div class="spinner" style="width: 20px; height: 20px; border-width: 2px;"></div>
                            <span id="indexProgressText">Indexando documentos...</span>
                        </div>
                        <div
                            style="margin-top: 0.5rem; background: var(--bg-tertiary); border-radius: 4px; height: 6px; overflow: hidden;">
                            <div id="indexProgressBar"
                                style="width: 0%; height: 100%; background: var(--accent-primary); transition: width 0.3s;">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="stats-grid">
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

                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <?php
                            $vincCount = (int) $db->query('SELECT COUNT(*) FROM vinculos')->fetchColumn();
                            ?>
                            <div class="stat-value"><?= number_format($vincCount) ?></div>
                            <div class="stat-label">Vínculos</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Acciones Rápidas</h3>
                    </div>
                    <div class="flex gap-3" style="flex-wrap: wrap;">
                        <a href="../busqueda/" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            Buscar Códigos
                        </a>
                        <a href="../subir/" class="btn btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Subir Documento
                        </a>
                        <a href="../resaltar/" class="btn btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                            </svg>
                            Resaltar PDF
                        </a>
                        <a href="vincular.php" class="btn btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            Vincular Docs
                        </a>
                        <a href="../sincronizar/" class="btn btn-primary" style="background: #10b981;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Sincronizar BD
                        </a>
                    </div>
                </div>

                <!-- Recent Documents & Stats by Type -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                    <!-- Recent Documents -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Documentos Recientes</h3>
                            <a href="../recientes/" class="btn btn-secondary"
                                style="padding: 0.5rem 1rem; font-size: 0.75rem;">Ver todos</a>
                        </div>
                        <?php if (empty($recentDocs)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                    </svg>
                                </div>
                                <h4 class="empty-state-title">Sin documentos</h4>
                                <p class="empty-state-text">Comienza subiendo tu primer documento.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Número</th>
                                            <th>Fecha</th>
                                            <th>Códigos</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentDocs as $doc): ?>
                                            <tr>
                                                <td><span class="badge badge-primary"><?= strtoupper($doc['tipo']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($doc['numero']) ?></td>
                                                <td><?= htmlspecialchars($doc['fecha']) ?></td>
                                                <td><span class="code-tag"><?= $doc['code_count'] ?></span></td>
                                                <td>
                                                    <a href="../../modules/resaltar/viewer.php?doc=<?= $doc['id'] ?>"
                                                        class=" btn btn-secondary btn-icon" title="Ver documento">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Stats by Type -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Por Tipo</h3>
                        </div>
                        <?php if (empty($docsByType)): ?>
                            <p class="text-muted text-center">Sin datos</p>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <?php foreach ($docsByType as $type): ?>
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-md);">
                                        <span
                                            style="font-weight: 500; text-transform: capitalize;"><?= htmlspecialchars($type['tipo']) ?></span>
                                        <span class="badge badge-primary"><?= $type['cnt'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <?php if ($pendingIndex > 0): ?>
        <script>
            const apiUrl = '../../api.php';
            let totalPending = <?= $pendingIndex ?>;
            let totalIndexed = 0;

            async function autoIndex() {
                const btn = document.getElementById('autoIndexBtn');
                const banner = document.getElementById('indexBanner');
                const progress = document.getElementById('indexProgress');
                const progressText = document.getElementById('indexProgressText');
                const progressBar = document.getElementById('indexProgressBar');

                btn.style.display = 'none';
                progress.style.display = 'block';

                let processedCount = 0;
                let pending = totalPending;
                // Use a large batch size to attempt single-pass, but support loop if needed
                const batchSize = 50;

                try {
                    do {
                        // Request batch
                        const response = await fetch(`${apiUrl}?action=reindex_documents&batch=${batchSize}`);

                        // 1. Get raw text first
                        const rawText = await response.text();

                        if (!rawText.trim()) {
                            throw new Error("El servidor devolvió una respuesta vacía.");
                        }

                        // 2. Try parse JSON
                        let result;
                        try {
                            result = JSON.parse(rawText);
                        } catch (e) {
                            console.error("JSON Parse Error:", e);
                            throw new Error(`Respuesta inválida (no JSON): ${rawText.substring(0, 100)}...`);
                        }

                        if (!result.success) {
                            throw new Error(result.error || 'Error desconocido del servidor');
                        }

                        // Update counters
                        processedCount += result.indexed;
                        pending = result.pending;
                        totalIndexed += result.indexed;

                        // Update UI
                        const percent = Math.round(((totalPending - pending) / totalPending) * 100);
                        progressBar.style.width = percent + '%';
                        progressText.textContent = `Indexados: ${totalIndexed}, Pendientes: ${pending}`;

                        // Safety break if no progress is made
                        if (result.indexed === 0 && pending > 0) {
                            throw new Error(`El proceso se detuvo. ${pending} documentos no se pudieron procesar (posiblemente archivos faltantes).`);
                        }

                    } while (pending > 0);

                    // Success!
                    progressText.textContent = '✅ ¡Todos los documentos indexados!';
                    progressBar.style.width = '100%';
                    progressBar.style.background = '#10b981';

                    setTimeout(() => {
                        banner.style.display = 'none';
                        progress.style.display = 'none';
                        // Reload to reflect changes
                        window.location.reload();
                    }, 2000);

                } catch (error) {
                    console.error(error);
                    progressText.textContent = 'Error: ' + error.message;
                    // Re-enable button on error
                    btn.style.display = 'inline-flex';
                }
            }
        </script>
    <?php endif; ?>
</body>

</html>