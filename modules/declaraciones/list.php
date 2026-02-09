<?php
/**
 * Declaraciones Module - List View
 * 
 * Shows all declaraciones with sidebar navigation.
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

// Get declaraciones
$stmt = $db->prepare('SELECT id, numero, fecha, proveedor, ruta_archivo FROM documentos WHERE tipo = ? ORDER BY fecha DESC');
$stmt->execute(['declaracion']);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For sidebar
$currentModule = 'declaraciones';
$baseUrl = '../../';
$pageTitle = 'Declaraciones';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Declaraciones - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Lista de Declaraciones</h3>
                        <a href="upload.php" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4" />
                            </svg>
                            Subir Declaración
                        </a>
                    </div>

                    <?php if (empty($docs)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </div>
                            <h4 class="empty-state-title">Sin declaraciones</h4>
                            <p class="empty-state-text">Sube tu primera declaración para comenzar.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Fecha</th>
                                        <th>Proveedor</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($docs as $doc): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($doc['numero']) ?></strong></td>
                                            <td><?= htmlspecialchars($doc['fecha']) ?></td>
                                            <td><?= htmlspecialchars($doc['proveedor'] ?? '-') ?></td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <a href="view.php?id=<?= $doc['id'] ?>" class="btn btn-secondary btn-icon"
                                                        title="Ver">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>
</body>

</html>