<?php
/**
 * Backup System - KINO TRACE
 * 
 * Creates a downloadable backup of client data including:
 * - SQLite database file
 * - All uploaded documents (PDFs)
 * 
 * Access: Client-only (logged in users)
 */

// 1. ROMPER LÍMITES DE TIEMPO Y MEMORIA
set_time_limit(0);              // Evita el error "Maximum execution time exceeded"
ini_set('memory_limit', '1024M'); // Aumenta RAM a 1GB para procesar ZIPs grandes
ini_set('max_execution_time', 0); // Refuerzo para servidores estrictos

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];

/**
 * Create a backup ZIP file containing:
 * - The client's SQLite database
 * - All files in the uploads directory
 * 
 * @param string $clientCode Client identifier
 * @return array Result with success status and file path or error
 */
function createBackup(string $clientCode): array
{
    $clientDir = CLIENTS_DIR . '/' . $clientCode;
    $dbPath = $clientDir . '/data.db';
    $uploadsDir = $clientDir . '/uploads';

    // Verify client directory exists
    if (!is_dir($clientDir)) {
        return ['success' => false, 'error' => 'Directorio del cliente no encontrado'];
    }

    // Create temp directory for backup if needed
    $tempDir = sys_get_temp_dir();
    $timestamp = date('Y-m-d_H-i-s');
    $backupName = "backup_{$clientCode}_{$timestamp}.zip";
    $zipPath = $tempDir . '/' . $backupName;

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'error' => 'No se pudo crear el archivo ZIP'];
    }

    $filesAdded = 0;

    // Add database file if exists
    if (file_exists($dbPath)) {
        $zip->addFile($dbPath, 'data.db');
        $filesAdded++;
    }

    // Add all files from uploads directory
    if (is_dir($uploadsDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = 'uploads/' . substr($item->getPathname(), strlen($uploadsDir) + 1);

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getPathname(), $relativePath);
                $filesAdded++;
            }
        }
    }

    $zip->close();

    if ($filesAdded === 0) {
        unlink($zipPath);
        return ['success' => false, 'error' => 'No hay archivos para respaldar'];
    }

    return [
        'success' => true,
        'path' => $zipPath,
        'filename' => $backupName,
        'files_count' => $filesAdded,
        'size' => filesize($zipPath)
    ];
}

/**
 * Format bytes to human readable format
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

// Handle download request
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $result = createBackup($clientCode);

    if ($result['success']) {
        // Send file to browser
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Length: ' . $result['size']);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($result['path']);

        // Clean up temp file
        unlink($result['path']);
        exit;
    } else {
        $error = $result['error'];
    }
}

// Get client stats for display
$db = open_client_db($clientCode);
$stats = [
    'documents' => (int) $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn(),
    'codes' => (int) $db->query('SELECT COUNT(*) FROM codigos')->fetchColumn(),
    'links' => (int) $db->query('SELECT COUNT(*) FROM vinculos')->fetchColumn()
];

// Calculate uploads size
$uploadsDir = CLIENTS_DIR . '/' . $clientCode . '/uploads';
$uploadsSize = 0;
$fileCount = 0;
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $uploadsSize += $file->getSize();
            $fileCount++;
        }
    }
}

// Partial load for AJAX (from sidebar)
if (isset($_GET['partial']) && $_GET['partial'] === '1') {
    // Return only the content without layout
    ?>
    <div class="backup-hero">
        <div class="backup-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
        </div>
        <h1>💾 Respaldo de Datos</h1>
        <p>Descarga una copia completa de seguridad de todos tus documentos y base de datos.</p>

        <div class="backup-stats">
            <div class="backup-stat">
                <div class="value"><?= number_format($stats['documents']) ?></div>
                <div class="label">Documentos</div>
            </div>
            <div class="backup-stat">
                <div class="value"><?= number_format($stats['codes']) ?></div>
                <div class="label">Códigos</div>
            </div>
            <div class="backup-stat">
                <div class="value"><?= number_format($fileCount) ?></div>
                <div class="label">Archivos PDF</div>
            </div>
            <div class="backup-stat">
                <div class="value"><?= formatBytes($uploadsSize) ?></div>
                <div class="label">Tamaño Total</div>
            </div>
        </div>

        <a href="admin/backup.php?download=1" class="backup-btn">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar Backup Completo
        </a>
    </div>

    <div class="backup-info">
        <h3>📋 ¿Qué incluye el backup?</h3>
        <ul>
            <li>Base de datos SQLite completa (documentos, códigos, vínculos)</li>
            <li>Todos los archivos PDF subidos</li>
            <li>Estructura de carpetas preservada</li>
            <li>Archivo ZIP listo para restaurar</li>
        </ul>
    </div>

    <div class="backup-info" style="margin-top: 1rem;">
        <h3>💡 Recomendaciones</h3>
        <ul>
            <li>Realiza backups periódicamente (semanal o mensual)</li>
            <li>Guarda el archivo en un lugar seguro externo</li>
            <li>Verifica que el ZIP descargue correctamente</li>
            <li>El nombre del archivo incluye la fecha del backup</li>
        </ul>
    </div>

    <style>
        .backup-hero {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
        }

        .backup-icon {
            width: 70px;
            height: 70px;
            background: var(--accent-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
        }

        .backup-icon svg {
            width: 35px;
            height: 35px;
        }

        .backup-hero h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .backup-hero p {
            color: var(--text-secondary);
            max-width: 450px;
            margin: 0 auto 1.5rem;
        }

        .backup-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .backup-stat {
            background: var(--bg-secondary);
            padding: 1rem;
            border-radius: var(--radius-md);
            text-align: center;
        }

        .backup-stat .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .backup-stat .label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .backup-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .backup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
            color: white;
        }

        .backup-btn svg {
            width: 20px;
            height: 20px;
        }

        .backup-info {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
        }

        .backup-info h3 {
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .backup-info ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .backup-info li {
            padding: 0.4rem 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .backup-info li::before {
            content: '✓';
            color: var(--accent-success);
            font-weight: bold;
            margin-right: 0.5rem;
        }
    </style>
    <?php
    exit;
}

// For sidebar (full page load)
$currentSection = 'backup';
$baseUrl = '../';
$pageTitle = 'Respaldo de Datos';

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respaldo de Datos - KINO TRACE</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .backup-hero {
            text-align: center;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
        }

        .backup-icon {
            width: 80px;
            height: 80px;
            background: var(--accent-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
        }

        .backup-icon svg {
            width: 40px;
            height: 40px;
        }

        .backup-hero h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .backup-hero p {
            color: var(--text-secondary);
            max-width: 500px;
            margin: 0 auto 1.5rem;
        }

        .backup-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }

        .backup-stat {
            background: var(--bg-secondary);
            padding: 1.25rem;
            border-radius: var(--radius-md);
            text-align: center;
        }

        .backup-stat .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .backup-stat .label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .backup-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .backup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }

        .backup-btn svg {
            width: 24px;
            height: 24px;
        }

        .backup-info {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        .backup-info h3 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .backup-info ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .backup-info li {
            padding: 0.5rem 0;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .backup-info li::before {
            content: '✓';
            color: var(--accent-success);
            font-weight: bold;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--accent-danger);
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../includes/header.php'; ?>

            <div class="page-content">
                <?php if (isset($error)): ?>
                    <div class="alert-error">
                        ⚠️
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="backup-hero">
                    <div class="backup-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                    </div>
                    <h1>💾 Respaldo de Datos</h1>
                    <p>Descarga una copia completa de seguridad de todos tus documentos y base de datos.</p>

                    <div class="backup-stats">
                        <div class="backup-stat">
                            <div class="value">
                                <?= number_format($stats['documents']) ?>
                            </div>
                            <div class="label">Documentos</div>
                        </div>
                        <div class="backup-stat">
                            <div class="value">
                                <?= number_format($stats['codes']) ?>
                            </div>
                            <div class="label">Códigos</div>
                        </div>
                        <div class="backup-stat">
                            <div class="value">
                                <?= number_format($fileCount) ?>
                            </div>
                            <div class="label">Archivos PDF</div>
                        </div>
                        <div class="backup-stat">
                            <div class="value">
                                <?= formatBytes($uploadsSize) ?>
                            </div>
                            <div class="label">Tamaño Total</div>
                        </div>
                    </div>

                    <a href="?download=1" class="backup-btn"
                        onclick="this.innerHTML='<svg class=\'spinner\' width=\'24\' height=\'24\' viewBox=\'0 0 24 24\'><circle cx=\'12\' cy=\'12\' r=\'10\' stroke=\'currentColor\' stroke-width=\'3\' fill=\'none\' opacity=\'0.3\'/><path d=\'M12 2a10 10 0 0 1 10 10\' stroke=\'currentColor\' stroke-width=\'3\' fill=\'none\'/></svg> Preparando backup...'; return true;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Descargar Backup Completo
                    </a>
                </div>

                <div class="backup-info">
                    <h3>📋 ¿Qué incluye el backup?</h3>
                    <ul>
                        <li>Base de datos SQLite completa (documentos, códigos, vínculos)</li>
                        <li>Todos los archivos PDF subidos</li>
                        <li>Estructura de carpetas preservada</li>
                        <li>Archivo ZIP listo para restaurar</li>
                    </ul>
                </div>

                <div class="backup-info" style="margin-top: 1rem;">
                    <h3>💡 Recomendaciones</h3>
                    <ul>
                        <li>Realiza backups periódicamente (semanal o mensual)</li>
                        <li>Guarda el archivo en un lugar seguro externo</li>
                        <li>Verifica que el ZIP descargue correctamente</li>
                        <li>El nombre del archivo incluye la fecha del backup</li>
                    </ul>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>
</body>

</html>