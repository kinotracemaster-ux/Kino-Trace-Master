<?php
/**
 * Módulo de Sincronización de Documentos con BD
 * 
 * Enlaza documentos PDF subidos por lote con los códigos
 * de la base de datos original de KINO por coincidencia de nombre.
 * 
 * Funcionalidad:
 * - Lee documentos de la BD actual
 * - Compara con datos del SQL original (documents + codes)
 * - Enlaza códigos por coincidencia de nombre/path
 * - Muestra reporte de sincronización
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// Para sidebar
$currentModule = 'sincronizar';
$baseUrl = '../../';
$pageTitle = 'Sincronizar BD';

$results = [];
$error = '';
$success = '';
$stats = ['matched' => 0, 'codes_linked' => 0, 'not_found' => 0];

// Cargar datos del SQL original de KINO
function loadKinoData()
{
    $sqlFile = BASE_DIR . '/if0_39064130_buscador (10).sql';

    if (!file_exists($sqlFile)) {
        return ['error' => 'Archivo SQL de KINO no encontrado'];
    }

    $sqlContent = file_get_contents($sqlFile);

    // Extraer documentos: (id, 'name', 'date', 'path', NULL)
    $documents = [];
    preg_match_all('/\((\d+),\s*\'([^\']*)\',\s*\'([^\']*)\',\s*\'([^\']*)\',\s*NULL\)/', $sqlContent, $docMatches, PREG_SET_ORDER);

    foreach ($docMatches as $match) {
        $documents[$match[1]] = [
            'id' => $match[1],
            'name' => $match[2],
            'date' => $match[3],
            'path' => $match[4]
        ];
    }

    // Extraer códigos: (id, document_id, 'code')
    $codes = [];
    preg_match_all('/\((\d+),\s*(\d+),\s*\'([^\']*)\'\)/', $sqlContent, $codeMatches, PREG_SET_ORDER);

    foreach ($codeMatches as $match) {
        $docId = $match[2];
        if (!isset($codes[$docId])) {
            $codes[$docId] = [];
        }
        $codes[$docId][] = $match[3];
    }

    return [
        'documents' => $documents,
        'codes' => $codes,
        'total_docs' => count($documents),
        'total_codes' => count($codeMatches)
    ];
}

// Función para normalizar nombres para comparación
function normalizeName($name)
{
    // Remover timestamp al inicio (ej: 1748100868_)
    $name = preg_replace('/^\d+_/', '', $name);
    // Remover extensión .pdf
    $name = preg_replace('/\.pdf$/i', '', $name);
    // Convertir a minúsculas y remover espacios extra
    $name = strtolower(trim($name));
    // Remover caracteres especiales excepto letras, números, espacios y guiones
    $name = preg_replace('/[^a-z0-9\s-]/', '', $name);
    // Normalizar espacios
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

// Función para buscar coincidencia - MEJORADA
function findMatch($docName, $docPath, $kinoDocuments)
{
    // Primero intentar coincidencia exacta por path (con o sin .pdf)
    $docNameClean = preg_replace('/\.pdf$/i', '', trim($docName));

    foreach ($kinoDocuments as $kinoDoc) {
        $kinoPathClean = preg_replace('/\.pdf$/i', '', trim($kinoDoc['path']));

        // Coincidencia exacta por path completo
        if (strcasecmp($docNameClean, $kinoPathClean) === 0) {
            return $kinoDoc;
        }

        // Coincidencia exacta por path (local como numero)
        if (strcasecmp($docName, $kinoDoc['path']) === 0) {
            return $kinoDoc;
        }

        // Coincidencia sin extensión
        if (strcasecmp($docNameClean . '.pdf', $kinoDoc['path']) === 0) {
            return $kinoDoc;
        }
    }

    // Si no hay coincidencia exacta, buscar por nombre normalizado
    $normalizedName = normalizeName($docName);
    $normalizedPath = normalizeName($docPath);

    foreach ($kinoDocuments as $kinoDoc) {
        $kinoName = normalizeName($kinoDoc['name']);
        $kinoPath = normalizeName($kinoDoc['path']);

        // Coincidencia exacta por nombre normalizado
        if ($normalizedName === $kinoName) {
            return $kinoDoc;
        }

        // Coincidencia por path normalizado
        if (!empty($normalizedPath) && $normalizedPath === $kinoPath) {
            return $kinoDoc;
        }

        // Coincidencia: el nombre local contiene el path de KINO normalizado
        if (!empty($kinoPath) && strpos($normalizedName, $kinoPath) !== false) {
            return $kinoDoc;
        }

        // Coincidencia: el path de KINO contiene el nombre local normalizado
        if (!empty($normalizedName) && strlen($normalizedName) > 5 && strpos($kinoPath, $normalizedName) !== false) {
            return $kinoDoc;
        }

        // Coincidencia parcial por nombre KINO
        if (
            strlen($kinoName) > 5 &&
            (strpos($normalizedName, $kinoName) !== false ||
                strpos($kinoName, $normalizedName) !== false)
        ) {
            return $kinoDoc;
        }
    }

    return null;
}

// Procesar sincronización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'preview') {
        // Cargar datos de KINO
        $kinoData = loadKinoData();

        if (isset($kinoData['error'])) {
            $error = $kinoData['error'];
        } else {
            // Obtener documentos actuales de la BD del cliente
            $stmt = $db->query("SELECT id, tipo, numero, fecha, ruta_archivo FROM documentos ORDER BY id");
            $localDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($localDocs as $doc) {
                $match = findMatch($doc['numero'], $doc['ruta_archivo'] ?? '', $kinoData['documents']);

                if ($match) {
                    $codesCount = count($kinoData['codes'][$match['id']] ?? []);
                    $results[] = [
                        'local_id' => $doc['id'],
                        'local_name' => $doc['numero'],
                        'kino_id' => $match['id'],
                        'kino_name' => $match['name'],
                        'codes_count' => $codesCount,
                        'codes' => array_slice($kinoData['codes'][$match['id']] ?? [], 0, 10), // Previsualizamos 10
                        'status' => 'matched'
                    ];
                    $stats['matched']++;
                    $stats['codes_linked'] += $codesCount;
                } else {
                    $results[] = [
                        'local_id' => $doc['id'],
                        'local_name' => $doc['numero'],
                        'kino_id' => null,
                        'kino_name' => null,
                        'codes_count' => 0,
                        'codes' => [],
                        'status' => 'not_found'
                    ];
                    $stats['not_found']++;
                }
            }

            // Guardar en sesión para la sincronización
            $_SESSION['sync_results'] = $results;
            $_SESSION['sync_kino_data'] = $kinoData;
        }
    }

    if ($_POST['action'] === 'sync') {
        if (!isset($_SESSION['sync_results']) || !isset($_SESSION['sync_kino_data'])) {
            $error = 'Primero debe previsualizar los datos';
        } else {
            $results = $_SESSION['sync_results'];
            $kinoData = $_SESSION['sync_kino_data'];

            $db->beginTransaction();

            try {
                // Preparar statement para insertar códigos
                $stmtCode = $db->prepare("INSERT OR IGNORE INTO codigos (documento_id, codigo) VALUES (?, ?)");

                // También actualizar ruta_archivo
                $stmtUpdatePath = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE id = ?");

                // Obtener info del documento local para buscar el PDF real
                $stmtGetDoc = $db->prepare("SELECT tipo, numero, ruta_archivo FROM documentos WHERE id = ?");

                $syncedCodes = 0;
                $syncedDocs = 0;
                $pdfFixed = 0;

                // Directorio base de uploads
                $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

                foreach ($results as $result) {
                    if ($result['status'] === 'matched' && $result['kino_id']) {
                        $kinoDocId = $result['kino_id'];
                        $localDocId = $result['local_id'];

                        // Obtener todos los códigos de este documento de KINO
                        $codes = $kinoData['codes'][$kinoDocId] ?? [];

                        foreach ($codes as $code) {
                            $stmtCode->execute([$localDocId, $code]);
                            $syncedCodes++;
                        }

                        // Buscar y corregir la ruta del PDF
                        $stmtGetDoc->execute([$localDocId]);
                        $localDoc = $stmtGetDoc->fetch(PDO::FETCH_ASSOC);

                        if ($localDoc) {
                            $currentPath = $localDoc['ruta_archivo'] ?? '';
                            $tipo = $localDoc['tipo'] ?? 'documento';
                            $numero = $localDoc['numero'] ?? '';

                            // Si la ruta actual no incluye el tipo, buscar el archivo
                            $pdfPath = null;

                            // Buscar el PDF en varias ubicaciones posibles
                            $searchPaths = [
                                // Ruta actual si ya existe
                                $uploadsDir . $currentPath,
                                $uploadsDir . $tipo . '/' . $currentPath,
                                $uploadsDir . $tipo . '/' . basename($currentPath),
                            ];

                            // Buscar por nombre del documento
                            if (!empty($numero)) {
                                // Buscar archivos que contengan el nombre
                                $typeDir = $uploadsDir . $tipo . '/';
                                if (is_dir($typeDir)) {
                                    $files = scandir($typeDir);
                                    foreach ($files as $file) {
                                        if ($file === '.' || $file === '..')
                                            continue;
                                        // Verificar si el nombre del archivo contiene el número del documento
                                        $fileBasename = pathinfo($file, PATHINFO_FILENAME);
                                        if (
                                            stripos($fileBasename, preg_replace('/[^a-zA-Z0-9]/', '', $numero)) !== false ||
                                            stripos($numero, preg_replace('/[^a-zA-Z0-9]/', '', $fileBasename)) !== false
                                        ) {
                                            $searchPaths[] = $typeDir . $file;
                                        }
                                    }
                                }
                            }

                            // Encontrar el primer archivo que exista
                            foreach ($searchPaths as $path) {
                                if (file_exists($path) && is_file($path)) {
                                    // Construir ruta relativa correcta: tipo/archivo.pdf
                                    $pdfPath = $tipo . '/' . basename($path);
                                    break;
                                }
                            }

                            // Si encontramos el PDF, actualizar la ruta
                            if ($pdfPath && $pdfPath !== $currentPath) {
                                $stmtUpdatePath->execute([$pdfPath, $localDocId]);
                                $pdfFixed++;
                            } elseif (empty($currentPath) && !empty($kinoData['documents'][$kinoDocId]['path'])) {
                                // Si no hay ruta y tenemos el path de KINO, usar ese
                                $kinoPath = $tipo . '/' . $kinoData['documents'][$kinoDocId]['path'];
                                $stmtUpdatePath->execute([$kinoPath, $localDocId]);
                            }
                        }

                        $syncedDocs++;
                    }
                }

                $db->commit();
                $success = "✅ Sincronización completada: $syncedDocs documentos procesados, $syncedCodes códigos enlazados";
                if ($pdfFixed > 0) {
                    $success .= ", $pdfFixed rutas de PDF corregidas";
                }

                // Limpiar sesión
                unset($_SESSION['sync_results'], $_SESSION['sync_kino_data']);

            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Error en sincronización: ' . $e->getMessage();
            }
        }
    }

    // Acción para limpiar códigos duplicados
    if ($_POST['action'] === 'clean_duplicates') {
        try {
            // Contar duplicados antes de limpiar
            $countBefore = $db->query("SELECT COUNT(*) FROM codigos")->fetchColumn();

            // Eliminar duplicados manteniendo solo el primero (ID más bajo)
            $db->exec("
                DELETE FROM codigos 
                WHERE rowid NOT IN (
                    SELECT MIN(rowid) 
                    FROM codigos 
                    GROUP BY documento_id, codigo
                )
            ");

            $countAfter = $db->query("SELECT COUNT(*) FROM codigos")->fetchColumn();
            $deleted = $countBefore - $countAfter;

            if ($deleted > 0) {
                $success = "🧹 ¡Limpieza completada! Se eliminaron $deleted códigos duplicados. (De $countBefore a $countAfter)";
            } else {
                $success = "✅ No se encontraron códigos duplicados. La base de datos está limpia.";
            }
        } catch (Exception $e) {
            $error = 'Error al limpiar duplicados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizar BD - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .sync-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .sync-card h3 {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-box {
            background: var(--bg-secondary);
            padding: 1rem;
            border-radius: var(--radius-md);
            text-align: center;
        }

        .stat-box .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-box .label {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .stat-box.success .number {
            color: var(--accent-success);
        }

        .stat-box.warning .number {
            color: var(--accent-warning);
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .results-table th,
        .results-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        .results-table th {
            background: var(--bg-secondary);
            font-weight: 600;
        }

        .results-table tr:hover {
            background: var(--bg-secondary);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-matched {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
        }

        .status-not-found {
            background: rgba(239, 68, 68, 0.1);
            color: var(--accent-danger);
        }

        .codes-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            max-width: 300px;
        }

        .code-tag {
            background: var(--bg-secondary);
            padding: 0.125rem 0.375rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-family: monospace;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--accent-danger);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .info-box p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .scroll-table {
            max-height: 500px;
            overflow-y: auto;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <h1 style="margin-bottom: 1.5rem;">🔗 Sincronizar con BD KINO</h1>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <div class="info-box">
                    <p><strong>📋 ¿Qué hace este módulo?</strong></p>
                    <p>Enlaza los documentos subidos por lote con los códigos de la base de datos original de KINO.
                        Busca coincidencias por nombre del documento y vincula automáticamente todos los códigos
                        asociados.</p>
                </div>

                <?php if (empty($results)): ?>
                    <!-- Paso 1: Previsualizar -->
                    <div class="sync-card">
                        <h3>📊 Paso 1: Previsualizar Coincidencias</h3>
                        <p style="color: var(--text-muted); margin-bottom: 1rem;">
                            Analizaremos los documentos actuales y buscaremos coincidencias con la BD de KINO.
                        </p>

                        <form method="POST" style="display: inline-block;">
                            <input type="hidden" name="action" value="preview">
                            <button type="submit" class="btn btn-primary">
                                🔍 Analizar Coincidencias
                            </button>
                        </form>

                        <form method="POST" style="display: inline-block; margin-left: 1rem;"
                            data-confirm="Esta acción eliminará todos los códigos duplicados de la base de datos."
                            data-confirm-title="🧹 Limpiar Duplicados" data-confirm-button="Sí, limpiar"
                            data-confirm-icon="warning">
                            <input type="hidden" name="action" value="clean_duplicates">
                            <button type="submit" class="btn btn-secondary">
                                🧹 Limpiar Duplicados
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Resultados del análisis -->
                    <div class="stats-grid">
                        <div class="stat-box success">
                            <div class="number">
                                <?= $stats['matched'] ?>
                            </div>
                            <div class="label">Documentos encontrados</div>
                        </div>
                        <div class="stat-box success">
                            <div class="number">
                                <?= $stats['codes_linked'] ?>
                            </div>
                            <div class="label">Códigos a enlazar</div>
                        </div>
                        <div class="stat-box warning">
                            <div class="number">
                                <?= $stats['not_found'] ?>
                            </div>
                            <div class="label">Sin coincidencia</div>
                        </div>
                    </div>

                    <div class="sync-card">
                        <h3>📋 Resultados del Análisis</h3>

                        <div class="scroll-table">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>ID Local</th>
                                        <th>Documento Local</th>
                                        <th>Estado</th>
                                        <th>Match KINO</th>
                                        <th>Códigos</th>
                                        <th>Preview Códigos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                        <tr>
                                            <td>
                                                <?= $result['local_id'] ?>
                                            </td>
                                            <td title="<?= htmlspecialchars($result['local_name']) ?>">
                                                <?= htmlspecialchars(substr($result['local_name'], 0, 40)) ?>
                                                <?= strlen($result['local_name']) > 40 ? '...' : '' ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= $result['status'] ?>">
                                                    <?= $result['status'] === 'matched' ? '✓ Encontrado' : '✗ No encontrado' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($result['kino_name']): ?>
                                                    <?= htmlspecialchars(substr($result['kino_name'], 0, 30)) ?>
                                                    <?= strlen($result['kino_name']) > 30 ? '...' : '' ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong>
                                                    <?= $result['codes_count'] ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <div class="codes-preview">
                                                    <?php foreach ($result['codes'] as $code): ?>
                                                        <span class="code-tag">
                                                            <?= htmlspecialchars($code) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if ($result['codes_count'] > 10): ?>
                                                        <span class="code-tag">+
                                                            <?= $result['codes_count'] - 10 ?> más
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($stats['matched'] > 0): ?>
                            <div class="btn-group">
                                <form method="POST" style="display: inline;"
                                    data-confirm="Se sincronizarán <?= $stats['matched'] ?> documentos con <?= $stats['codes_linked'] ?> códigos."
                                    data-confirm-title="✅ Sincronizar Base de Datos" data-confirm-button="Sincronizar Ahora"
                                    data-confirm-icon="question">
                                    <input type="hidden" name="action" value="sync">
                                    <button type="submit" class="btn btn-primary">
                                        ✅ Sincronizar Ahora
                                    </button>
                                </form>
                                <a href="index.php" class="btn btn-secondary">
                                    🔄 Reiniciar
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-error" style="margin-top: 1rem;">
                                No se encontraron coincidencias. Verifique que los nombres de los documentos coincidan
                                con la BD de KINO.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Instrucciones -->
                <div class="sync-card">
                    <h3>💡 Instrucciones</h3>
                    <ol style="margin: 0; padding-left: 1.25rem; color: var(--text-secondary);">
                        <li>Primero suba los documentos PDF usando "Subida por Lote"</li>
                        <li>El nombre del PDF debe coincidir con el nombre en la BD de KINO</li>
                        <li>Use este módulo para analizar y sincronizar los códigos</li>
                        <li>Los códigos se enlazarán automáticamente al documento correcto</li>
                        <li>Después podrá buscar cualquier código y ver su documento asociado</li>
                    </ol>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>
</body>

</html>