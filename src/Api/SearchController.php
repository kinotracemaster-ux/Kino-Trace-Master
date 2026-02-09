<?php

namespace Kino\Api;

use PDO;

class SearchController extends BaseController
{
    public function search($post)
    {
        $rawInput = $post['codes'] ?? $_GET['codes'] ?? '';
        $lines = explode("\n", $rawInput);

        // Extract only the first column (first token before whitespace) from each line
        // This allows pasting blocks of text where codes are in the first column
        $codes = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '')
                continue;

            // Split by whitespace (spaces or tabs) and take only the first token
            $parts = preg_split('/[\s\t]+/', $line, 2);
            $firstColumn = trim($parts[0] ?? '');

            if ($firstColumn !== '') {
                $codes[] = $firstColumn;
            }
        }

        if (empty($codes)) {
            $this->jsonExit(['error' => 'No se proporcionaron códigos']);
        }

        // Global helper
        $result = greedy_search($this->db, $codes);
        $this->jsonExit($result);
    }

    public function searchByCode($request)
    {
        $code = trim($request['code'] ?? '');
        $result = search_by_code($this->db, $code);
        $this->jsonExit(['documents' => $result]);
    }

    public function suggest($get)
    {
        $term = trim($get['term'] ?? '');
        $suggestions = suggest_codes($this->db, $term, 10);
        $this->jsonExit($suggestions);
    }

    public function stats()
    {
        $stats = get_search_stats($this->db);
        $this->jsonExit($stats);
    }

    public function fulltextSearch($request)
    {
        $query = trim($request['query'] ?? '');
        $limit = min(200, max(1, (int) ($request['limit'] ?? 100)));

        if (strlen($query) < 3) {
            $this->jsonExit(['error' => 'El término debe tener al menos 3 caracteres']);
        }

        // Búsqueda case-insensitive usando LOWER()
        $lowerQuery = '%' . strtolower($query) . '%';
        $stmt = $this->db->prepare("
            SELECT 
                d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                d.datos_extraidos
            FROM documentos d
            WHERE LOWER(d.datos_extraidos) LIKE ? OR LOWER(d.numero) LIKE ?
            ORDER BY d.fecha DESC, d.id DESC
            LIMIT $limit
        ");
        $stmt->execute([$lowerQuery, $lowerQuery]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $data = json_decode($r['datos_extraidos'], true);
            $text = $data['text'] ?? '';
            $snippet = '';

            if (!empty($text)) {
                $pos = stripos($text, $query);
                if ($pos !== false) {
                    $start = max(0, $pos - 60);
                    $end = min(strlen($text), $pos + strlen($query) + 60);
                    $snippet = ($start > 0 ? '...' : '') .
                        substr($text, $start, $end - $start) .
                        ($end < strlen($text) ? '...' : '');
                    $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                }
            }

            $occurrences = substr_count(strtolower($text), strtolower($query));

            $results[] = [
                'id' => (int) $r['id'],
                'tipo' => $r['tipo'],
                'numero' => $r['numero'],
                'fecha' => $r['fecha'],
                'proveedor' => $r['proveedor'],
                'ruta_archivo' => $r['ruta_archivo'],
                'snippet' => $snippet,
                'occurrences' => $occurrences
            ];
        }

        usort($results, fn($a, $b) => $b['occurrences'] - $a['occurrences']);

        $this->jsonExit([
            'query' => $query,
            'count' => count($results),
            'results' => $results
        ]);
    }
}
