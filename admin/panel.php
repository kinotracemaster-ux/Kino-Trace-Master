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

                    // Process ZIP file if uploaded
                    if (!empty($_FILES['zip_file']['tmp_name']) && $_FILES['zip_file']['error'] === UPLOAD_ERR_OK) {
                        $zip = new ZipArchive();
                        if ($zip->open($_FILES['zip_file']['tmp_name']) === TRUE) {
                            $uploadDir = CLIENTS_DIR . "/{$code}/uploads/documento/";
                            if (!file_exists($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }

                            $newDb = open_client_db($code);
                            $pdfCount = 0;

                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $filename = $zip->getNameIndex($i);
                                if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf')
                                    continue;

                                $basename = basename($filename);
                                $newFilename = time() . '_' . $pdfCount . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
                                $content = $zip->getFromIndex($i);

                                if ($content !== false && file_put_contents($uploadDir . $newFilename, $content)) {
                                    $docName = pathinfo($basename, PATHINFO_FILENAME);
                                    $stmt = $newDb->prepare("INSERT INTO documentos (tipo, numero, fecha, ruta_archivo) VALUES (?, ?, ?, ?)");
                                    $stmt->execute(['documento', $docName, date('Y-m-d'), $newFilename]);
                                    $pdfCount++;
                                }
                            }
                            $zip->close();
                            $extraMsg .= " + {$pdfCount} PDFs del ZIP importados.";
                        }
                    }

                    $message = "✅ Cliente '{$name}' creado correctamente." . $extraMsg;
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

        // IMPORTAR SQL
        elseif ($action === 'import_sql') {
            $code = sanitize_code($_POST['client_code'] ?? '');

            if ($code === '' || empty($_FILES['sql_file']['tmp_name'])) {
                $error = 'Debe seleccionar un cliente y un archivo SQL.';
            } else {
                $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
                $db = open_client_db($code);

                // Clean MySQL-specific syntax for SQLite compatibility
                $sqlContent = preg_replace('/\/\*!.*?\*\/;?/s', '', $sqlContent); // Remove MySQL comments /*!...*/
                $sqlContent = preg_replace('/SET\s+[^;]+;/i', '', $sqlContent); // Remove SET statements
                $sqlContent = preg_replace('/LOCK\s+TABLES[^;]+;/i', '', $sqlContent); // Remove LOCK TABLES
                $sqlContent = preg_replace('/UNLOCK\s+TABLES;?/i', '', $sqlContent); // Remove UNLOCK TABLES
                $sqlContent = preg_replace('/ENGINE\s*=\s*\w+/i', '', $sqlContent); // Remove ENGINE=
                $sqlContent = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sqlContent); // Remove CHARSET
                $sqlContent = preg_replace('/COLLATE\s*=?\s*\w+/i', '', $sqlContent); // Remove COLLATE
                $sqlContent = preg_replace('/AUTO_INCREMENT\s*=\s*\d+/i', '', $sqlContent); // Remove AUTO_INCREMENT=N
                $sqlContent = preg_replace('/ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sqlContent); // Remove ON UPDATE
                $sqlContent = preg_replace('/`/s', '"', $sqlContent); // Convert backticks to double quotes
                $sqlContent = preg_replace('/int\s*\(\d+\)/i', 'INTEGER', $sqlContent); // int(11) -> INTEGER
                $sqlContent = preg_replace('/varchar\s*\(\d+\)/i', 'TEXT', $sqlContent); // varchar -> TEXT
                $sqlContent = preg_replace('/datetime/i', 'TEXT', $sqlContent); // datetime -> TEXT
                $sqlContent = preg_replace('/--.*$/m', '', $sqlContent); // Remove -- comments

                // Split into statements and execute
                $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
                $executed = 0;
                $errors = 0;

                foreach ($statements as $stmt) {
                    if (empty($stmt) || strlen($stmt) < 5)
                        continue;
                    try {
                        $db->exec($stmt);
                        $executed++;
                    } catch (PDOException $e) {
                        $errors++;
                        // Continue with other statements
                    }
                }

                if ($errors > 0) {
                    $message = "⚠️ SQL importado con {$executed} sentencias OK, {$errors} errores ignorados.";
                } else {
                    $message = "✅ SQL importado correctamente: {$executed} sentencias ejecutadas.";
                }
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
            <div class="cards-section">
                <!-- Create Client -->
                <div class="form-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                        Crear Nuevo Cliente
                    </h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Código *</label>
                                <input type="text" class="form-input" name="code" placeholder="ej: kino" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Nombre *</label>
                                <input type="text" class="form-input" name="name" placeholder="KINO Company" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Contraseña *</label>
                                <input type="password" class="form-input" name="password" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">📧 Correo Electrónico</label>
                                <input type="email" class="form-input" name="email" placeholder="cliente@empresa.com">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Título del Dashboard</label>
                                <input type="text" class="form-input" name="titulo" placeholder="Mi Empresa">
                            </div>
                            <div class="form-group"></div>
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

                        <div class="form-group"
                            style="padding: 0.75rem; background: rgba(59,130,246,0.05); border-radius: var(--radius-md); border: 1px dashed var(--border-color);">
                            <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="copy_kino_data">
                                <span>📦 Copiar datos de referencia de KINO</span>
                            </label>
                            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0.5rem 0 0 1.5rem;">
                                Incluye 119 documentos y 18,400 códigos de inventario
                            </p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">🖼️ Logo del Cliente (PNG/JPG)</label>
                            <input type="file" class="form-input" name="logo_file" accept=".png,.jpg,.jpeg,.gif"
                                style="padding: 0.5rem;">
                            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                Imagen pequeña para mostrar en Dashboard y Buscador (recomendado: 200x80px)
                            </p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">📁 Subir PDFs (ZIP opcional)</label>
                            <input type="file" class="form-input" name="zip_file" accept=".zip"
                                style="padding: 0.5rem;">
                            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                Sube un archivo ZIP con PDFs para importar automáticamente
                            </p>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">Crear Cliente</button>
                    </form>
                </div>

                <!-- Clone Client -->
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

                <!-- Import SQL -->
                <div class="form-card">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                        </svg>
                        Importar SQL
                    </h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_sql">
                        <div class="form-group">
                            <label class="form-label">Cliente destino *</label>
                            <select class="form-select" name="client_code" required>
                                <option value="">Seleccione cliente...</option>
                                <?php foreach ($clientCodes as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Archivo SQL *</label>
                            <input type="file" class="form-input" name="sql_file" accept=".sql" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Importar SQL</button>
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
    </script>
</body>

</html>