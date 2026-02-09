<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/validator.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('ID no válido');
}

// Obtener documento
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    die('Documento no encontrado');
}

// Validar el documento (stub)
$validation = validate_document($db, $id);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del Manifiesto</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>

<body>
    <div class="container">
        <h1>Detalle de Manifiesto</h1>
        <p>
            <a href="../trazabilidad/dashboard.php">🏠 Tablero</a> |
            <a href="list.php">← Volver al listado</a> |
            <a href="../../logout.php">Cerrar sesión</a>
        </p>
        <table>
            <tr>
                <th>Número</th>
                <td><?= htmlspecialchars($doc['numero']) ?></td>
            </tr>
            <tr>
                <th>Fecha</th>
                <td><?= htmlspecialchars($doc['fecha']) ?></td>
            </tr>
            <tr>
                <th>Proveedor</th>
                <td><?= htmlspecialchars($doc['proveedor'] ?? '-') ?></td>
            </tr>
            <tr>
                <th>Archivo</th>
                <td><a href="<?= '../resaltar/download.php?doc=' . $doc['id'] ?>" target="_blank">Descargar PDF</a></td>
            </tr>
            <tr>
                <th>Datos Extraídos</th>
                <td>
                    <pre><?= htmlspecialchars($doc['datos_extraidos']) ?></pre>
                </td>
            </tr>
        </table>
        <h3>Resultado de Validación</h3>
        <p>Estado: <?= htmlspecialchars($validation['status']) ?></p>
        <?php if (!empty($validation['messages'])): ?>
            <ul>
                <?php foreach ($validation['messages'] as $msg): ?>
                    <li><?= htmlspecialchars($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No se encontraron inconsistencias.</p>
        <?php endif; ?>
    </div>
</body>

</html>