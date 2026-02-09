<?php
/**
 * Doc Recientes - Recent Documents
 *
 * Shows the most recently added/modified documents across all types.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

// Get recent documents
$recentDocs = $db->query("
    SELECT d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.fecha_creacion, d.ruta_archivo,
           (SELECT COUNT(*) FROM codigos WHERE documento_id = d.id) as code_count
    FROM documentos d
    ORDER BY d.fecha_creacion DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// For sidebar
$currentModule = 'recientes';
$baseUrl = '../../';
$pageTitle = 'Documentos Recientes';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doc Recientes - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Documentos Recientes</h3>
                        <!-- Filtros rápidos (opcional para futuro) -->
                    </div>

                    <div class="table-container">
                        <table class="table" id="recientesTable">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Número</th>
                                    <th>Fecha Doc</th>
                                    <th>Proveedor</th>
                                    <th>Códigos</th>
                                    <th>Subido</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="recientesBody">
                                <!-- Los datos se cargarán aquí vía AJAX -->
                            </tbody>
                        </table>
                    </div>

                    <div id="loading" class="loading hidden" style="text-align: center; padding: 1rem;">
                        <div class="spinner"></div>
                        <p>Cargando documentos...</p>
                    </div>

                    <div style="text-align: center; padding: 1rem;">
                        <button id="loadMoreBtn" class="btn btn-secondary">
                            ⏬ Ver 50 más
                        </button>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        let offset = 0;
        const limit = 50;
        const clientCode = '<?= $code ?>';

        function loadDocuments() {
            const btn = document.getElementById('loadMoreBtn');
            const loading = document.getElementById('loading');

            btn.disabled = true;
            loading.classList.remove('hidden');

            fetch(`api_recientes.php?offset=${offset}&limit=${limit}`)
                .then(response => response.json())
                .then(data => {
                    loading.classList.add('hidden');
                    btn.disabled = false;

                    if (data.length === 0) {
                        btn.style.display = 'none';
                        if (offset === 0) {
                            document.getElementById('recientesBody').innerHTML = '<tr><td colspan="7" style="text-align:center">No hay documentos recientes.</td></tr>';
                        }
                        return;
                    }

                    if (data.length < limit) {
                        btn.style.display = 'none'; // No hay más páginas
                    }

                    const tbody = document.getElementById('recientesBody');
                    data.forEach(doc => {
                        const pdfUrl = doc.ruta_archivo ? `../../modules/resaltar/download.php?doc=${doc.id}` : '';

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><span class="badge badge-primary">${doc.tipo.toUpperCase()}</span></td>
                            <td>${escapeHtml(doc.numero)}</td>
                            <td>${doc.fecha}</td>
                            <td>${escapeHtml(doc.proveedor || '-')}</td>
                            <td><span class="code-tag">${doc.code_count}</span></td>
                            <td style="font-size: 0.75rem; color: var(--text-secondary);">${doc.fecha_creacion_fmt}</td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="../../modules/resaltar/viewer.php?doc=${doc.id}" class="btn btn-secondary btn-icon" title="Ver documento">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </a>
                                    ${pdfUrl ? `
                                    <a href="${pdfUrl}" target="_blank" class="btn btn-primary btn-icon" title="Ver PDF">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                    </a>` : ''}
                                </div>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    offset += limit;
                })
                .catch(err => {
                    console.error(err);
                    loading.classList.add('hidden');
                    btn.disabled = false;
                    alert('Error al cargar documentos');
                });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.getElementById('loadMoreBtn').addEventListener('click', loadDocuments);

        // Cargar inicialmente
        loadDocuments();
    </script>
</body>

</html>