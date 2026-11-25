<?php
/**
 * Chequeo: el resaltador encuentra "1980-1" aunque el PDF solo diga "1980".
 * Uso: php tests/resaltado/chequeo_sin_sufijo.php  (sale con 1 si algo falla)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../helpers/ocr_highlight.php';

$fallos = 0;
function ok(bool $c, string $msg): void { global $fallos; echo ($c ? '  OK  ' : '  FALLA ') . $msg . "\n"; if (!$c) $fallos++; }
function palabras(string $texto): array {
    $w = []; $x = 0;
    foreach (preg_split('/\s+/', trim($texto)) as $t) { $w[] = ['text' => $t, 'x' => $x, 'y' => 0, 'w' => 10, 'h' => 10]; $x += 12; }
    return $w;
}
function buscar(string $texto, array $terms): array { return ocr_hl_match_terms(palabras($texto), $terms)['matches']; }

echo "Base sin sufijo\n";
ok(ocr_hl_base_sin_sufijo('1980-1') === '1980', '1980-1 → 1980');
ok(ocr_hl_base_sin_sufijo('NO.2519A-5') === 'NO.2519A', 'NO.2519A-5 → NO.2519A');
ok(ocr_hl_base_sin_sufijo('156-2') === '156', '156-2 → 156');
ok(ocr_hl_base_sin_sufijo('BL-12') === null, 'BL-12 no se recorta (base corta)');
ok(ocr_hl_base_sin_sufijo('DS-230166') === null, 'DS-230166 no se recorta (no es -N)');
ok(ocr_hl_base_sin_sufijo('1980') === null, '1980 no tiene sufijo');

echo "Coincidencias\n";
$m = buscar('ITEM 1980 CAJAS', ['1980-1']);
ok(count($m) === 1 && $m[0]['term'] === '1980-1' && ($m[0]['via'] ?? '') === '1980', '1980-1 encuentra 1980 (marcado via)');
$m = buscar('ITEM 1980-1 Y 1980', ['1980-1']);
ok(count($m) === 1 && !isset($m[0]['via']), 'si está exacto, no usa la base');
ok(count(buscar('REF NO.2519A TOTAL', ['NO.2519A-5'])) === 1, 'NO.2519A-5 encuentra NO.2519A');
ok(count(buscar('REF NO. 2519A TOTAL', ['NO.2519A-5'])) === 1, 'NO.2519A-5 encuentra "NO. 2519A"');
ok(count(buscar('VALOR 1560 Y 11560', ['156-1'])) === 0, '156-1 no cae dentro de 1560 ni 11560');
ok(count(buscar('TOTAL 1.156.000', ['156-1'])) === 0, '156-1 no cae dentro de 1.156.000');
$m = buscar('ITEM 1980-2', ['1980-1']);
ok(count($m) === 1 && ($m[0]['via'] ?? '') === '1980' && ($m[0]['otro'] ?? '') === '-2', '1980-1 con solo 1980-2 en el PDF: resalta la base y avisa que figura 1980-2');
$m = buscar('ITEM 1980 -2', ['1980-1']);
ok(count($m) === 1 && ($m[0]['otro'] ?? '') === '-2', '"1980 -2" también se avisa como otro ítem');
$m = buscar('ITEM 1980-2 Y 1980 CAJAS', ['1980-1']);
ok(count($m) === 1 && !isset($m[0]['otro']), 'si hay una base limpia, se prefiere sobre la que va seguida de otro -N');
ok(count(buscar('ITEM 2000 CAJAS', ['1980-1'])) === 0, 'sin la base en el documento sigue sin encontrarse (queda en Faltan)');
ok(count(buscar('ITEM 156 CAJAS', ['156-1', '156-2'])) === 2, '156-1 y 156-2 cuentan como encontrados con 156');

echo $fallos ? "\n$fallos FALLA(S)\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
