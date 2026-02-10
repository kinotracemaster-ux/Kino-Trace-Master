<?php
/**
 * Script de migración inicial para crear el cliente principal
 * Ejecutar una sola vez para crear el usuario inicial
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

echo "=== KINO-TRACE: Migración Inicial ===\n\n";

// Verificar si ya existe un cliente
$stmt = $centralDb->query('SELECT COUNT(*) as total FROM control_clientes');
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result['total'] > 0) {
    echo "⚠️  Ya existen clientes en la base de datos.\n";
    echo "Total de clientes registrados: {$result['total']}\n\n";

    // Mostrar clientes existentes
    $stmt = $centralDb->query('SELECT codigo, nombre, activo FROM control_clientes ORDER BY codigo');
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Clientes registrados:\n";
    foreach ($clientes as $cliente) {
        $status = $cliente['activo'] ? '✅ Activo' : '❌ Inactivo';
        echo "  - {$cliente['codigo']} ({$cliente['nombre']}) $status\n";
    }

    echo "\n¿Deseas crear un nuevo cliente? (Escribe 'si' y presiona Enter, o presiona Enter para salir)\n";
    $respuesta = trim(fgets(STDIN));

    if (strtolower($respuesta) !== 'si') {
        echo "\nMigración cancelada.\n";
        exit(0);
    }
    echo "\n";
}

// Crear cliente principal 'kino'
$codigo = 'kino';
$nombre = 'KINO MASTER';
$password = 'kino123'; // Contraseña por defecto
$titulo = 'KINO-TRACE Master Dashboard';
$colorP = '#6366f1'; // Indigo
$colorS = '#ec4899'; // Pink

echo "Creando cliente principal...\n";
echo "  Código: $codigo\n";
echo "  Nombre: $nombre\n";
echo "  Contraseña: $password\n";
echo "  Título: $titulo\n\n";

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    create_client_structure($codigo, $nombre, $passwordHash, $titulo, $colorP, $colorS);

    echo "✅ Cliente '$codigo' creado exitosamente!\n\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 CREDENCIALES DE ACCESO\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  Usuario:     $codigo\n";
    echo "  Contraseña:  $password\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    echo "⚠️  IMPORTANTE: Cambia la contraseña después del primer login.\n\n";
    echo "🚀 Puedes acceder ahora en: http://localhost:8080/ (local)\n";
    echo "   o en tu URL de Railway si está desplegado.\n\n";

} catch (Exception $e) {
    echo "❌ Error creando cliente: " . $e->getMessage() . "\n";
    exit(1);
}
