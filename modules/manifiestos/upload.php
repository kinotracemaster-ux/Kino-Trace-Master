<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/ai_engine.php';

// Verificar sesión
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$error = $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = trim($_POST['numero'] ?? '');
    $fecha = trim($_POST['fecha'] ?? '');
    $proveedor = trim($_POST['proveedor'] ?? '');
    $file = $_FILES['file'] ?? null;

    if ($numero === '' || $fecha === '' || !$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Todos los campos son obligatorios y el archivo debe subirse correctamente.';
    } else {
        try {
            // Directorio de destino
            $clientDir = CLIENTS_DIR . '/' . $clientCode;
            $uploadDir = $clientDir . '/uploads/manifiestos';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = basename($file['name']);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            // Generar nombre único para evitar colisiones
            $targetName = uniqid('mf_', true) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $targetName;

            // Mover el archivo subido
            move_uploaded_file($file['tmp_name'], $targetPath);

            // Calcular hash (opcional)
            $hash = hash_file('sha256', $targetPath);

            // Extracción de datos por IA (stub)
            $extracted = ai_extract_data_from_pdf($targetPath);
            $datosExtraidos = json_encode($extracted);

            // Insertar en la base de datos
            $stmt = $db->prepare(
                'INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, hash_archivo, datos_extraidos) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                'manifiesto',
                $numero,
                $fecha,
                $proveedor,
                'manifiestos/' . $targetName,
                $hash,
                $datosExtraidos
            ]);

            $ok = 'Manifiesto registrado correctamente.';
        } catch (Exception $e) {
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir Manifiesto</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body>
<div class="container">
    <h1>Subir Manifiesto</h1>
    <p>
        <a href="../trazabilidad/dashboard.php">🏠 Tablero</a> |
        <a href="list.php">← Volver al listado</a>
    </p>
    <?php if ($ok): ?>
        <div class="ok-box"><?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label>Número de Manifiesto</label>
        <input type="text" name="numero" required>
        <label>Fecha</label>
        <input type="date" name="fecha" required>
        <label>Proveedor</label>
        <input type="text" name="proveedor" required>
        <label>Archivo PDF</label>
        <input type="file" name="file" accept="application/pdf" required>
        <button type="submit">Subir</button>
    </form>
</div>
</body>
</html>