<?php
/**
 * Diagnóstico de Resaltado de PDF
 * 
 * Este archivo ayuda a debuggear por qué algunos PDFs no resaltan términos.
 * Acceso: modules/resaltar/debug_highlighting.php?doc=ID&term=TERMINO
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/logger.php';
require_once __DIR__ . '/../../debug_config.php';

// Solo para usuario autenticado
if (!isset($_SESSION['client_code'])) {
    die('No autenticado');
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// Obtener parámetros
$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

if ($documentId <= 0) {
    die('ID de documento inválido. Usa: ?doc=123&term=texto');
}

// Obtener documento
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

// Construir ruta del PDF
$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$rutaArchivo = $document['ruta_archivo'];

$possiblePaths = [
    $uploadsDir . $rutaArchivo,
    $uploadsDir . $document['tipo'] . '/' . $rutaArchivo,
    $uploadsDir . $document['tipo'] . '/' . basename($rutaArchivo),
];

$pdfPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $pdfPath = $path;
        break;
    }
}

if (!$pdfPath) {
    echo "<pre>";
    echo "❌ PDF NO ENCONTRADO\n\n";
    echo "Rutas probadas:\n";
    foreach ($possiblePaths as $path) {
        echo "  - $path\n";
    }
    echo "</pre>";
    exit;
}

// Extraer texto
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #00ff00; }
h1,h2 { color: #00ffff; }
.good { color: #00ff00; }
.bad { color: #ff0000; }
.info { color: #ffff00; }
pre { background: #2a2a2a; padding: 15px; border-radius: 8px; overflow-x: auto; }
mark { background: yellow; color: black; padding: 2px; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; }
table td,th { border: 1px solid #444; padding: 8px; text-align: left; }
</style></head><body>";

echo "<h1>🔍 Diagnóstico de Resaltado de PDF</h1>";

// INFO del documento
echo "<h2>📄 Información del Documento</h2>";
echo "<table>";
echo "<tr><th>Campo</th><th>Valor</th></tr>";
echo "<tr><td>ID</td><td>{$document['id']}</td></tr>";
echo "<tr><td>Tipo</td><td>{$document['tipo']}</td></tr>";
echo "<tr><td>Número</td><td>{$document['numero']}</td></tr>";
echo "<tr><td>Ruta en BD</td><td>{$document['ruta_archivo']}</td></tr>";
echo "<tr><td>Ruta física</td><td class='good'>$pdfPath</td></tr>";
echo "<tr><td>Tamaño archivo</td><td>" . number_format(filesize($pdfPath) / 1024, 2) . " KB</td></tr>";
echo "</table>";

// TÉRMINO DE BÚSQUEDA
echo "<h2>🔎 Término de Búsqueda</h2>";
if (empty($searchTerm)) {
    echo "<p class='bad'>⚠️ NO SE PROPORCIONÓ TÉRMINO DE BÚSQUEDA</p>";
    echo "<p class='info'>Usa: ?doc={$documentId}&term=tu_termino</p>";
} else {
    echo "<table>";
    echo "<tr><td>Término</td><td class='good'><code>$searchTerm</code></td></tr>";
    echo "<tr><td>Longitud</td><td>" . strlen($searchTerm) . " caracteres</td></tr>";
    echo "</table>";
}

// EXTRACCIÓN DE TEXTO
echo "<h2>📝 Extracción de Texto</h2>";

try {
    Logger::info('Diagnóstico de resaltado iniciado', [
        'doc_id' => $documentId,
        'search_term' => $searchTerm,
        'pdf_path' => $pdfPath
    ]);

    $extractedText = extract_text_from_pdf($pdfPath);

    if (empty($extractedText)) {
        echo "<p class='bad'>❌ NO SE PUDO EXTRAER TEXTO</p>";
        echo "<p>El PDF puede estar protegido, ser una imagen escaneada, o estar corrupto.</p>";

        Logger::error('No se pudo extraer texto del PDF', [
            'doc_id' => $documentId,
            'pdf_path' => $pdfPath
        ]);
    } else {
        $textLength = strlen($extractedText);
        $wordCount = str_word_count($extractedText);

        echo "<table>";
        echo "<tr><td>Estado</td><td class='good'>✅ Texto extraído correctamente</td></tr>";
        echo "<tr><td>Caracteres</td><td>" . number_format($textLength) . "</td></tr>";
        echo "<tr><td>Palabras</td><td>" . number_format($wordCount) . "</td></tr>";
        echo "</table>";

        // BÚSQUEDA DE COINCIDENCIAS
        if (!empty($searchTerm)) {
            echo "<h2>🎯 Búsqueda de Coincidencias</h2>";

            $foundPositions = [];
            $pos = 0;
            while (($pos = stripos($extractedText, $searchTerm, $pos)) !== false) {
                $foundPositions[] = $pos;
                $pos += strlen($searchTerm);
            }

            $matchCount = count($foundPositions);

            if ($matchCount > 0) {
                echo "<p class='good'>✅ ENCONTRADAS {$matchCount} COINCIDENCIAS</p>";

                echo "<h3>Primeras 10 coincidencias con contexto:</h3>";
                foreach (array_slice($foundPositions, 0, 10) as $i => $position) {
                    $start = max(0, $position - 60);
                    $end = min($textLength, $position + strlen($searchTerm) + 60);
                    $snippet = substr($extractedText, $start, $end - $start);

                    // Resaltar la coincidencia
                    $snippet = str_ireplace($searchTerm, "<mark>$searchTerm</mark>", $snippet);

                    echo "<pre>Coincidencia " . ($i + 1) . " (posición $position):\n";
                    echo ($start > 0 ? '...' : '') . htmlspecialchars_decode($snippet) . ($end < $textLength ? '...' : '');
                    echo "</pre>";
                }

                Logger::info('Coincidencias encontradas', [
                    'doc_id' => $documentId,
                    'search_term' => $searchTerm,
                    'match_count' => $matchCount
                ]);

            } else {
                echo "<p class='bad'>❌ NO SE ENCONTRARON COINCIDENCIAS</p>";

                echo "<h3>🔧 Sugerencias:</h3>";
                echo "<ul>";
                echo "<li>Verifica que el término esté escrito correctamente</li>";
                echo "<li>El PDF puede tener codificación especial de caracteres</li>";
                echo "<li>Intenta buscar palabras más cortas o comunes</li>";
                echo "<li>El texto puede estar en otra codificación (UTF-8, Latin1, etc)</li>";
                echo "</ul>";

                // Muestra muestra del texto para ayudar
                echo "<h3>📄 Primeros 500 caracteres del texto extraído:</h3>";
                echo "<pre>" . htmlspecialchars(substr($extractedText, 0, 500)) . "...</pre>";

                Logger::warning('No se encontraron coincidencias', [
                    'doc_id' => $documentId,
                    'search_term' => $searchTerm,
                    'text_sample' => substr($extractedText, 0, 200)
                ]);
            }
        }

        // PREVIEW DEL TEXTO
        echo "<h2>📄 Preview del Texto Completo (primeros 2000 caracteres)</h2>";
        echo "<pre>" . htmlspecialchars(substr($extractedText, 0, 2000)) . "...</pre>";
    }

} catch (Exception $e) {
    echo "<p class='bad'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    Logger::exception($e, [
        'doc_id' => $documentId,
        'pdf_path' => $pdfPath
    ]);
}

// RESUMEN FINAL
echo "<h2>📊 Resumen y Recomendaciones</h2>";

if (isset($matchCount) && $matchCount > 0) {
    echo "<p class='good'>✅ El resaltado DEBERÍA funcionar correctamente</p>";
    echo "<p>Si no está funcionando en el visor, el problema puede estar en:</p>";
    echo "<ul>";
    echo "<li>JavaScript: La librería mark.js no está cargando</li>";
    echo "<li>CSS: Los estilos de resaltado no se están aplicando</li>";
    echo "<li>Timing: El resaltado se ejecuta antes de que cargue el texto</li>";
    echo "</ul>";
} else if (isset($extractedText) && !empty($extractedText)) {
    echo "<p class='info'>⚠️ Texto extraído pero sin coincidencias</p>";
    echo "<p>Prueba con otros términos de búsqueda presentes en el texto.</p>";
} else {
    echo "<p class='bad'>❌ No se puede resaltar porque no hay texto extraído</p>";
    echo "<p>Posibles soluciones:</p>";
    echo "<ul>";
    echo "<li>Verificar si pdftotext está instalado</li>";
    echo "<li>Intentar con la biblioteca Smalot PDF Parser</li>";
    echo "<li>Si es PDF escaneado, necesita OCR</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p>💡 <strong>Tip:</strong> Revisa los logs en <code>clients/logs/app.log</code> para más detalles</p>";
echo "<p><a href='viewer.php?doc={$documentId}&term=" . urlencode($searchTerm) . "' style='color: #00ffff;'>⬅️ Volver al visor</a></p>";

echo "</body></html>";
?>