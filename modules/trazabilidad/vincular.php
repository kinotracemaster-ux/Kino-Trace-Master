<?php
/**
 * Página para vincular documentos entre sí.
 *
 * Permite seleccionar un documento de origen y uno de destino, y genera
 * un registro en la tabla de vínculos calculando coincidencias y
 * discrepancias entre los códigos asociados a ambos documentos.
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
        $docOrigen = (int) ($_POST['doc_origen'] ?? 0);
        $docDestino = (int) ($_POST['doc_destino'] ?? 0);
        $tipo = trim($_POST['tipo_vinculo'] ?? 'general');
        if ($docOrigen && $docDestino && $docOrigen !== $docDestino) {
            // Obtener códigos de ambos documentos
            $codesOrigen = $db->query("SELECT codigo FROM codigos WHERE documento_id = " . $docOrigen)->fetchAll(PDO::FETCH_COLUMN);
            $codesDestino = $db->query("SELECT codigo FROM codigos WHERE documento_id = " . $docDestino)->fetchAll(PDO::FETCH_COLUMN);
            $setOrigen = array_unique($codesOrigen);
            $setDestino = array_unique($codesDestino);
            $coinciden = count(array_intersect($setOrigen, $setDestino));
            $faltanList = array_diff($setOrigen, $setDestino);
            $extraList = array_diff($setDestino, $setOrigen);
            $faltanCount = count($faltanList);
            $extraCount = count($extraList);
            $discrepancias = '';
            if ($faltanCount > 0) {
                $discrepancias .= 'Faltan: ' . implode(', ', $faltanList) . '; ';
            }
            if ($extraCount > 0) {
                $discrepancias .= 'Extra: ' . implode(', ', $extraList);
            }
            $stmt = $db->prepare("INSERT INTO vinculos (documento_origen_id, documento_destino_id, tipo_vinculo, codigos_coinciden, codigos_faltan, codigos_extra, discrepancias) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$docOrigen, $docDestino, $tipo, $coinciden, $faltanCount, $extraCount, $discrepancias]);
            $message = 'Vínculo creado exitosamente.';
        } else {
            $error = 'Debe seleccionar dos documentos distintos.';
        }
    }
} catch (Exception $ex) {
    $error = 'Error: ' . $ex->getMessage();
}

// Obtener listado de documentos para los selects
$docs = $db->query("SELECT id, tipo, numero, fecha FROM documentos ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Vincular Documentos</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
<style>
.container { max-width: 800px; margin: 2rem auto; padding: 1rem; }
form { margin-bottom: 2rem; padding: 1rem; border: 1px solid #e5e7eb; border-radius: 8px; }
.form-row { margin-bottom: 0.75rem; }
.form-row label { display: block; margin-bottom: 0.25rem; font-weight: bold; }
.form-row select, .form-row input { width: 100%; padding: 0.5rem; }
button { padding: 0.5rem 1rem; background: #2563eb; color: white; border: none; border-radius: 4px; cursor: pointer; }
button:hover { background: #1d4ed8; }
.message, .error { padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
.message { background: #dcfce7; color: #065f46; }
.error { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="container">
    <h1>Vincular Documentos</h1>
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="form-row">
            <label for="doc_origen">Documento origen</label>
            <select id="doc_origen" name="doc_origen" required>
                <option value="">Seleccione...</option>
                <?php foreach ($docs as $d): ?>
                    <option value="<?= $d['id'] ?>">
                        <?= htmlspecialchars($d['tipo']) ?> Nº<?= htmlspecialchars($d['numero']) ?> (<?= htmlspecialchars($d['fecha']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="doc_destino">Documento destino</label>
            <select id="doc_destino" name="doc_destino" required>
                <option value="">Seleccione...</option>
                <?php foreach ($docs as $d): ?>
                    <option value="<?= $d['id'] ?>">
                        <?= htmlspecialchars($d['tipo']) ?> Nº<?= htmlspecialchars($d['numero']) ?> (<?= htmlspecialchars($d['fecha']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="tipo_vinculo">Tipo de vínculo</label>
            <input type="text" id="tipo_vinculo" name="tipo_vinculo" value="general">
        </div>
        <button type="submit">Crear vínculo</button>
    </form>
    <p><a href="dashboard.php">← Volver al tablero</a></p>
</div>
</body>
</html>