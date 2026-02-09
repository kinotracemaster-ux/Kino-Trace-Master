<?php

use PHPUnit\Framework\TestCase;

/**
 * @runInSeparateProcess
 */
class CsrfProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock session
        if (session_status() === PHP_SESSION_NONE) {
            // Cannot verify session_start inside PHPUnit process easily if headers sent
            // But we can just populate $_SESSION manually if the class doesn't force session_start
            // CsrfProtection forces session_start if PHP_SESSION_NONE.
            // In CLI, session usually isn't started.
            @session_start();
        }
        $_SESSION = [];
    }

    public function testTokenGeneration()
    {
        $token = CsrfProtection::generateToken();
        $this->assertNotEmpty($token);
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    public function testValidation()
    {
        $token = CsrfProtection::generateToken();
        $this->assertTrue(CsrfProtection::validate($token));
        $this->assertFalse(CsrfProtection::validate('invalid_token'));
    }

    public function testMiddlewareBlocksInvalidPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'invalid';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSRF_INVALID');

        CsrfProtection::middleware();
    }

    public function testMiddlewareAllowsValidPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = CsrfProtection::generateToken();
        $_POST['csrf_token'] = $token;

        // Should not throw exception
        CsrfProtection::middleware();
        $this->assertTrue(true);
    }

    public function testMiddlewareIgnoresGet()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // No token provided, should pass
        CsrfProtection::middleware();
        $this->assertTrue(true);
    }
}
