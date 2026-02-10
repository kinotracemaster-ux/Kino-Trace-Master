<!DOCTYPE html>
<html>

<head>
    <title>Railway - Inicialización de Base de Datos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
        }

        .btn {
            background: #4CAF50;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
        }

        .btn:hover {
            background: #45a049;
        }

        .btn-danger {
            background: #f44336;
        }

        .btn-danger:hover {
            background: #da190b;
        }

        .output {
            background: #f9f9f9;
            padding: 15px;
            border-left: 4px solid #4CAF50;
            margin-top: 20px;
            white-space: pre-wrap;
            font-family: monospace;
        }

        .warning {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🚀 Inicialización de Base de Datos - Railway</h1>

        <div class="warning">
            <strong>⚠️ Importante:</strong> Esta página ejecutará el script de inicialización.
            Solo debe ejecutarse UNA VEZ después del primer despliegue.
        </div>

        <p>Esta herramienta copiará las bases de datos iniciales al volumen persistente de Railway.</p>

        <form method="POST" action="">
            <input type="hidden" name="action" value="init">
            <button type="submit" class="btn" name="execute" value="1">
                ✅ Ejecutar Inicialización
            </button>
            <button type="submit" class="btn btn-danger" name="verify" value="1">
                🔍 Solo Verificar Estado
            </button>
        </form>

        <?php
        if (isset($_POST['execute']) || isset($_POST['verify'])) {
            echo '<div class="output">';

            if (isset($_POST['verify'])) {
                echo "=== Verificación de Estado ===\n\n";

                $centralDb = __DIR__ . '/clients/central.db';
                $logsDb = __DIR__ . '/clients/logs/logs.db';

                echo "📁 Directorio clients: ";
                echo is_dir(__DIR__ . '/clients') ? "✅ Existe\n" : "❌ No existe\n";

                echo "📄 central.db: ";
                echo file_exists($centralDb) ? "✅ Existe (" . filesize($centralDb) . " bytes)\n" : "❌ No existe\n";

                echo "📄 logs.db: ";
                echo file_exists($logsDb) ? "✅ Existe (" . filesize($logsDb) . " bytes)\n" : "❌ No existe\n";

                echo "\n📦 Archivos iniciales disponibles:\n";
                echo "central.db inicial: ";
                echo file_exists(__DIR__ . '/database_initial/central.db') ? "✅ Disponible\n" : "❌ No disponible\n";
                echo "logs.db inicial: ";
                echo file_exists(__DIR__ . '/database_initial/logs.db') ? "✅ Disponible\n" : "❌ No disponible\n";

            } else {
                // Ejecutar inicialización
                ob_start();
                include(__DIR__ . '/init_volume.php');
                $output = ob_get_clean();
                echo $output;
            }

            echo '</div>';
        }
        ?>

        <hr style="margin: 30px 0;">

        <h3>📚 Información</h3>
        <ul>
            <li><strong>Volumen montado en:</strong> /var/www/html/clients</li>
            <li><strong>Bases de datos:</strong> central.db, logs/logs.db</li>
            <li><strong>Origen:</strong> database_initial/</li>
        </ul>

        <p><small>Después de la inicialización exitosa, puedes eliminar esta página.</small></p>
    </div>
</body>

</html>