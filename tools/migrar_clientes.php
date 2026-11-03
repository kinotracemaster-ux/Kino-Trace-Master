<?php
/**
 * Pone al día la base de datos de TODOS los clientes (y central.db).
 *
 *   php tools/migrar_clientes.php
 *
 * Lo ejecuta el contenedor al arrancar cada deploy (ver start.sh en el Dockerfile),
 * antes de abrir la web: así cada mejora con cambio de base llega a todos los
 * clientes a la vez, y si algo falla se ve en el log de Railway en vez de en la
 * pantalla de un cliente. Es seguro correrlo muchas veces (no hace nada si ya
 * están al día). Código de salida 1 si algún cliente quedó con error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';          // migra central.db
require_once __DIR__ . '/../helpers/tenant.php';

$target = client_schema_version();
echo "KINO TRACE: migrando clientes a esquema v{$target}\n";

$fallos = 0;
foreach (migrate_all_clients() as $code => $r) {
    if ($r['despues'] === null && $r['ok']) {
        echo "  -  {$code}: {$r['error']}\n";
        continue;
    }
    $cambio = $r['antes'] === $r['despues'] ? "v{$r['despues']} (ya estaba al día)" : "v{$r['antes']} -> v{$r['despues']}";
    if ($r['ok']) {
        echo "  OK {$code}: {$cambio}\n";
    } else {
        $fallos++;
        echo "  !! {$code}: {$cambio} ERROR: {$r['error']}\n";
    }
}

echo $fallos ? "TERMINADO CON {$fallos} ERROR(ES)\n" : "Todos los clientes al día.\n";
exit($fallos ? 1 : 0);
