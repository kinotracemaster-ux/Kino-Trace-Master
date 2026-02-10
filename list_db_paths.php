<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$stmt = $pdo->query("SELECT ruta_archivo FROM documentos LIMIT 20");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['ruta_archivo'] . "\n";
}
