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

/**
 * Lee de un PDF, hoja por hoja, qué códigos aparecen en su capa de texto.
 * Una sola llamada a `pdftotext -bbox` por archivo.
 *
 * @return array<int, array{terms:string[], escaneada:bool}>  hoja (desde 1) => datos
 */
function kt_codigos_por_hoja(string $pdf, array $codigos): array
{
    require_once __DIR__ . '/pdf_extractor.php';
    require_once __DIR__ . '/ocr_highlight.php';
    $bin = function_exists('find_pdftotext') ? find_pdftotext() : null;
    if (!$bin) {
        return [];
    }
    $html = (string) @shell_exec(escapeshellarg($bin) . ' -bbox ' . escapeshellarg($pdf) . ' - 2>/dev/null');
    if ($html === '' || !preg_match_all('/<page [^>]*>(.*?)<\/page>/s', $html, $paginas)) {
        return [];
    }
    $res = [];
    foreach ($paginas[1] as $i => $contenido) {
        $palabras = [];
        $letras = 0;
        if (preg_match_all('/<word [^>]*>([^<]*)<\/word>/', $contenido, $m)) {
            foreach ($m[1] as $w) {
                $t = trim(html_entity_decode($w, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($t !== '') {
                    $palabras[] = ['text' => $t, 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];
                    $letras += mb_strlen($t, 'UTF-8');
                }
            }
        }
        $escaneada = $letras < OCR_HL_MIN_TEXT_CHARS;
        $terms = [];
        if (!$escaneada) {
            foreach (ocr_hl_match_terms($palabras, $codigos)['matches'] as $mt) {
                $terms[$mt['term']] = true;
            }
        }
        $res[$i + 1] = ['terms' => array_keys($terms), 'escaneada' => $escaneada];
    }
    return $res;
}

/**
 * PDF unificado MÍNIMO: solo las hojas necesarias para cubrir los códigos, sin repetir.
 * Elige hojas con el mismo método voraz de la búsqueda (la hoja que cubre más códigos
 * pendientes primero): si un código ya salió en una hoja, no se agregan otras hojas por él.
 * Las hojas escaneadas (sin texto) solo se agregan si quedaron códigos sin encontrar en
 * ninguna hoja con texto, porque podrían tenerlos.
 *
 * Con la lista de códigos vacía, o si ninguna hoja los trae, une los PDFs completos.
 *
 * @return array{paginas:int, omitidos:string[], originales:int, sin_cubrir:string[]}
 */
function kt_unir_pdfs_minimo(array $rutas, array $codigos, string $salida): array
{
    $codigos = array_values(array_unique(array_filter(array_map('trim', $codigos), 'strlen')));
    $completo = static function () use ($rutas, $salida) {
        $r = kt_unir_pdfs($rutas, $salida);
        return $r + ['originales' => $r['paginas'], 'sin_cubrir' => []];
    };
    $pdfseparate = trim((string) @shell_exec('command -v pdfseparate 2>/dev/null'));
    $pdfunite = trim((string) @shell_exec('command -v pdfunite 2>/dev/null'));
    if (!$codigos || $pdfseparate === '' || $pdfunite === '') {
        return $completo();
    }

    // 1) Qué códigos trae cada hoja
    $hojas = []; // [ [indiceArchivo, hoja, terms[], escaneada] ]
    $omitidos = [];
    $originales = 0;
    foreach ($rutas as $f => $ruta) {
        $info = kt_codigos_por_hoja($ruta, $codigos);
        if (!$info) {
            $omitidos[] = $ruta;
            continue;
        }
        foreach ($info as $n => $d) {
            $hojas[] = [$f, $n, $d['terms'], $d['escaneada']];
            $originales++;
        }
    }
    $conTexto = array_values(array_filter($hojas, fn($h) => !$h[3] && $h[2]));
    if (!$conTexto) {
        return $completo();
    }

    // 2) Selección voraz de hojas
    $pendientes = [];
    foreach ($conTexto as $h) {
        foreach ($h[2] as $t) {
            $pendientes[$t] = true;
        }
    }
    $elegidas = [];
    $cubiertos = [];
    while (true) {
        $mejor = null;
        $mejorN = 0;
        foreach ($conTexto as $k => $h) {
            if (isset($elegidas[$k])) {
                continue;
            }
            $n = 0;
            foreach ($h[2] as $t) {
                if (!isset($cubiertos[$t])) {
                    $n++;
                }
            }
            if ($n > $mejorN) {
                $mejorN = $n;
                $mejor = $k;
            }
        }
        if ($mejor === null) {
            break;
        }
        $elegidas[$mejor] = true;
        foreach ($conTexto[$mejor][2] as $t) {
            $cubiertos[$t] = true;
        }
    }
    $sinCubrir = array_values(array_filter($codigos, fn($c) => !isset($cubiertos[$c])));

    $final = []; // "archivo:hoja" => [archivo, hoja]
    foreach (array_keys($elegidas) as $k) {
        $final[$conTexto[$k][0] . ':' . $conTexto[$k][1]] = [$conTexto[$k][0], $conTexto[$k][1]];
    }
    // 3) Hojas escaneadas: solo si faltan códigos por encontrar
    if ($sinCubrir) {
        foreach ($hojas as $h) {
            if ($h[3]) {
                $final[$h[0] . ':' . $h[1]] = [$h[0], $h[1]];
            }
        }
    }
    uasort($final, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    // 4) Extraer las hojas y unirlas
    $tmp = sys_get_temp_dir() . '/kt_min_' . getmypid() . '_' . mt_rand();
    @mkdir($tmp, 0700, true);
    $partes = [];
    foreach ($final as $key => [$f, $n]) {
        $parte = $tmp . '/' . sprintf('%04d_%04d', $f, $n) . '.pdf';
        exec($pdfseparate . ' -f ' . (int) $n . ' -l ' . (int) $n . ' ' . escapeshellarg($rutas[$f]) . ' ' . escapeshellarg($parte) . ' 2>&1');
        if (is_file($parte) && filesize($parte) > 0) {
            $partes[] = $parte;
        }
    }
    $ok = false;
    if (count($partes) === 1) {
        $ok = copy($partes[0], $salida);
    } elseif ($partes) {
        $cmd = $pdfunite;
        foreach ($partes as $p) {
            $cmd .= ' ' . escapeshellarg($p);
        }
        exec($cmd . ' ' . escapeshellarg($salida) . ' 2>&1', $o, $codigo);
        $ok = $codigo === 0 && is_file($salida) && filesize($salida) > 0;
    }
    foreach ($partes as $p) {
        @unlink($p);
    }
    @rmdir($tmp);
    if (!$ok) {
        return $completo();
    }
    return ['paginas' => count($partes), 'omitidos' => $omitidos, 'originales' => $originales, 'sin_cubrir' => $sinCubrir];
}
