<?php
// Solo línea de comandos: desde el navegador este script no existe (ver CLAUDE.md, Seguridad)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Script de Diagnóstico y Reparación para Railway
 * Ejecutar este script desde Railway para diagnosticar y solucionar problemas con la BD
 */

header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);

?>
<!DOCTYPE html>
<html>

<head>
    <title>Diagnóstico Railway - KINO-TRACE</title>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Consolas', 'Monaco', monospace;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #16213e;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }

        h1 {
            color: #00d9ff;
            margin-bottom: 20px;
        }

        h2 {
            color: #ffa500;
            margin: 20px 0 10px;
        }

        .success {
            color: #00ff00;
        }

        .error {
            color: #ff4444;
        }

        .warning {
            color: #ffaa00;
        }

        .info {
            color: #00d9ff;
        }

        pre {
            background: #0f1419;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            margin: 10px 0;
        }

        .btn {
            background: #00d9ff;
            color: #000;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin: 10px 5px;
            font-size: 14px;
        }

        .btn:hover {
            background: #00b8d4;
        }

        .btn-danger {
            background: #ff4444;
            color: #fff;
        }

        .btn-danger:hover {
            background: #cc0000;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🔍 Diagnóstico Railway - KINO-TRACE</h1>

        <?php
        $baseDir = __DIR__;
        $sourceDbCentral = $baseDir . '/database_initial/central.db';
        $targetDbCentral = $baseDir . '/clients/central.db';
        $clientsDir = $baseDir . '/clients';

        echo "<h2>📁 Información del Sistema</h2>";
        echo "<pre>";
        echo "Directorio base: $baseDir\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "Usuario: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'N/A') . "\n";
        echo "</pre>";

        // Función recursiva para copiar directorios
        function recursive_copy($src, $dst)
        {
            $dir = opendir($src);
            if (!is_dir($dst))
                mkdir($dst, 0777, true);

            $status = true;
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..')) {
                    if (is_dir($src . '/' . $file)) {
                        if (!recursive_copy($src . '/' . $file, $dst . '/' . $file))
                            $status = false;
                    } else {
                        if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                            $status = false;
                            echo "<span class='error'>❌ Falló copiar: $file</span>\n";
                        } else {
                            chmod($dst . '/' . $file, 0666);
                            echo "<span class='success'>✅ Copiado: $file</span>\n";
                        }
                    }
                }
            }
            closedir($dir);
            return $status;
        }

        if (isset($_POST['action'])) {

            if ($_POST['action'] === 'fix') {
                echo "<h2>🔧 REPARANDO...</h2>";
                echo "<pre>";

                // 1. Verificar fuente global
                $sourceDir = $baseDir . '/database_initial';
                if (!is_dir($sourceDir)) {
                    echo "<span class='error'>❌ ERROR CRÍTICO: Directorio database_initial/ NO EXISTE</span>\n";
                } else {
                    echo "<span class='success'>✅ Directorio fuente encontrado</span>\n";
                    echo "   Copiando recursivamente...\n\n";

                    // 2. Copia recursiva
                    if (recursive_copy($sourceDir, $clientsDir)) {
                        echo "\n<span class='success'>✨ Estructura copiada exitosamente</span>\n";

                        // 3. Verificar central.db
                        try {
                            if (file_exists($targetDbCentral)) {
                                $pdo = new PDO('sqlite:' . $targetDbCentral);
                                $stmt = $pdo->query('SELECT COUNT(*) as total FROM control_clientes');
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                echo "   Clientes en central: " . $result['total'] . "\n";

                                if ($result['total'] > 0) {
                                    echo "\n<span class='success'>🎉 REPARACIÓN EXITOSA</span>\n";
                                } else {
                                    echo "\n<span class='warning'>⚠️  Base central sin clientes</span>\n";
                                }
                            }
                        } catch (PDOException $e) {
                            echo "<span class='error'>❌ Error veificando BD: {$e->getMessage()}</span>\n";
                        }

                    } else {
                        echo "\n<span class='error'>❌ Hubo errores durante la copia</span>\n";
                    }
                }


                echo "</pre>";
                echo "<p><a href='railway_fix.php' class='btn'>🔄 Volver a Diagnosticar</a></p>";

            } else {
                // DIAGNÓSTICO
                echo "<h2>🔍 DIAGNÓSTICO COMPLETO</h2>";
                echo "<pre>";

                // Verificar estructura de directorios
                echo "=== DIRECTORIOS ===\n";
                echo "clients/: " . (is_dir($clientsDir) ? "<span class='success'>✅ Existe</span>" : "<span class='error'>❌ No existe</span>") . "\n";
                if (is_dir($clientsDir)) {
                    echo "  Permisos: " . substr(sprintf('%o', fileperms($clientsDir)), -4) . "\n";
                    echo "  Escribible: " . (is_writable($clientsDir) ? "<span class='success'>✅</span>" : "<span class='error'>❌</span>") . "\n";
                }

                echo "\n=== ARCHIVOS FUENTE (database_initial/) ===\n";
                if (is_dir($baseDir . '/database_initial')) {
                    $files = scandir($baseDir . '/database_initial');
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..') {
                            $fullPath = $baseDir . '/database_initial/' . $file;
                            $size = filesize($fullPath);
                            echo "$file: " . number_format($size) . " bytes";
                            if ($size === 0) {
                                echo " <span class='warning'>⚠️  VACÍO</span>";
                            }
                            echo "\n";
                        }
                    }
                } else {
                    echo "<span class='error'>❌ Directorio database_initial/ NO EXISTE</span>\n";
                }

                echo "\n=== ARCHIVOS DESTINO (clients/) ===\n";
                echo "central.db: ";
                if (file_exists($targetDbCentral)) {
                    echo "<span class='success'>✅ Existe (" . number_format(filesize($targetDbCentral)) . " bytes)</span>\n";

                    // Verificar contenido
                    try {
                        $pdo = new PDO('sqlite:' . $targetDbCentral);
                        $stmt = $pdo->query('SELECT COUNT(*) as total FROM control_clientes');
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        echo "  Clientes: " . $result['total'] . "\n";

                        if ($result['total'] > 0) {
                            // Mostrar clientes
                            $stmt = $pdo->query('SELECT codigo, nombre FROM control_clientes LIMIT 5');
                            echo "  Ejemplos:\n";
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo "    - {$row['codigo']}: {$row['nombre']}\n";
                            }
                        }
                    } catch (PDOException $e) {
                        echo "<span class='error'>  ❌ Error leyendo BD: {$e->getMessage()}</span>\n";
                    }
                } else {
                    echo "<span class='error'>❌ NO EXISTE</span>\n";
                }

                echo "\n=== VARIABLES DE ENTORNO ===\n";
                $envVars = ['RAILWAY_VOLUME_MOUNT_PATH', 'RAILWAY_ENVIRONMENT', 'PORT'];
                foreach ($envVars as $var) {
                    $value = getenv($var);
                    echo "$var: " . ($value ?: '<span class="warning">No definida</span>') . "\n";
                }

                echo "\n=== config.php ===\n";
                if (file_exists($baseDir . '/config.php')) {
                    echo "<span class='success'>✅ Existe</span>\n";
                    // Verificar si tiene auto-init
                    $config = file_get_contents($baseDir . '/config.php');
                    if (strpos($config, 'AUTO-INICIALIZACIÓN') !== false) {
                        echo "  <span class='success'>✅ Tiene auto-inicialización</span>\n";
                    } else {
                        echo "  <span class='warning'>⚠️  No tiene auto-inicialización</span>\n";
                    }
                }

                echo "</pre>";

                // Determinar acción recomendada
                $needsRepair = false;
                $reason = "";

                if (!file_exists($targetDbCentral)) {
                    $needsRepair = true;
                    $reason = "El archivo no existe en el volumen.";
                } else {
                    // Verificar si está vacío de clientes
                    try {
                        $pdo = new PDO('sqlite:' . $targetDbCentral);
                        $stmt = $pdo->query('SELECT COUNT(*) as total FROM control_clientes');
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($result['total'] == 0) {
                            $needsRepair = true;
                            $reason = "La base de datos existe pero tiene 0 clientes.";
                        }
                    } catch (Exception $e) {
                        $needsRepair = true;
                        $reason = "Error leyendo la base de datos: " . $e->getMessage();
                    }
                }

                if ($needsRepair) {
                    echo "<h2 class='warning'>⚠️  ATENCIÓN REQUERIDA</h2>";
                    echo "<p class='warning'>$reason</p>";

                    if (file_exists($sourceDbCentral) && filesize($sourceDbCentral) > 0) {
                        echo "<p class='success'>✅ Solución disponible: Restaurar desde copia original</p>";
                        echo "<form method='POST'>";
                        echo "<input type='hidden' name='action' value='fix'>";
                        echo "<button type='submit' class='btn'>🔧 REPARAR AHORA</button>";
                        echo "</form>";
                    } else {
                        echo "<p class='error'>❌ No se puede reparar: La base de datos original no está disponible</p>";
                    }
                } else {
                    echo "<h2 class='success'>✅ BASE DE DATOS CORRECTA</h2>";
                    echo "<p>La base de datos está en el volumen y tiene clientes.</p>";
                    echo "<p><a href='/' class='btn'>🏠 Ir a la Aplicación</a></p>";

                    echo "<hr style='margin: 20px 0; border-color: #333;'>";
                    echo "<p style='font-size: 0.9em; opacity: 0.8;'>¿Problemas? Puedes forzar una reinstalación:</p>";
                    echo "<form method='POST' onsubmit='return confirm(\"¿Estás seguro? Se borrarán los datos actuales en el volumen.\");'>";
                    echo "<input type='hidden' name='action' value='fix'>";
                    echo "<button type='submit' class='btn btn-danger'>⚠️ FORZAR REINSTALACIÓN BD</button>";
                    echo "</form>";
                }
            }

        } else {
            // Pantalla inicial
            echo "<p>Ejecuta un diagnóstico para verificar el estado de la base de datos:</p>";
            echo "<form method='POST'>";
            echo "<input type='hidden' name='action' value='diagnose'>";
            echo "<button type='submit' class='btn'>🔍 EJECUTAR DIAGNÓSTICO</button>";
            echo "</form>";
        }
        ?>

    </div>
</body>

</html>