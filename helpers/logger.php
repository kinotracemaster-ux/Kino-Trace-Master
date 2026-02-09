<?php
/**
 * Sistema de Logging Centralizado para KINO TRACE
 * 
 * Proporciona logging estructurado con niveles de severidad,
 * contexto automático y rotación de archivos.
 */

class Logger
{
    // Niveles de severidad
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    private static $logDir = null;
    private static $maxFileSize = 10485760; // 10MB
    private static $enableConsole = false;

    /**
     * Inicializa el directorio de logs
     */
    private static function init(): void
    {
        if (self::$logDir === null) {
            self::$logDir = defined('CLIENTS_DIR')
                ? CLIENTS_DIR . DIRECTORY_SEPARATOR . 'logs'
                : __DIR__ . '/../clients/logs';

            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0777, true);
            }
        }
    }

    /**
     * Escribe mensaje de log
     * 
     * @param string $level Nivel de severidad
     * @param string $message Mensaje principal
     * @param array $context Datos adicionales
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        self::init();

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => array_merge(
                self::getAutoContext(),
                $context
            )
        ];

        // Escribir a archivo general
        self::writeToFile('app.log', $entry);

        // Escribir a archivo específico si es error
        if (in_array($level, [self::ERROR, self::CRITICAL])) {
            self::writeToFile('error.log', $entry);
        }

        // Log por cliente si está disponible
        if (isset($_SESSION['client_code'])) {
            $clientCode = $_SESSION['client_code'];
            $clientDir = self::$logDir . DIRECTORY_SEPARATOR . $clientCode;
            if (!is_dir($clientDir)) {
                mkdir($clientDir, 0777, true);
            }
            self::writeToFile($clientCode . DIRECTORY_SEPARATOR . $clientCode . '.log', $entry);
        }

        // Output a consola en desarrollo
        if (self::$enableConsole && php_sapi_name() === 'cli') {
            echo "[{$entry['timestamp']}] {$level}: {$message}\n";
        }
    }

    /**
     * Obtiene contexto automático
     */
    private static function getAutoContext(): array
    {
        $context = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
        ];

        if (isset($_SESSION['client_code'])) {
            $context['client'] = $_SESSION['client_code'];
        }

        return $context;
    }

    /**
     * Escribe entrada al archivo de log
     */
    private static function writeToFile(string $filename, array $entry): void
    {
        $filepath = self::$logDir . DIRECTORY_SEPARATOR . $filename;

        // Rotación si el archivo es muy grande
        if (file_exists($filepath) && filesize($filepath) > self::$maxFileSize) {
            $backupPath = $filepath . '.' . date('YmdHis');
            rename($filepath, $backupPath);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($filepath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log nivel DEBUG
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Log nivel INFO
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Log nivel WARNING
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Log nivel ERROR
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * Log nivel CRITICAL
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    /**
     * Log de excepción completa
     */
    public static function exception(\Throwable $e, array $context = []): void
    {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        self::log(self::ERROR, 'Exception: ' . $e->getMessage(), $context);
    }

    /**
     * Habilita output a consola (útil para debugging)
     */
    public static function enableConsole(): void
    {
        self::$enableConsole = true;
    }
}
