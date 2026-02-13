<?php
/**
 * Sidebar Navigation Component - KINO TRACE
 * 
 * Nueva estructura:
 * 1. Búsqueda Voraz (carga AJAX)
 * 2. Consultar (carga AJAX)
 * 3. Búsqueda por Código (carga AJAX)
 * 4. Subir Documento (carga AJAX)
 * 5. Administrador (expandible - oculta 1-4)
 * 6. Backup (carga AJAX)
 * 7. Salir
 */

// Get client info for branding
$clientCode = $_SESSION['client_code'] ?? 'guest';
$clientName = 'KINO TRACE';

// Try to get client name from central DB if available
if (isset($centralDb)) {
    $stmt = $centralDb->prepare('SELECT nombre, titulo FROM control_clientes WHERE codigo = ? LIMIT 1');
    $stmt->execute([$clientCode]);
    $clientInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($clientInfo) {
        $clientName = $clientInfo['titulo'] ?: $clientInfo['nombre'];
    }
}

// Detect client logo
$clientLogo = '';
$extensions = ['png', 'jpg', 'jpeg', 'gif'];
foreach ($extensions as $ext) {
    if (file_exists(__DIR__ . '/../clients/' . $clientCode . '/logo.' . $ext)) {
        $clientLogo = 'clients/' . $clientCode . '/logo.' . $ext;
        break;
    }
}

// Current section for active state
$currentSection = $currentSection ?? 'voraz';
?>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <!-- Header / Brand -->
    <div class="sidebar-header" style="padding: 1rem; text-align: center;">
        <h1
            style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.5rem 0; line-height: 1.3;">
            <?= htmlspecialchars($clientName) ?>
        </h1>
        <?php if ($clientLogo): ?>
            <div style="width: 100%; display: flex; justify-content: center; align-items: center;">
                <img src="<?= $baseUrl ?? './' ?><?= $clientLogo ?>" alt="<?= htmlspecialchars($clientName) ?>"
                    style="max-width: 100%; max-height: 60px; object-fit: contain;">
            </div>
        <?php else: ?>
            <div class="sidebar-logo" style="margin: 0 auto;"><?= strtoupper(substr($clientName, 0, 1)) ?></div>
        <?php endif; ?>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- NAVEGACIÓN PRINCIPAL (se ocultan al expandir Administrador) -->
        <div id="main-nav-buttons">
            <!-- 1. BÚSQUEDA VORAZ -->
            <button class="nav-item nav-section-btn active" data-section="voraz" onclick="switchSection('voraz')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                </span>
                <span class="nav-label">Búsqueda Voraz</span>
            </button>

            <!-- 2. CONSULTAR -->
            <button class="nav-item nav-section-btn" data-section="consultar" onclick="switchSection('consultar')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                </span>
                <span class="nav-label">Consultar</span>
            </button>

            <!-- 3. BÚSQUEDA POR CÓDIGO -->
            <button class="nav-item nav-section-btn" data-section="codigo" onclick="switchSection('codigo')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                </span>
                <span class="nav-label">Búsqueda por Código</span>
            </button>

            <!-- 4. SUBIR DOCUMENTO -->
            <button class="nav-item nav-section-btn" data-section="subir" onclick="switchSection('subir')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                </span>
                <span class="nav-label">Subir Documento</span>
            </button>
        </div>

        <!-- 5. ADMINISTRADOR (Expandible - Oculta botones principales) -->
        <div class="nav-item-expandable">
            <button class="nav-item nav-toggle" onclick="toggleAdminMenu()">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </span>
                <span class="nav-label">Administrador</span>
                <span class="nav-arrow">▾</span>
            </button>
            <div class="nav-submenu" id="submenu-admin">
                <a href="<?= $baseUrl ?? '' ?>modules/excel_import/" class="nav-subitem">
                    Importar Data Excel
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/importar/" class="nav-subitem">
                    Importar Backup (SQL)
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/lote/" class="nav-subitem">
                    Subida por Lote
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/sincronizar/" class="nav-subitem">
                    Sincronizar
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/vincular.php" class="nav-subitem">
                    Vincular
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/indexar/" class="nav-subitem">
                    Indexar
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/validar.php" class="nav-subitem">
                    Validar
                </a>
            </div>
        </div>

        <!-- 6. BACKUP -->
        <button class="nav-item nav-section-btn" data-section="backup" onclick="switchSection('backup')">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                </svg>
            </span>
            <span class="nav-label">Backup</span>
        </button>

    </nav>

    <!-- Footer / User Card -->
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?= strtoupper(substr($clientCode, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($clientCode) ?></div>
                <div class="user-role">Cliente</div>
            </div>
        </div>
        <a href="<?= $baseUrl ?? '' ?>logout.php" class="nav-item logout-btn">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
            </span>
            <span class="nav-label">Salir</span>
        </a>
    </div>
</aside>

<style>
    /* =====================================
       SIDEBAR STYLES - KINO TRACE
       Diseño minimalista y responsive
       ===================================== */

    /* Estilos base del sidebar */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 260px;
        height: 100vh;
        background: var(--bg-primary);
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        z-index: 100;
        transition: transform 0.3s ease, width 0.3s ease;
    }

    /* Header del Sidebar */
    .sidebar-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1.25rem 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .sidebar-logo {
        width: 42px;
        height: 42px;
        background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        color: white;
        flex-shrink: 0;
    }

    .sidebar-brand h1 {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        line-height: 1.2;
    }

    .sidebar-brand span {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    /* Navegación */
    .sidebar-nav {
        flex: 1;
        padding: 1rem 0.75rem;
        overflow-y: auto;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.2s ease;
        cursor: pointer;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
        font-size: 0.9rem;
        font-family: inherit;
    }

    .nav-item:hover {
        background: var(--bg-secondary);
        color: var(--text-primary);
    }

    .nav-item.active,
    .nav-section-btn.active {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(16, 185, 129, 0.1));
        color: var(--accent-primary);
    }

    .nav-icon {
        width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .nav-icon svg {
        width: 100%;
        height: 100%;
    }

    .nav-label {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Menús expandibles */
    .nav-item-expandable {
        margin: 0.25rem 0;
    }

    .nav-toggle {
        position: relative;
    }

    .nav-toggle .nav-arrow {
        position: absolute;
        right: 1rem;
        transition: transform 0.2s ease;
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .nav-toggle.active .nav-arrow {
        transform: rotate(180deg);
    }

    .nav-submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease;
        background: var(--bg-tertiary);
        border-radius: var(--radius-md);
        margin: 0.25rem 0 0.5rem 0;
    }

    .nav-submenu.open {
        max-height: 500px;
        padding: 0.5rem 0;
    }

    .nav-subitem {
        display: block;
        padding: 0.65rem 1rem 0.65rem 2.75rem;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.85rem;
        transition: all 0.2s ease;
        border-left: 3px solid transparent;
    }

    .nav-subitem:hover {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border-left-color: var(--accent-primary);
    }

    /* Animación para ocultar botones principales */
    #main-nav-buttons {
        overflow: hidden;
        max-height: 500px;
        transition: max-height 0.4s ease, opacity 0.3s ease;
        opacity: 1;
    }

    #main-nav-buttons.hidden {
        max-height: 0;
        opacity: 0;
        pointer-events: none;
    }

    /* Footer del Sidebar */
    .sidebar-footer {
        padding: 1rem;
        border-top: 1px solid var(--border-color);
    }

    .user-card {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem;
        margin-bottom: 0.5rem;
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: white;
        font-size: 0.9rem;
    }

    .user-info {
        flex: 1;
        min-width: 0;
    }

    .user-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.85rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-role {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .logout-btn {
        margin-top: 0.5rem;
        color: var(--text-muted);
    }

    .logout-btn:hover {
        background: rgba(239, 68, 68, 0.1);
        color: var(--accent-danger);
    }

    /* Overlay Mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 99;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .sidebar-overlay.active {
        display: block;
        opacity: 1;
    }

    /* =====================================
       RESPONSIVE DESIGN
       ===================================== */

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            width: 280px;
        }

        .sidebar.open {
            transform: translateX(0);
        }

        .sidebar-overlay.active {
            display: block;
        }
    }

    @media (min-width: 769px) and (max-width: 1024px) {
        .sidebar {
            width: 220px;
        }

        .nav-item {
            padding: 0.6rem 0.75rem;
            font-size: 0.85rem;
        }
    }

    /* Sidebar colapsado (para tablets/desktop) */
    .sidebar.collapsed {
        width: 70px;
    }

    .sidebar.collapsed .sidebar-brand,
    .sidebar.collapsed .nav-label,
    .sidebar.collapsed .nav-arrow,
    .sidebar.collapsed .user-info,
    .sidebar.collapsed .nav-submenu {
        display: none;
    }

    .sidebar.collapsed .sidebar-header {
        justify-content: center;
        padding: 1rem 0.5rem;
    }

    .sidebar.collapsed .nav-item {
        justify-content: center;
        padding: 0.75rem;
    }

    .sidebar.collapsed .user-card {
        justify-content: center;
    }
</style>

<script>
    // Toggle Sidebar (mobile)
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    }

    // Toggle Admin Menu (oculta botones principales) - PROTEGIDO CON CÓDIGO
    let adminUnlocked = false;

    function toggleAdminMenu() {
        const submenu = document.getElementById('submenu-admin');
        const button = submenu.previousElementSibling;
        const mainButtons = document.getElementById('main-nav-buttons');

        // Si el menú está cerrado y no está desbloqueado, pedir código
        if (!submenu.classList.contains('open') && !adminUnlocked) {
            const code = prompt('🔐 Ingresa el código de administrador:');

            if (code === null) {
                // Usuario canceló
                return;
            }

            if (code !== '3312') {
                alert('❌ Código incorrecto');
                return;
            }

            // Código correcto - desbloquear para esta sesión
            adminUnlocked = true;
        }

        // Toggle submenu
        submenu.classList.toggle('open');
        button.classList.toggle('active');

        // Ocultar/mostrar botones principales
        if (submenu.classList.contains('open')) {
            mainButtons.classList.add('hidden');
        } else {
            mainButtons.classList.remove('hidden');
        }
    }

    // Switch Section (cambiar contenido principal)
    function switchSection(sectionName) {
        // Check if we're on a page that has the section content (like index.php)
        const targetSection = document.getElementById('section-' + sectionName);

        // If the section doesn't exist, redirect to index.php with section parameter
        if (!targetSection) {
            // Determine the base URL relative to current location
            let baseUrl = './';
            const path = window.location.pathname;

            // If we're in a subdirectory (like modules/resaltar/), adjust the path
            if (path.includes('/modules/')) {
                // Count how many levels deep we are
                const parts = path.split('/modules/')[1];
                const depth = (parts.match(/\//g) || []).length;
                baseUrl = '../'.repeat(depth + 1);
            }

            window.location.href = baseUrl + 'index.php?section=' + sectionName;
            return;
        }

        // Actualizar botones activos
        document.querySelectorAll('.nav-section-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        const activeBtn = document.querySelector(`.nav-section-btn[data-section="${sectionName}"]`);
        if (activeBtn) {
            activeBtn.classList.add('active');
        }

        // Cambiar contenido de secciones
        document.querySelectorAll('.section-content').forEach(section => {
            section.classList.remove('active');
        });
        targetSection.classList.add('active');

        // Cargar documentos si es la sección "consultar"
        if (sectionName === 'consultar' && typeof loadDocuments === 'function') {
            loadDocuments();
        }

        // Cargar backup si es necesario
        if (sectionName === 'backup') {
            loadBackupSection();
        }

        // Cerrar sidebar en móvil después de seleccionar
        if (window.innerWidth <= 768) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }
    }

    // Cargar sección de Backup via AJAX
    function loadBackupSection() {
        const backupSection = document.getElementById('section-backup');
        if (!backupSection) return;

        // Evitar recargar si ya tiene contenido
        if (backupSection.dataset.loaded === 'true') return;

        backupSection.innerHTML = '<div class="loading"><div class="spinner"></div><p>Cargando backup...</p></div>';

        fetch('admin/backup.php?partial=1')
            .then(response => response.text())
            .then(html => {
                backupSection.innerHTML = html;
                backupSection.dataset.loaded = 'true';
            })
            .catch(err => {
                backupSection.innerHTML = `
                    <div class="backup-hero">
                        <h1>💾 Respaldo de Datos</h1>
                        <p>Descarga una copia completa de seguridad.</p>
                        <a href="admin/backup.php?download=1" class="btn btn-primary">
                            Descargar Backup
                        </a>
                    </div>
                `;
            });
    }

    // Handle window resize
    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }
    });
</script>