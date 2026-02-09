<?php
/**
 * Envoltorio para funciones de inteligencia artificial.
 *
 * Este módulo sirve como punto de integración para motores de IA como
 * Claude u OpenAI. Actualmente expone stubs que retornan valores
 * predecibles mientras se desarrolla la implementación real. El diseño
 * permite sustituir fácilmente estas funciones por llamadas a APIs
 * externas sin afectar al resto del código.
 */

/**
 * Extrae datos estructurados de un archivo PDF.
 *
 * En una implementación real esta función invocaría un servicio de OCR y
 * extracción de datos que devuelva un array asociativo con los campos
 * detectados (por ejemplo, número de factura, fecha, proveedor, etc.).
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @return array Datos extraídos del PDF.
 */
function ai_extract_data_from_pdf(string $pdfPath): array
{
    // TODO: Integrar motor OCR e IA para extracción real de datos.
    // Por ahora retornamos un array vacío para no romper el flujo.
    return [];
}

/**
 * Realiza una consulta de chat sobre los documentos de un cliente.
 *
 * Esta función recibirá una pregunta del usuario y devolverá una
 * respuesta generada por IA basada en la información disponible en la
 * base de datos. Se puede implementar con embeddings y búsqueda
 * semántica o un proveedor de chat que acepte contextos.
 *
 * @param string $clientCode Código del cliente.
 * @param string $query Consulta del usuario.
 * @return string Respuesta generada por IA.
 */
function ai_chat_with_docs(string $clientCode, string $query): string
{
    // TODO: Implementar búsqueda semántica y generación de respuesta.
    return 'Función de chat aún no implementada.';
}

/**
 * Genera un reporte inteligente según parámetros proporcionados.
 *
 * Este método podría aceptar parámetros como rango de fechas, tipo de
 * documento y formato de salida. La implementación real podría
 * combinar datos de la base y generación de documentos (PDF/Excel).
 *
 * @param string $clientCode Código del cliente.
 * @param array $params Parámetros del reporte.
 * @return string Ruta al archivo generado o mensaje de error.
 */
function ai_generate_report(string $clientCode, array $params): string
{
    // TODO: Implementar generación de reportes automatizados.
    return 'Generación de reportes aún no implementada.';
}

?>