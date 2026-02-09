<?php
/**
 * Batch Upload Module - Upload ZIP files with multiple PDFs
 * 
 * Features:
 * - Upload ZIP file with multiple PDFs
 * - Extract and process each PDF
 * - Optional code extraction
 * - Progress tracking
 * - Summary of results
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// For sidebar
$currentModule = 'subir';
$baseUrl = '../../';
$pageTitle = 'Subida Masiva';

$results = [];
$error = '';
$success = '';

// Handle ZIP upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
    $zipFile = $_FILES['zip_file'];
    $docType = $_POST['doc_type'] ?? 'documento';
    $extractCodes = isset($_POST['extract_codes']);

    if ($zipFile['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo ZIP.';
    } elseif (pathinfo($zipFile['name'], PATHINFO_EXTENSION) !== 'zip') {
        $error = 'El archivo debe ser un ZIP.';
    } else {
        $zip = new ZipArchive();
        if ($zip->open($zipFile['tmp_name']) === TRUE) {
            $uploadDir = CLIENTS_DIR . "/{$clientCode}/uploads/{$docType}/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $processed = 0;
            $errors = 0;
            $codesExtracted = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Only process PDF files
                if ($ext !== 'pdf') {
                    continue;
                }

                // Extract file
                $basename = basename($filename);
                $newFilename = time() . '_' . $processed . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
                $destPath = $uploadDir . $newFilename;

                // Copy from zip to destination
                $content = $zip->getFromIndex($i);
                if ($content !== false && file_put_contents($destPath, $content)) {
                    // Get document name from filename
                    $docName = pathinfo($basename, PATHINFO_FILENAME);
                    $docDate = date('Y-m-d');

                    // Insert document record
                    try {
                        $stmt = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$docType, $docName, $docDate, $newFilename]);
                        $docId = $db->lastInsertId();

                        // Extract codes if requested
                        if ($extractCodes && function_exists('extract_codes_from_pdf')) {
                            try {
                                $codes = extract_codes_from_pdf($destPath);
                                if (!empty($codes)) {
                                    $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
                                    foreach ($codes as $code) {
                                        $stmtCode->execute([$docId, $code]);
                                        $codesExtracted++;
                                    }
                                }
                            } catch (Exception $e) {
                                // Ignore extraction errors
                            }
                        }

                        $results[] = [
                            'file' => $basename,
                            'status' => 'success',
                            'id' => $docId
                        ];
                        $processed++;
                    } catch (Exception $e) {
                        $results[] = [
                            'file' => $basename,
                            'status' => 'error',
                            'message' => $e->getMessage()
                        ];
                        $errors++;
                    }
                } else {
                    $results[] = [
                        'file' => $basename,
                        'status' => 'error',
                        'message' => 'No se pudo extraer'
                    ];
                    $errors++;
                }
            }

            $zip->close();

            if ($processed > 0) {
                $success = "✅ Se procesaron {$processed} documentos correctamente.";
                if ($codesExtracted > 0) {
                    $success .= " Se extrajeron {$codesExtracted} códigos.";
                }
                if ($errors > 0) {
                    $success .= " ({$errors} errores)";
                }
            } else {
                $error = 'No se encontraron archivos PDF en el ZIP.';
            }
        } else {
            $error = 'No se pudo abrir el archivo ZIP.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subida Masiva - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .upload-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 3rem 2rem;
            text-align: center;
            background: var(--bg-primary);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--accent-primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .upload-zone svg {
            width: 64px;
            height: 64px;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .upload-zone h3 {
            margin-bottom: 0.5rem;
        }

        .upload-zone p {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .upload-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .option-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
        }

        .results-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .result-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .result-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
        }

        .result-icon.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--accent-danger);
        }

        .result-name {
            flex: 1;
            font-size: 0.875rem;
            word-break: break-all;
        }

        .file-selected {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--accent-primary);
        }

        .file-info {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
        }

        .progress-bar {
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 1rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent-primary);
            transition: width 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <?php if ($error): ?>
                    <div class="alert alert-error"
                        style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); color: var(--accent-danger); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1rem;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"
                        style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); color: var(--accent-success); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1rem;">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📦 Subida Masiva de Documentos</h3>
                    </div>

                    <form method="post" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-zone" id="dropZone" onclick="document.getElementById('zipInput').click()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <h3>Arrastra tu archivo ZIP aquí</h3>
                            <p>o haz clic para seleccionar</p>
                            <p style="margin-top: 0.5rem; font-size: 0.75rem;">Máximo 100MB • Solo archivos .zip con
                                PDFs</p>
                            <input type="file" name="zip_file" id="zipInput" accept=".zip" style="display: none;"
                                required>
                        </div>

                        <div class="file-info" id="fileInfo" style="display: none;">
                            <strong>📁 Archivo seleccionado:</strong>
                            <span id="fileName"></span>
                            <span id="fileSize" style="color: var(--text-muted); margin-left: 0.5rem;"></span>
                        </div>

                        <div class="upload-options">
                            <div class="option-card">
                                <label class="form-label">Tipo de documento</label>
                                <select name="doc_type" class="form-select">
                                    <option value="documento">Documento General</option>
                                    <option value="manifiesto">Manifiesto</option>
                                    <option value="declaracion">Declaración</option>
                                    <option value="factura">Factura</option>
                                </select>
                            </div>

                            <div class="option-card">
                                <label class="form-label">Opciones</label>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="extract_codes" checked>
                                    <span>Extraer códigos automáticamente</span>
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem;"
                            id="submitBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 0.5rem;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Procesar ZIP
                        </button>
                    </form>
                </div>

                <?php if (!empty($results)):
                    $successCount = 0;
                    $errorCount = 0;
                    foreach ($results as $r) {
                        if ($r['status'] === 'success')
                            $successCount++;
                        else
                            $errorCount++;
                    }
                    ?>
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3 class="card-title">📊 Resumen de Procesamiento</h3>
                            <div class="flex gap-2">
                                <span class="badge badge-success"><?= $successCount ?> Exitosos</span>
                                <?php if ($errorCount > 0): ?>
                                    <span class="badge badge-danger"><?= $errorCount ?> Errores</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="results-list">
                            <table class="table" style="margin:0">
                                <thead>
                                    <tr>
                                        <th>Estado</th>
                                        <th>Archivo</th>
                                        <th>Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                        <tr>
                                            <td width="50">
                                                <?php if ($result['status'] === 'success'): ?>
                                                    <span class="badge badge-success">OK</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Error</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($result['file']) ?></td>
                                            <td>
                                                <?php if ($result['status'] === 'success'): ?>
                                                    <span style="color:var(--accent-success)">Guardado (ID:
                                                        <?= $result['id'] ?>)</span>
                                                <?php else: ?>
                                                    <span
                                                        style="color:var(--accent-danger)"><?= htmlspecialchars($result['message']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h3 class="card-title">💡 Instrucciones</h3>
                    </div>
                    <div style="padding: 0 1rem 1rem;">
                        <ol style="margin: 0; padding-left: 1.25rem; color: var(--text-secondary);">
                            <li>Crea una carpeta con todos tus archivos PDF</li>
                            <li>Comprime la carpeta en un archivo ZIP</li>
                            <li>Sube el ZIP aquí (máximo 100MB)</li>
                            <li>El sistema extraerá y procesará cada PDF</li>
                            <li>Los códigos se extraen automáticamente</li>
                        </ol>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const dropZone = document.getElementById('dropZone');
        const zipInput = document.getElementById('zipInput');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const submitBtn = document.getElementById('submitBtn');
        const form = document.getElementById('uploadForm');

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'));
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'));
        });

        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0 && files[0].name.endsWith('.zip')) {
                zipInput.files = files;
                showFileInfo(files[0]);
            }
        });

        zipInput.addEventListener('change', () => {
            if (zipInput.files.length > 0) {
                showFileInfo(zipInput.files[0]);
            }
        });

        function showFileInfo(file) {
            fileInfo.style.display = 'block';
            fileName.textContent = file.name;
            fileSize.textContent = formatBytes(file.size);
            dropZone.classList.add('file-selected');
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        form.addEventListener('submit', () => {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="loading"></span> Procesando...';
        });
    </script>
</body>

</html>