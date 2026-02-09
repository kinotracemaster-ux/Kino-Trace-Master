<?php
/**
 * Catálogo de Códigos de Error Estandarizados - KINO TRACE
 * 
 * Define todos los errores posibles del sistema con códigos únicos,
 * mensajes claros y códigos HTTP apropiados.
 */

require_once __DIR__ . '/logger.php';

/**
 * Obtiene el mapa completo de códigos de error
 */
function get_error_map(): array
{
    return [
        // ====== AUTENTICACIÓN ======
        'AUTH_001' => [
            'message' => 'Credenciales inválidas. Verifica tu código de cliente y contraseña.',
            'http' => 401
        ],
        'AUTH_002' => [
            'message' => 'Sesión expirada. Por favor inicia sesión nuevamente.',
            'http' => 401
        ],
        'AUTH_003' => [
            'message' => 'Cliente no existe en el sistema.',
            'http' => 404
        ],
        'AUTH_004' => [
            'message' => 'No tienes permisos para realizar esta acción.',
            'http' => 403
        ],

        // ====== BASE DE DATOS ======
        'DB_001' => [
            'message' => 'Error al conectar con la base de datos. Intenta nuevamente.',
            'http' => 500
        ],
        'DB_002' => [
            'message' => 'Error al ejecutar consulta en la base de datos.',
            'http' => 500
        ],
        'DB_003' => [
            'message' => 'Registro no encontrado en la base de datos.',
            'http' => 404
        ],
        'DB_004' => [
            'message' => 'Error de integridad de datos. Verifica que no haya duplicados.',
            'http' => 409
        ],

        // ====== ARCHIVOS ======
        'FILE_001' => [
            'message' => 'Archivo no encontrado en el servidor.',
            'http' => 404
        ],
        'FILE_002' => [
            'message' => 'Tipo de archivo inválido. Solo se permiten archivos PDF.',
            'http' => 400
        ],
        'FILE_003' => [
            'message' => 'Archivo muy grande. El tamaño máximo permitido es 10MB.',
            'http' => 413
        ],
        'FILE_004' => [
            'message' => 'Error al subir el archivo. Intenta nuevamente.',
            'http' => 500
        ],
        'FILE_005' => [
            'message' => 'No se recibió ningún archivo.',
            'http' => 400
        ],
        'FILE_006' => [
            'message' => 'Error al eliminar el archivo del servidor.',
            'http' => 500
        ],

        // ====== EXTRACCIÓN PDF ======
        'PDF_001' => [
            'message' => 'Herramienta de extracción PDF no disponible. Contacta al administrador.',
            'http' => 500
        ],
        'PDF_002' => [
            'message' => 'PDF corrupto, protegido con contraseña o no se puede leer.',
            'http' => 422
        ],
        'PDF_003' => [
            'message' => 'PDF no contiene texto extraíble. Puede requerir OCR.',
            'http' => 422
        ],
        'PDF_004' => [
            'message' => 'Tiempo de extracción excedido. El PDF es muy complejo o grande.',
            'http' => 504
        ],
        'PDF_005' => [
            'message' => 'No se encontraron códigos en el PDF usando el patrón especificado.',
            'http' => 200
        ],

        // ====== VALIDACIÓN ======
        'VALIDATION_001' => [
            'message' => 'Campos requeridos faltantes. Completa todos los campos obligatorios.',
            'http' => 400
        ],
        'VALIDATION_002' => [
            'message' => 'Formato de fecha inválido. Usa el formato YYYY-MM-DD.',
            'http' => 400
        ],
        'VALIDATION_003' => [
            'message' => 'Código de cliente inválido. Solo se permiten letras, números y guiones bajos.',
            'http' => 400
        ],
        'VALIDATION_004' => [
            'message' => 'El valor proporcionado está fuera del rango permitido.',
            'http' => 400
        ],
        'VALIDATION_005' => [
            'message' => 'Formato de email inválido.',
            'http' => 400
        ],

        // ====== API ======
        'API_001' => [
            'message' => 'Acción no reconocida o no soportada.',
            'http' => 400
        ],
        'API_002' => [
            'message' => 'Método HTTP no permitido para esta acción.',
            'http' => 405
        ],
        'API_003' => [
            'message' => 'Límite de peticiones excedido. Intenta más tarde.',
            'http' => 429
        ],
        'API_004' => [
            'message' => 'Parámetros inválidos en la petición.',
            'http' => 400
        ],

        // ====== BÚSQUEDA ======
        'SEARCH_001' => [
            'message' => 'No se proporcionaron códigos para buscar.',
            'http' => 400
        ],
        'SEARCH_002' => [
            'message' => 'El término de búsqueda debe tener al menos 3 caracteres.',
            'http' => 400
        ],
        'SEARCH_003' => [
            'message' => 'No se encontraron resultados para tu búsqueda.',
            'http' => 200
        ],

        // ====== DOCUMENTOS ======
        'DOC_001' => [
            'message' => 'Documento no encontrado.',
            'http' => 404
        ],
        'DOC_002' => [
            'message' => 'Ya existe un documento con ese número.',
            'http' => 409
        ],
        'DOC_003' => [
            'message' => 'Error al procesar el documento.',
            'http' => 500
        ],

        // ====== SISTEMA ======
        'SYS_001' => [
            'message' => 'Error interno del servidor. El equipo técnico ha sido notificado.',
            'http' => 500
        ],
        'SYS_002' => [
            'message' => 'Servicio temporalmente no disponible. Intenta más tarde.',
            'http' => 503
        ],
        'SYS_003' => [
            'message' => 'Espacio en disco insuficiente.',
            'http' => 507
        ]
    ];
}

/**
 * Genera respuesta de error estandarizada para API
 * 
 * @param string $code Código de error (ej: 'AUTH_001')
 * @param string|null $customMessage Mensaje personalizado (opcional)
 * @param array $context Contexto adicional para logging
 * @param bool $includeContext Si incluir contexto en respuesta (solo en debug)
 * @return array Array con estructura de error para JSON
 */
function api_error(
    string $code,
    ?string $customMessage = null,
    array $context = [],
    bool $includeContext = false
): array {
    $errorMap = get_error_map();

    // Si el código no existe, usar error genérico
    if (!isset($errorMap[$code])) {
        Logger::warning('Unknown error code used', ['code' => $code]);
        $code = 'SYS_001';
    }

    $error = $errorMap[$code];
    $message = $customMessage ?? $error['message'];

    // Log del error
    Logger::error("API Error: $code - $message", array_merge($context, [
        'error_code' => $code,
        'http_code' => $error['http']
    ]));

    $response = [
        'error' => true,
        'code' => $code,
        'message' => $message,
        'http_code' => $error['http'],
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Solo incluir contexto en modo debug
    if ($includeContext && (getenv('APP_ENV') === 'development' || getenv('DEBUG') === 'true')) {
        $response['context'] = $context;
    }

    return $response;
}

/**
 * Valida campos requeridos en un array
 * 
 * @param array $data Array con los datos a validar
 * @param array $required Lista de campos requeridos
 * @return array|null Array de error si falta algo, null si todo bien
 */
function validate_required_fields(array $data, array $required): ?array
{
    $missing = [];

    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        return api_error(
            'VALIDATION_001',
            'Campos requeridos faltantes: ' . implode(', ', $missing),
            ['missing_fields' => $missing]
        );
    }

    return null;
}

/**
 * Valida tipo de archivo
 * 
 * @param array $file Array $_FILES
 * @param array $allowedTypes Tipos MIME permitidos
 * @return array|null Array de error o null si válido
 */
function validate_file_type(array $file, array $allowedTypes = ['application/pdf']): ?array
{
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return api_error('FILE_005');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return api_error('FILE_002', null, [
            'detected_type' => $mimeType,
            'allowed_types' => $allowedTypes
        ]);
    }

    return null;
}

/**
 * Valida tamaño de archivo
 * 
 * @param array $file Array $_FILES
 * @param int $maxSize Tamaño máximo en bytes (default: 10MB)
 * @return array|null Array de error o null si válido
 */
function validate_file_size(array $file, int $maxSize = 10485760): ?array
{
    if ($file['size'] > $maxSize) {
        return api_error('FILE_003', null, [
            'file_size' => $file['size'],
            'max_size' => $maxSize,
            'file_size_mb' => round($file['size'] / 1048576, 2),
            'max_size_mb' => round($maxSize / 1048576, 2)
        ]);
    }

    return null;
}

/**
 * Envía respuesta de error y termina ejecución
 */
function send_error_response(array $error): void
{
    http_response_code($error['http_code'] ?? 500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    exit;
}
