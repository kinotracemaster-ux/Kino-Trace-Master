<?php
// Verificar base de datos en database_initial/
$dbPath = __DIR__ . '/database_initial/central.db';

if (!file_exists($dbPath)) {
    die("❌ No existe database_initial/central.db\n");
}

echo "📁 Archivo: database_initial/central.db\n";
echo "📊 Tamaño: " . number_format(filesize($dbPath)) . " bytes\n\n";

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query('SELECT codigo, nombre, activo FROM control_clientes ORDER BY codigo');
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== CLIENTES EN database_initial/central.db ===\n\n";

    if (empty($clientes)) {
        echo "❌ BASE DE DATOS VACÍA - NO HAY CLIENTES\n";
    } else {
        echo "✅ Total de clientes: " . count($clientes) . "\n\n";
        foreach ($clientes as $cliente) {
            $status = $cliente['activo'] ? '✅ Activo' : '❌ Inactivo';
            echo "- Código: {$cliente['codigo']}\n";
            echo "  Nombre: {$cliente['nombre']}\n";
            echo "  Estado: $status\n\n";
        }
    }

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage() . "\n");
}
