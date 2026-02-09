<?php

namespace Kino\Api;

class PdfController extends BaseController
{
    public function extractCodes($files, $post)
    {
        if (empty($files['file']['tmp_name'])) {
            $this->jsonExit(['error' => 'Archivo no recibido']);
        }

        $prefix = $post['prefix'] ?? '';
        $terminator = $post['terminator'] ?? '/';
        $minLength = (int) ($post['min_length'] ?? 4);
        $maxLength = (int) ($post['max_length'] ?? 50);

        // Capture DPI if provided, default to null (extractor will handle default)
        $dpi = isset($post['dpi']) ? (int) $post['dpi'] : null;

        $config = [
            'prefix' => $prefix,
            'terminator' => $terminator,
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'dpi' => $dpi
        ];

        // Global helper function assumed to be available
        $result = extract_codes_from_pdf($files['file']['tmp_name'], $config);
        $this->jsonExit($result);
    }

    public function searchInPdf($files, $post)
    {
        if (empty($files['file']['tmp_name'])) {
            $this->jsonExit(['error' => 'Archivo no recibido']);
        }

        $searchCodes = array_filter(
            array_map('trim', explode("\n", $post['codes'] ?? ''))
        );

        // Global helper function assumed to be available
        $result = search_codes_in_pdf($files['file']['tmp_name'], $searchCodes);
        $this->jsonExit($result);
    }
}
