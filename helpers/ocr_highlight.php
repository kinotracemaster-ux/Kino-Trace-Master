<?php
/**
 * Palabras con coordenadas por página para el resaltador (privado y público).
 *
 * Orden de obtención (lo más barato primero):
 *  1. Cache por archivo (clave = ruta + tamaño + fecha de modificación, así un PDF
 *     reemplazado no reutiliza coordenadas viejas y dos archivos nunca comparten clave).
 *  2. Capa de texto del PDF con `pdftotext -bbox` (~50 ms por página).
 *  3. OCR con Tesseract (segundos por página), SOLO si la página no trae texto.
 *
 * Requiere pdf_extractor.php y cache_manager.php cargados.
 */

// Mínimo de caracteres (sin espacios) para considerar que una página trae texto
// propio. Por debajo se asume página escaneada (puede tener solo un sello o
// encabezado) y se usa OCR. El visor usa el mismo umbral en el navegador.
const OCR_HL_MIN_TEXT_CHARS = 150;

/**
 * Clave de cache de palabras para una página de un PDF concreto.
 */
function ocr_hl_cache_key(string $pdfPath, int $pageNum, bool $forceOcr = false): string
{
    $real = realpath($pdfPath) ?: $pdfPath;
    $sig = md5($real . '|' . (int) @filesize($pdfPath) . '|' . (int) @filemtime($pdfPath));
    return "ocr_v6_{$sig}_p{$pageNum}" . ($forceOcr ? '_ocr' : '');
}

/**
 * Devuelve las palabras de una página con sus coordenadas.
 * $forceOcr salta la capa de texto: para códigos que solo existen dentro de
 * imágenes de un PDF digital (el visor lo pide solo para términos faltantes).
 *
 * @return array ['success', 'words' => [{text,x,y,w,h}], 'image_width', 'image_height', 'source' => cache|text|ocr|none]
 */
function ocr_hl_get_page_words(string $clientCode, string $pdfPath, int $pageNum, bool $forceOcr = false): array
{
    $cacheKey = ocr_hl_cache_key($pdfPath, $pageNum, $forceOcr);
    $cached = CacheManager::get($clientCode, $cacheKey);
    if (is_array($cached) && isset($cached['words'])) {
        $cached['success'] = true;
        $cached['source'] = 'cache';
        return $cached;
    }

    // 1. Capa de texto del PDF (instantáneo)
    $textResult = $forceOcr
        ? ['success' => true, 'words' => [], 'image_width' => 0, 'image_height' => 0]
        : ocr_hl_words_from_text_layer($pdfPath, $pageNum);
    $chars = 0;
    foreach ($textResult['words'] as $w) {
        $chars += mb_strlen($w['text'], 'UTF-8');
    }

    if ($chars >= OCR_HL_MIN_TEXT_CHARS) {
        $result = $textResult + ['source' => 'text'];
    } else {
        // 2. Página escaneada: OCR (limitado por semáforo de procesos)
        $slot = ocr_hl_acquire_slot();
        if ($slot === null) {
            return ['success' => false, 'error' => 'Servidor OCR ocupado, intenta en unos segundos', 'words' => []];
        }
        try {
            $ocr = extract_with_ocr_coordinates($pdfPath, $pageNum);
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }

        if (!empty($ocr['success'])) {
            $result = [
                'success' => true,
                'words' => $ocr['words'] ?? [],
                'image_width' => $ocr['image_width'] ?? 0,
                'image_height' => $ocr['image_height'] ?? 0,
                'source' => 'ocr',
            ];
        } elseif (!empty($textResult['words'])) {
            // Sin Tesseract (p. ej. modo local): usar el poco texto que haya
            $result = $textResult + ['source' => 'text'];
        } else {
            // Fallo transitorio o herramienta ausente: no cachear
            return [
                'success' => false,
                'error' => $ocr['error'] ?? 'No se pudo extraer texto de la página',
                'words' => [],
                'source' => 'none',
            ];
        }
    }

    // Se cachea también una página vacía, para no volver a pasarle OCR
    CacheManager::set($clientCode, $cacheKey, [
        'words' => $result['words'],
        'image_width' => $result['image_width'],
        'image_height' => $result['image_height'],
    ], 604800); // 7 días

    return $result;
}

/**
 * Palabras con coordenadas desde la capa de texto (pdftotext -bbox).
 * Coordenadas en puntos PDF; image_width/height = tamaño de la página en puntos.
 */
function ocr_hl_words_from_text_layer(string $pdfPath, int $pageNum): array
{
    $empty = ['success' => true, 'words' => [], 'image_width' => 0, 'image_height' => 0];

    $pdftotext = find_pdftotext();
    if (!$pdftotext) {
        return $empty;
    }

    $cmd = escapeshellarg($pdftotext) . " -bbox -f $pageNum -l $pageNum "
        . escapeshellarg($pdfPath) . ' - 2>' . (PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null');
    $html = shell_exec($cmd);
    if (!$html) {
        return $empty;
    }

    if (!preg_match('/<page width="([\d.]+)" height="([\d.]+)"/', $html, $pm)) {
        return $empty;
    }

    $words = [];
    $pattern = '/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/';
    if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $text = trim(html_entity_decode($m[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }
            $words[] = [
                'text' => $text,
                'x' => round((float) $m[1], 2),
                'y' => round((float) $m[2], 2),
                'w' => round((float) $m[3] - (float) $m[1], 2),
                'h' => round((float) $m[4] - (float) $m[2], 2),
            ];
        }
    }

    return [
        'success' => true,
        'words' => $words,
        'image_width' => (float) $pm[1],
        'image_height' => (float) $pm[2],
    ];
}

/**
 * Clave de comparación de un texto para el resaltado: mayúsculas y sin los
 * separadores que el cliente usa a veces dentro de un código (espacio, guion,
 * punto, coma, ...). Misma regla que code_match_key() de helpers/tenant.php,
 * así "BL-1258" resalta también "BL1258" y "DS 230166" resalta "DS230166".
 */
function ocr_hl_key(string $text): string
{
    if (function_exists('code_match_key')) {
        return mb_strtoupper(code_match_key($text), 'UTF-8');
    }
    return mb_strtoupper(preg_replace('/[\s\-.,;:_\/\x{00A0}]+/u', '', $text), 'UTF-8');
}

/**
 * Busca los términos dentro de las palabras (sin distinguir mayúsculas ni
 * separadores). Las palabras se unen en un solo texto continuo, así un código
 * que el OCR parte en dos palabras ("DS" + "230166") también se encuentra;
 * se resalta cada palabra que forma parte de la coincidencia.
 *
 * @return array ['matches' => [...], 'highlights' => [...]]
 */
function ocr_hl_match_terms(array $words, array $terms): array
{
    $matches = [];
    $highlights = [];

    // Texto continuo de claves + a qué palabra pertenece cada carácter
    $stream = '';
    $owner = []; // posición (en caracteres) → [índice de palabra, posición en la palabra]
    $chars = []; // caracteres originales de cada palabra
    foreach ($words as $i => $word) {
        $chars[$i] = mb_str_split((string) ($word['text'] ?? ''), 1, 'UTF-8');
        foreach ($chars[$i] as $k => $ch) {
            $key = ocr_hl_key($ch);
            if ($key === '') {
                continue;
            }
            $stream .= $key;
            for ($n = mb_strlen($key, 'UTF-8'); $n > 0; $n--) {
                $owner[] = [$i, $k];
            }
        }
    }

    // Busca una clave; devuelve cuántas veces la encontró. $via = texto con el
    // que se encontró cuando no es el término exacto (búsqueda sin el "-N").
    // Con $via, si alguna aparición no va seguida de otro "-N" se usan solo esas;
    // si todas van seguidas de uno ("1980" en "1980-2") se resaltan igual (la base
    // SÍ está en el documento) y se anota cuál figura en el campo 'otro'.
    $scan = function (string $needle, string $term, ?string $via) use ($words, $stream, $owner, $chars, &$matches, &$highlights): int {
        $nLen = mb_strlen($needle, 'UTF-8');
        $from = 0;
        $cands = [];
        while (($pos = mb_strpos($stream, $needle, $from, 'UTF-8')) !== false) {
            $from = $pos + $nLen;
            $end = $owner[$pos + $nLen - 1];
            if (!ocr_hl_edge_ok($chars, $owner[$pos], -1, mb_substr($needle, 0, 1, 'UTF-8'))
                || !ocr_hl_edge_ok($chars, $end, 1, mb_substr($needle, -1, 1, 'UTF-8'))) {
                continue;
            }
            $cands[] = ['pos' => $pos, 'otro' => $via !== null ? ocr_hl_sufijo_siguiente($chars, $words, $end) : ''];
        }
        if ($via !== null && array_filter($cands, fn($c) => $c['otro'] === '')) {
            $cands = array_values(array_filter($cands, fn($c) => $c['otro'] === ''));
        }
        foreach ($cands as $c) {
            $pos = $c['pos'];
            $extra = $via !== null ? ['via' => $via] + ($c['otro'] !== '' ? ['otro' => $c['otro']] : []) : [];
            $wordIdx = array_values(array_unique(array_column(array_slice($owner, $pos, $nLen), 0)));
            $matchNum = count($matches); // enlaza cada caja con su coincidencia
            $texts = [];
            foreach ($wordIdx as $i) {
                $w = $words[$i];
                $box = ['x' => $w['x'], 'y' => $w['y'], 'w' => $w['w'], 'h' => $w['h']];
                $highlights[] = $box + ['term' => $term, 'match' => $matchNum] + $extra;
                $texts[] = $w['text'];
            }
            $first = $words[$wordIdx[0]];
            $matches[] = [
                'term' => $term,
                'word' => implode(' ', $texts),
                'x' => $first['x'], 'y' => $first['y'], 'w' => $first['w'], 'h' => $first['h'],
            ] + $extra;
        }
        return count($cands);
    };

    foreach ($terms as $term) {
        $term = (string) $term;
        $needle = ocr_hl_key($term);
        if ($needle === '') {
            continue;
        }
        if ($scan($needle, $term, null) > 0) {
            continue;
        }
        // No está tal cual: probar sin el "-N" final ("1980-1" → "1980")
        $base = ocr_hl_base_sin_sufijo($term);
        if ($base !== null && $scan(ocr_hl_key($base), $term, $base) > 0) {
            continue;
        }
        // Tampoco con la letra ("1523H-1" → "1523")
        $dig = ocr_hl_base_digitos($term);
        if ($dig !== null) {
            $scan(ocr_hl_key($dig), $term, $dig);
        }
    }

    return ['matches' => $matches, 'highlights' => $highlights];
}

/**
 * "1980-1" → "1980", "NO.2519A-5" → "NO.2519A". En las listas de búsqueda el
 * "-N" final suele ser número de ítem/copia que el PDF no trae. Solo 1-2 cifras
 * y la base debe tener al menos 3 letras/números ("BL-12" no se recorta).
 * Misma regla que ktBaseSinSufijo() en los visores. null si no aplica.
 */
function ocr_hl_base_sin_sufijo(string $term): ?string
{
    if (!preg_match('/^(.*[\p{L}\p{N}])\s*-\s*\d{1,2}$/u', trim($term), $m)) {
        return null;
    }
    return mb_strlen(ocr_hl_key($m[1]), 'UTF-8') >= 3 ? trim($m[1]) : null;
}

/**
 * "1523H-1" → "1523": en las declaraciones muchas referencias se imprimen solo con
 * los números ("Ref: 1523.////") y la letra final (H, G, B...) es del inventario.
 * Solo para cuando no se halló ni el código ni su base con la letra. null si no aplica.
 */
function ocr_hl_base_digitos(string $term): ?string
{
    $base = ocr_hl_base_sin_sufijo($term);
    if ($base !== null && preg_match('/^(\d{3,})\s*-?\s*[A-Za-z]{1,3}$/', $base, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Si después de la coincidencia viene "-<cifras>" (en la misma palabra o en las
 * siguientes) devuelve ese texto ("-2"); si no, ''. Sirve para avisar que la
 * base figura en el documento como otro ítem ("1980-2") al buscar "1980-1".
 */
function ocr_hl_sufijo_siguiente(array $chars, array $words, array $at): string
{
    [$i, $k] = $at;
    $rest = implode('', array_slice($chars[$i], $k + 1));
    if ($rest === '' && isset($words[$i + 1])) {
        $rest = (string) ($words[$i + 1]['text'] ?? '');
        if (isset($words[$i + 2]) && preg_match('/^-$/', $rest)) {
            $rest .= (string) ($words[$i + 2]['text'] ?? '');
        }
    }
    return preg_match('/^\s*-\s*(\d+)/u', $rest, $m) ? '-' . $m[1] : '';
}

/**
 * Borde de una coincidencia: el código no puede ir pegado a otra letra/número
 * ("11251" no es "1251") ni ser parte de una cifra con puntos ("1.251.000").
 * El borde de una palabra cuenta como separación. Misma regla que el visor.
 */
function ocr_hl_edge_ok(array $chars, array $at, int $dir, string $termEdge): bool
{
    [$i, $k] = $at;
    $w = $chars[$i];
    $j = $k + $dir;
    $skipped = 0;
    while ($j >= 0 && $j < count($w) && ($w[$j] === '.' || $w[$j] === ',')) {
        $j += $dir;
        $skipped++;
    }
    if ($j < 0 || $j >= count($w)) {
        return true;
    }
    $ch = $w[$j];
    if (!preg_match('/[\p{L}\p{N}]/u', $ch)) {
        return true;
    }
    return $skipped > 0 && !(preg_match('/\p{N}/u', $ch) && preg_match('/\p{N}/u', $termEdge));
}

/**
 * Respuesta JSON estándar de los endpoints de resaltado.
 */
function ocr_hl_build_response(array $pageWords, array $terms): array
{
    $found = ocr_hl_match_terms($pageWords['words'], $terms);

    return [
        'success' => true,
        'matches' => $found['matches'],
        'match_count' => count($found['matches']),
        'highlights' => $found['highlights'],
        'image_width' => $pageWords['image_width'] ?? 0,
        'image_height' => $pageWords['image_height'] ?? 0,
        'terms_searched' => array_values($terms),
        'source' => $pageWords['source'] ?? '',
    ];
}

/**
 * Semáforo global de procesos OCR (compartido por todos los clientes).
 * Espera hasta ~25 s a que se libere cualquier slot.
 *
 * @return resource|null Handle bloqueado (liberar con flock LOCK_UN + fclose) o null si no hubo slot.
 */
function ocr_hl_acquire_slot()
{
    $slots = ocr_hl_max_processes();
    $lockDir = sys_get_temp_dir();
    $deadline = microtime(true) + 25;

    do {
        for ($slot = 0; $slot < $slots; $slot++) {
            $handle = @fopen($lockDir . "/ocr_slot_{$slot}.lock", 'c');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if ($handle) {
                fclose($handle);
            }
        }
        usleep(150000);
    } while (microtime(true) < $deadline);

    return null;
}

/**
 * Procesos OCR simultáneos: uno por núcleo (Tesseract corre con 1 hilo, ver
 * extract_with_ocr_coordinates), entre 2 y 6. Configurable con OCR_MAX_PROCS.
 */
function ocr_hl_max_processes(): int
{
    $env = (int) getenv('OCR_MAX_PROCS');
    if ($env > 0) {
        return $env;
    }

    $cores = 0;
    if (PHP_OS_FAMILY !== 'Windows') {
        $cores = (int) @shell_exec('nproc 2>/dev/null');
    } else {
        $cores = (int) getenv('NUMBER_OF_PROCESSORS');
    }

    return $cores > 0 ? max(2, min(6, $cores)) : 3;
}
