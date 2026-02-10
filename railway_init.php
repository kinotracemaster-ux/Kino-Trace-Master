<?php
/**
 * Script de Inicialización Standalone para Railway
 * Este script NO carga ninguna dependencia de la aplicación
 * para evitar errores de base de datos faltante
 */

// Desactivar todos los errores excepto los críticos
error_reporting(E_ERROR | E_PARSE);

// Headers para evitar timeout
header('Content-Type: text/html; charset=utf-8');
set_time_limit(300);

?>
<!DOCTYPE html>
<html>

<head>
    <title>Inicialización Railway - KINO-TRACE</title>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
        }

        .content {
            padding: 30px;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
            margin: 10px 5px;
        }

        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }

        .btn-verify {
            background: #48bb78;
        }

        .btn-verify:hover {
            background: #38a169;
        }

        .output {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            line-height: 1.6;
        }

        .success {
            color: #22543d;
            background: #c6f6d5;
            border-color: #9ae6b4;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
        }

        .error {
            color: #742a2a;
            background: #fed7d7;
            border-color: #fc8181;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
        }

        .warning {
            color: #744210;
            background: #feebc8;
            border-color: #f6ad55;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
        }

        .info {
            color: #2c5282;
            background: #bee3f8;
            border-color: #90cdf4;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Inicialización de Base de Datos</h1>
            <p>Railway - KINO-TRACE Master</p>
        </div>
        <div class="content">

            <?php if (!isset($_POST['action'])): ?>

                <div class="warning">
                    <strong>⚠️ Primera Vez:</strong> Este script debe ejecutarse UNA VEZ después del primer despliegue en
                    Railway.
                </div>

                <p style="margin: 20px 0;">
                    Este proceso copiará las bases de datos iniciales desde <code>database_initial/</code>
                    al volumen persistente montado en <code>clients/</code>.
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="init">
                    <button type="submit" name="execute" class="btn">
                        ✅ Ejecutar Inicialización
                    </button>
                    <button type="submit" name="verify" class="btn btn-verify">
                        🔍 Verificar Estado
                    </button>
                </form>

            <?php else: ?>

                <div class="output">
                    <?php
                    $baseDir = __DIR__;
                    $sourceDbCentral = $baseDir . '/database_initial/central.db';
                    $sourceDbLogs = $baseDir . '/database_initial/logs.db';
                    $targetDbCentral = $baseDir . '/clients/central.db';
                    $targetDbLogs = $baseDir . '/clients/logs/logs.db';
                    $clientsDir = $baseDir . '/clients';
                    $logsDir = $baseDir . '/clients/logs';

                    echo "=== Inicialización Railway - KINO-TRACE ===\n\n";
                    echo "Directorio base: $baseDir\n\n";

                    if (isset($_POST['verify'])) {
                        echo "--- MODO VERIFICACIÓN ---\n\n";

                        echo "📁 Estructura de directorios:\n";
                        echo "  clients/: " . (is_dir($clientsDir) ? "✅ Existe" : "❌ No existe") . "\n";
                        echo "  clients/logs/: " . (is_dir($logsDir) ? "✅ Existe" : "❌ No existe") . "\n\n";

                        echo "📄 Bases de datos en volumen:\n";
                        echo "  central.db: ";
                        if (file_exists($targetDbCentral)) {
                            echo "✅ Existe (" . number_format(filesize($targetDbCentral)) . " bytes)\n";
                        } else {
                            echo "❌ No existe\n";
                        }

                        echo "  logs/logs.db: ";
                        if (file_exists($targetDbLogs)) {
                            echo "✅ Existe (" . number_format(filesize($targetDbLogs)) . " bytes)\n";
                        } else {
                            echo "❌ No existe\n";
                        }

                        echo "\n📦 Archivos fuente disponibles:\n";
                        echo "  database_initial/central.db: ";
                        if (file_exists($sourceDbCentral)) {
                            echo "✅ Disponible (" . number_format(filesize($sourceDbCentral)) . " bytes)\n";
                        } else {
                            echo "❌ No disponible\n";
                        }

                        echo "  database_initial/logs.db: ";
                        if (file_exists($sourceDbLogs)) {
                            echo "✅ Disponible (" . number_format(filesize($sourceDbLogs)) . " bytes)\n";
                        } else {
                            echo "❌ No disponible\n";
                        }

                        echo "\n✨ Verificación completada\n";

                    } else {
                        echo "--- MODO INICIALIZACIÓN ---\n\n";

                        // Crear directorios
                        echo "[1/5] Creando estructura de directorios...\n";
                        if (!is_dir($clientsDir)) {
                            if (mkdir($clientsDir, 0777, true)) {
                                echo "  ✅ Directorio clients/ creado\n";
                            } else {
                                echo "  ❌ Error creando clients/\n";
                            }
                        } else {
                            echo "  ⏭ Directorio clients/ ya existe\n";
                        }

                        if (!is_dir($logsDir)) {
                            if (mkdir($logsDir, 0777, true)) {
                                echo "  ✅ Directorio clients/logs/ creado\n";
                            } else {
                                echo "  ❌ Error creando clients/logs/\n";
                            }
                        } else {
                            echo "  ⏭ Directorio clients/logs/ ya existe\n";
                        }

                        echo "\n[2/5] Verificando archivos fuente...\n";
                        if (!file_exists($sourceDbCentral)) {
                            echo "  ❌ ERROR: database_initial/central.db no encontrado\n";
                        } else {
                            echo "  ✅ central.db fuente disponible\n";
                        }

                        if (!file_exists($sourceDbLogs)) {
                            echo "  ❌ ERROR: database_initial/logs.db no encontrado\n";
                        } else {
                            echo "  ✅ logs.db fuente disponible\n";
                        }

                        // Copiar central.db
                        echo "\n[3/5] Copiando central.db...\n";
                        if (file_exists($targetDbCentral)) {
                            echo "  ⏭ central.db ya existe en el volumen (no sobrescribir)\n";
                        } elseif (file_exists($sourceDbCentral)) {
                            if (copy($sourceDbCentral, $targetDbCentral)) {
                                chmod($targetDbCentral, 0666);
                                echo "  ✅ central.db copiado exitosamente\n";
                                echo "     Tamaño: " . number_format(filesize($targetDbCentral)) . " bytes\n";
                            } else {
                                echo "  ❌ Error copiando central.db\n";
                            }
                        }

                        // Copiar logs.db
                        echo "\n[4/5] Copiando logs.db...\n";
                        if (file_exists($targetDbLogs)) {
                            echo "  ⏭ logs.db ya existe en el volumen (no sobrescribir)\n";
                        } elseif (file_exists($sourceDbLogs)) {
                            if (copy($sourceDbLogs, $targetDbLogs)) {
                                chmod($targetDbLogs, 0666);
                                echo "  ✅ logs.db copiado exitosamente\n";
                                echo "     Tamaño: " . number_format(filesize($targetDbLogs)) . " bytes\n";
                            } else {
                                echo "  ❌ Error copiando logs.db\n";
                            }
                        }

                        // Verificar permisos
                        echo "\n[5/5] Verificando permisos...\n";
                        echo "  clients/: " . (is_writable($clientsDir) ? "✅ Escribible" : "⚠️ Solo lectura") . "\n";
                        if (file_exists($targetDbCentral)) {
                            echo "  central.db: " . (is_writable($targetDbCentral) ? "✅ Escribible" : "⚠️ Solo lectura") . "\n";
                        }

                        echo "\n" . str_repeat("=", 50) . "\n";
                        echo "✨ INICIALIZACIÓN COMPLETADA\n";
                        echo str_repeat("=", 50) . "\n";
                    }
                    ?>
                </div>

                <div class="info" style="margin-top: 20px;">
                    <strong>📝 Próximos pasos:</strong><br>
                    1. Cierra esta página<br>
                    2. Ve a la página principal: <a href="/" style="color: #2c5282; font-weight: bold;">Ir a la
                        aplicación</a><br>
                    3. Intenta hacer login con tus credenciales
                </div>

            <?php endif; ?>

        </div>
    </div>
</body>

</html>