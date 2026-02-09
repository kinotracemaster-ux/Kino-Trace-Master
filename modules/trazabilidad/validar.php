<?php
/**
 * Página para validar códigos asociados a documentos.
 *
 * Permite marcar cada código como validado o pendiente. Los cambios se
 * reflejan directamente en la base de datos del cliente.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Acciones masivas
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'validate_all') {
                $stmt = $db->prepare('UPDATE codigos SET validado = 1 WHERE validado = 0');
                $stmt->execute();
                $count = $stmt->rowCount();
                $message = "¡Éxito! Se han validado TODOS los códigos ({$count}).";
            } elseif ($_POST['action'] === 'validate_selected') {
                if (!empty($_POST['ids'])) {
                    $ids = array_map('intval', $_POST['ids']);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $db->prepare("UPDATE codigos SET validado = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $count = $stmt->rowCount();
                    $message = "Se han validado {$count} códigos seleccionados.";
                } else {
                    $error = "No has seleccionado ningún código.";
                }
            }
        }
        // Acción individual (legacy)
        elseif (isset($_POST['code_id'])) {
            $codeId = (int) $_POST['code_id'];
            $val = (int) $_POST['validado'];
            if ($codeId) {
                $stmt = $db->prepare('UPDATE codigos SET validado = ? WHERE id = ?');
                $stmt->execute([$val, $codeId]);
                $message = 'Código actualizado correctamente.';
            }
        }
    }
} catch (Exception $ex) {
    if (strpos($ex->getMessage(), 'database is locked') !== false) {
        $error = 'Error: La base de datos está ocupada (Lock). Intenta de nuevo en unos segundos.';
    } else {
        $error = 'Error crítico: ' . $ex->getMessage();
    }
}

// Obtener códigos y sus documentos asociados
$rows = $db->query("SELECT codigos.id AS id, codigos.codigo AS codigo_text, codigos.descripcion AS descripcion, codigos.validado, documentos.tipo, documentos.numero FROM codigos JOIN documentos ON codigos.documento_id = documentos.id ORDER BY documentos.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Validar Códigos</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        th,
        td {
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            text-align: left;
        }

        button {
            padding: 0.25rem 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-valid {
            background: #22c55e;
            color: #ffffff;
        }

        .btn-invalid {
            background: #ef4444;
            color: #ffffff;
        }

        .message,
        .error {
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .message {
            background: #dcfce7;
            color: #065f46;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Validar Códigos</h1>
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
     <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <table>
            <form method="post" id="massForm">
                <div style="margin-bottom: 1rem; display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" name="action" value="validate_selected" class="btn-valid"
                        style="padding: 0.5rem 1rem; font-size: 1rem;">
                        ✅ Validar Seleccionados
                    </button>
                    <button type="submit" name="action" value="validate_all" class="btn-valid"
                        style="background: #0ea5e9; padding: 0.5rem 1rem; font-size: 1rem;"
                        onclick="return confirm('¿Estás seguro de validar TODOS los códigos pendientes?');">
                        🚀 Validar TODO
                    </button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll"></th>
                            <th>Documento</th>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                   <?php foreach ($rows as $r): ?>
                            <tr>
                                <td style="text-align: center;">
                                 <?php if (!$r['validado']): ?>
                                        <input type="checkbox" name="ids[]" value="<?= $r['id'] ?>" class="row-checkbox">
                                  <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['tipo']) ?> Nº<?= htmlspecialchars($r['numero']) ?></td>
                                <td><?= htmlspecialchars($r['codigo_text']) ?></td>
                                <td><?= htmlspecialchars($r['descripcion'] ?? '') ?></td>
                                <td>
                                        <?php if ($r['validado']): ?>
                                        <span style="color: #22c55e; font-weight: bold;">Validado</span>
                                  <?php else: ?>
                                        <span style="color: #f59e0b; font-weight: bold;">Pendiente</span>
                                        <?php endif; ?>
                                </td>
                                <td>
                                        <?php if ($r['validado']): ?>
                                        <button type="submit" form="single_<?= $r['id'] ?>" class="btn-invalid">Marcar
                                            pendiente</button>
                                        <?php else: ?>
                                        <button type="submit" form="single_<?= $r['id'] ?>" class="btn-valid">Validar</button>
                                        <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Forms individuales para mantener la funcionalidad botón por botón sin romper el form masivo -->
       <?php foreach ($rows as $r): ?>
                <form method="post" id="single_<?= $r['id'] ?>" style="display:none;">
                    <input type="hidden" name="code_id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="validado" value="<?= $r['validado'] ? 0 : 1 ?>">
                </form>
          <?php endforeach; ?>

            <script>
                document.getElementById('selectAll').addEventListener('change', function () {
                    const checkboxes = document.querySelectorAll('.row-checkbox');
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });
            </script>
            <p><a href="dashboard.php">← Volver al tablero</a></p>
    </div>
</body>

</html>