<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$files = [
    'doc_698368964d7cc8.63656584.pdf',
    'doc_698368a7d3d5b0.07977079.pdf'
];

foreach ($files as $f) {
    if (empty($f))
        continue;
    $stmt = $pdo->prepare("SELECT * FROM documentos WHERE ruta_archivo LIKE ?");
    $stmt->execute(["%$f%"]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "File: $f - Found: " . ($doc ? "YES (" . $doc['ruta_archivo'] . ")" : "NO") . "\n";
}
