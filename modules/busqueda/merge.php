<?php
/**
 * Unificación de PDFs con Resaltado Múltiple
 * 
 * Re-implementación segura usando Smalot Parser para filtrar páginas.
 * Soporta múltiples términos de búsqueda.
 */

use setasign\Fpdi\TcpdfFpdi;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../vendor/autoload.php';

session_start();

if (!isset($_SESSION['client_code'])) {
    die("Acceso denegado");
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

$ids = $_GET['ids'] ?? '';
$term = $_GET['term'] ?? ''; // Can be string or array if passed as term[]
$termsParam = $_GET['terms'] ?? '';

$searchTerms = [];

if ($termsParam) {
    if (is_array($termsParam)) {
        $searchTerms = $termsParam;
    } else {
        $searchTerms = explode(',', $termsParam);
    }
} elseif ($term) {
    if (is_array($term)) {
        $searchTerms = $term;
    } else {
        $searchTerms = [$term];
    }
}

// Limpiar términos vacíos y decodificar
$searchTerms = array_map(function ($t) {
    return urldecode(trim($t)); }, $searchTerms);
$searchTerms = array_filter($searchTerms);

if (!$ids || empty($searchTerms)) {
    die("Faltan parámetros (ids o terms)");
}

$idList = explode(',', $ids);
$cleanIds = array_map('intval', $idList);
$inQuery = implode(',', $cleanIds);

// Obtener rutas de archivos
$stmt = $db->query("SELECT id, tipo, numero, fecha, ruta_archivo FROM documentos WHERE id IN ($inQuery) ORDER BY fecha DESC");
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($docs)) {
    die("No se encontraron documentos");
}

class MyPDF extends TcpdfFpdi
{
}

$pdf = new MyPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0);

$totalPagesAdded = 0;

foreach ($docs as $doc) {
    $fullPath = CLIENTS_DIR . '/' . $clientCode . '/uploads/' . $doc['ruta_archivo'];

    if (!file_exists($fullPath)) {
        continue;
    }

    try {
        // 1. Identificar páginas relevantes usando Smalot Parser
        $relevantPages = [];

        if (class_exists('Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdfParsed = $parser->parseFile($fullPath);
                $pages = $pdfParsed->getPages();

                $pIndex = 1;
                foreach ($pages as $page) {
                    $pageText = $page->getText();
                    $pageTextLower = mb_strtolower($pageText);

                    foreach ($searchTerms as $st) {
                        if (mb_strpos($pageTextLower, mb_strtolower($st)) !== false) {
                            $relevantPages[] = $pIndex;
                            break;
                        }
                    }
                    $pIndex++;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        if (empty($relevantPages)) {
            continue;
        }

        // 2. Importar páginas identificadas
        $pageCount = $pdf->setSourceFile($fullPath);

        foreach ($relevantPages as $pageNo) {
            if ($pageNo > $pageCount)
                continue;

            $tplIdx = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tplIdx);
            $orientation = ($size['w'] > $size['h']) ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['w'], $size['h']]);
            $pdf->useTemplate($tplIdx);

            // Marca de agua
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY(5, 5);
            $infoText = "Doc: {$doc['numero']} (Pág $pageNo)";
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell($pdf->GetStringWidth($infoText) + 2, 5, $infoText, 0, 0, '', true);

            $totalPagesAdded++;
        }

    } catch (Exception $e) {
        continue;
    }
}

if ($totalPagesAdded === 0) {
    die("No se encontraron páginas con los códigos buscados.");
}

$pdf->Output('busqueda_voraz_' . date('Ymd_His') . '.pdf', 'I');
