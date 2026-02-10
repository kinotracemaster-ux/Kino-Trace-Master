<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$stmt = $pdo->query("SELECT ruta_archivo FROM documentos WHERE ruta_archivo NOT LIKE 'uploads/client_kino%' LIMIT 50");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['ruta_archivo'] . "\n";
}
