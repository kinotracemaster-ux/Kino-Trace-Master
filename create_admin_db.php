<?php
// create_admin_db.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

try {
    $code = 'admin';
    echo "Creating admin structure...\n";

    // Manually create directory and empty DB file to bypass open_client_db check
    $clientDir = CLIENTS_DIR . "/$code";
    if (!is_dir($clientDir)) {
        mkdir($clientDir, 0777, true);
    }

    $dbFile = "$clientDir/$code.db";
    if (!file_exists($dbFile)) {
        // Create an empty SQLite database file
        $pdo = new PDO("sqlite:$dbFile");
        // We just need the file to exist so open_client_db doesn't throw
        unset($pdo);
        echo "Created empty admin.db file.\n";
    }

    // Check if it exists in central
    $stmt = $centralDb->prepare('SELECT COUNT(*) FROM control_clientes WHERE codigo = ?');
    $stmt->execute([$code]);
    if ($stmt->fetchColumn() > 0) {
        echo "Admin client already exists in central DB.\n";
    } else {
        // Create client structure (this will run CREATE TABLEs)
        create_client_structure(
            $code,
            'Administrador',
            password_hash('admin', PASSWORD_DEFAULT),
            'Admin Panel',
            '#000000',
            '#ffffff'
        );
        echo "Created client structure for admin.\n";
    }

    // Now copy to database_initial
    $src = CLIENTS_DIR . "/$code";
    $dst = BASE_DIR . "/database_initial/$code";

    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }

    if (file_exists("$src/$code.db")) {
        copy("$src/$code.db", "$dst/$code.db");
        echo "Copied admin.db to database_initial.\n";
    }

    echo "Done.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
