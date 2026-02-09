<?php

namespace Kino\Api;

class BaseController
{
    protected $db;
    protected $clientCode;

    public function __construct($db, $clientCode)
    {
        $this->db = $db;
        $this->clientCode = $clientCode;
    }

    /**
     * Send JSON response and exit
     */
    protected function jsonExit($data)
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);

        if (defined('PHPUNIT_RUNNING')) {
            throw new \RuntimeException('JSON_EXIT');
        }

        exit;
    }

    /**
     * Send standardized error response
     */
    protected function sendError($code, $message = null, $details = [])
    {
        // Assuming api_error helper exists globally or we need to wrap it
        if (function_exists('api_error')) {
            $error = api_error($code, $message, $details);
        } else {
            $error = ['error' => $message ?? $code, 'code' => $code, 'details' => $details];
        }

        // Use global send_error_response if available to handle logging/status codes
        if (function_exists('send_error_response')) {
            send_error_response($error);
        } else {
            $this->jsonExit($error);
        }
    }

    /**
     * Validate required fields in a request
     */
    protected function validateRequired($data, $fields)
    {
        // Reusing existing logic if possible, or reimplementing
        if (function_exists('validate_required_fields')) {
            return validate_required_fields($data, $fields); // Returns error array or null
        }

        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                return ['error' => "Campo requerido faltante: $field"];
            }
        }
        return null;
    }
}
