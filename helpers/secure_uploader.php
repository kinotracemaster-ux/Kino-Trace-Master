<?php
/**
 * Secure File Uploader
 * 
 * Valida y sanitiza uploads de archivos PDF con múltiples capas de seguridad
 * para prevenir ejecución remota de código y otros exploits.
 */

class SecureFileUploader
{
    private const MAX_SIZE = 10485760; // 10MB
    private const ALLOWED_EXTENSIONS = ['pdf'];
    private const ALLOWED_MIME = 'application/pdf';
    private const PDF_MAGIC_BYTES = '%PDF';

    /**
     * Valida que el archivo subido sea un PDF legítimo
     */
    public static function validate($file): array
    {
        // 1. Verificar que el archivo existe
        $isTest = defined('PHPUNIT_RUNNING');
        if (!isset($file['tmp_name']) || (!$isTest && !is_uploaded_file($file['tmp_name'])) || ($isTest && !file_exists($file['tmp_name']))) {
            return ['error' => 'Archivo no válido o no subido'];
        }

        // 2. Verificar errores de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Error en la subida: ' . self::getUploadError($file['error'])];
        }

        // 3. Verificar tamaño
        if ($file['size'] > self::MAX_SIZE) {
            return ['error' => 'Archivo muy grande. Máximo 10MB'];
        }

        if ($file['size'] === 0) {
            return ['error' => 'Archivo vacío'];
        }

        // 4. Verificar extensión
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return ['error' => 'Solo se permiten archivos PDF'];
        }

        // 5. Verificar MIME type REAL (no confiar en el cliente)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($mimeType !== self::ALLOWED_MIME) {
                return ['error' => 'El archivo no es un PDF válido (MIME: ' . $mimeType . ')'];
            }
        }

        // 6. Verificar magic bytes (primeros bytes del archivo)
        $handle = fopen($file['tmp_name'], 'rb');
        if (!$handle) {
            return ['error' => 'No se puede leer el archivo'];
        }

        $header = fread($handle, 4);
        fclose($handle);

        if (substr($header, 0, 4) !== self::PDF_MAGIC_BYTES) {
            return ['error' => 'El archivo no tiene el formato de un PDF válido'];
        }

        // PDF válido - las validaciones anteriores son suficientes
        // La verificación de duplicados se hace a nivel de hash en api.php

        return ['success' => true];
    }

    /**
     * Sanitiza el nombre del archivo
     */
    public static function sanitizeFilename(string $originalName): string
    {
        // Remover path traversal (../)
        $name = basename($originalName);

        // Obtener extensión segura
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Generar nombre único y seguro
        $safeName = uniqid('doc_', true) . '.' . $extension;

        return $safeName;
    }

    /**
     * Mueve el archivo a una ubicación segura FUERA del webroot
     */
    public static function secureMove($file, string $clientCode, string $tipo): array
    {
        // Validar primero
        $validation = self::validate($file);
        if (isset($validation['error'])) {
            return $validation;
        }

        $clientCode = sanitize_code($clientCode);

        // Directorio del cliente (ya existe por tenant.php)
        $clientDir = CLIENTS_DIR . '/' . $clientCode;
        $uploadDir = $clientDir . '/uploads/' . $tipo;

        // Crear directorio si no existe
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Nombre seguro
        $safeName = self::sanitizeFilename($file['name']);
        $targetPath = $uploadDir . '/' . $safeName;

        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['error' => 'No se pudo guardar el archivo'];
        }

        // Cambiar permisos para que no sea ejecutable
        chmod($targetPath, 0644);

        // Calcular hash para detección de duplicados
        $hash = hash_file('sha256', $targetPath);

        return [
            'success' => true,
            'filename' => $safeName,
            'path' => $tipo . '/' . $safeName,
            'hash' => $hash,
            'size' => filesize($targetPath)
        ];
    }

    /**
     * Traduce códigos de error de PHP a mensajes legibles
     */
    private static function getUploadError(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Archivo excede límite del servidor',
            UPLOAD_ERR_FORM_SIZE => 'Archivo excede límite del formulario',
            UPLOAD_ERR_PARTIAL => 'Archivo subido parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco',
            UPLOAD_ERR_EXTENSION => 'Subida detenida por extensión PHP'
        ];

        return $errors[$errorCode] ?? 'Error desconocido';
    }

    /**
     * Verifica si un archivo ya existe por su hash
     */
    public static function checkDuplicate(PDO $db, string $hash): ?array
    {
        $stmt = $db->prepare("SELECT id, numero FROM documentos WHERE hash_archivo = ? LIMIT 1");
        $stmt->execute([$hash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }
}
