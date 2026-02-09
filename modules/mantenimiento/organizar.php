<?php
/**
 * Herramienta de Organización de Archivos
 * 
 * Mueve los archivos PDF a sus carpetas correspondientes según el 'tipo' de documento.
 * - Crea carpetas si no existen (manifiestos, declaraciones, etc.)
 * - Mueve archivos sueltos o mal ubicados.
 * - Actualiza la ruta en la base de datos.
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
$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

$action = $_POST['action'] ?? null;
$results = [];
$stats = ['moved' => 0, 'updated' => 0, 'errors' => 0, 'already_ok' => 0];

if ($action === 'organize') {
    // 1. Obtener todos los documentos
    $stmt = $db->query("SELECT id, tipo, numero, ruta_archivo FROM documentos");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($docs as $doc) {
        $tipo = strtolower(trim($doc['tipo'])) ?: 'otros';
        $filename = basename($doc['ruta_archivo']);

        // Carpeta destino ideal
        $targetDir = $uploadsDir . $tipo;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $targetPath = $targetDir . '/' . $filename;
        $relativeTargetPath = $tipo . '/' . $filename;

        // Buscar el archivo actual usando nuestra función robusta
        $currentFullPath = resolve_pdf_path($clientCode, $doc);

        if (!$currentFullPath || !file_exists($currentFullPath)) {
            $results[] = [
                'doc' => $doc['numero'],
                'status' => 'error',
                'msg' => 'Archivo físico no encontrado'
            ];
            $stats['errors']++;
            continue;
        }

        // Verificar si ya está en el lugar correcto
        // Normalizamos rutas para comparar
        $realCurrent = realpath($currentFullPath);
        $realTarget = realpath($targetPath); // Puede ser false si no existe aún

        // Si el archivo ya existe en destino (caso de que realCurrent == realTarget es 'ya ordenado')
        // Pero cuidado: si realTarget existe Y NO ES el mismo archivo, es colisión.

        if ($realCurrent && $realTarget && $realCurrent === $realTarget) {
            // Ya está en su sitio. Solo verificar si la BD tiene la ruta "bonita"
            if ($doc['ruta_archivo'] !== $relativeTargetPath) {
                // Actualizar solo BD
                $upd = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE id = ?");
                $upd->execute([$relativeTargetPath, $doc['id']]);
                $stats['updated']++;
                $results[] = [
                    'doc' => $doc['numero'],
                    'status' => 'updated',
                    'msg' => 'Ruta DB corregida (archivo ya estaba bien)'
                ];
            } else {
                $stats['already_ok']++;
            }
            continue;
        }

        // Si llegamos aquí, hay que mover
        if (rename($currentFullPath, $targetPath)) {
            // Actualizar BD
            $upd = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE id = ?");
            $upd->execute([$relativeTargetPath, $doc['id']]);

            $stats['moved']++;
            $results[] = [
                'doc' => $doc['numero'],
                'type' => $tipo,
                'status' => 'success',
                'msg' => "Movido de " . basename(dirname($currentFullPath)) . " a $tipo"
            ];
        } else {
            $stats['errors']++;
            $results[] = [
                'doc' => $doc['numero'],
                'status' => 'error',
                'msg' => 'Fallo al mover archivo (permisos?)'
            ];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Organizador de Archivos</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 1rem;
        }

        .log-box {
            background: #1a1a1a;
            color: #0f0;
            padding: 1rem;
            border-radius: 8px;
            height: 300px;
            overflow-y: auto;
            font-family: monospace;
        }

        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🗄️ Organizador de Archivos</h1>
        <p>Esta herramienta moverá todos los archivos PDF a carpetas separadas por tipo (<code>manifiestos/</code>,
            <code>declaraciones/</code>, etc.).
        </p>

        <div class="card">
            <?php if (!$action): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="organize">
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%">
                        🚀 Iniciar Organización Automática
                    </button>
                </form>
            <?php else: ?>
                <div class="grid">
                    <div class="stat-card">
                        <h3>
                            <?= $stats['moved'] ?>
                        </h3>
                        <small>Movidos</small>
                    </div>
                    <div class="stat-card">
                        <h3>
                            <?= $stats['updated'] ?>
                        </h3>
                        <small>Ruta BD actualizada</small>
                    </div>
                    <div class="stat-card">
                        <h3>
                            <?= $stats['already_ok'] ?>
                        </h3>
                        <small>Ya estaban bien</small>
                    </div>
                    <div class="stat-card">
                        <h3 style="color: red">
                            <?= $stats['errors'] ?>
                        </h3>
                        <small>Errores</small>
                    </div>
                </div>

                <h3>Log de Cambios</h3>
                <div class="log-box">
                    <?php if (empty($results))
                        echo "No se requirieron cambios importantes."; ?>
                    <?php foreach ($results as $r): ?>
                        <div style="margin-bottom: 5px;">
                            [
                            <?= $r['doc'] ?>]
                            <span style="color: <?= $r['status'] == 'error' ? 'red' : 'inherit' ?>">
                                <?= $r['msg'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top: 1rem">
                    <a href="organizar.php" class="btn btn-secondary">Volver</a>
                    <a href="../trazabilidad/dashboard.php" class="btn btn-primary">Ir al Dashboard</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>