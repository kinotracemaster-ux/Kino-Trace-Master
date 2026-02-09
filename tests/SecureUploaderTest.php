<?php

use PHPUnit\Framework\TestCase;

class SecureUploaderTest extends TestCase
{
    private $testPdf;
    private $fakePdf;

    protected function setUp(): void
    {
        // Create a dummy PDF
        $this->testPdf = sys_get_temp_dir() . '/test.pdf';
        file_put_contents($this->testPdf, '%PDF-1.4 content...');

        // Create a fake PDF (text file)
        $this->fakePdf = sys_get_temp_dir() . '/fake.pdf';
        file_put_contents($this->fakePdf, 'This is not a PDF');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testPdf))
            unlink($this->testPdf);
        if (file_exists($this->fakePdf))
            unlink($this->fakePdf);
    }

    public function testValidPdfUpload()
    {
        $file = [
            'name' => 'test.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $this->testPdf,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($this->testPdf)
        ];

        // We assume validate is static
        $result = SecureFileUploader::validate($file);
        $this->assertArrayHasKey('success', $result, 'Valid PDF should be accepted. Error: ' . ($result['error'] ?? ''));
    }

    public function testInvalidMimeType()
    {
        $file = [
            'name' => 'fake.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $this->fakePdf,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($this->fakePdf)
        ];

        $result = SecureFileUploader::validate($file);
        $this->assertArrayHasKey('error', $result);
        // It might fail on Magic Bytes or MIME depending on what finfo returns for plain text
        // Plain text usually returns text/plain
        $this->assertStringContainsString('El archivo no es un PDF válido', $result['error']);
    }

    public function testMagicBytesValidation()
    {
        // Create a file with correct extension but bad magic bytes
        $badMagic = sys_get_temp_dir() . '/badmagic.pdf';
        file_put_contents($badMagic, 'TRASH%PDF'); // Wrong start

        $file = [
            'name' => 'badmagic.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $badMagic,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($badMagic)
        ];

        $result = SecureFileUploader::validate($file);
        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(
            strpos($result['error'], 'MIME') !== false ||
            strpos($result['error'], 'formato') !== false
        );

        unlink($badMagic);
    }
}
