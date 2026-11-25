<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once '../../config.php';
require_once '../../vendor/autoload.php';
require_once __DIR__ . '/../../helpers/tenant.php';


header('Content-Type: application/json');
require_once __DIR__ . '/../../helpers/session_init.php';

// ⭐ SOLO PROCESAR SI VIENE DE BÚSQUEDA VORAZ
if (!isset($_SERVER['HTTP_X_VORAZ_MODE']) || $_SERVER['HTTP_X_VORAZ_MODE'] !== 'true') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado']);
    exit;
}

try {
    if (!isset($_SESSION['client_code'])) {
        throw new Exception('No autenticado');
    }

    $clientCode = $_SESSION['client_code'];

    // M8: Limpieza automática de archivos temporales > 1 hora
    $tempDir = CLIENTS_DIR . "/{$clientCode}/uploads/temp/";
    if (is_dir($tempDir)) {
        $oldFiles = glob($tempDir . 'unified_voraz_*.pdf');
        $cutoff = time() - 3600; // 1 hora
        foreach ($oldFiles as $oldFile) {
            if (is_file($oldFile) && filemtime($oldFile) < $cutoff) {
                @unlink($oldFile);
            }
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['documents']) || !isset($input['codes'])) {
        throw new Exception('Datos incompletos');
    }

    $documents = $input['documents'];
    $codes = $input['codes'];

    // Resolver la ruta de cada documento (resolve_pdf_path acepta ruta absoluta/relativa y sufijos antiguos)
    $rutas = [];
    foreach ($documents as $doc) {
        $filePath = resolve_pdf_path($clientCode, $doc);
        if (!$filePath || !file_exists($filePath)) {
            error_log("Archivo no encontrado para PDF unificado: " . ($doc['ruta_archivo'] ?? 'DESCONOCIDO'));
            continue;
        }
        $rutas[] = $filePath;
    }

    // Guardar PDF unificado
    $timestamp = date('YmdHis');
    $fileName = "unified_voraz_{$timestamp}.pdf";
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
    $tempDir = $uploadsDir . "temp/";
    $outputPath = $tempDir . $fileName;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    // pdfunite lee todos los PDFs (FPDI descartaba en silencio los de compresión moderna)
    require_once __DIR__ . '/../../helpers/unir_pdfs.php';
    // Solo las hojas necesarias: cada código sale en UNA hoja (la que cubre más códigos pendientes)
    $listaCodigos = [];
    foreach ((array) $codes as $c) {
        foreach (preg_split('/[,;\t\n\r]+/', (string) $c, -1, PREG_SPLIT_NO_EMPTY) as $x) {
            $listaCodigos[] = $x;
        }
    }
    $union = kt_unir_pdfs_minimo($rutas, $listaCodigos, $outputPath);
    $pageCount = $union['paginas'];
    $omitidos = count($union['omitidos']) + (count($documents) - count($rutas));

    if ($pageCount === 0) {
        throw new Exception('No se pudieron procesar páginas de los documentos seleccionados');
    }



    // Return path relative to web root or module
    // Viewer expects a path it can load/download
    // Enlace firmado (los PDFs ya no se sirven como archivos estáticos)
    require_once __DIR__ . '/../../helpers/pdf_acceso.php';
    $downloadUrl = kt_pdf_url($outputPath, KT_PDF_TTL_INTERNO, '../../', true);

    // The viewer currently takes absolute filesystem path via 'file' param in some contexts (legacy) 
    // BUT the new viewer logic in our plan uses 'file' as path relative to uploads usually?
    // Wait, viewer.php says: $relativePath = str_replace($uploadsDir, '', $pdfPath);
    // So if we pass 'temp/filename.pdf' as file param, viewer might find it if it looks in uploads.

    echo json_encode([
        'success' => true,
        'unified_pdf_path' => "temp/{$fileName}", // Relative to uploads dir
        'download_url' => $downloadUrl,
        'page_count' => $pageCount,
        'document_count' => count($documents),
        'omitted_count' => $omitidos,
        'original_pages' => $union['originales'],
        'uncovered_codes' => $union['sin_cubrir']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
