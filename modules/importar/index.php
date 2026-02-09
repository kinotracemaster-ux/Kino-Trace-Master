<?php
/**
 * Módulo de Importación Inteligente (Smart Merge)
 * - Valida congruencia estructural.
 * - Fusiona datos sin sobrescribir (INSERT OR IGNORE).
 * - Vincula archivos automáticamente.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Configuración para cargas pesadas
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '128M');
ini_set('post_max_size', '128M');
ini_set('max_execution_time', 600);

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$uploadsDir = __DIR__ . "/../../clients/$clientCode/uploads/";
$backupsDir = __DIR__ . "/../../clients/$clientCode/backups/";

// Asegurar directorios
if (!file_exists($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}

$message = '';
$error = '';
$logs = [];

// --- HERRAMIENTAS DE VALIDACIÓN Y PROCESAMIENTO ---

/**
 * Verifica si la estructura del SQL entrante es CONGRUENTE con la BD actual.
 * Devuelve array de errores o array vacío si todo está OK.
 */
function verificar_congruencia($db, $rutaSql)
{
    $problemas = [];

    // 1. Mapa de la BD Actual
    $estructura_bd = [];
    $tablas = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $t) {
        $cols = $db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
        $estructura_bd[$t] = array_column($cols, 'name');
    }

    // 2. Escanear el SQL (sin ejecutarlo)
    $handle = fopen($rutaSql, "r");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            // Detectar intentos de inserción
            if (preg_match('/INSERT\s+(?:OR\s+\w+\s+)?INTO\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)/i', $line, $matches)) {
                $tabla = $matches[1];
                $columnas_sql = array_map(function ($c) {
                    return trim($c, " `\"'"); }, explode(',', $matches[2]));

                // A. ¿La tabla existe?
                if (!isset($estructura_bd[$tabla])) {
                    $problemas[] = "⚠️ <b>Incongruencia:</b> El archivo intenta insertar en la tabla '{$tabla}' que NO existe en tu sistema.";
                    continue;
                }

                // B. ¿Las columnas existen?
                $col_faltantes = array_diff($columnas_sql, $estructura_bd[$tabla]);
                if (!empty($col_faltantes)) {
                    $problemas[] = "⛔ <b>Estructura Incompatible:</b> La tabla '{$tabla}' en el archivo tiene columnas que tú no tienes: " . implode(', ', $col_faltantes);
                }
            }
        }
        fclose($handle);
    }

    // Eliminar duplicados en el reporte
    return array_unique($problemas);
}

// --- LÓGICA PRINCIPAL ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ACCIÓN: LIMPIEZA TOTAL (WIPE)
    if ($_POST['action'] === 'wipe_data') {
        try {
            $db->exec("PRAGMA foreign_keys = OFF");
            $db->exec("DELETE FROM vinculos");
            $db->exec("DELETE FROM codigos");
            $db->exec("DELETE FROM documentos");
            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('vinculos','codigos','documentos')");
            $message = "🗑️ Base de datos vaciada. Lista para nuevos datos.";
        } catch (Exception $e) {
            $error = "Error al borrar: " . $e->getMessage();
        }
    }

    // ACCIÓN: IMPORTACIÓN INTELIGENTE (FUSIONAR)
    if ($_POST['action'] === 'smart_import') {
        try {
            if (empty($_FILES['sql_file']['tmp_name']) || empty($_FILES['zip_file']['tmp_name'])) {
                throw new Exception("Por favor sube ambos archivos (SQL y ZIP).");
            }

            $sqlTemp = $_FILES['sql_file']['tmp_name'];
            $zipTemp = $_FILES['zip_file']['tmp_name'];
            $modo = $_POST['mode'] ?? 'merge'; // 'merge' (fusionar) o 'replace' (reemplazar)

            // PASO 1: VERIFICAR CONGRUENCIA
            $incongruencias = verificar_congruencia($db, $sqlTemp);

            if (!empty($incongruencias)) {
                // Si hay errores estructurales graves, detenemos todo.
                $htmlErrores = implode('<br>', $incongruencias);
                throw new Exception("NO SE PUEDE IMPORTAR. La estructura no es congruente:<br><br>$htmlErrores");
            }

            $db->beginTransaction();

            // PASO 2: PREPARAR BD
            if ($modo === 'replace') {
                // Borrado total previo
                $db->exec("PRAGMA foreign_keys = OFF");
                $db->exec("DELETE FROM vinculos; DELETE FROM codigos; DELETE FROM documentos;");
                $logs[] = "🗑️ Datos antiguos eliminados.";
            }

            // PASO 3: PROCESAR SQL (MAGIA "NO SOBRESCRIBIR")
            $sqlContent = file_get_contents($sqlTemp);

            if ($modo === 'merge') {
                // Transformar INSERTs normales en INSERT OR IGNORE para SQLite
                // Esto hace que si el ID ya existe, se salte el error y no haga nada (protege el dato existente)
                $sqlContent = str_ireplace('INSERT INTO', 'INSERT OR IGNORE INTO', $sqlContent);
                $logs[] = "🛡️ Modo Fusión activado: Se ignorarán los registros duplicados.";
            }

            // Ejecutar importación
            $db->exec($sqlContent);
            $logs[] = "✅ Datos procesados en la base de datos.";

            // PASO 4: PROCESAR ARCHIVOS (ZIP)
            $zip = new ZipArchive;
            if ($zip->open($zipTemp) === TRUE) {
                // Extraer sin borrar lo que ya existe
                $zip->extractTo($uploadsDir);
                $zip->close();
                $logs[] = "📦 Archivos extraídos en la nube.";
            } else {
                $logs[] = "⚠️ No se pudo procesar el ZIP (¿Quizás está dañado?).";
            }

            // PASO 5: VINCULADOR DE ARCHIVOS (REPARADOR)
            $stmtUpdate = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE id = ?");
            $docs = $db->query("SELECT id, numero, ruta_archivo FROM documentos")->fetchAll(PDO::FETCH_ASSOC);
            $vinculados = 0;

            foreach ($docs as $doc) {
                // Solo buscamos si no tiene ruta o si la ruta no existe
                $rutaActual = $uploadsDir . ($doc['ruta_archivo'] ?? 'x');

                if (empty($doc['ruta_archivo']) || !file_exists($rutaActual)) {
                    // Buscar archivo PDF que contenga el número
                    $nombreLimpio = preg_replace('/[^a-zA-Z0-9]/', '*', $doc['numero']);
                    $patron = $uploadsDir . "*" . $nombreLimpio . "*.pdf";
                    $encontrados = glob($patron);

                    if (!empty($encontrados)) {
                        $archivoReal = basename($encontrados[0]);
                        $stmtUpdate->execute([$archivoReal, $doc['id']]);
                        $vinculados++;
                    }
                }
            }
            if ($vinculados > 0) {
                $logs[] = "🔗 Se encontraron y vincularon $vinculados documentos nuevos.";
            }

            $db->commit();
            $message = "🎉 <b>Proceso Terminado:</b><br>" . implode('<br>', $logs);

        } catch (Exception $e) {
            if ($db->inTransaction())
                $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Importador Inteligente</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 1rem;
        }

        .card {
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card-import {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
        }

        .card-danger {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            opacity: 0.8;
        }

        .btn-block {
            display: block;
            width: 100%;
            margin-top: 1rem;
            padding: 1rem;
            font-size: 1.1rem;
        }

        .option-group {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
            border: 1px solid #e0f2fe;
        }

        .log-box {
            background: #1e293b;
            color: #a5f3fc;
            padding: 1rem;
            border-radius: 6px;
            font-family: monospace;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>📡 Centro de Importación y Sincronización</h1>

        <?php if ($message): ?>
            <div class="log-box"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>

        <div class="grid-container">

            <div class="card card-import">
                <h2 style="color: #0369a1; margin-top:0;">📥 Importar Datos</h2>
                <p>Sube tu respaldo (SQL + ZIP). El sistema verificará la estructura antes de procesar.</p>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="smart_import">

                    <div style="margin-bottom: 1rem;">
                        <label><strong>1. Archivo SQL</strong> (Base de datos)</label>
                        <input type="file" name="sql_file" accept=".sql" required style="width:100%">
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label><strong>2. Archivo ZIP</strong> (Documentos)</label>
                        <input type="file" name="zip_file" accept=".zip" required style="width:100%">
                    </div>

                    <div class="option-group">
                        <p style="margin:0 0 0.5rem 0; font-weight:bold; color:#0c4a6e;">Modo de Importación:</p>

                        <label style="display:block; margin-bottom:0.5rem; cursor:pointer;">
                            <input type="radio" name="mode" value="merge" checked>
                            <strong>🛡️ Fusión Segura (Recomendado)</strong>
                            <div style="font-size:0.85em; color:#555; margin-left:1.5rem;">
                                No borra nada. Si un dato ya existe, lo respeta. Solo agrega lo nuevo.
                            </div>
                        </label>

                        <label style="display:block; cursor:pointer;">
                            <input type="radio" name="mode" value="replace">
                            <strong>⚠️ Reemplazo Total</strong>
                            <div style="font-size:0.85em; color:#555; margin-left:1.5rem;">
                                Borra TODA la base de datos actual y pone la del archivo.
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        🚀 Iniciar Importación
                    </button>
                </form>
            </div>

            <div>
                <div class="card card-danger">
                    <h3 style="color: #9f1239; margin-top:0;">☢️ Zona de Limpieza</h3>
                    <p style="font-size:0.9rem;">Solo usar si necesitas reiniciar el sistema completamente a cero.</p>
                    <form method="POST"
                        onsubmit="return confirm('¿ESTÁS SEGURO? Se borrarán todos los datos permanentemente.');">
                        <input type="hidden" name="action" value="wipe_data">
                        <button type="submit" class="btn btn-danger" style="width:100%">🗑️ Borrar Todo (Reset)</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1rem; background: #f8fafc;">
                    <h3>ℹ️ ¿Cómo funciona la "Fusión"?</h3>
                    <ul style="font-size: 0.9rem; padding-left: 1.2rem; color: #475569;">
                        <li><strong>Estructura:</strong> Primero lee el SQL y verifica que las columnas coincidan con tu
                            sistema. Si no coinciden, cancela todo para evitar daños.</li>
                        <li><strong>Datos:</strong> Usa la técnica <code>INSERT OR IGNORE</code>. Intenta insertar el
                            dato; si el ID ya está ocupado, simplemente pasa al siguiente sin dar error y sin borrar el
                            dato viejo.</li>
                        <li><strong>Archivos:</strong> Descomprime el ZIP y busca automáticamente qué PDF pertenece a
                            qué documento nuevo.</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</body>

</html>