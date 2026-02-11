<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once '../../config.php';
require_once '../../vendor/autoload.php';
require_once __DIR__ . '/../../helpers/tenant.php';

use setasign\Fpdi\Tcpdf\Fpdi;

header('Content-Type: application/json');
session_start();

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

    // Crear PDF unificado
    $pdf = new Fpdi();
    $pdf->setPrintHeader(false); // Disable TCPDF header
    $pdf->setPrintFooter(false); // Disable TCPDF footer
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    $pageCount = 0;

    // Base uploads directory
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

    // Combinar todos los PDFs
    foreach ($documents as $doc) {
        // Use centralized robust path resolution
        // doc must have keys expected by resolve_pdf_path (id, tipo, fecha, ruta_archivo, numero)
        $filePath = resolve_pdf_path($clientCode, $doc);

        if (!$filePath || !file_exists($filePath)) {
            error_log("Archivo no encontrado para PDF unificado: " . ($doc['ruta_archivo'] ?? 'DESCONOCIDO'));
            continue;
        }

        try {
            $pageTotal = $pdf->setSourceFile($filePath);

            for ($i = 1; $i <= $pageTotal; $i++) {
                $tplId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tplId);

                // Add page preserving orientation
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);

                $pageCount++;
            }
        } catch (Exception $e) {
            error_log("Error procesando PDF {$filePath}: " . $e->getMessage());
            continue;
        }
    }

    if ($pageCount === 0) {
        throw new Exception('No se pudieron procesar páginas de los documentos seleccionados');
    }

    // Guardar PDF unificado
    $timestamp = date('YmdHis');
    $fileName = "unified_voraz_{$timestamp}.pdf";
    $tempDir = $uploadsDir . "temp/";
    $outputPath = $tempDir . $fileName;

    // Crear directorio si no existe
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $pdf->Output($outputPath, 'F');

    // Return path relative to web root or module
    // Viewer expects a path it can load/download
    // Relative to modules/resaltar/: ../../clients/CODE/uploads/temp/FILE
    $downloadUrl = "../../clients/{$clientCode}/uploads/temp/{$fileName}";

    // The viewer currently takes absolute filesystem path via 'file' param in some contexts (legacy) 
    // BUT the new viewer logic in our plan uses 'file' as path relative to uploads usually?
    // Wait, viewer.php says: $relativePath = str_replace($uploadsDir, '', $pdfPath);
    // So if we pass 'temp/filename.pdf' as file param, viewer might find it if it looks in uploads.

    echo json_encode([
        'success' => true,
        'unified_pdf_path' => "temp/{$fileName}", // Relative to uploads dir
        'download_url' => $downloadUrl,
        'page_count' => $pageCount,
        'document_count' => count($documents)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
