<?php
/**
 * Resaltar Doc - Buscador de Documentos para Resaltar
 *
 * Módulo unificado que utiliza la misma lógica que "Consultar"
 * para buscar términos en documentos y abrir el visor con resaltado.
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

// For sidebar
$currentModule = 'resaltar';
$baseUrl = '../../';
$pageTitle = 'Resaltar Documento';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resaltar Doc - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .result-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            transition: all var(--transition-fast);
            margin-bottom: 1rem;
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

        .summary-box {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

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
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="card">
                    <h3 style="margin-bottom: 1rem;">🔍 Buscar y Resaltar</h3>
                    <p class="text-muted mb-4">
                        Busca palabras, códigos o nombres. El sistema buscará dentro del contenido de los PDFs.
                    </p>

                    <div
                        style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.5rem;">
                        <input type="text" class="form-input" id="fulltextSearch"
                            placeholder="Ej: ABC-123, Factura 001, Cliente X..." style="flex: 1; min-width: 200px;">

                        <button class="btn btn-primary" onclick="searchFulltext()" id="fulltextBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            Buscar Documentos
                        </button>
                    </div>

                    <div id="searchResults" class="hidden">
                        <div class="summary-box">
                            <span id="searchSummary"></span>
                            <button class="btn btn-secondary"
                                style="float: right; padding: 0.25rem 0.5rem; font-size: 0.75rem;"
                                onclick="clearSearch()">✕ Limpiar</button>
                        </div>
                        <div id="resultsList" class="results-list"></div>
                    </div>

                    <div id="loading" class="loading hidden">
                        <div class="spinner"></div>
                        <p>Buscando coincidencias...</p>
                    </div>

                    <!-- Empty State Inicial -->
                    <div id="emptyState" class="empty-state" style="margin-top: 2rem;">
                        <div class="empty-state-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <h4 class="empty-state-title">Encuentra tus documentos</h4>
                        <p class="empty-state-text">Usa el buscador para localizar documentos por su contenido o nombre
                            y verlos resaltados.</p>
                    </div>

                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = '../../api.php';
        const clientCode = '<?= $code ?>';
        const input = document.getElementById('fulltextSearch');

        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') searchFulltext();
        });

        async function searchFulltext() {
            const query = input.value.trim();
            if (query.length < 3) {
                alert('Ingresa al menos 3 caracteres');
                return;
            }

            const btn = document.getElementById('fulltextBtn');
            const loading = document.getElementById('loading');
            const results = document.getElementById('searchResults');
            const empty = document.getElementById('emptyState');

            btn.disabled = true;
            btn.innerHTML = 'Buscando...';
            loading.classList.remove('hidden');
            results.classList.add('hidden');
            empty.classList.add('hidden');

            try {
                // Reutilizamos la API existente de fulltext_search
                const response = await fetch(`${apiUrl}?action=fulltext_search&query=${encodeURIComponent(query)}`);
                const result = await response.json();

                btn.disabled = false;
                btn.textContent = 'Buscar Documentos';
                loading.classList.add('hidden');

                if (result.error) {
                    alert(result.error);
                    return;
                }

                showResults(result);
            } catch (error) {
                btn.disabled = false;
                btn.textContent = 'Buscar Documentos';
                loading.classList.add('hidden');
                alert('Error: ' + error.message);
            }
        }

        function showResults(result) {
            const container = document.getElementById('resultsList');
            const summary = document.getElementById('searchSummary');
            document.getElementById('searchResults').classList.remove('hidden');

            summary.innerHTML = `<strong>${result.count}</strong> documento(s) encontrados para "<strong>${result.query}</strong>"`;

            if (result.results.length === 0) {
                container.innerHTML = '<p class="text-muted">No se encontraron coincidencias.</p>';
                return;
            }

            let html = '';
            for (const doc of result.results) {
                let pdfUrl = '';
                if (doc.ruta_archivo) {
                    // Use download.php to resolve path server-side
                    pdfUrl = `download.php?doc=${doc.id}`;
                }

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha} · ${doc.occurrences} coincidencia(s)</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        
                        <div class="result-actions" style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="viewer.php?doc=${doc.id}&term=${encodeURIComponent(result.query)}" 
                               class="btn btn-primary btn-sm" style="flex: 1; justify-content: center;">
                                🖍️ Ver Resaltado
                            </a>
                            
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary btn-sm">📄 Original</a>` : ''}
                        </div>
                    </div>
                `;
            }
            container.innerHTML = html;
        }

        function clearSearch() {
            input.value = '';
            document.getElementById('searchResults').classList.add('hidden');
            document.getElementById('emptyState').classList.remove('hidden');
        }
    </script>
</body>

</html>