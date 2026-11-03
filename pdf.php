<?php
/**
 * Sirve un PDF a partir de un enlace firmado (helpers/pdf_acceso.php).
 * GET r = ruta relativa a clients/, e = vencimiento (unix), s = firma, d = 1 para descargar.
 * No necesita sesión: la firma es el permiso (así funciona también el buscador público).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/pdf_acceso.php';

$r = kt_pdf_validar((string) ($_GET['r'] ?? ''), (int) ($_GET['e'] ?? 0), (string) ($_GET['s'] ?? ''));
if (is_int($r)) {
    http_response_code($r);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $r === 410 ? 'El enlace venció. Vuelva a abrir el documento.' : 'No disponible.';
    exit;
}
if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
kt_pdf_enviar($r, !empty($_GET['d']));
