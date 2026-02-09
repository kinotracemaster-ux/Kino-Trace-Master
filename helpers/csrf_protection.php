<?php
/**
 * CSRF Protection
 * 
 * Previene ataques Cross-Site Request Forgery
 * Valida que los requests POST/DELETE vengan del sitio legítimo
 */

class CsrfProtection
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Genera un token CSRF y lo guarda en sesión
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;

        return $token;
    }

    /**
     * Obtiene el token actual de la sesión
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return self::generateToken();
        }

        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Valida que el token recibido coincida con el de la sesión
     */
    public static function validate(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        if ($token === null || $token === '') {
            return false;
        }

        // Usar hash_equals para prevenir timing attacks
        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }

    /**
     * Middleware para proteger endpoints
     */
    public static function middleware(): void
    {
        // Solo verificar en requests que modifican datos
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return;
        }

        // Obtener token del request
        $token = $_POST[self::TOKEN_NAME]
            ?? $_GET[self::TOKEN_NAME]
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? null;

        if (!self::validate($token)) {
            if (!headers_sent()) {
                header('HTTP/1.1 403 Forbidden');
                header('Content-Type: application/json');
            }

            echo json_encode([
                'error' => 'CSRF token inválido o faltante',
                'code' => 'CSRF_INVALID'
            ]);

            Logger::warning('CSRF attack detected', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'endpoint' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
                'received_token' => $token ? substr($token, 0, 5) . '...' : 'NULL',
                'expected_token' => isset($_SESSION[self::TOKEN_NAME]) ? substr($_SESSION[self::TOKEN_NAME], 0, 5) . '...' : 'NOT_SET',
                'session_status' => session_status()
            ]);

            if (defined('PHPUNIT_RUNNING')) {
                throw new \RuntimeException('CSRF_INVALID');
            }

            exit;
        }
    }

    /**
     * Genera HTML para input hidden con token
     */
    public static function tokenField(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="' . self::TOKEN_NAME . '" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Genera meta tag para AJAX requests
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
}
