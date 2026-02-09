<?php

use PHPUnit\Framework\TestCase;
use Kino\Api\SearchController;

class SearchControllerTest extends TestCase
{
    private $db;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock PDO connection
        $this->db = $this->createMock(PDO::class);
        $this->controller = new SearchController($this->db, 'TEST_CLIENT');
    }

    public function testSearchFailsIfNoCodesProvided()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON_EXIT');
        $this->expectOutputRegex('/"error":.*No se proporcionaron códigos/');

        $this->controller->search([]);
    }
}
