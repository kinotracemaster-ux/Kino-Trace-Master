<?php

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    // Need to clean up storage file before/after tests
    // Using reflection to reset or just deleting the file in CLIENTS_DIR/logs/rate_limits.json

    protected function setUp(): void
    {
        // Limpiar archivo de estado antes de cada prueba
        $file = CLIENTS_DIR . '/logs/rate_limits.json';
        if (file_exists($file)) {
            unlink($file);
        }

        // Reset internal static cache if possible (using Reflection) or just rely on init() logic
        // The class has private static $storage.
        $reflection = new ReflectionClass('RateLimiter');
        $prop = $reflection->getProperty('storage');
        $prop->setAccessible(true);
        $prop->setValue(null);
    }

    public function testRateLimitingEnforcement()
    {
        // Call middleware 100 times (LIMIT)
        for ($i = 0; $i < 100; $i++) {
            RateLimiter::middleware();
        }

        // 101st call should throw exception (mocking exit)
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RATE_LIMIT_EXCEEDED');

        // Also check if header 429 was "sent" (xdebug_get_headers is needed or mock header func)
        // Since we can't easily mock header() without namespacing tricks, we just check exception.

        RateLimiter::middleware();
    }
}
