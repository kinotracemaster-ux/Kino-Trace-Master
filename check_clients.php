<?php
// Script temporal para verificar clientes en la base de datos local
$centralDb = __DIR__ . '/clients/central.db';

if (!file_exists($centralDb)) {
    die("❌ No existe clients/central.db\n");
}

try {
    $pdo = new PDO('sqlite:' . $centralDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== Clientes en la Base de Datos Local ===\n\n";

    $stmt = $pdo->query('SELECT codigo, nombre, activo FROM control_clientes ORDER BY codigo');
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($clientes)) {
        echo "⚠️  No hay clientes registrados en la base de datos local.\n";
    } else {
        echo "Total de clientes: " . count($clientes) . "\n\n";
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
