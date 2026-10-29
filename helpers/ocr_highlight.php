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
 * Busca los términos dentro de las palabras (sin distinguir mayúsculas).
 *
 * @return array ['matches' => [...], 'highlights' => [...]]
 */
function ocr_hl_match_terms(array $words, array $terms): array
{
    $matches = [];
    $highlights = [];

    foreach ($words as $word) {
        foreach ($terms as $term) {
            if (mb_stripos($word['text'], $term, 0, 'UTF-8') !== false) {
                $box = ['x' => $word['x'], 'y' => $word['y'], 'w' => $word['w'], 'h' => $word['h']];
                $matches[] = ['term' => $term, 'word' => $word['text']] + $box;
                $highlights[] = $box + ['term' => $term];
            }
        }
    }

    return ['matches' => $matches, 'highlights' => $highlights];
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
