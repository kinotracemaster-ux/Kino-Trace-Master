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


// 3) Unificado mínimo: cada código en UNA hoja, sin repetir
$relleno = str_repeat('Texto de relleno para que la hoja cuente como hoja con texto propio. ', 5);
$mk = function (string $ruta, array $hojas) use ($relleno) {
    $t = new TCPDF(); $t->SetFont('helvetica', '', 10);
    foreach ($hojas as $txt) { $t->AddPage(); $t->MultiCell(0, 5, $relleno . $txt, 0, 'L'); }
    file_put_contents($ruta, $t->Output('', 'S'));
};
$p1 = $tmp . '/p1.pdf'; $p2 = $tmp . '/p2.pdf';
$mk($p1, ['nada aqui', 'Ref: 1523.//// y BL-77', 'otra hoja sin codigos']);
$mk($p2, ['BL-77 repetido', 'Ref 9001 solo', 'BL-77 y 1523 otra vez']);
$min = $tmp . '/min.pdf';
$r = kt_unir_pdfs_minimo([$p1, $p2], ['1523H-1', 'BL-77', '9001-2', 'NOEXISTE-9'], $min);
$ok($r['originales'] === 6, 'cuenta las hojas originales (6)');
$ok($r['paginas'] === 2, 'queda solo lo necesario: 2 hojas (hay ' . $r['paginas'] . ')');
$ok($r['sin_cubrir'] === ['NOEXISTE-9'], 'avisa el código que no está en ninguna hoja');
$txt = (string) shell_exec('pdftotext ' . escapeshellarg($min) . ' - 2>/dev/null');
$ok(substr_count($txt, 'BL-77') === 1, 'BL-77 aparece una sola vez (no se repite)');
$ok(strpos($txt, '9001') !== false && strpos($txt, '1523') !== false, 'conserva las hojas con 1523 y 9001');

array_map('unlink', glob($tmp . '/*'));
rmdir($tmp);
exit($fallos ? 1 : 0);
