<?php
/**
 * Integración con Google Gemini AI
 *
 * Proporciona funcionalidades de IA para:
 * - Extracción inteligente de datos de documentos
 * - Chat contextual con documentos
 * - Análisis y sugerencias automáticas
 *
 * Requiere API Key de Gemini: https://aistudio.google.com/app/apikey
 */

// Configuración de Gemini (se podría mover a config.php o variables de entorno)
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', 'gemini-2.0-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

/**
 * Verifica si la API de Gemini está configurada.
 *
 * @return bool True si hay API key configurada.
 */
function is_gemini_configured(): bool
{
    return GEMINI_API_KEY !== '';
}

/**
 * Llama a la API de Gemini con un prompt.
 *
 * @param string $prompt El prompt a enviar.
 * @param array $context Contexto adicional (opcional).
 * @return array Respuesta de la API.
 */
function call_gemini(string $prompt, array $context = []): array
{
    if (!is_gemini_configured()) {
        return [
            'success' => false,
            'error' => 'API de Gemini no configurada. Configure GEMINI_API_KEY.',
            'response' => null
        ];
    }

    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.4,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 1024,
        ]
    ];

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError) {
            return [
                'success' => false,
                'error' => 'Error de conexión con Gemini API: ' . ($curlError ?: 'sin respuesta'),
                'response' => null
            ];
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200 || isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error']['message'] ?? "Error HTTP $httpCode de Gemini",
                'response' => null
            ];
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'success' => true,
            'response' => $text,
            'raw' => $result
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'response' => null
        ];
    }
}

/**
 * Extrae datos estructurados de texto de documento usando Gemini.
 *
 * @param string $documentText Texto del documento.
 * @param string $documentType Tipo de documento.
 * @return array Datos extraídos.
 */
function ai_extract_document_data(string $documentText, string $documentType = 'documento'): array
{
    // Limitar texto para no exceder tokens
    $maxChars = 8000;
    if (strlen($documentText) > $maxChars) {
        $documentText = substr($documentText, 0, $maxChars) . '...';
    }

    $prompt = <<<PROMPT
Analiza el siguiente texto extraído de un documento de tipo "$documentType" y extrae la información estructurada.

Responde SOLO con un JSON válido con el siguiente formato:
{
    "numero_documento": "número o referencia del documento",
    "fecha": "fecha en formato YYYY-MM-DD si se encuentra",
    "proveedor": "nombre del proveedor o emisor",
    "codigos": ["lista", "de", "códigos", "de", "productos"],
    "valor_total": "valor total si aplica",
    "items": [
        {"codigo": "ABC123", "descripcion": "descripción", "cantidad": 1, "valor": 100}
    ],
    "notas": "observaciones importantes"
}

INSTRUCCIONES DE CORRECCIÓN OCR:
- El documento proviene de una extracción OCR y puede tener errores.
- Específicamente, corrige la confusión entre la letra "G" y el número "6" en códigos numéricos.
- Corrige la confusión visual entre "H" y "M".
- Elimina puntos, comas o guiones sueltos al final de los valores.
- Los códigos válidos tienen mínimo 2 caracteres. Ignora basura de 1 caracter.

Si un campo no se encuentra, usar null.

TEXTO DEL DOCUMENTO:
$documentText
PROMPT;

    $result = call_gemini($prompt);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'],
            'data' => null
        ];
    }

    // Intentar parsear el JSON de la respuesta
    $responseText = $result['response'];

    // Extraer JSON si viene con markdown
    if (preg_match('/```json?\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
        $responseText = $matches[1];
    }

    $data = json_decode($responseText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => true,
            'warning' => 'La respuesta no es JSON válido, retornando texto',
            'data' => null,
            'raw_response' => $result['response']
        ];
    }

    return [
        'success' => true,
        'data' => $data
    ];
}

/**
 * Chat contextual con el contenido de documentos.
 *
 * @param string $question Pregunta del usuario.
 * @param array $documentContext Contexto de documentos relevantes.
 * @return array Respuesta del chat.
 */
function ai_chat_with_context(string $question, array $documentContext): array
{
    $contextText = '';
    foreach ($documentContext as $doc) {
        $contextText .= "--- Documento: {$doc['tipo']} #{$doc['numero']} ---\n";
        $contextText .= "Fecha: {$doc['fecha']}\n";
        if (!empty($doc['codigos'])) {
            $contextText .= "Códigos: " . implode(', ', $doc['codigos']) . "\n";
        }
        $contextText .= "\n";
    }

    $prompt = <<<PROMPT
Eres un asistente experto en gestión documental y trazabilidad de importaciones.

CONTEXTO DE DOCUMENTOS:
$contextText

PREGUNTA DEL USUARIO:
$question

Responde de forma clara, concisa y profesional. Si la información no está disponible en el contexto, indícalo.
PROMPT;

    $result = call_gemini($prompt);

    return [
        'success' => $result['success'],
        'answer' => $result['response'] ?? '',
        'error' => $result['error'] ?? null
    ];
}

/**
 * Analiza discrepancias entre documentos vinculados.
 *
 * @param array $doc1 Datos del primer documento.
 * @param array $doc2 Datos del segundo documento.
 * @return array Análisis de discrepancias.
 */
function ai_analyze_discrepancies(array $doc1, array $doc2): array
{
    $prompt = <<<PROMPT
Analiza las discrepancias entre estos dos documentos de importación:

DOCUMENTO 1 ({$doc1['tipo']} #{$doc1['numero']}):
Códigos: {$doc1['codigos']}
Fecha: {$doc1['fecha']}

DOCUMENTO 2 ({$doc2['tipo']} #{$doc2['numero']}):
Códigos: {$doc2['codigos']}
Fecha: {$doc2['fecha']}

Identifica:
1. Códigos que están en Doc1 pero no en Doc2
2. Códigos que están en Doc2 pero no en Doc1
3. Posibles errores o inconsistencias
4. Recomendaciones

Responde en formato estructurado.
PROMPT;

    return call_gemini($prompt);
}

/**
 * Sugiere categorización automática de un documento.
 *
 * @param string $documentText Texto del documento.
 * @return array Sugerencia de tipo y metadatos.
 */
function ai_suggest_document_type(string $documentText): array
{
    $maxChars = 3000;
    if (strlen($documentText) > $maxChars) {
        $documentText = substr($documentText, 0, $maxChars) . '...';
    }

    $prompt = <<<PROMPT
Analiza el siguiente texto de un documento y determina qué tipo de documento es.

Tipos posibles:
- manifiesto: Documento de carga marítima/aérea
- declaracion: Declaración aduanera
- factura: Factura comercial
- packing_list: Lista de empaque
- certificado: Certificado de origen u otro
- reporte: Reporte o tabla de datos
- otro: Otro tipo

Responde con JSON: {"tipo": "tipo_sugerido", "confianza": 0.95, "razon": "explicación breve"}

TEXTO:
$documentText
PROMPT;

    $result = call_gemini($prompt);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error']];
    }

    $responseText = $result['response'];
    if (preg_match('/```json?\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
        $responseText = $matches[1];
    }

    $data = json_decode($responseText, true);

    return [
        'success' => true,
        'suggestion' => $data
    ];
}

/**
 * Chat inteligente que conoce toda la app KINO TRACE.
 * Puede buscar códigos, documentos y explicar funcionalidades.
 *
 * @param PDO $db Conexión a la base de datos del cliente.
 * @param string $question Pregunta del usuario.
 * @param string $clientCode Código del cliente.
 * @return array Respuesta del chat con enlaces a documentos si aplica.
 */
function ai_smart_chat(PDO $db, string $question, string $clientCode): array
{
    // Obtener estadísticas del sistema
    $stats = [
        'total_documentos' => $db->query("SELECT COUNT(*) FROM documentos")->fetchColumn(),
        'total_codigos' => $db->query("SELECT COUNT(*) FROM codigos")->fetchColumn(),
        'tipos_documentos' => $db->query("SELECT tipo, COUNT(*) as cnt FROM documentos GROUP BY tipo")->fetchAll(PDO::FETCH_ASSOC)
    ];

    // Buscar si la pregunta menciona un código específico
    $codigosEncontrados = [];
    $documentosRelacionados = [];

    // Extraer posibles códigos de la pregunta (patrones alfanuméricos)
    preg_match_all('/[A-Z]{2,4}[-\s]?\d{3,}[-\/]?\d*[-\/]?\d*/i', $question, $matches);
    $posiblesCodigos = array_unique($matches[0]);

    if (!empty($posiblesCodigos)) {
        foreach ($posiblesCodigos as $codigo) {
            $codigoLimpio = preg_replace('/[\s-]/', '', $codigo);
            $stmt = $db->prepare("
                SELECT c.codigo, d.id, d.numero, d.tipo, d.fecha, d.ruta_archivo
                FROM codigos c
                JOIN documentos d ON c.documento_id = d.id
                WHERE UPPER(REPLACE(c.codigo, '-', '')) LIKE UPPER(?)
                LIMIT 5
            ");
            $stmt->execute(['%' . $codigoLimpio . '%']);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($resultados as $r) {
                $codigosEncontrados[$r['codigo']] = $r;
                $documentosRelacionados[$r['id']] = $r;
            }
        }
    }

    // Buscar documentos recientes si no hay códigos específicos
    $docsRecientes = $db->query("
        SELECT d.id, d.numero, d.tipo, d.fecha, d.ruta_archivo,
               (SELECT COUNT(*) FROM codigos WHERE documento_id = d.id) as total_codigos
        FROM documentos d
        ORDER BY d.fecha DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Preparar tipos de documentos
    $tiposTexto = formatTiposDocumentos($stats['tipos_documentos']);

    // Obtener contenido del manual
    $manualPath = __DIR__ . '/../APP_MANUAL.md';
    $manualContent = '';
    if (file_exists($manualPath)) {
        $manualContent = file_get_contents($manualPath);
    }

    // Construir contexto para Gemini
    $appContext = "ERES EL ASISTENTE VIRTUAL DE KINO TRACE - Sistema de Trazabilidad de Documentos

=== MANUAL DE LA APLICACIÓN ===
{$manualContent}

=== ESTADÍSTICAS ACTUALES ===
- Total de documentos: {$stats['total_documentos']}
- Total de códigos enlazados: {$stats['total_codigos']}
- Tipos de documentos: {$tiposTexto}
";

    // Agregar información de códigos encontrados
    if (!empty($codigosEncontrados)) {
        $appContext .= "\n=== CÓDIGOS ENCONTRADOS EN LA PREGUNTA ===\n";
        foreach ($codigosEncontrados as $codigo => $info) {
            $appContext .= "Código: {$codigo}\n";
            $appContext .= "  - Documento: {$info['tipo']} #{$info['numero']}\n";
            $appContext .= "  - Fecha: {$info['fecha']}\n";
            $appContext .= "  - ID Documento: {$info['id']}\n";
            if ($info['ruta_archivo']) {
                $appContext .= "  - PDF disponible: Sí\n";
            }
            $appContext .= "\n";
        }
    }

    // Agregar documentos recientes
    $appContext .= "\n=== DOCUMENTOS RECIENTES ===\n";
    foreach ($docsRecientes as $doc) {
        $appContext .= "- {$doc['tipo']} #{$doc['numero']} (ID: {$doc['id']}, {$doc['total_codigos']} códigos, Fecha: {$doc['fecha']})\n";
    }

    $prompt = <<<PROMPT
{$appContext}

=== TU IDENTIDAD ===
Eres KINO, el asistente experto de KINO TRACE. Eres conciso, directo y útil.

=== CÓMO RAZONAS (internamente, no lo muestres) ===
1. ¿Qué está preguntando realmente el usuario?
2. ¿Tengo datos relevantes en el contexto?
3. ¿Cuál es la respuesta más útil y directa?

=== ESTILO DE RESPUESTA ===
- **Conciso**: Respuestas cortas y directas. No rellenes.
- **Estructurado**: Usa listas o bullets cuando ayude.
- **Actionable**: Da pasos concretos cuando pregunten "cómo".
- **Inteligente**: Conecta datos, infiere, sugiere.
- **Sin Límites de Lógica**: Razona libremente sobre cualquier tema de documentos.

=== FORMATO ===
- Máximo 3-4 párrafos cortos o una lista de bullets
- Si mencionas un documento, incluye [DOC:ID:NUMERO] para crear enlace
- Si mencionas un código, usa [CODE:CODIGO]
- Evita introducciones largas como "¡Claro!" o "Por supuesto que puedo ayudarte"

=== RESTRICCIÓN ÚNICA ===
Solo bloquea: claves, contraseñas, rutas del servidor, config interna.
Todo lo demás: responde con libertad y lógica.

=== PREGUNTA ===
{$question}

Responde directamente:
PROMPT;

    $result = call_gemini($prompt);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'],
            'response' => null
        ];
    }

    $response = $result['response'];

    // Procesar la respuesta para crear enlaces reales
    // Reemplazar [DOC:ID:NUMERO] con enlaces HTML
    $response = preg_replace_callback(
        '/\[DOC:(\d+):([^\]]+)\]/',
        function ($matches) {
            return '<a href="../resaltar/viewer.php?doc=' . $matches[1] . '" class="chat-link">' . htmlspecialchars($matches[2]) . '</a>';
        },
        $response
    );

    // Reemplazar [CODE:CODIGO] con badges
    $response = preg_replace_callback(
        '/\[CODE:([^\]]+)\]/',
        function ($matches) {
            return '<span class="chat-code">' . htmlspecialchars($matches[1]) . '</span>';
        },
        $response
    );

    // Agregar documentos relacionados para mostrar como tarjetas
    $docCards = [];
    foreach ($documentosRelacionados as $doc) {
        $docCards[] = [
            'id' => $doc['id'],
            'numero' => $doc['numero'],
            'tipo' => $doc['tipo'],
            'fecha' => $doc['fecha'],
            'ruta_archivo' => $doc['ruta_archivo']
        ];
    }

    return [
        'success' => true,
        'response' => $response,
        'documents' => $docCards,
        'codes_found' => array_keys($codigosEncontrados)
    ];
}

/**
 * Formatea los tipos de documentos para el contexto.
 */
function formatTiposDocumentos(array $tipos): string
{
    $result = [];
    foreach ($tipos as $t) {
        $result[] = "{$t['tipo']}: {$t['cnt']}";
    }
    return implode(', ', $result);
}
