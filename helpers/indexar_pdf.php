<?php
/**
 * Indexación de un PDF con tiempo límite y OCR por páginas que se retoma.
 *
 * Por qué existe: los PDFs escaneados (imágenes) necesitan OCR, que tarda varios
 * segundos POR PÁGINA. Antes el lote entero (50 PDFs × todas sus páginas) corría
 * en una sola petición, pasaba el tiempo máximo del proxy (Railway/Cloudflare) y
 * el navegador recibía "upstream error" en vez de JSON, sin guardar nada.
 *
 * Ahora:
 *  - Cada petición trabaja hasta un plazo ($deadline) y devuelve lo hecho.
 *  - El OCR va página por página. Si se acaba el tiempo a mitad de un PDF, se
 *    guarda el avance (página y texto leído) en datos_extraidos y la siguiente
 *    petición sigue desde esa página. Nunca se pierde trabajo ya hecho.
 *
 * El avance se guarda con claves que NO son "text" ni "error", así el documento
 * sigue contando como pendiente (ver PENDING_CONDITION en SystemController).
 *
 * Requiere helpers/pdf_extractor.php cargado.
 */

/** Menos caracteres que esto = página escaneada: se complementa con OCR. */
const KT_INDEXAR_MIN_TEXTO = 100;

/**
 * Número de páginas del PDF (pdfinfo). null si no se puede saber.
 */
function kt_pdf_num_paginas(string $pdfPath): ?int
{
    $pdfinfo = null;
    if (PHP_OS_FAMILY === 'Windows') {
        $pdfinfo = shell_exec('where pdfinfo 2>nul') ? 'pdfinfo' : null;
    } else {
        $which = shell_exec('which pdfinfo 2>/dev/null');
        $pdfinfo = $which ? trim($which) : null;
    }
    if (!$pdfinfo) {
        return null;
    }
    $out = shell_exec($pdfinfo . ' ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
    if ($out && preg_match('/^Pages:\s+(\d+)/mi', $out, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * OCR de UNA página. Devuelve el texto, o null si la página no existe.
 */
function kt_ocr_una_pagina(string $pdfPath, int $pagina, int $dpi = 150): ?string
{
    $tesseract = find_tesseract();
    $pdftoppm = find_pdftoppm();
    if (!$tesseract || !$pdftoppm) {
        return null;
    }

    $tmp = sys_get_temp_dir() . '/ktocr_' . uniqid('', true);
    if (!@mkdir($tmp, 0777, true)) {
        return null;
    }
    try {
        $prefijo = $tmp . '/p';
        $cmd = $pdftoppm . ' -png -gray -r ' . (int) $dpi . ' -f ' . (int) $pagina . ' -l ' . (int) $pagina
            . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefijo) . ' 2>&1';
        exec($cmd);
        $imagenes = glob($tmp . '/*.png');
        if (!$imagenes) {
            return null; // la página no existe (o el PDF está dañado)
        }
        $texto = '';
        foreach ($imagenes as $img) {
            exec($tesseract . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($img) . ' -l spa --oem 1 2>&1');
            if (is_file($img . '.txt')) {
                $texto .= file_get_contents($img . '.txt') . "\n";
            }
        }
        return $texto;
    } finally {
        array_map('unlink', glob($tmp . '/*') ?: []);
        @rmdir($tmp);
    }
}

/**
 * Indexa un PDF trabajando hasta $deadline (timestamp con microtime(true)).
 *
 * @param array|null $previo datos_extraidos actuales (decodificados), para retomar el OCR.
 * @return array Una de:
 *   ['estado' => 'ok',      'text' => ..., 'codes' => [...]]
 *   ['estado' => 'parcial', 'avance' => [...datos a guardar...], 'pagina' => n, 'paginas' => total|null]
 *   ['estado' => 'error',   'error' => '...']
 */
function kt_indexar_pdf(string $pdfPath, ?array $previo, float $deadline, int $dpi = 150): array
{
    $textoBase = '';
    $paginaInicio = 1;
    $textoOcr = '';
    $paginas = null;

    $retoma = is_array($previo) && isset($previo['ocr_pagina']);
    if ($retoma) {
        $paginaInicio = max(1, (int) $previo['ocr_pagina']);
        $textoOcr = (string) ($previo['ocr_texto'] ?? '');
        $textoBase = (string) ($previo['ocr_base'] ?? '');
        $paginas = isset($previo['ocr_paginas']) ? (int) $previo['ocr_paginas'] : null;
    } else {
        // 1) Capa de texto (rápido). Plazo corto para que un PDF raro no se coma la petición.
        $restante = (int) max(5, min(30, $deadline - microtime(true)));
        $textoBase = extract_with_pdftotext($pdfPath, $restante);
        if (strlen(trim($textoBase)) < KT_INDEXAR_MIN_TEXTO) {
            $smalot = extract_with_smalot($pdfPath);
            if (strlen(trim($smalot)) > strlen(trim($textoBase))) {
                $textoBase = $smalot;
            }
        }
        if (strlen(trim($textoBase)) >= KT_INDEXAR_MIN_TEXTO) {
            return kt_indexar_resultado($textoBase);
        }
    }

    // 2) PDF escaneado: OCR página por página.
    if (!find_tesseract() || !find_pdftoppm()) {
        if (trim($textoBase) !== '') {
            return kt_indexar_resultado($textoBase);
        }
        return ['estado' => 'error', 'error' => 'PDF escaneado y el servidor no tiene OCR (tesseract/pdftoppm)'];
    }
    if ($paginas === null) {
        $paginas = kt_pdf_num_paginas($pdfPath);
    }

    $pagina = $paginaInicio;
    $hechasAhora = 0;
    while (true) {
        if ($paginas !== null && $pagina > $paginas) {
            break;
        }
        // Siempre al menos una página por petición, para avanzar seguro.
        if ($hechasAhora > 0 && microtime(true) >= $deadline) {
            return [
                'estado' => 'parcial',
                'pagina' => $pagina,
                'paginas' => $paginas,
                'avance' => [
                    'ocr_pagina' => $pagina,
                    'ocr_paginas' => $paginas,
                    'ocr_texto' => $textoOcr,
                    'ocr_base' => $textoBase,
                    'ocr_actualizado' => date('Y-m-d H:i:s'),
                ],
            ];
        }
        $t = kt_ocr_una_pagina($pdfPath, $pagina, $dpi);
        if ($t === null) {
            break; // no hay más páginas (cuando pdfinfo no está disponible)
        }
        $textoOcr .= $t;
        $pagina++;
        $hechasAhora++;
    }

    $texto = trim($textoBase . "\n" . $textoOcr);
    if ($texto === '') {
        return ['estado' => 'error', 'error' => 'No se pudo leer texto del PDF (ni con OCR)'];
    }
    return kt_indexar_resultado($texto);
}

function kt_indexar_resultado(string $texto): array
{
    return [
        'estado' => 'ok',
        'text' => $texto,
        'codes' => extract_codes_with_pattern($texto, '', '/', 4, 50),
    ];
}
