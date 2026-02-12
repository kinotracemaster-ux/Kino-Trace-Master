<?php
/**
 * Panel de Administración - Gestor de Clientes
 *
 * Funcionalidades:
 * - Crear nuevo cliente (con colores personalizados)
 * - Clonar cliente existente (incluyendo datos y archivos)
 * - Importar SQL a cliente
 * - Cambiar contraseña de cliente
 * - Habilitar/Deshabilitar cliente
 * - Eliminar cliente
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/tenant.php';

// Verificar que el usuario sea administrador
if (!isset($_SESSION['client_code']) || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';
$createdClientCode = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // CREAR NUEVO CLIENTE
        if ($action === 'create') {
            $code = sanitize_code($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $password = $_POST['password'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $titulo = trim($_POST['titulo'] ?? '');
            $colorP = trim($_POST['color_primario'] ?? '#3b82f6');
            $colorS = trim($_POST['color_secundario'] ?? '#64748b');
            $copyKinoData = isset($_POST['copy_kino_data']);

            if ($code === '' || $name === '' || $password === '') {
                $error = 'Debe completar código, nombre y contraseña.';
            } else {
                // Verificar que no exista
                $stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
                $stmt->execute([$code]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $error = 'Ya existe un cliente con ese código.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    create_client_structure($code, $name, $hash, $titulo, $colorP, $colorS, $email);

                    $extraMsg = '';

                    // Process logo upload
                    if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                        $logoExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                        if (in_array($logoExt, ['png', 'jpg', 'jpeg', 'gif'])) {
                            $logoDir = CLIENTS_DIR . "/{$code}/";
                            $logoPath = $logoDir . 'logo.' . $logoExt;
                            // Remove old logos
                            foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
                                $old = $logoDir . 'logo.' . $ext;
                                if (file_exists($old))
                                    unlink($old);
                            }
                            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $logoPath)) {
                                $extraMsg .= ' + Logo subido.';
                            }
                        }
                    }

                    // Copy KINO reference data if requested
                    if ($copyKinoData) {
                        $kinoDb = open_client_db('kino');
                        $newDb = open_client_db($code);

                        // Copy documentos
                        $docs = $kinoDb->query('SELECT tipo, numero, fecha, ruta_archivo FROM documentos')->fetchAll(PDO::FETCH_ASSOC);
                        $docMapping = [];
                        $stmtDoc = $newDb->prepare('INSERT INTO documentos (tipo, numero, fecha, ruta_archivo) VALUES (?, ?, ?, ?)');
                        foreach ($docs as $doc) {
                            $stmtDoc->execute([$doc['tipo'], $doc['numero'], $doc['fecha'], $doc['ruta_archivo']]);
                            $docMapping[$doc['numero']] = $newDb->lastInsertId();
                        }

                        // Copy codigos
                        $codes = $kinoDb->query('SELECT c.codigo, d.numero FROM codigos c JOIN documentos d ON c.documento_id = d.id')->fetchAll(PDO::FETCH_ASSOC);
                        $stmtCode = $newDb->prepare('INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)');
                        foreach ($codes as $codeRow) {
                            if (isset($docMapping[$codeRow['numero']])) {
                                $stmtCode->execute([$docMapping[$codeRow['numero']], $codeRow['codigo']]);
                            }
                        }

                        $extraMsg = " + Datos de KINO copiados (" . count($docs) . " docs, " . count($codes) . " códigos).";
                    }

                    // Process SQL file if uploaded (MySQL dump → KINO-TRACE SQLite)
                    if (!empty($_FILES['sql_file']['tmp_name']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                        $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
                        $newDb = open_client_db($code);

                        // Parse documents
                        preg_match_all(
                            "/INSERT\s+INTO\s+`?documents`?.*?VALUES\s*(.+?)\s*;/si",
                            $sqlContent, $docMatches
                        );

                        $idMap = [];
                        $docCount = 0;
                        $stmtDoc = $newDb->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo, original_path) VALUES (?, ?, ?, ?, ?)");

                        foreach ($docMatches[1] as $block) {
                            preg_match_all("/\(([^)]+)\)/", $block, $rows);
                            foreach ($rows[1] as $row) {
                                $vals = str_getcsv($row, ',', "'");
                                if (count($vals) < 4) continue;
                                $oldId = (int)trim($vals[0]);
                                $docName = trim($vals[1]);
                                $docDate = trim($vals[2]);
                                $docPath = trim($vals[3]);
                                $stmtDoc->execute(['importado_sql', $docName, $docDate, 'pending', $docPath]);
                                $idMap[$oldId] = (int)$newDb->lastInsertId();
                                $docCount++;
                            }
                        }

                        // Parse codes
                        preg_match_all(
                            "/INSERT\s+INTO\s+`?codes`?.*?VALUES\s*(.+?)\s*;/si",
                            $sqlContent, $codeMatches
                        );

                        $codeCount = 0;
                        $stmtCode = $newDb->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");

                        foreach ($codeMatches[1] as $block) {
                            preg_match_all("/\(([^)]+)\)/", $block, $rows);
                            foreach ($rows[1] as $row) {
                                $vals = str_getcsv($row, ',', "'");
                                if (count($vals) < 3) continue;
                                $oldDocId = (int)trim($vals[1]);
                                $codeVal = trim($vals[2]);
                                $newDocId = $idMap[$oldDocId] ?? null;
                                if ($newDocId) {
                                    $stmtCode->execute([$newDocId, $codeVal]);
                                    $codeCount++;
                                }
                            }
                        }

                        $extraMsg .= " + SQL importado ({$docCount} docs, {$codeCount} códigos).";
                    }

                    // Process ZIP file if uploaded (link PDFs to documents)
                    if (!empty($_FILES['zip_file']['tmp_name']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
                        require_once __DIR__ . '/../helpers/pdf_linker.php';
                        $newDb = $newDb ?? open_client_db($code);
                        $uploadDir = CLIENTS_DIR . "/{$code}/uploads/sql_import/";
                        processZipAndLink($newDb, $_FILES['zip_file']['tmp_name'], $uploadDir, 'sql_import/');
                        $extraMsg .= " + PDFs del ZIP enlazados.";
                    }

                    $message = "✅ Cliente '{$name}' creado correctamente." . $extraMsg;
                    
                    // Store created client code for batch ZIP UI
                    $createdClientCode = $code;
                }
            }
        }

        // CLONAR CLIENTE
        elseif ($action === 'clone') {
            $source = sanitize_code($_POST['source'] ?? '');
            $newCode = sanitize_code($_POST['new_code'] ?? '');
            $newName = trim($_POST['new_name'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';

            if ($source === '' || $newCode === '' || $newName === '' || $newPassword === '') {
                $error = 'Debe completar todos los campos de clonación.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                clone_client($source, $newCode, $newName, $hash);
                $message = "✅ Cliente clonado desde '{$source}' a '{$newCode}'.";
            }
        }

        // CAMBIAR CONTRASEÑA
        elseif ($action === 'change_password') {
            $code = sanitize_code($_POST['client_code'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';

            if ($code === '' || $newPassword === '') {
                $error = 'Debe especificar el cliente y la nueva contraseña.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $centralDb->prepare('UPDATE control_clientes SET password_hash = ? WHERE codigo = ?');
                $stmt->execute([$hash, $code]);
                $message = "✅ Contraseña actualizada para '{$code}'.";
            }
        }

        // ACTUALIZAR COLORES Y LOGO
        elseif ($action === 'update_colors') {
            $code = sanitize_code($_POST['client_code'] ?? '');
            $titulo = trim($_POST['titulo'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $colorP = trim($_POST['color_primario'] ?? '#3b82f6');
            $colorS = trim($_POST['color_secundario'] ?? '#64748b');

            if ($code !== '') {
                $stmt = $centralDb->prepare('UPDATE control_clientes SET titulo = ?, email = ?, color_primario = ?, color_secundario = ? WHERE codigo = ?');
                $stmt->execute([$titulo, $email, $colorP, $colorS, $code]);

                $logoMsg = '';
                // Process logo upload if provided
                if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $logoExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($logoExt, ['png', 'jpg', 'jpeg', 'gif'])) {
                        $logoDir = CLIENTS_DIR . "/{$code}/";
                        $logoPath = $logoDir . 'logo.' . $logoExt;
                        // Remove old logos
                        foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
                            $old = $logoDir . 'logo.' . $ext;
                            if (file_exists($old))
                                unlink($old);
                        }
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $logoPath)) {
                            $logoMsg = ' + Logo actualizado.';
                        }
                    }
                }

                $message = "✅ Cliente '{$code}' actualizado." . $logoMsg;
            }
        }

        // IMPORTAR SQL (MySQL dump → KINO-TRACE SQLite)
        elseif ($action === 'import_sql') {
            $code = sanitize_code($_POST['client_code'] ?? '');

            if ($code === '' || empty($_FILES['sql_file']['tmp_name'])) {
                $error = 'Debe seleccionar un cliente y un archivo SQL.';
            } else {
                $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
                $db = open_client_db($code);

                // Parse documents from INSERT INTO `documents`
                preg_match_all(
                    "/INSERT\s+INTO\s+`?documents`?.*?VALUES\s*(.+?)\s*;/si",
                    $sqlContent,
                    $docMatches
                );

                $idMap = []; // old_id => new_id
                $docCount = 0;
                $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo, original_path) VALUES (?, ?, ?, ?, ?)");

                foreach ($docMatches[1] as $block) {
                    preg_match_all("/\(([^)]+)\)/", $block, $rows);
                    foreach ($rows[1] as $row) {
                        $vals = str_getcsv($row, ',', "'");
                        if (count($vals) < 4)
                            continue;
                        $oldId = (int) trim($vals[0]);
                        $name = trim($vals[1]);
                        $date = trim($vals[2]);
                        $path = trim($vals[3]);

                        $stmtDoc->execute(['importado_sql', $name, $date, 'pending', $path]);
                        $idMap[$oldId] = (int) $db->lastInsertId();
                        $docCount++;
                    }
                }

                // Parse codes from INSERT INTO `codes`
                preg_match_all(
                    "/INSERT\s+INTO\s+`?codes`?.*?VALUES\s*(.+?)\s*;/si",
                    $sqlContent,
                    $codeMatches
                );

                $codeCount = 0;
                $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");

                foreach ($codeMatches[1] as $block) {
                    preg_match_all("/\(([^)]+)\)/", $block, $rows);
                    foreach ($rows[1] as $row) {
                        $vals = str_getcsv($row, ',', "'");
                        if (count($vals) < 3)
                            continue;
                        $oldDocId = (int) trim($vals[1]);
                        $codeVal = trim($vals[2]);

                        $newDocId = $idMap[$oldDocId] ?? null;
                        if ($newDocId) {
                            $stmtCode->execute([$newDocId, $codeVal]);
                            $codeCount++;
                        }
                    }
                }

                $extraMsg = "";

                // Process ZIP if provided
                if (!empty($_FILES['zip_file']['tmp_name']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
                    require_once __DIR__ . '/../helpers/pdf_linker.php';
                    $uploadDir = CLIENTS_DIR . "/{$code}/uploads/sql_import/";
                    processZipAndLink($db, $_FILES['zip_file']['tmp_name'], $uploadDir, 'sql_import/');
                    $extraMsg = " + PDFs del ZIP enlazados.";
                }

                $message = "✅ SQL importado: {$docCount} documentos, {$codeCount} códigos.{$extraMsg}";
            }
        }


        // HABILITAR/DESHABILITAR
        elseif ($action === 'toggle') {
            $toggleCode = sanitize_code($_POST['toggle_code'] ?? '');
            if ($toggleCode !== '' && $toggleCode !== 'admin') {
                $stmt = $centralDb->prepare('SELECT activo FROM control_clientes WHERE codigo = ?');
                $stmt->execute([$toggleCode]);
                $curr = (int) $stmt->fetchColumn();
                $new = $curr ? 0 : 1;
                $update = $centralDb->prepare('UPDATE control_clientes SET activo = ? WHERE codigo = ?');
                $update->execute([$new, $toggleCode]);
                $message = $new ? "✅ Cliente habilitado." : "⏸️ Cliente deshabilitado.";
            }
        }

        // ELIMINAR
        elseif ($action === 'delete') {
            $delCode = sanitize_code($_POST['delete_code'] ?? '');
            if ($delCode !== '' && $delCode !== 'admin') {
                // Borrar registro
                $delStmt = $centralDb->prepare('DELETE FROM control_clientes WHERE codigo = ?');
                $delStmt->execute([$delCode]);

                // Eliminar directorio
                $dir = CLIENTS_DIR . DIRECTORY_SEPARATOR . $delCode;
                if (is_dir($dir)) {
                    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
                    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($files as $file) {
                        if ($file->isDir()) {
                            rmdir($file->getRealPath());
                        } else {
                            unlink($file->getRealPath());
                        }
                    }
                    rmdir($dir);
                }
                $message = "🗑️ Cliente '{$delCode}' eliminado.";
            }
        }
    }
} catch (Exception $ex) {
    $error = '❌ Error: ' . $ex->getMessage();
}

// Obtener lista de clientes con detalles
$clients = $centralDb->query("
    SELECT codigo, nombre, titulo, email, color_primario, color_secundario, activo, fecha_creacion 
    FROM control_clientes 
    ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Para clonación
$clientCodes = array_column($clients, 'codigo');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Clientes - Admin</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        .admin-layout {
            min-height: 100vh;
            background: var(--bg-primary);
        }

        .admin-header {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .admin-header h1 span {
            background: var(--accent-primary);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
        }

        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
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

        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .client-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .client-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--client-color, var(--accent-primary));
        }

        .client-card.inactive {
            opacity: 0.6;
        }

        .client-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .client-name {
            font-weight: 600;
            font-size: 1rem;
        }

        .client-code {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .client-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
        }

        .client-status.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
        }

        .client-status.inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--accent-danger);
        }

        .client-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .client-colors {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .color-swatch {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid var(--border-color);
        }

        .client-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .client-actions form {
            display: inline;
        }

        .btn-xs {
            padding: 0.375rem 0.625rem;
            font-size: 0.75rem;
        }

        .btn-danger {
            background: var(--accent-danger);
            color: white;
        }

        .cards-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .form-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        .form-card h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .color-row {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .color-row input[type="color"] {
            width: 50px;
            height: 36px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
        }

        .color-row label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            max-width: 450px;
            width: 90%;
        }

        .modal h3 {
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <header class="admin-header">
            <h1>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Gestor de Clientes <span>ADMIN</span>
            </h1>
            <div class="flex gap-3">
                <a href="../modules/trazabilidad/dashboard.php" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Dashboard
                </a>
                <a href="../logout.php" class="btn btn-secondary">Salir</a>
            </div>
        </header>

        <div class="admin-container">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Clients List -->
            <h2 style="margin-bottom: 1rem;">Clientes Registrados (<?= count($clients) ?>)</h2>

            <div class="clients-grid">
                <?php foreach ($clients as $cli): ?>
                    <div class="client-card <?= $cli['activo'] ? '' : 'inactive' ?>"
                        style="--client-color: <?= htmlspecialchars($cli['color_primario'] ?: '#3b82f6') ?>">
                        <div class="client-header">
                            <div>
                                <div class="client-name"><?= htmlspecialchars($cli['nombre']) ?></div>
                                <div class="client-code"><?= htmlspecialchars($cli['codigo']) ?></div>
                            </div>
                            <span class="client-status <?= $cli['activo'] ? 'active' : 'inactive' ?>">
                                <?= $cli['activo'] ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </div>

                        <?php if ($cli['titulo']): ?>
                            <div class="client-meta">📍 <?= htmlspecialchars($cli['titulo']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($cli['email'])): ?>
                            <div class="client-meta">📧 <?= htmlspecialchars($cli['email']) ?></div>
                        <?php endif; ?>

                        <div class="client-colors">
                            <div class="color-swatch"
                                style="background: <?= htmlspecialchars($cli['color_primario'] ?: '#3b82f6') ?>"
                                title="Primario"></div>
                            <div class="color-swatch"
                                style="background: <?= htmlspecialchars($cli['color_secundario'] ?: '#64748b') ?>"
                                title="Secundario"></div>
                        </div>

                        <div class="client-actions">
                            <button class="btn btn-secondary btn-xs"
                                onclick="openEditModal('<?= htmlspecialchars($cli['codigo']) ?>', '<?= htmlspecialchars($cli['titulo']) ?>', '<?= htmlspecialchars($cli['email'] ?? '') ?>', '<?= htmlspecialchars($cli['color_primario']) ?>', '<?= htmlspecialchars($cli['color_secundario']) ?>')">
                                Editar
                            </button>
                            <button class="btn btn-secondary btn-xs"
                                onclick="openPasswordModal('<?= htmlspecialchars($cli['codigo']) ?>')">
                                Clave
                            </button>
                            <?php if ($cli['codigo'] !== 'admin'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="toggle_code" value="<?= htmlspecialchars($cli['codigo']) ?>">
                                    <button type="submit" class="btn btn-secondary btn-xs">
                                        <?= $cli['activo'] ? 'Pause' : 'Activar' ?>
                                    </button>
                                </form>
                                <form method="post"
                                    onsubmit="return confirm('¿Eliminar cliente <?= htmlspecialchars($cli['codigo']) ?> y todos sus datos?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_code" value="<?= htmlspecialchars($cli['codigo']) ?>">
                                    <button type="submit" class="btn btn-danger btn-xs">Eliminar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Action Cards -->
            <div class="cards-section" style="grid-template-columns: 1fr 1fr;">

                <!-- ═══════════ CREAR NUEVO CLIENTE (full-width) ═══════════ -->
                <div class="form-card" style="grid-column: 1 / -1;">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                        Crear Nuevo Cliente
                    </h3>
                    <form method="post" enctype="multipart/form-data" id="createForm">
                        <input type="hidden" name="action" value="create">

                        <!-- ── Datos básicos ── -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                            <div class="form-group">
                                <label class="form-label">Código *</label>
                                <input type="text" class="form-input" name="code" placeholder="ej: kino" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nombre *</label>
                                <input type="text" class="form-input" name="name" placeholder="KINO Company" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contraseña *</label>
                                <input type="password" class="form-input" name="password" required>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                            <div class="form-group">
                                <label class="form-label">📧 Correo Electrónico</label>
                                <input type="email" class="form-input" name="email" placeholder="cliente@empresa.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Título del Dashboard</label>
                                <input type="text" class="form-input" name="titulo" placeholder="Mi Empresa">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Colores</label>
                                <div class="color-row">
                                    <div>
                                        <input type="color" name="color_primario" value="#3b82f6">
                                        <label>Primario</label>
                                    </div>
                                    <div>
                                        <input type="color" name="color_secundario" value="#64748b">
                                        <label>Secundario</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Opciones adicionales ── -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; margin-top: 0.5rem;">
                            <div class="form-group">
                                <label class="form-label">🖼️ Logo (PNG/JPG)</label>
                                <input type="file" class="form-input" name="logo_file" accept=".png,.jpg,.jpeg,.gif"
                                    style="padding: 0.5rem;">
                                <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Recomendado: 200x80px
                                </p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">🗄️ Archivo SQL (phpMyAdmin export)</label>
                                <input type="file" class="form-input" name="sql_file" accept=".sql"
                                    style="padding: 0.5rem;">
                                <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Tablas <code>documents</code> + <code>codes</code>
                                </p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">📦 ZIP con PDFs (opcional)</label>
                                <input type="file" class="form-input" name="zip_file" accept=".zip"
                                    style="padding: 0.5rem;">
                                <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Si > 400MB, usa lotes después de crear
                                </p>
                            </div>
                        </div>

                        <div class="form-group"
                            style="padding: 0.75rem; background: rgba(59,130,246,0.05); border-radius: var(--radius-md); border: 1px dashed var(--border-color); margin-top: 0.5rem;">
                            <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="copy_kino_data">
                                <span>📦 Copiar datos de referencia de KINO (119 docs + 18,400 códigos)</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.75rem; font-size: 1rem; padding: 0.75rem;">
                            🚀 Crear Cliente
                        </button>
                    </form>
                </div>

                <!-- ═══════════ ENLAZAR PDFs POR LOTES ═══════════ -->
                <div class="form-card" id="batchZipCard" style="<?= !empty($createdClientCode) ? '' : '' ?>">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        📦 Enlazar PDFs por Lotes
                    </h3>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                        Sube ZIPs con PDFs para enlazar a documentos ya importados. Puedes repetir varias veces (~400MB por lote).
                    </p>
                    <form id="batchZipForm" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label">Cliente destino *</label>
                            <select class="form-select" name="client_code" id="batchClient" required>
                                <option value="">Seleccione cliente...</option>
                                <?php foreach ($clientCodes as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"
                                        <?= (!empty($createdClientCode) && $c === $createdClientCode) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">📦 ZIP con PDFs *</label>
                            <input type="file" class="form-input" name="zip_file" accept=".zip" required
                                style="padding: 0.5rem;">
                        </div>
                        <button type="button" class="btn btn-primary" style="width: 100%;" onclick="submitBatchZip()"
                            id="batchBtn">
                            📦 Subir y Enlazar Lote
                        </button>
                        <div id="batchResult" style="margin-top: 0.75rem; font-size: 0.85rem; display: none;"></div>
                        <div id="batchHistory" style="margin-top: 0.5rem; font-size: 0.8rem;"></div>
                    </form>
                </div>

                <!-- ═══════════ CLONAR CLIENTE ═══════════ -->
                <div class="form-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Clonar Cliente (con datos)
                    </h3>
                    <form method="post">
                        <input type="hidden" name="action" value="clone">
                        <div class="form-group">
                            <label class="form-label">Cliente origen *</label>
                            <select class="form-select" name="source" required>
                                <option value="">Seleccione cliente a clonar...</option>
                                <?php foreach ($clientCodes as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Nuevo código *</label>
                                <input type="text" class="form-input" name="new_code" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nuevo nombre *</label>
                                <input type="text" class="form-input" name="new_name" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contraseña *</label>
                            <input type="password" class="form-input" name="new_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Clonar Cliente</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <h3>Editar Cliente</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_colors">
                <input type="hidden" name="client_code" id="editClientCode">
                <div class="form-group">
                    <label class="form-label">Título del Dashboard</label>
                    <input type="text" class="form-input" name="titulo" id="editTitulo">
                </div>
                <div class="form-group">
                    <label class="form-label">📧 Correo Electrónico</label>
                    <input type="email" class="form-input" name="email" id="editEmail"
                        placeholder="cliente@empresa.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Colores</label>
                    <div class="color-row">
                        <div>
                            <input type="color" name="color_primario" id="editColorP">
                            <label>Primario</label>
                        </div>
                        <div>
                            <input type="color" name="color_secundario" id="editColorS">
                            <label>Secundario</label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🖼️ Logo del Cliente</label>
                    <div id="currentLogoPreview" style="margin-bottom: 0.5rem;"></div>
                    <input type="file" class="form-input" name="logo_file" accept=".png,.jpg,.jpeg,.gif"
                        style="padding: 0.5rem;">
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Dejar vacío para mantener el logo actual
                    </p>
                </div>
                <div class="flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal">
            <h3>Cambiar Contraseña</h3>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="client_code" id="pwClientCode">
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <input type="text" class="form-input" id="pwClientDisplay" disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">Nueva Contraseña *</label>
                    <input type="password" class="form-input" name="new_password" required>
                </div>
                <div class="flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Cambiar Contraseña</button>
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('passwordModal')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(code, titulo, email, colorP, colorS) {
            document.getElementById('editClientCode').value = code;
            document.getElementById('editTitulo').value = titulo || '';
            document.getElementById('editEmail').value = email || '';
            document.getElementById('editColorP').value = colorP || '#3b82f6';
            document.getElementById('editColorS').value = colorS || '#64748b';
            document.getElementById('editModal').classList.add('active');
        }

        function openPasswordModal(code) {
            document.getElementById('pwClientCode').value = code;
            document.getElementById('pwClientDisplay').value = code;
            document.getElementById('passwordModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    overlay.classList.remove('active');
                }
            });
        });

        // Batch ZIP upload via AJAX
        let batchCount = 0;
        async function submitBatchZip() {
            const form = document.getElementById('batchZipForm');
            const btn = document.getElementById('batchBtn');
            const resultDiv = document.getElementById('batchResult');
            const clientCode = document.getElementById('batchClient').value;

            if (!clientCode) { alert('Seleccione un cliente.'); return; }

            const zipInput = form.querySelector('input[name="zip_file"]');
            if (!zipInput.files.length) { alert('Seleccione un archivo ZIP.'); return; }

            btn.disabled = true;
            btn.textContent = '⏳ Subiendo...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '⏳ Subiendo y enlazando PDFs...';

            // We need to set the session to the target client temporarily
            const formData = new FormData();
            formData.append('zip_file', zipInput.files[0]);
            formData.append('client_code', clientCode);

            try {
                batchCount++;
                const response = await fetch('../modules/importar_datos/link_zip_admin.php', {
                    method: 'POST',
                    body: formData
                });

                const text = await response.text();
                let result;
                try { result = JSON.parse(text); } catch (e) {
                    resultDiv.innerHTML = '❌ Respuesta inválida: ' + text.substring(0, 200);
                    btn.disabled = false;
                    btn.textContent = '📦 Subir y Enlazar Lote';
                    return;
                }

                let html = '';
                if (result.success) {
                    html += `<div style="color: var(--accent-success);">✅ Lote #${batchCount} completado</div>`;
                    if (result.pending !== undefined) {
                        html += `<div>📊 Documentos sin PDF: <strong>${result.pending}</strong></div>`;
                        if (result.pending === 0) {
                            html += `<div style="color: var(--accent-success);">🎉 ¡Todos los documentos tienen PDF!</div>`;
                        }
                    }
                } else {
                    html += `<div style="color: var(--accent-danger);">❌ ${result.error || 'Error desconocido'}</div>`;
                }

                if (result.logs) {
                    result.logs.slice(-5).forEach(l => {
                        html += `<div style="font-size: 0.75rem; color: var(--text-muted);">${l.msg}</div>`;
                    });
                }

                resultDiv.innerHTML = html;

                // Reset file input for next batch
                zipInput.value = '';

            } catch (err) {
                resultDiv.innerHTML = '❌ Error de conexión: ' + err.message;
            }

            btn.disabled = false;
            btn.textContent = '📦 Subir y Enlazar Lote';
        }
    </script>
</body>

</html>