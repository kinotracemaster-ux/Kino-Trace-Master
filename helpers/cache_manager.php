<?php
/**
 * CacheManager Optimizado v2
 * 
 * Mejoras:
 * - Cache en memoria para evitar lecturas repetidas de disco
 * - GC optimizado usando tiempo de modificación del archivo
 * - Estadísticas de uso del cache
 * - Manejo de errores mejorado
 */

class CacheManager
{
    private static $cacheDir = null;
    private static $gcProbability = 2; // 2% probabilidad de GC

    // Cache en memoria para evitar lecturas repetidas
    private static $memoryCache = [];
    private static $memoryCacheLimit = 50; // Máximo items en memoria

    // Estadísticas
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0
    ];

    private static function init($clientCode)
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = dirname(__DIR__) . '/clients/' . $clientCode . '/cache';

            if (!is_dir(self::$cacheDir)) {
                if (!@mkdir(self::$cacheDir, 0755, true)) {
                    error_log("CacheManager: Failed to create cache dir: " . self::$cacheDir);
                    return false;
                }
                // Proteger carpeta de acceso web
                @file_put_contents(self::$cacheDir . '/.htaccess', "Order Deny,Allow\nDeny from all");
            }
        }
        return true;
    }

    /**
     * Guardar en cache
     */
    public static function set($clientCode, $key, $data, $ttl = 300)
    {
        if (!self::init($clientCode))
            return false;

        // Garbage Collector Probabilístico
        if (rand(1, 100) <= self::$gcProbability) {
            self::gc($clientCode);
        }

        $filename = self::getFilename($key);
        $expiry = time() + $ttl;
        $payload = [
            'expiry' => $expiry,
            'created' => time(),
            'data' => $data
        ];

        // Guardar en disco
        $result = @file_put_contents($filename, json_encode($payload), LOCK_EX);

        if ($result !== false) {
            // También guardar en memoria
            self::setMemoryCache($key, $data, $expiry);
            self::$stats['writes']++;
            return true;
        }

        return false;
    }

    /**
     * Obtener del cache
     */
    public static function get($clientCode, $key)
    {
        if (!self::init($clientCode))
            return null;

        // 1. Verificar cache en memoria primero (más rápido)
        $memoryResult = self::getMemoryCache($key);
        if ($memoryResult !== null) {
            self::$stats['hits']++;
            return $memoryResult;
        }

        // 2. Verificar cache en disco
        $filename = self::getFilename($key);

        if (!file_exists($filename)) {
            self::$stats['misses']++;
            return null;
        }

        // Verificación rápida: si archivo es muy viejo, probablemente expiró
        $fileAge = time() - filemtime($filename);
        if ($fileAge > 604800) { // Más de 7 días
            @unlink($filename);
            self::$stats['misses']++;
            return null;
        }

        $content = @file_get_contents($filename);
        if (!$content) {
            self::$stats['misses']++;
            return null;
        }

        $payload = json_decode($content, true);

        if (!$payload || !isset($payload['expiry']) || time() > $payload['expiry']) {
            // Expirado - borrar archivo
            @unlink($filename);
            self::$stats['misses']++;
            return null;
        }

        // Guardar en memoria para próximo acceso
        self::setMemoryCache($key, $payload['data'], $payload['expiry']);
        self::$stats['hits']++;

        return $payload['data'];
    }

    /**
     * Borrar item específico
     */
    public static function delete($clientCode, $key)
    {
        if (!self::init($clientCode))
            return;

        $filename = self::getFilename($key);
        if (file_exists($filename)) {
            @unlink($filename);
        }

        // También borrar de memoria
        unset(self::$memoryCache[$key]);
    }

    /**
     * Limpiar todo el cache
     */
    public static function clear($clientCode)
    {
        if (!self::init($clientCode))
            return;

        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Limpiar memoria
        self::$memoryCache = [];
    }

    /**
     * Garbage Collector optimizado
     * Usa tiempo de modificación del archivo para verificación rápida
     */
    public static function gc($clientCode)
    {
        if (!self::init($clientCode))
            return 0;

        $files = glob(self::$cacheDir . '/*.cache');
        $now = time();
        $deleted = 0;
        $maxAge = 604800; // 7 días máximo

        foreach ($files as $file) {
            if (!is_file($file))
                continue;

            // Verificación rápida por edad del archivo
            $fileAge = $now - filemtime($file);

            if ($fileAge > $maxAge) {
                // Archivo muy viejo, borrar directamente
                @unlink($file);
                $deleted++;
                continue;
            }

            // Solo leer contenido si archivo no es muy viejo
            $content = @file_get_contents($file);
            if ($content) {
                $payload = json_decode($content, true);
                if (isset($payload['expiry']) && $now > $payload['expiry']) {
                    @unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Obtener estadísticas del cache
     */
    public static function stats($clientCode)
    {
        if (!self::init($clientCode))
            return null;

        $files = glob(self::$cacheDir . '/*.cache');
        $totalSize = 0;
        $count = count($files);

        foreach ($files as $file) {
            $totalSize += filesize($file);
        }

        return [
            'files' => $count,
            'size_bytes' => $totalSize,
            'size_mb' => round($totalSize / 1024 / 1024, 2),
            'memory_items' => count(self::$memoryCache),
            'hits' => self::$stats['hits'],
            'misses' => self::$stats['misses'],
            'writes' => self::$stats['writes'],
            'hit_rate' => self::$stats['hits'] + self::$stats['misses'] > 0
                ? round(self::$stats['hits'] / (self::$stats['hits'] + self::$stats['misses']) * 100, 1) . '%'
                : 'N/A'
        ];
    }

    // ============================================
    // Cache en Memoria (intra-request)
    // ============================================

    private static function setMemoryCache($key, $data, $expiry)
    {
        // Limitar tamaño de cache en memoria
        if (count(self::$memoryCache) >= self::$memoryCacheLimit) {
            // Eliminar el más viejo
            array_shift(self::$memoryCache);
        }

        self::$memoryCache[$key] = [
            'data' => $data,
            'expiry' => $expiry
        ];
    }

    private static function getMemoryCache($key)
    {
        if (!isset(self::$memoryCache[$key])) {
            return null;
        }

        $cached = self::$memoryCache[$key];

        // Verificar expiración
        if (time() > $cached['expiry']) {
            unset(self::$memoryCache[$key]);
            return null;
        }

        return $cached['data'];
    }

    private static function getFilename($key)
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }
}
