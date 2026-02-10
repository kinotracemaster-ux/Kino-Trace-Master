<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$row = $pdo->query("SELECT ruta_archivo FROM documentos WHERE ruta_archivo IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Sample Path: " . $row['ruta_archivo'] . "\n";
