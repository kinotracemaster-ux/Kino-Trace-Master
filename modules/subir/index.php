<?php
/**
 * Módulo de Subida de Documentos con Extracción de Códigos
 *
 * Permite subir PDFs y extraer códigos automáticamente usando patrones
 * personalizables (prefijo/terminador) como en la aplicación anterior.
 * soporta MODO EDICIÓN.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/gemini_ai.php';
require_once __DIR__ . '/../../helpers/csrf_protection.php';

// Generar token CSRF para este módulo
$csrfToken = CsrfProtection::getToken();

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$geminiConfigured = is_gemini_configured();

$message = '';
$error = '';

// Leer mensaje de éxito de sesión (flash message)
if (isset($_SESSION['upload_success'])) {
    $message = $_SESSION['upload_success'];
    unset($_SESSION['upload_success']);
}

// --- MODO EDICIÓN: Cargar datos si existe parámetro 'edit' ---
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isEditMode = $editId > 0;
$editDoc = null;
$editCodes = [];

if ($isEditMode) {
    // Cargar documento
    $stmt = $db->prepare("SELECT * FROM documentos WHERE id = ?");
    $stmt->execute([$editId]);
    $editDoc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editDoc) {
        // Cargar códigos
        $stmtCodes = $db->prepare("SELECT codigo FROM codigos WHERE documento_id = ?");
        $stmtCodes->execute([$editId]);
        $editCodes = $stmtCodes->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $error = "Documento no encontrado o eliminado.";
        $isEditMode = false;
    }
}

// Procesar Formulario (Guardar o Actualizar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save' || $action === 'update') {
        try {
            $tipo = sanitize_code($_POST['tipo'] ?? 'documento');
            $numero = trim($_POST['numero'] ?? '');
            $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
            $proveedor = trim($_POST['proveedor'] ?? '');
            $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? '')));

            // Validar ID en update
            $updateId = isset($_POST['edit_id']) ? (int) $_POST['edit_id'] : 0;
            if ($action === 'update' && $updateId <= 0) {
                throw new Exception('ID de documento inválido para actualización');
            }

            // Manejo de Archivo
            $targetPath = null;
            $targetName = null;
            $hash = null;
            $datosExtraidos = [];
            $fileUploaded = false;

            // Si hay archivo nuevo subido
            if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $originalFileName = $_FILES['file']['name'];

                /* 
                // OPTIMIZACIÓN: Permitir duplicados a petición del usuario
                // Se comenta la validación de nombre y hash para agilizar la subida

                // Validar nombre de archivo duplicado
                $checkFileStmt = $db->prepare("SELECT id, numero FROM documentos WHERE ruta_archivo LIKE ?");
                $checkFileStmt->execute(['%/' . basename($originalFileName)]);
                $existingFile = $checkFileStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingFile) {
                    throw new Exception('⚠️ Ya existe un archivo con el nombre "' . $originalFileName . '" (Documento #' . $existingFile['numero'] . '). Renombra el archivo antes de subirlo.');
                }

                // Validar contenido duplicado por hash
                $tempHash = hash_file('sha256', $_FILES['file']['tmp_name']);
                $checkHashStmt = $db->prepare("SELECT id, numero FROM documentos WHERE hash_archivo = ?");
                $checkHashStmt->execute([$tempHash]);
                $existingHash = $checkHashStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingHash) {
                    throw new Exception('⚠️ Este archivo ya fue subido anteriormente (Documento #' . $existingHash['numero'] . '). El contenido es idéntico.');
                }
                */

                // Calculamos hash solo para guardarlo, pero sin validar unicidad
                $tempHash = hash_file('sha256', $_FILES['file']['tmp_name']);

                // Crear directorio
                $clientDir = CLIENTS_DIR . '/' . $code;
                $uploadDir = $clientDir . '/uploads/' . $tipo;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $ext = pathinfo($originalFileName, PATHINFO_EXTENSION);
                // Guardar con nombre original para poder validarlo después
                $targetName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($originalFileName, PATHINFO_FILENAME)) . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . '/' . $targetName;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    throw new Exception('Error al mover el archivo subido.');
                }

                $hash = $tempHash;
                $fileUploaded = true;

                // Extraer datos si es PDF
                if (strtolower($ext) === 'pdf') {
                    // Obtener configuración de extracción de la base de datos del cliente
                    // ASYNC: No extraemos aquí. El proceso de fondo lo hará.
                    // Solo preparamos el trigger si es PDF.
                }
            } elseif ($action === 'save') {
                throw new Exception('Debes seleccionar un archivo PDF para crear un nuevo documento.');
            }

            // --- OPERACIÓN EN BASE DE DATOS ---
            $db->beginTransaction();

            if ($action === 'save') {
                // Validar número de documento duplicado
                $checkStmt = $db->prepare("SELECT id FROM documentos WHERE numero = ?");
                $checkStmt->execute([$numero]);
                if ($checkStmt->fetch()) {
                    $db->rollBack();
                    throw new Exception('⚠️ Ya existe un documento con el número "' . $numero . '". Por favor usa un número diferente.');
                }

                // INSERT
                $stmt = $db->prepare("
                    INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, hash_archivo, datos_extraidos)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $tipo,
                    $numero,
                    $fecha,
                    $proveedor,
                    $tipo . '/' . $targetName,
                    $hash,
                    json_encode($datosExtraidos)
                ]);
                $docId = $db->lastInsertId();
                $message = "✅ Documento creado exitosamente";

            } else {
                // UPDATE
                $docId = $updateId;

                // Construir query dinámico según si cambió el archivo
                if ($fileUploaded) {
                    $stmt = $db->prepare("
                        UPDATE documentos 
                        SET tipo=?, numero=?, fecha=?, proveedor=?, ruta_archivo=?, hash_archivo=?, datos_extraidos=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $tipo,
                        $numero,
                        $fecha,
                        $proveedor,
                        $tipo . '/' . $targetName,
                        $hash,
                        json_encode($datosExtraidos),
                        $docId
                    ]);
                } else {
                    $stmt = $db->prepare("
                        UPDATE documentos 
                        SET tipo=?, numero=?, fecha=?, proveedor=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $tipo,
                        $numero,
                        $fecha,
                        $proveedor,
                        $docId
                    ]);
                }

                // Borrar códigos anteriores para re-insertar (estrategia simple de reemplazo)
                $db->prepare("DELETE FROM codigos WHERE documento_id = ?")->execute([$docId]);
                $message = "✅ Documento actualizado exitosamente";

                // Actualizar modo edición para reflejar cambios
                $isEditMode = true;
                $editDoc = [
                    'id' => $docId,
                    'tipo' => $tipo,
                    'numero' => $numero,
                    'fecha' => $fecha,
                    'proveedor' => $proveedor,
                    'ruta_archivo' => $fileUploaded ? ($tipo . '/' . $targetName) : $_POST['current_file_path'] // Mantener o nuevo
                ];
                $editCodes = $codes;
            }

            // Insertar códigos (común para save y update)
            if (!empty($codes)) {
                $insertCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
                foreach (array_unique($codes) as $c) {
                    if (!empty($c))
                        $insertCode->execute([$docId, $c]);
                }
            }

            $db->commit();

            // --- ASYNC TRIGGER ---
            // Lanzar proceso en segundo plano si hay un archivo PDF nuevo
            if ($fileUploaded && isset($ext) && strtolower($ext) === 'pdf') {
                $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/process_extraction.php';

                $postData = http_build_query([
                    'client_code' => $code,
                    'doc_id' => $docId,
                    'token' => md5($code . 'kino_async_' . date('Y-m-d'))
                ]);

                // Fire-and-forget request
                $parts = parse_url($url);
                $fp = fsockopen($parts['host'], isset($parts['port']) ? $parts['port'] : 80, $errno, $errstr, 30);

                if ($fp) {
                    $out = "POST " . $parts['path'] . " HTTP/1.1\r\n";
                    $out .= "Host: " . $parts['host'] . "\r\n";
                    $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
                    $out .= "Content-Length: " . strlen($postData) . "\r\n";
                    $out .= "Connection: Close\r\n\r\n";
                    $out .= $postData;
                    fwrite($fp, $out);
                    fclose($fp);
                }
            }

            // Guardar mensaje en sesión y redirigir (patrón POST-Redirect-GET)
            $successMsg = ($action === 'save' ? "✅ Documento creado" : "✅ Documento actualizado") . " exitosamente. Procesando códigos en segundo plano...";
            $_SESSION['upload_success'] = $successMsg;

            // Redirigir para limpiar estado del formulario
            if ($action === 'save') {
                header("Location: index.php");
                exit;
            } else {
                // En modo edición, quedarse en el documento
                header("Location: index.php?edit=" . $docId);
                exit;
            }

        } catch (Exception $e) {
            if ($db->inTransaction())
                $db->rollBack();
            $error = "❌ Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEditMode ? 'Editar Documento' : 'Subir Documento' ?> - KINO TRACE</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f3f4f6;
            margin: 0;
            min-height: auto !important;
            /* PREVENT IFRAME RESIZE LOOP */
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 1rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .header h1 {
            margin: 0;
            color: #1f2937;
        }

        .nav-links a {
            margin-left: 1rem;
            color: #2563eb;
            text-decoration: none;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .card h2 {
            margin-top: 0;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #374151;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
        }

        .file-drop {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9fafb;
        }

        .file-drop:hover,
        .file-drop.dragover {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .file-drop .icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        .file-drop input {
            display: none;
        }

        /* Edit Mode Styles for File Drop */
        .file-drop.has-file {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }

        .pattern-config {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 0.75rem;
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .pattern-config label {
            font-size: 0.875rem;
        }

        .pattern-config input {
            padding: 0.5rem;
            font-size: 0.875rem;
        }

        .codes-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .codes-area {
                grid-template-columns: 1fr;
            }
        }

        .codes-box {
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
        }

        .codes-box h4 {
            margin-top: 0;
            color: #374151;
        }

        .codes-box textarea {
            width: 100%;
            height: 200px;
            font-family: monospace;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            resize: vertical;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }



        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            justify-content: center;
        }

        .code-count {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }

        .message {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
            animation: slideIn 0.5s ease-out;
            position: relative;
        }

        .error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Overlay de carga para submit del formulario */
        .submit-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .submit-overlay.active {
            display: flex;
        }

        .submit-overlay .spinner-large {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .submit-overlay p {
            color: white;
            font-size: 1.25rem;
            margin-top: 1rem;
            font-weight: 500;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
            color: #6b7280;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #e5e7eb;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }



        /* Responsive Improvements */
        @media (max-width: 900px) {
            .pattern-config {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 600px) {
            .pattern-config {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 0.5rem;
            }

            .card {
                padding: 1rem;
            }

            .form-grid,
            .codes-area {
                gap: 0.75rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
                margin-bottom: 0.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            h2 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <!-- Overlay de carga -->
    <div class="submit-overlay" id="submitOverlay">
        <div class="spinner-large"></div>
        <p>📤 Subiendo documento... Por favor espera</p>
    </div>

    <!-- Modal de confirmación para códigos vacíos -->
    <div class="confirm-modal-overlay" id="confirmCodesModal">
        <div class="confirm-modal">
            <div class="confirm-modal-icon">⚠️</div>
            <h3>Documento sin códigos asignados</h3>
            <p class="confirm-modal-text">
                ¿Deseas subir el PDF sin códigos asignados?
            </p>
            <p class="confirm-modal-hint">
                💡 Puedes agregarlos manualmente en el textarea o extraerlos con el extractor de códigos.
            </p>
            <div class="confirm-modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="cancelUploadAndFocusCodes()">
                    ✏️ No, quiero agregar códigos
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmUploadWithoutCodes()">
                    ✅ Sí, subir sin códigos
                </button>
            </div>
        </div>
    </div>

    <style>
        .confirm-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease;
        }

        .confirm-modal-overlay.active {
            display: flex;
        }

        .confirm-modal {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 450px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .confirm-modal-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .confirm-modal h3 {
            color: #1f2937;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }

        .confirm-modal-text {
            color: #4b5563;
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .confirm-modal-hint {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #1e40af;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            border: 1px solid #bfdbfe;
        }

        .confirm-modal-buttons {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .confirm-modal-buttons .btn {
            flex: 1;
            min-width: 150px;
        }
    </style>

    <div class="container">

        <?php if ($message): ?>
            <div class="message" id="successMsg">
                <?= htmlspecialchars($message) ?>
            </div>
            <script>document.addEventListener('DOMContentLoaded', function () { document.getElementById('successMsg')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });</script>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error" id="errorMsg">
                <?= htmlspecialchars($error) ?>
            </div>
            <script>document.addEventListener('DOMContentLoaded', function () { document.getElementById('errorMsg')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });</script>
        <?php endif; ?>

        <?php if ($isEditMode): ?>
            <div style="margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                <h1 style="font-size: 1.5rem; color: #4338ca;">✏️ Editando: <?= htmlspecialchars($editDoc['numero']) ?></h1>
                <a href="index.php" class="btn btn-secondary" style="text-decoration: none;">Cancelar Edición</a>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm" novalidate>
            <!-- Hidden inputs for Edit Mode -->
            <input type="hidden" name="action" value="<?= $isEditMode ? 'update' : 'save' ?>">
            <?php if ($isEditMode): ?>
                <input type="hidden" name="edit_id" value="<?= $editDoc['id'] ?>">
                <input type="hidden" name="current_file_path" value="<?= htmlspecialchars($editDoc['ruta_archivo']) ?>">
            <?php endif; ?>

            <!-- Paso 1: Subir archivo -->
            <div class="card">
                <h2>📁 Paso 1: <?= $isEditMode ? 'Actualizar Archivo PDF (Opcional)' : 'Seleccionar Archivo PDF' ?></h2>

                <div class="file-drop <?= $isEditMode ? 'has-file' : '' ?>" id="dropZone"
                    onclick="document.getElementById('fileInput').click()">
                    <div class="icon">📄</div>
                    <p id="fileName">
                        <?php if ($isEditMode): ?>
                            ✅ Archivo actual: <?= htmlspecialchars(basename($editDoc['ruta_archivo'])) ?><br>
                            <span style="font-size: 0.9em; color: #666;">Haz clic para reemplazarlo</span>
                        <?php else: ?>
                            Arrastra un PDF aquí o haz clic para seleccionar
                        <?php endif; ?>
                    </p>
                    <input type="file" name="file" id="fileInput" accept=".pdf" <?= $isEditMode ? '' : 'required' ?>>
                </div>

                <div class="pattern-config">
                    <div class="form-group" style="margin-bottom:0">
                        <label>Prefijo (Empieza en)</label>
                        <input type="text" id="prefix" placeholder="Ej: Ref:">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Terminador (Termina en)</label>
                        <input type="text" id="terminator" value="/" placeholder="Ej: / o espacio">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Long. Mínima</label>
                        <input type="number" id="minLength" value="4" min="1" max="100">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Long. Máxima</label>
                        <input type="number" id="maxLength" value="50" min="1" max="200">
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-primary" onclick="extractCodes()">
                        🔍 Extraer Códigos del PDF
                    </button>

                </div>

                <div class="loading" id="extractLoading">
                    <div class="spinner"></div>
                    <p>Extrayendo códigos...</p>
                </div>
            </div>

            <!-- Paso 2: Códigos -->
            <div class="card">
                <h2>📋 Paso 2: Códigos Detectados
                    <span class="code-count" id="codeCount"><?= $isEditMode ? count($editCodes) : 0 ?> códigos</span>
                </h2>

                <div class="codes-area">
                    <div class="codes-box">
                        <h4>✏️ Códigos a Guardar (editables)</h4>
                        <textarea name="codes" id="codesInput"
                            placeholder="Los códigos aparecerán aquí después de extraerlos del PDF...
También puedes escribirlos manualmente (uno por línea)"><?= $isEditMode ? htmlspecialchars(implode("\n", $editCodes)) : '' ?></textarea>
                    </div>
                    <div class="codes-box">
                        <h4>📄 Texto Extraído del PDF</h4>
                        <textarea id="pdfText" readonly placeholder="El texto del PDF aparecerá aquí..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Paso 3: Metadatos -->
            <div class="card">
                <h2>📝 Paso 3: Información del Documento</h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de Documento</label>
                        <select name="tipo" id="tipoDoc" required>
                            <?php
                            $types = ['declaracion' => '📄 Declaración', 'factura' => '💰 Factura', 'otro' => '📁 Otro'];
                            $currentType = $isEditMode ? $editDoc['tipo'] : 'documento';
                            foreach ($types as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $currentType === $val ? 'selected' : '' ?>><?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Número de Documento</label>
                        <input type="text" name="numero" id="numeroDoc" required placeholder="Ej: 12345"
                            value="<?= $isEditMode ? htmlspecialchars($editDoc['numero']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" id="fechaDoc" required
                            value="<?= $isEditMode ? htmlspecialchars($editDoc['fecha']) : date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Proveedor</label>
                        <input type="text" name="proveedor" placeholder="Nombre del proveedor"
                            value="<?= $isEditMode ? htmlspecialchars($editDoc['proveedor']) : '' ?>">
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <?= $isEditMode ? '💾 Actualizar Documento' : '💾 Guardar Documento con Códigos' ?>
                    </button>
                    <a href="index.php" class="btn btn-secondary">Limpiar / Nuevo</a>
                </div>
            </div>
        </form>
    </div>

    <script>
        const apiUrl = '../../api.php';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const codesInput = document.getElementById('codesInput');
        const pdfText = document.getElementById('pdfText');
        const codeCount = document.getElementById('codeCount');

        // Auto-resize Iframe logic 
        // Auto-resize Iframe logic (Fixed)
        function updateParentHeight() {
            if (window.frameElement) {
                const height = document.body.scrollHeight;
                // Evitar jitter: solo actualizar si hay diferencia significativa (> 5px)
                const currentFnHeight = parseInt(window.frameElement.style.height) || 0;
                if (Math.abs((height + 50) - currentFnHeight) > 10) {
                    window.frameElement.style.height = (height + 50) + 'px';
                }
            }
        }

        window.addEventListener('load', updateParentHeight);
        // REMOVIDO: window.addEventListener('resize', ...) causaba bucle infinito

        // Observar cambios de contenido internos que afecten la altura
        const observer = new MutationObserver(updateParentHeight);
        observer.observe(document.body, { childList: true, subtree: true, attributes: true });

        // Observar cambios de tamaño del body específicamente (mejor que window.resize)
        const resizeObserver = new ResizeObserver(entries => {
            updateParentHeight();
        });
        resizeObserver.observe(document.body);

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFileName();
            }
        });

        fileInput.addEventListener('change', updateFileName);

        function updateFileName() {
            if (fileInput.files.length) {
                document.getElementById('fileName').textContent = '📄 ' + fileInput.files[0].name;
            }
        }

        function updateCodeCount() {
            const codes = codesInput.value.split('\n').filter(c => c.trim());
            codeCount.textContent = codes.length + ' código(s)';
        }

        codesInput.addEventListener('input', updateCodeCount);

        // Validación del formulario al enviar
        document.getElementById('uploadForm').addEventListener('submit', function (e) {
            const isEditMode = <?= $isEditMode ? 'true' : 'false' ?>;
            const fileInput = document.getElementById('fileInput');
            const tipoDoc = document.getElementById('tipoDoc').value.trim();
            const numero = document.getElementById('numeroDoc').value.trim();
            const fechaDoc = document.getElementById('fechaDoc').value.trim();
            const codes = codesInput.value.trim();

            console.log('[VALIDACIÓN] PDF:', fileInput.files.length, 'Tipo:', tipoDoc, 'Número:', numero, 'Fecha:', fechaDoc, 'Códigos:', codes ? codes.split('\n').length : 0);

            // 1. Validar que haya archivo si es nuevo documento
            if (!isEditMode && !fileInput.files.length) {
                e.preventDefault();
                alert('⚠️ Debes seleccionar un archivo PDF');
                return false;
            }

            // 2. Validar tipo de documento
            if (!tipoDoc) {
                e.preventDefault();
                alert('⚠️ Debes seleccionar un tipo de documento');
                document.getElementById('tipoDoc').focus();
                return false;
            }

            // 3. Validar número de documento
            if (!numero) {
                e.preventDefault();
                alert('⚠️ Debes ingresar un número de documento');
                document.getElementById('numeroDoc').focus();
                return false;
            }

            // 4. Validar fecha
            if (!fechaDoc) {
                e.preventDefault();
                alert('⚠️ Debes seleccionar una fecha');
                document.getElementById('fechaDoc').focus();
                return false;
            }

            // 5. Verificar si hay códigos - usar confirm() simple
            if (!codes) {
                const userConfirmed = confirm(
                    '⚠️ No hay códigos asignados al documento.\n\n' +
                    '¿Deseas continuar sin códigos?\n\n' +
                    'Puedes agregarlos manualmente o extraerlos con el extractor.'
                );

                if (!userConfirmed) {
                    e.preventDefault();
                    document.getElementById('codesInput').focus();
                    document.getElementById('codesInput').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            }

            // ✅ Todo válido - mostrar overlay y enviar
            console.log('[VALIDACIÓN] ✅ Enviando formulario...');
            document.getElementById('submitOverlay').classList.add('active');
            document.getElementById('submitBtn').disabled = true;
        });

        async function extractCodes() {
            if (!fileInput.files.length) {
                alert('Primero selecciona un archivo PDF');
                return;
            }

            const loading = document.getElementById('extractLoading');
            loading.style.display = 'block';

            try {
                const formData = new FormData();
                formData.append('action', 'extract_codes');
                formData.append('file', fileInput.files[0]);
                formData.append('prefix', document.getElementById('prefix').value);
                formData.append('terminator', document.getElementById('terminator').value);
                formData.append('min_length', document.getElementById('minLength').value);
                formData.append('max_length', document.getElementById('maxLength').value);

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result = await response.json();

                loading.style.display = 'none';

                if (result.error) {
                    alert('Error: ' + result.error);
                    return;
                }

                if (result.codes && result.codes.length > 0) {
                    codesInput.value = result.codes.join('\n');
                    updateCodeCount();
                } else {
                    codesInput.value = '';
                    alert('No se encontraron códigos con el patrón especificado. Prueba ajustando el prefijo/terminador.');
                }

                if (result.text) {
                    pdfText.value = result.text;
                }

            } catch (error) {
                loading.style.display = 'none';
                alert('Error al extraer códigos: ' + error.message);
            }
        }

        async function aiExtract() {
            if (!fileInput.files.length) {
                alert('Primero selecciona un archivo PDF');
                return;
            }

            const loading = document.getElementById('extractLoading');
            loading.style.display = 'block';

            try {
                // Primero extraer texto
                const formData1 = new FormData();
                formData1.append('action', 'extract_codes');
                formData1.append('file', fileInput.files[0]);

                const response1 = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData1,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result1 = await response1.json();

                if (!result1.text) {
                    loading.style.display = 'none';
                    alert('No se pudo extraer texto del PDF');
                    return;
                }

                pdfText.value = result1.text;

                // Ahora enviar a IA
                const formData2 = new FormData();
                formData2.append('action', 'ai_extract');
                formData2.append('text', result1.text);
                formData2.append('document_type', document.getElementById('tipoDoc').value);

                const response2 = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData2,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result2 = await response2.json();

                loading.style.display = 'none';

                if (result2.error) {
                    alert('Error IA: ' + result2.error);
                    return;
                }

                if (result2.data) {
                    // Llenar campos con datos extraídos por IA
                    if (result2.data.numero_documento) {
                        document.getElementById('numeroDoc').value = result2.data.numero_documento;
                    }
                    if (result2.data.codigos && result2.data.codigos.length > 0) {
                        codesInput.value = result2.data.codigos.join('\n');
                        updateCodeCount();
                    }
                    alert('✅ IA extrajo los datos del documento');
                }

            } catch (error) {
                loading.style.display = 'none';
                alert('Error: ' + error.message);
            }
        }
    </script>
</body>

</html>