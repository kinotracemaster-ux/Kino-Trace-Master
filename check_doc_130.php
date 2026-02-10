<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$stmt = $pdo->prepare("SELECT * FROM documentos WHERE id = ?");
$stmt->execute([130]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($doc);

if ($doc) {
    $expectedPath = __DIR__ . '/clients/kino/' . $doc['ruta_archivo'];
    echo "Expected Path: $expectedPath\n";
    echo "Exists: " . (file_exists($expectedPath) ? 'YES' : 'NO') . "\n";
}
