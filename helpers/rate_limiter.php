<?php
/**
 * Rate Limiter Middleware
 * 
 * Previene abuso de API mediante limitación de requests por IP
 * Protege contra DDoS y saturación del servidor
 */

class RateLimiter
{
    private const LIMIT = 100; // Requests por ventana
    private const WINDOW = 60; // Segundos
    private const STORAGE_FILE = 'rate_limits.json';

    private static $storage = null;

    /**
     * Inicializa el almacenamiento
     */
    private static function init(): void
    {
        if (self::$storage === null) {
            $dir = CLIENTS_DIR . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$storage = $dir . '/' . self::STORAGE_FILE;

            // Cargar límites existentes
            if (!file_exists(self::$storage)) {
                file_put_contents(self::$storage, json_encode([]));
            }
        }
    }

    /**
     * Verifica si la IP actual ha excedido el límite
     */
    public static function check(?string $ip = null): array
    {
        self::init();

        $ip = $ip ?? self::getClientIp();
        $key = self::getKey($ip);

        // Cargar datos
        $data = self::loadData();

        // Limpiar entradas expiradas
        $data = self::cleanup($data);

        // Verificar límite
        if (isset($data[$key])) {
            $attempts = $data[$key]['attempts'];
            $resetTime = $data[$key]['reset_time'];

            // Si la ventana expiró, resetear
            if (time() > $resetTime) {
                $data[$key] = [
                    'attempts' => 1,
                    'reset_time' => time() + self::WINDOW
                ];
            } else {
                // Incrementar intentos
                $data[$key]['attempts']++;
                $attempts = $data[$key]['attempts'];

                // Verificar si excedió límite
                if ($attempts > self::LIMIT) {
                    $retryAfter = $resetTime - time();

                    self::saveData($data);

                    return [
                        'allowed' => false,
                        'limit' => self::LIMIT,
                        'remaining' => 0,
                        'retry_after' => $retryAfter,
                        'message' => "Demasiados requests. Intenta en $retryAfter segundos."
                    ];
                }
            }
        } else {
            // Primera vez
            $data[$key] = [
                'attempts' => 1,
                'reset_time' => time() + self::WINDOW
            ];
        }

        // Guardar
        self::saveData($data);

        $remaining = self::LIMIT - $data[$key]['attempts'];

        return [
            'allowed' => true,
            'limit' => self::LIMIT,
            'remaining' => max(0, $remaining),
            'reset_time' => $data[$key]['reset_time']
        ];
    }

    /**
     * Middleware para aplicar en API
     */
    public static function middleware(): void
    {
        $result = self::check();

        if (!headers_sent()) {
            // Agregar headers de rate limiting
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: ' . $result['remaining']);

            if (isset($result['reset_time'])) {
                header('X-RateLimit-Reset: ' . $result['reset_time']);
            }
        }

        if (!$result['allowed']) {
            if (!headers_sent()) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $result['retry_after']);
                header('Content-Type: application/json');
            }

            echo json_encode([
                'error' => $result['message'],
                'retry_after' => $result['retry_after']
            ]);

            Logger::warning('Rate limit exceeded', [
                'ip' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);

            if (defined('PHPUNIT_RUNNING')) {
                throw new \RuntimeException('RATE_LIMIT_EXCEEDED');
            }

            exit;
        }
    }

    /**
     * Obtiene la IP real del cliente
     */
    private static function getClientIp(): string
    {
        // Verificar proxy headers
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Proxy estándar
            'HTTP_X_REAL_IP',        // Nginx
            'REMOTE_ADDR'            // Directo
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Si hay múltiples IPs (proxy chain), tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validar que es una IP válida
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Genera key única por IP
     */
    private static function getKey(string $ip): string
    {
        return 'ratelimit:' . md5($ip);
    }

    /**
     * Carga datos del archivo
     */
    private static function loadData(): array
    {
        $content = file_get_contents(self::$storage);
        return json_decode($content, true) ?? [];
    }

    /**
     * Guarda datos al archivo
     */
    private static function saveData(array $data): void
    {
        file_put_contents(self::$storage, json_encode($data), LOCK_EX);
    }

    /**
     * Limpia entradas expiradas (más de 1 hora)
     */
    private static function cleanup(array $data): array
    {
        $now = time();
        $cleaned = [];

        foreach ($data as $key => $value) {
            // Mantener solo si no ha expirado hace más de 1 hora
            if (isset($value['reset_time']) && ($value['reset_time'] + 3600) > $now) {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Resetea límites de una IP específica (para testing o admin)
     */
    public static function reset(string $ip): void
    {
        self::init();
        $data = self::loadData();
        $key = self::getKey($ip);

        unset($data[$key]);
        self::saveData($data);
    }

    /**
     * Obtiene estadísticas de rate limiting
     */
    public static function getStats(): array
    {
        self::init();
        $data = self::loadData();

        $stats = [
            'total_ips' => count($data),
            'blocked_ips' => 0,
            'top_requesters' => []
        ];

        foreach ($data as $key => $value) {
            if ($value['attempts'] > self::LIMIT) {
                $stats['blocked_ips']++;
            }

            $stats['top_requesters'][] = [
                'attempts' => $value['attempts'],
                'reset_time' => $value['reset_time']
            ];
        }

        // Ordenar por intentos
        usort($stats['top_requesters'], function ($a, $b) {
            return $b['attempts'] - $a['attempts'];
        });

        $stats['top_requesters'] = array_slice($stats['top_requesters'], 0, 10);

        return $stats;
    }
}
