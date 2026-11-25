<?php
// Chequeo: kt_unir_pdfs() une PDFs con compresión moderna (que FPDI descarta) y conserva el texto.
// Uso: php tests/resaltado/chequeo_unir_pdfs.php
chdir(__DIR__ . '/../..');
require 'vendor/autoload.php';
require 'helpers/unir_pdfs.php';

if (trim((string) shell_exec('command -v pdfunite')) === '' || trim((string) shell_exec('command -v pdfinfo')) === '') {
    echo "OMITIDO: falta pdfunite/pdfinfo (poppler-utils)\n";
    exit(0);
}
$tmp = sys_get_temp_dir() . '/kt_unir_' . getmypid();
mkdir($tmp);
$fallos = 0;
$ok = function (bool $c, string $m) use (&$fallos) { echo ($c ? "OK    " : "FALLA ") . $m . "\n"; if (!$c) $fallos++; };

// 1) PDF "moderno" (object streams): se arma con pdftk/qpdf si existe; si no, con el de la plantilla kino
$modernos = [];
foreach (glob('database_initial/kino/uploads/client_kino/csv_import/*.pdf') ?: [] as $f) {
    try { (new setasign\Fpdi\Tcpdf\Fpdi())->setSourceFile($f); } catch (Throwable $e) { $modernos[] = $f; }
    if (count($modernos) >= 2) break;
}
// 2) PDF simple con texto
$a = $tmp . '/a.pdf';
$t = new TCPDF(); $t->AddPage(); $t->SetFont('helvetica', '', 12); $t->Write(0, 'ITEM 1523.//// prueba'); file_put_contents($a, $t->Output('', 'S'));
$malo = $tmp . '/malo.pdf'; file_put_contents($malo, 'no es un pdf');

$lista = array_merge([$a], $modernos, [$malo]);
$salida = $tmp . '/union.pdf';
$r = kt_unir_pdfs($lista, $salida);
$ok(is_file($salida) && filesize($salida) > 0, 'se creó el PDF unido');
$ok(count($r['omitidos']) === 1 && $r['omitidos'][0] === $malo, 'solo se omitió el archivo dañado');
$ok($r['paginas'] >= 1 + count($modernos), 'se unieron las hojas de los PDFs modernos (' . $r['paginas'] . ' hojas)');
$texto = (string) shell_exec('pdftotext ' . escapeshellarg($salida) . ' - 2>/dev/null');
$ok(strpos($texto, '1523') !== false, 'la capa de texto se conserva');
if (!$modernos) echo "AVISO: no hay PDFs modernos en database_initial para probar ese caso\n";

array_map('unlink', glob($tmp . '/*'));
rmdir($tmp);
exit($fallos ? 1 : 0);
