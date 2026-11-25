<?php
/**
 * Une varios PDFs en uno solo conservando la capa de texto.
 *
 * Usa `pdfunite` (poppler-utils, ya instalado en el Dockerfile): lee cualquier PDF.
 * FPDI gratuito NO puede importar PDFs con compresión moderna (PDF 1.5+, "object streams"):
 * en el PDF unificado de la búsqueda voraz esos documentos se descartaban en silencio
 * (82 de 120 en la plantilla kino) y sus códigos salían en "Faltan" aunque el documento
 * estaba elegido. FPDI queda solo como respaldo si no existe `pdfunite`.
 *
 * @param string[] $rutas  Rutas de los PDFs en el orden deseado.
 * @param string   $salida Ruta del PDF resultante.
 * @return array{paginas:int, omitidos:string[]}  omitidos = rutas que no se pudieron leer.
 */
function kt_unir_pdfs(array $rutas, string $salida): array
{
    $omitidos = [];
    $validos = [];
    $paginas = 0;

    $pdfunite = trim((string) @shell_exec('command -v pdfunite 2>/dev/null'));
    $pdfinfo = trim((string) @shell_exec('command -v pdfinfo 2>/dev/null'));

    if ($pdfunite !== '' && $pdfinfo !== '') {
        // Quedarse solo con los que se pueden leer (un PDF dañado no debe tumbar a los demás)
        foreach ($rutas as $ruta) {
            $info = (string) @shell_exec($pdfinfo . ' ' . escapeshellarg($ruta) . ' 2>/dev/null');
            if (preg_match('/^Pages:\s+(\d+)/m', $info, $m) && (int) $m[1] > 0) {
                $validos[] = $ruta;
                $paginas += (int) $m[1];
            } else {
                $omitidos[] = $ruta;
            }
        }
        if (!$validos) {
            return ['paginas' => 0, 'omitidos' => $omitidos];
        }
        if (count($validos) === 1) {
            copy($validos[0], $salida);
            return ['paginas' => $paginas, 'omitidos' => $omitidos];
        }
        $cmd = $pdfunite;
        foreach ($validos as $ruta) {
            $cmd .= ' ' . escapeshellarg($ruta);
        }
        $cmd .= ' ' . escapeshellarg($salida) . ' 2>&1';
        $salidaCmd = [];
        $codigo = 0;
        exec($cmd, $salidaCmd, $codigo);
        if ($codigo === 0 && is_file($salida) && filesize($salida) > 0) {
            return ['paginas' => $paginas, 'omitidos' => $omitidos];
        }
        error_log('pdfunite falló (' . $codigo . '): ' . implode(' | ', array_slice($salidaCmd, 0, 3)));
        // si falló, se intenta con FPDI más abajo
        $paginas = 0;
        $omitidos = [];
    }

    // Respaldo: FPDI (no lee PDFs con compresión moderna)
    $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    foreach ($rutas as $ruta) {
        try {
            $total = $pdf->setSourceFile($ruta);
            for ($i = 1; $i <= $total; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage(($size['width'] > $size['height']) ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
                $paginas++;
            }
        } catch (\Throwable $e) {
            error_log("Error procesando PDF {$ruta}: " . $e->getMessage());
            $omitidos[] = $ruta;
        }
    }
    if ($paginas > 0) {
        $pdf->Output($salida, 'F');
    }
    return ['paginas' => $paginas, 'omitidos' => $omitidos];
}
