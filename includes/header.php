<?php
/**
 * Header Component
 * 
 * Top navigation bar with sidebar toggle, page title, and actions.
 * Incluye meta tags de seguridad (CSRF)
 */

$pageTitle = $pageTitle ?? 'Dashboard';

// 🛡️ SEGURIDAD: Meta tag CSRF para AJAX
if (file_exists(__DIR__ . '/../helpers/csrf_protection.php')) {
    require_once __DIR__ . '/../helpers/csrf_protection.php';
    $csrfToken = CsrfProtection::getToken();
} else {
    $csrfToken = '';
}
?>

<!-- 🛡️ CSRF Token para requests AJAX -->
<?php if ($csrfToken): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <script>
        // Configurar CSRF token global para fetch
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!csrfToken) return;

            // Sobrescribir fetch para incluir token automáticamente
            const originalFetch = window.fetch;
            window.fetch = function (url, options = {}) {
                const method = (options.method || 'GET').toUpperCase();

                // Agregar token en métodos que modifican datos
                if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
                    options.headers = options.headers || {};

                    // Solo agregar si no existe ya
                    if (!options.headers['X-CSRF-Token'] && !options.headers['x-csrf-token']) {
                        options.headers['X-CSRF-Token'] = csrfToken;
                    }
                }

                return originalFetch(url, options);
            };
        })();
    </script>
<?php endif; ?>

<header class="main-header">
    <div class="header-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <h2 class="page-title">
            <?= htmlspecialchars($pageTitle) ?>
        </h2>
    </div>

    <div class="header-right">
        <button class="header-btn" title="Limpiar Caché y Recargar" onclick="forceClearCache()"
            style="width: auto; padding: 0 12px; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            <span style="font-size: 0.875rem; font-weight: 500;">Borrar cache</span>
        </button>
    </div>
</header>

<script>
    async function forceClearCache() {
        if (!confirm('¿Limpiar caché del servidor y recargar?')) return;
        try {
            await fetch('api.php?action=clear_cache');
            // Force browser reload ignoring cache
            window.location.href = window.location.href;
        } catch (e) {
            console.error(e);
            location.reload();
        }
    }
</script>