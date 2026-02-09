<?php

namespace Kino\Api;

use PDO;

class AiController extends BaseController
{
    public function extract($post)
    {
        if (!is_gemini_configured()) {
            $this->jsonExit(['error' => 'Gemini AI no configurado. Configure GEMINI_API_KEY.']);
        }

        $documentId = (int) ($post['document_id'] ?? 0);
        $documentType = $post['document_type'] ?? 'documento';

        if ($documentId) {
            $stmt = $this->db->prepare("SELECT datos_extraidos FROM documentos WHERE id = ?");
            $stmt->execute([$documentId]);
            $data = json_decode($stmt->fetchColumn(), true);
            $text = $data['text'] ?? '';
        } else {
            $text = $post['text'] ?? '';
        }

        if (empty($text)) {
            $this->jsonExit(['error' => 'No hay texto para analizar']);
        }

        $result = ai_extract_document_data($text, $documentType);
        $this->jsonExit($result);
    }

    public function chat($post)
    {
        if (!is_gemini_configured()) {
            $this->jsonExit(['error' => 'Gemini AI no configurado']);
        }

        $question = trim($post['question'] ?? '');
        if (empty($question)) {
            $this->jsonExit(['error' => 'Pregunta vacía']);
        }

        $context = $this->db->query("
            SELECT tipo, numero, fecha, proveedor
            FROM documentos
            ORDER BY fecha DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($context as &$doc) {
            $stmt = $this->db->prepare("SELECT codigo FROM codigos WHERE documento_id = ?");
            $stmt->execute([$doc['id'] ?? 0]);
            $doc['codigos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $result = ai_chat_with_context($question, $context);
        $this->jsonExit($result);
    }

    public function smartChat($post)
    {
        if (!is_gemini_configured()) {
            $this->jsonExit(['error' => 'Gemini AI no configurado. Configure GEMINI_API_KEY.']);
        }

        $question = trim($post['question'] ?? '');
        if (empty($question)) {
            $this->jsonExit(['error' => 'Pregunta vacía']);
        }

        $result = ai_smart_chat($this->db, $question, $this->clientCode);
        $this->jsonExit($result);
    }

    public function status()
    {
        $this->jsonExit([
            'configured' => is_gemini_configured(),
            'model' => defined('GEMINI_MODEL') ? GEMINI_MODEL : 'unknown'
        ]);
    }
}
