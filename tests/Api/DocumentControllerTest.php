<?php

use PHPUnit\Framework\TestCase;
use Kino\Api\DocumentController;

class DocumentControllerTest extends TestCase
{
    private $db;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock PDO connection
        $this->db = $this->createMock(PDO::class);
        $this->controller = new DocumentController($this->db, 'TEST_CLIENT');
    }

    public function testUploadValidationFailsIfFieldsMissing()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON_EXIT');

        // Capture output
        $this->expectOutputRegex('/Campos requeridos faltantes/');

        // Empty post/files to trigger validation failure
        $this->controller->upload([], []);
    }
}
