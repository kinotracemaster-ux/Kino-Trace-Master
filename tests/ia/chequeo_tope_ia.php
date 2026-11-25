<?php
// Chequeo: el tope diario de IA por cliente corta en el límite y no se mezcla entre clientes.
//   php tests/ia/chequeo_tope_ia.php
require_once __DIR__ . '/../../helpers/uso_ia.php';

$fallos = 0;
$ok = function (bool $c, string $m) use (&$fallos) { echo ($c ? 'OK    ' : 'FALLA ') . $m . "\n"; if (!$c) $fallos++; };

putenv('KT_IA_LIMITE_DIARIO=3');
$mk = function () {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE uso_ia (dia TEXT PRIMARY KEY, consultas INTEGER NOT NULL DEFAULT 0)');
    return $db;
};
$a = $mk();
$b = $mk();
$r = [kt_ia_consumir($a), kt_ia_consumir($a), kt_ia_consumir($a), kt_ia_consumir($a)];
$ok($r === [true, true, true, false], 'con tope 3: las 3 primeras pasan y la 4ª se corta');
$ok(kt_ia_usadas_hoy($a) === 3, 'el conteo se queda en el tope (3)');
$ok(kt_ia_consumir($b) === true, 'otro cliente no gasta el cupo del primero');
putenv('KT_IA_LIMITE_DIARIO=0');
$ok(kt_ia_consumir($mk()) === false, 'tope 0 = IA apagada');
putenv('KT_IA_LIMITE_DIARIO');
$ok(kt_ia_limite_diario() === 200, 'sin variable, el tope por defecto es 200');
$sinTabla = new PDO('sqlite::memory:');
$sinTabla->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ok(kt_ia_consumir($sinTabla) === true, 'base sin migrar: no bloquea la IA');

echo $fallos ? "\n$fallos fallo(s)\n" : "\nTodo OK\n";
exit($fallos ? 1 : 0);
