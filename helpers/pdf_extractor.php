<?php
/**
 * Motor de Extracción de Códigos de PDF
 *
 * Extrae códigos de documentos PDF usando patrones configurables por el usuario.
 * El usuario define:
 * - Prefijo: donde empieza el código (ej: "Ref:", "Código:", etc)
 * - Terminador: donde termina el código (ej: "/", espacio, nueva línea)
 *
 * También soporta integración con IA (Gemini) para extracción inteligente.
 */

/**
 * Extrae texto de un archivo PDF usando múltiples métodos.
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @param array $options Opciones de extracción (ej: ['dpi' => 150]).
 * @return string Texto extraído del PDF.
 */
function extract_text_from_pdf(string $pdfPath, array $options = []): string
{
    if (!file_exists($pdfPath)) {
        if (class_exists('Logger')) {
            Logger::error('PDF file not found', ['path' => $pdfPath]);
        }
        throw new Exception("Archivo PDF no encontrado: $pdfPath");
    }

    $text = '';

    // Método 1: pdftotext (más preciso)
    $text = extract_with_pdftotext($pdfPath);
    if (!empty(trim($text))) {
        return $text;
    }

    // Método 2: Smalot\PdfParser (si está disponible)
    $text = extract_with_smalot($pdfPath);
    if (!empty(trim($text))) {
        return $text;
    }

    if (!empty(trim($text))) {
        return $text;
    }

    // Método 4: OCR con Tesseract (para documentos escaneados)
    // Solo si los métodos anteriores fallaron o devolvieron muy poco texto
    if (function_exists('extract_with_ocr')) {
        $text = extract_with_ocr($pdfPath, $options);
        if (!empty(trim($text))) {
            return $text;
        }
    }

    if (class_exists('Logger')) {
        Logger::warning('Failed to extract text from PDF', ['path' => $pdfPath]);
    }
    return '';
}

/**
 * Extrae texto usando pdftotext (poppler-utils)
 * 
 * @param string $pdfPath Ruta al PDF
 * @param int $timeoutSeconds Timeout en segundos (default: 30)
 * @return string Texto extraído o cadena vacía
 */
function extract_with_pdftotext(string $pdfPath, int $timeoutSeconds = 30): string
{
    $pdftotextPath = find_pdftotext();
    if (!$pdftotextPath) {
        if (class_exists('Logger')) {
            Logger::warning('pdftotext not available', ['path' => $pdfPath]);
        }
        return '';
    }

    $escaped = escapeshellarg($pdfPath);
    $cmd = "$pdftotextPath -layout -enc UTF-8 $escaped -";

    // Usar proc_open para capturar errores y controlar timeout
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        if (class_exists('Logger')) {
            Logger::error('Failed to start pdftotext process', ['command' => $cmd]);
        }
        return '';
    }

    fclose($pipes[0]);

    // Configurar pipes como no bloqueantes para timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $startTime = time();
    $output = '';
    $errors = '';

    // Leer con timeout
    while (time() - $startTime < $timeoutSeconds) {
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        usleep(100000); // 100ms
    }

    // Si excedió timeout, terminar proceso
    if (time() - $startTime >= $timeoutSeconds) {
        proc_terminate($process, 9); // SIGKILL
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (class_exists('Logger')) {
            Logger::error('PDF extraction timeout', [
                'path' => $pdfPath,
                'timeout' => $timeoutSeconds
            ]);
        }
        return '';
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    if ($returnCode === 0 && !empty(trim($output))) {
        return $output;
    }

    if (!empty($errors) && class_exists('Logger')) {
        Logger::warning('pdftotext error output', [
            'path' => $pdfPath,
            'error' => substr($errors, 0, 500)
        ]);
    }

    return '';
}

/**
 * Extrae texto usando Smalot\PdfParser
 */
function extract_with_smalot(string $pdfPath): string
{
    $parserPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($parserPath)) {
        return '';
    }

    require_once $parserPath;
    if (!class_exists('Smalot\PdfParser\Parser')) {
        return '';
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        return $pdf->getText();
    } catch (Exception $e) {
        if (class_exists('Logger')) {
            Logger::error('Smalot parser error', [
                'path' => $pdfPath,
                'error' => $e->getMessage()
            ]);
        }
        return '';
    }
}

/**
 * Extracción nativa PHP - para PDFs con texto embebido simple
 * Lee el contenido binario y busca streams de texto
 */
function extract_with_native_php(string $pdfPath): string
{
    $content = file_get_contents($pdfPath);
    if ($content === false) {
        return '';
    }

    $text = '';

    // Buscar contenido entre BT y ET (Begin Text / End Text)
    if (preg_match_all('/BT\s*(.+?)\s*ET/s', $content, $matches)) {
        foreach ($matches[1] as $textBlock) {
            // Extraer texto entre paréntesis (Tj y TJ operadores)
            if (preg_match_all('/\(([^)]*)\)/', $textBlock, $textMatches)) {
                $text .= implode(' ', $textMatches[1]) . "\n";
            }
            // Extraer texto hexadecimal
            if (preg_match_all('/<([^>]+)>/', $textBlock, $hexMatches)) {
                foreach ($hexMatches[1] as $hex) {
                    $decoded = @hex2bin($hex);
                    if ($decoded) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }
    }

    // Limpiar caracteres no imprimibles
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF\n\r\t]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

/**
 * Extrae texto usando Tesseract OCR via pdftoppm (parte de poppler-utils)
 * Convierte primero a imagen y luego aplica OCR.
 * 
 * @param string $pdfPath Ruta al PDF
 * @param array $options Opciones (dpi, etc)
 * @return string Texto extraído o cadena vacía
 */
function extract_with_ocr(string $pdfPath, array $options = []): string
{
    $tesseractPath = find_tesseract();
    if (!$tesseractPath) {
        return '';
    }

    // Verificar si tenemos pdftoppm para convertir a imagen
    $pdftoppmPath = find_pdftoppm();
    if (!$pdftoppmPath) {
        return '';
    }

    // Configuración DPI (Default 150, subida puede usar 200+)
    $dpi = isset($options['dpi']) ? (int) $options['dpi'] : 150;

    // Directorio temporal para imágenes
    $tempDir = sys_get_temp_dir() . '/ocr_' . uniqid();
    if (!mkdir($tempDir, 0777, true)) {
        return '';
    }

    $text = '';

    try {
        // 1. Convertir PDF a imágenes (TODAS las páginas para extracción completa)
        $escapedPdf = escapeshellarg($pdfPath);
        $escapedPrefix = escapeshellarg($tempDir . '/page');

        // Sin límite de páginas para extraer de TODO el documento
        // Usar DPI configurable
        $cmdConvert = "$pdftoppmPath -png -gray -r $dpi $escapedPdf $escapedPrefix";
        exec($cmdConvert);

        // 2. Procesar cada imagen con Tesseract
        $images = glob($tempDir . '/*.png');
        foreach ($images as $image) {
            $escapedImage = escapeshellarg($image);
            $escapedOut = escapeshellarg($image); // tesseract añade .txt

            // tesseract imagen salida -l spa --oem 1 (LSTM only, faster)
            $cmdOcr = "$tesseractPath $escapedImage $escapedOut -l spa --oem 1";
            exec($cmdOcr);

            $txtFile = $image . '.txt';
            if (file_exists($txtFile)) {
                $text .= file_get_contents($txtFile) . "\n";
            }
        }

    } catch (Exception $e) {
        if (class_exists('Logger')) {
            Logger::error('OCR error', ['error' => $e->getMessage()]);
        }
    } finally {
        // Limpieza
        array_map('unlink', glob("$tempDir/*"));
        rmdir($tempDir);
    }

    return trim($text);
}

/**
 * Extrae texto con coordenadas usando Tesseract OCR con formato HOCR.
 * Convierte una página específica del PDF a imagen y aplica OCR.
 * 
 * OPTIMIZADO v4:
 * - DPI 150 (balance velocidad/calidad para resaltado visual)
 * - Solo 1 ejecución de Tesseract (HOCR + OEM 1 LSTM-only), texto se reconstruye de las palabras
 * 
 * @param string $pdfPath Ruta al archivo PDF.
 * @param int $pageNum Número de página (1-indexed).
 * @return array Array con: ['success', 'text', 'words' => [{text, x, y, w, h}], 'image_width', 'image_height']
 */
function extract_with_ocr_coordinates(string $pdfPath, int $pageNum = 1): array
{
    $tesseractPath = find_tesseract();
    if (!$tesseractPath) {
        return ['success' => false, 'error' => 'Tesseract no disponible', 'words' => []];
    }

    $pdftoppmPath = find_pdftoppm();
    if (!$pdftoppmPath) {
        return ['success' => false, 'error' => 'pdftoppm no disponible', 'words' => []];
    }

    $tempDir = sys_get_temp_dir() . '/ocr_coords_' . uniqid();
    if (!mkdir($tempDir, 0777, true)) {
        return ['success' => false, 'error' => 'No se pudo crear directorio temporal', 'words' => []];
    }

    $result = ['success' => false, 'text' => '', 'words' => [], 'image_width' => 0, 'image_height' => 0];

    try {
        // 1. Convertir solo la página específica a imagen
        $escapedPdf = escapeshellarg($pdfPath);
        $imagePrefix = $tempDir . '/page';
        $escapedPrefix = escapeshellarg($imagePrefix);

        // -r 150 DPI (balance velocidad/calidad para resaltado)
        $cmdConvert = "$pdftoppmPath -png -gray -r 150 -f $pageNum -l $pageNum $escapedPdf $escapedPrefix";
        exec($cmdConvert, $output, $returnCode);

        // Buscar la imagen generada
        $images = glob($tempDir . '/*.png');
        if (empty($images)) {
            throw new Exception("No se pudo convertir la página $pageNum a imagen");
        }

        $imagePath = $images[0];

        // Obtener dimensiones de la imagen
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo) {
            $result['image_width'] = $imageInfo[0];
            $result['image_height'] = $imageInfo[1];
        }

        // 2. Ejecutar Tesseract UNA SOLA VEZ con formato HOCR (coordenadas + texto)
        $hocrOutput = $tempDir . '/output';
        $escapedImage = escapeshellarg($imagePath);
        $escapedHocr = escapeshellarg($hocrOutput);

        $cmdOcr = "$tesseractPath $escapedImage $escapedHocr -l spa --oem 1 hocr";
        exec($cmdOcr, $ocrOutput, $ocrReturnCode);

        $hocrFile = $hocrOutput . '.hocr';
        if (!file_exists($hocrFile)) {
            throw new Exception("Tesseract no generó archivo HOCR");
        }

        // 3. Parsear HOCR para extraer palabras con coordenadas
        $hocrContent = file_get_contents($hocrFile);
        $result['words'] = parse_hocr_words($hocrContent);

        // OPTIMIZACIÓN: Reconstruir texto plano desde las palabras HOCR
        // (Elimina la necesidad de una segunda ejecución de Tesseract)
        $textParts = [];
        foreach ($result['words'] as $word) {
            $textParts[] = $word['text'];
        }
        $result['text'] = implode(' ', $textParts);

        $result['success'] = true;

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        if (class_exists('Logger')) {
            Logger::error('OCR coordinates error', ['error' => $e->getMessage()]);
        }
    } finally {
        // Limpieza
        $files = glob("$tempDir/*");
        if ($files) {
            array_map('unlink', $files);
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }

    return $result;
}

/**
 * Parsea contenido HOCR y extrae palabras con sus bounding boxes.
 * El formato HOCR tiene elementos span class="ocrx_word" con title="bbox x1 y1 x2 y2"
 * 
 * @param string $hocrContent Contenido del archivo HOCR.
 * @return array Array de palabras: [{text, x, y, w, h}]
 */
function parse_hocr_words(string $hocrContent): array
{
    $words = [];

    // Buscar todos los elementos ocrx_word con su bbox
    // Patrón: <span class='ocrx_word' ... title='bbox X1 Y1 X2 Y2; ...' ...>TEXTO</span>
    $pattern = "/<span[^>]*class=['\"]ocrx_word['\"][^>]*title=['\"]([^'\"]*)['\"][^>]*>([^<]*)<\/span>/i";

    if (preg_match_all($pattern, $hocrContent, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $title = $match[1];
            $text = trim(strip_tags($match[2]));

            if (empty($text))
                continue;

            // Extraer bbox del title: "bbox 100 200 300 250; ..."
            if (preg_match('/bbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $bboxMatch)) {
                $x1 = (int) $bboxMatch[1];
                $y1 = (int) $bboxMatch[2];
                $x2 = (int) $bboxMatch[3];
                $y2 = (int) $bboxMatch[4];

                $words[] = [
                    'text' => $text,
                    'x' => $x1,
                    'y' => $y1,
                    'w' => $x2 - $x1,
                    'h' => $y2 - $y1
                ];
            }
        }
    }

    return $words;
}

function find_tesseract(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        // En Windows requeriría instalación manual y añadir al PATH
        return shell_exec("where tesseract 2>nul") ? 'tesseract' : null;
    }
    $which = shell_exec('which tesseract 2>/dev/null');
    return $which ? trim($which) : null;
}

function find_pdftoppm(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return null; // Difícil en hosting windows sin configuración
    }
    $which = shell_exec('which pdftoppm 2>/dev/null');
    return $which ? trim($which) : null;
}

/**
 * Busca la ruta de pdftotext en el sistema.
 *
 * @return string|null Ruta al ejecutable o null si no se encuentra.
 */
function find_pdftotext(): ?string
{
    // Windows
    if (PHP_OS_FAMILY === 'Windows') {
        $possible = [
            'C:/Program Files/poppler/bin/pdftotext.exe',
            'C:/poppler/bin/pdftotext.exe',
            'pdftotext.exe'
        ];
        foreach ($possible as $path) {
            if (file_exists($path) || shell_exec("where $path 2>nul")) {
                return $path;
            }
        }
        return null;
    }

    // Linux/Mac
    $which = shell_exec('which pdftotext 2>/dev/null');
    return $which ? trim($which) : null;
}

/**
 * Extrae códigos del texto usando patrones personalizables.
 *
 * @param string $text Texto del PDF.
 * @param string $prefix Prefijo donde empieza el código (ej: "Ref:")
 * @param string $terminator Terminador del código (ej: "/" o "\s" para espacio)
 * @param int $minLength Longitud mínima del código.
 * @param int $maxLength Longitud máxima del código.
 * @return array Lista de códigos únicos encontrados.
 */
function extract_codes_with_pattern(
    string $text,
    string $prefix = '',
    string $terminator = '/',
    int $minLength = 2,
    int $maxLength = 50
): array {
    $codes = [];

    if ($prefix === '') {
        // Si no hay prefijo, buscar secuencias alfanuméricas largas
        // Típicos códigos de importación: números largos o combinaciones
        $pattern = '/\b([A-Z0-9][A-Z0-9\-\.]{' . ($minLength - 1) . ',' . ($maxLength - 1) . '})\b/i';
    } else {
        // Con prefijo: buscar "PREFIJO...TERMINADOR"
        $escapedPrefix = preg_quote($prefix, '/');
        // Si el terminador es explícito, no excluimos espacios para capturar "CODIGO CON ESPACIO"
        $escapedTerminator = $terminator === '' ? '\s' : preg_quote($terminator, '/');

        // Si terminador es vacío, excluimos \s. Si es explícito, solo excluimos ese terminador.
        $exclusion = $terminator === '' ? '\s' : $escapedTerminator;

        $pattern = '/' . $escapedPrefix . '\s*([^' . $exclusion . ']{' . $minLength . ',' . $maxLength . '})/i';
    }

    preg_match_all($pattern, $text, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $code) {
            $cleanedCode = clean_extracted_code($code);
            // Filtrar códigos que sean solo números muy cortos o muy largos
            if (strlen($cleanedCode) >= $minLength && strlen($cleanedCode) <= $maxLength) {
                // Validar que no sea solo puntos, guiones o caracteres inválidos
                if (validate_code($cleanedCode)) {
                    $codes[] = $cleanedCode;
                }
            }
        }
    }

    // Eliminar duplicados de forma robusta (case-insensitive pero preservando original)
    $uniqueCodes = [];
    $seenLower = [];
    foreach ($codes as $code) {
        $lowerCode = strtolower($code);
        if (!in_array($lowerCode, $seenLower)) {
            $uniqueCodes[] = $code;
            $seenLower[] = $lowerCode;
        }
    }

    return array_values($uniqueCodes);
}

/**
 * Limpia un código extraído: elimina puntos finales, espacios extra, etc.
 */
function clean_extracted_code(string $code): string
{
    // Eliminar espacios al inicio y final
    $code = trim($code);

    // Eliminar puntos al final (puede haber varios)
    $code = rtrim($code, '.');

    // Eliminar comas al final
    $code = rtrim($code, ',');

    // Eliminar guiones al final (si quedaron solos)
    $code = rtrim($code, '-');

    // Eliminar espacios internos extra (convertir múltiples espacios a uno)
    $code = preg_replace('/\s+/', ' ', $code);

    // Si el código tiene espacios internos, podría ser erróneo - eliminar espacios
    // (códigos normalmente no tienen espacios)
    $code = str_replace(' ', '', $code);

    // Correcciones comunes de OCR (letras/números confundidos)
    // El usuario reportó específicamente: G confundida con 6, H con M.

    // Si parece un número pero tiene una G, cambiar a 6.
    // Ej: 123G45 -> 123645
    // Solo si la mayoría son números y no es una palabra válida.
    if (preg_match('/^\d*[Gg]\d*$/', $code) && strlen($code) > 2) {
        $code = str_replace(['G', 'g'], '6', $code);
    }

    // Si parece un número pero tiene H/M (casos específicos reportados)
    // Esto es más delicado, aplicamos si el contexto parece numérico
    // H -> M (o viceversa según reporte, "H con M")
    // Asumiremos corrección hacia números si aplica, pero H y M son letras.
    // Si el usuario dice "H con la M", puede ser visual. 
    // Como no son números, solo normalizamos si hay patrón claro.
    // De momento dejaremos la corrección G->6 que es la más clara de OCR numérico.

    return $code;
}

/**
 * Valida que un código sea válido (no solo caracteres especiales)
 */
function validate_code(string $code): bool
{
    // Debe contener al menos un caracter alfanumérico
    if (!preg_match('/[A-Z0-9]/i', $code)) {
        return false;
    }

    // No puede ser solo números de 1-3 dígitos (muy genérico)
    if (preg_match('/^\d{1,3}$/', $code)) {
        return false;
    }

    // No puede ser palabras comunes
    $commonWords = ['de', 'la', 'el', 'en', 'que', 'del', 'los', 'las', 'por', 'con', 'una', 'para'];
    if (in_array(strtolower($code), $commonWords)) {
        return false;
    }

    // No puede ser solo puntos, guiones o espacios
    if (preg_match('/^[\-\.\s]+$/', $code)) {
        return false;
    }

    return true;
}

/**
 * Función completa: extrae texto del PDF y busca códigos con patrón.
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @param array $config Configuración del patrón (prefix, terminator, dpi, etc).
 * @return array Resultado con texto y códigos encontrados.
 */
function extract_codes_from_pdf(string $pdfPath, array $config = []): array
{
    $prefix = $config['prefix'] ?? '';
    $terminator = $config['terminator'] ?? '/';
    $minLength = $config['min_length'] ?? 4;
    $maxLength = $config['max_length'] ?? 50;

    // Pasar config entera para que llegue 'dpi' si existe
    $text = extract_text_from_pdf($pdfPath, $config);

    if (empty(trim($text))) {
        return [
            'success' => false,
            'error' => 'No se pudo extraer texto del PDF. Puede requerir OCR.',
            'text' => '',
            'codes' => []
        ];
    }

    $codes = extract_codes_with_pattern($text, $prefix, $terminator, $minLength, $maxLength);

    return [
        'success' => true,
        'text' => $text,
        'codes' => $codes,
        'count' => count($codes)
    ];
}

/**
 * Busca códigos específicos en el texto de un PDF.
 *
 * @param string $pdfPath Ruta al PDF.
 * @param array $searchCodes Códigos a buscar.
 * @return array Códigos encontrados y no encontrados.
 */
function search_codes_in_pdf(string $pdfPath, array $searchCodes): array
{
    $text = extract_text_from_pdf($pdfPath);
    $textUpper = strtoupper($text);

    $found = [];
    $notFound = [];

    foreach ($searchCodes as $code) {
        $code = trim($code);
        if ($code === '')
            continue;

        if (stripos($textUpper, strtoupper($code)) !== false) {
            $found[] = $code;
        } else {
            $notFound[] = $code;
        }
    }

    return [
        'found' => $found,
        'not_found' => $notFound,
        'total_searched' => count($searchCodes),
        'total_found' => count($found)
    ];
}

/**
 * Prepara datos para enviar a Gemini AI para extracción inteligente.
 * Esta función estructura el texto para que la IA lo procese.
 *
 * @param string $text Texto del PDF.
 * @param string $documentType Tipo de documento (manifiesto, factura, etc).
 * @return array Datos estructurados para enviar a la IA.
 */
function prepare_for_ai_extraction(string $text, string $documentType = 'documento'): array
{
    return [
        'document_type' => $documentType,
        'text_content' => $text,
        'text_length' => strlen($text),
        'prompt' => "Analiza el siguiente documento de tipo '$documentType' y extrae todos los códigos de importación, referencias de productos, y datos estructurados relevantes.",
        'expected_fields' => [
            'codes' => 'Lista de códigos de productos/importación',
            'date' => 'Fecha del documento',
            'provider' => 'Proveedor o emisor',
            'total' => 'Valor total si aplica',
            'items' => 'Lista de items con cantidad y descripción'
        ],
        'instructions' => 'IMPORTANTE: Corrige errores comunes de OCR en los códigos. Si un código parece numérico pero tiene letras parecidas (O, G, S, Z, B), corrígelas a números (0, 6, 5, 2, 8). Si ves confusión entre "H" y "M", usa el contexto para decidir. Los códigos suelen tener al menos 2 caracteres. Ignora puntos o basura al final de los códigos. Prioriza la precisión.'
    ];
}
