<?php
/**
 * Backup System - KINO TRACE
 * 
 * Creates a downloadable backup of client data including:
 * - SQLite database file
 * - All uploaded documents (PDFs)
 *
 * El ZIP se manda MIENTRAS se arma (helpers/zip_stream.php): antes se comprimía todo en un
 * archivo temporal y recién después empezaba la descarga; con muchos PDF eso superaba el
 * límite de 15 minutos por petición de Railway y la descarga nunca llegaba. Si el respaldo
 * es grande se baja en partes, cada una con tiempo de sobra.
 * 
 * Access: Client-only (logged in users)
 */

set_time_limit(0); // la descarga puede tardar varios minutos

require_once __DIR__ . '/../helpers/session_init.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';
require_once __DIR__ . '/../helpers/zip_stream.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];

// Tamaño máximo de cada parte: a 10 Mbps (1,25 MB/s) baja en unos 9 minutos
const KT_BACKUP_PARTE_BYTES = 700 * 1024 * 1024;

/**
 * Archivos de uploads/, ordenados por su ruta dentro del ZIP.
 *
 * @return array<int, array{ruta: string, nombre: string, tamano: int}>
 */
function kt_backup_lista(string $uploadsDir): array
{
    $lista = [];
    if (is_dir($uploadsDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $lista[] = [
                    'ruta' => $f->getPathname(),
                    'nombre' => 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($uploadsDir) + 1)),
                    'tamano' => (int) $f->getSize(),
                ];
            }
        }
    }
    usort($lista, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));
    return $lista;
}

/**
 * Divide la lista en partes por rango de nombres [desde, hasta): un archivo subido entre que
 * se abre la página y se baja una parte cae en una sola parte (ni se repite ni se pierde).
 * La parte 1 lleva además la base de datos.
 */
function kt_backup_partes(array $lista, int $maxBytes): array
{
    $partes = [];
    $actual = null;
    foreach ($lista as $e) {
        if ($actual !== null && ($actual['tamano'] + $e['tamano'] > $maxBytes || $actual['archivos'] >= 60000)) {
            $actual['hasta'] = $e['nombre'];
            $partes[] = $actual;
            $actual = null;
        }
        $actual = $actual ?? ['desde' => $partes ? $e['nombre'] : '', 'hasta' => '', 'tamano' => 0, 'archivos' => 0];
        $actual['tamano'] += $e['tamano'];
        $actual['archivos']++;
    }
    $partes[] = $actual ?? ['desde' => '', 'hasta' => '', 'tamano' => 0, 'archivos' => 0];
    return $partes;
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

$uploadsDir = CLIENTS_DIR . '/' . $clientCode . '/uploads';

// Handle download request
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $soloBd = ($_GET['solo_bd'] ?? '') === '1';
    $desde = (string) ($_GET['desde'] ?? '');
    $hasta = (string) ($_GET['hasta'] ?? '');
    $parte = max(0, (int) ($_GET['parte'] ?? 0));
    $de = max(0, (int) ($_GET['de'] ?? 0));

    $entradas = [];
    if (!$soloBd) {
        foreach (kt_backup_lista($uploadsDir) as $e) {
            if (strcmp($e['nombre'], $desde) >= 0 && ($hasta === '' || strcmp($e['nombre'], $hasta) < 0)) {
                $entradas[] = $e;
            }
        }
    }

    // Base de datos (copia consistente: incluye lo pendiente en el WAL) en la parte 1
    $dbPath = client_db_path($clientCode);
    if (($soloBd || $desde === '') && file_exists($dbPath)) {
        $dbCopy = sys_get_temp_dir() . '/kt_backup_' . bin2hex(random_bytes(6)) . '.db';
        register_shutdown_function(function () use ($dbCopy) {
            @unlink($dbCopy); // también si el navegador corta la descarga
        });
        $src = new PDO('sqlite:' . $dbPath);
        $src->exec('VACUUM INTO ' . $src->quote($dbCopy));
        $src = null;
        clearstatcache(true, $dbCopy);
        array_unshift($entradas, ['ruta' => $dbCopy, 'nombre' => $clientCode . '.db', 'tamano' => (int) filesize($dbCopy)]);
    }

    if (!$entradas) {
        $error = 'No hay archivos para respaldar';
    } elseif (count($entradas) > KT_ZIP_MAX_ARCHIVOS || kt_zip_tamano($entradas) > KT_ZIP_MAX_BYTES) {
        $error = 'Esta parte quedó demasiado grande (se subieron archivos mientras tanto). Recargue la página y vuelva a descargar.';
    } else {
        $sufijo = $soloBd ? '_base_de_datos' : ($de > 1 ? "_parte{$parte}de{$de}" : '');
        $filename = "backup_{$clientCode}_" . date('Y-m-d_H-i') . $sufijo . '.zip';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . kt_zip_tamano($entradas));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        kt_zip_enviar($entradas);
        exit;
    }
}

/**
 * Botones de descarga: uno si el respaldo cabe en una parte; si no, una por parte.
 * $base: URL de este archivo vista desde la página donde se muestran.
 */
function kt_backup_botones(array $partes, int $dbBytes, string $base): string
{
    $h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $icono = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>';
    $n = count($partes);
    if ($n === 1) {
        $html = '<a href="' . $h($base . '?download=1') . '" class="backup-btn">' . $icono
            . ' Descargar Backup Completo (' . formatBytes($partes[0]['tamano'] + $dbBytes) . ')</a>';
    } else {
        $total = array_sum(array_column($partes, 'tamano')) + $dbBytes;
        $html = '<p class="backup-partes-nota">Su respaldo pesa <strong>' . formatBytes($total) . '</strong>. Para que la descarga no se corte, '
            . "se baja en <strong>{$n} partes</strong>. Baje todas y descomprímalas en la misma carpeta.</p>"
            . '<div class="backup-partes">';
        foreach ($partes as $i => $p) {
            $url = $base . '?' . http_build_query(['download' => 1, 'parte' => $i + 1, 'de' => $n, 'desde' => $p['desde'], 'hasta' => $p['hasta']]);
            $html .= '<a href="' . $h($url) . '" class="backup-btn">' . $icono . ' Parte ' . ($i + 1) . " de {$n} ("
                . formatBytes($p['tamano'] + ($i === 0 ? $dbBytes : 0)) . ($i === 0 ? ', con la base de datos' : '') . ')</a>';
        }
        $html .= '</div>';
    }
    return $html . '<div class="backup-solo-bd"><a href="' . $h($base . '?download=1&solo_bd=1') . '">Descargar solo la base de datos ('
        . formatBytes($dbBytes) . ')</a></div>';
}

// Get client stats for display
$db = open_client_db($clientCode);
$stats = [
    'documents' => (int) $db->query('SELECT COUNT(*) FROM documentos')->fetchColumn(),
    'codes' => (int) $db->query('SELECT COUNT(*) FROM codigos')->fetchColumn(),
    'links' => (int) $db->query('SELECT COUNT(*) FROM vinculos')->fetchColumn()
];

// Calculate uploads size
$lista = kt_backup_lista($uploadsDir);
$uploadsSize = array_sum(array_column($lista, 'tamano'));
$fileCount = count($lista);
$partes = kt_backup_partes($lista, KT_BACKUP_PARTE_BYTES);
$dbBytes = (int) @filesize(client_db_path($clientCode)) + (int) @filesize(client_db_path($clientCode) . '-wal');

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

        <?= kt_backup_botones($partes, $dbBytes, 'Admin-gestor/backup.php') ?>
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
            <li>La descarga empieza enseguida y el navegador muestra cuánto falta</li>
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

        .backup-partes {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
        }

        .backup-partes-nota {
            margin-bottom: 1rem !important;
        }

        .backup-solo-bd {
            margin-top: 0.9rem;
            font-size: 0.85rem;
        }

        .backup-solo-bd a {
            color: var(--text-secondary);
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

        .backup-partes {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
        }

        .backup-partes-nota {
            margin-bottom: 1rem !important;
        }

        .backup-solo-bd {
            margin-top: 0.9rem;
            font-size: 0.85rem;
        }

        .backup-solo-bd a {
            color: var(--text-secondary);
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

                    <?= kt_backup_botones($partes, $dbBytes, 'backup.php') ?>
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
                        <li>La descarga empieza enseguida y el navegador muestra cuánto falta</li>
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