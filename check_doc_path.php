<?php
$dbPath = __DIR__ . '/clients/kino/kino.db';
$pdo = new PDO("sqlite:$dbPath");
$filename = '1768848304_100_1750882954_MONEDERO__032025001171379-1_JUNIO_2025.pdf';
// The filename in DB might include the path or just be the basename in the path column
$stmt = $pdo->prepare("SELECT * FROM documentos WHERE ruta_archivo LIKE ?");
$stmt->execute(["%$filename%"]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($doc);

if ($doc) {
    echo "DB Path: " . $doc['ruta_archivo'] . "\n";
} else {
    echo "File not found in DB\n";
}
