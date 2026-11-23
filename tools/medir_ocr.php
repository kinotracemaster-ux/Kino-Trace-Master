<?php
/**
 * Mide qué tan bien lee el OCR los códigos de un PDF, para decidir con datos
 * (y no a ojo) cómo configurarlo.
 *
 *   php tools/medir_ocr.php clients/kino/kino.db clients/kino            # usa los códigos de la base
 *   php tools/medir_ocr.php clients/kino/kino.db clients/kino --escaneados # PDFs SIN texto (escaneos de verdad)
 *   php tools/medir_ocr.php --verdad=verdad.csv --carpeta=carpeta/de/pdfs # usa una lista hecha a mano
 *
 * Opciones:
 *   --docs=8          cuántos documentos probar (modo base; elige los que tienen texto en el PDF)
 *   --paginas=1       cuántas páginas por documento
 *   --config=150/3,300/3,150/3/35   pruebas "dpi/psm[/calidad JPEG]"; la primera es la referencia.
 *                     Por defecto: 150/3 (lo que usa la app hoy), 200/3, 300/3, 300/6 y 150/3/35 (escaneo malo).
 *   --json=salida.json  guarda el resultado completo
 *
 * Qué mide, por cada configuración:
 *   - Aciertos exactos: de los códigos que de verdad están impresos en la hoja, cuántos aparecen
 *     en el texto del OCR (comparando sin mayúsculas ni separadores, como el buscador).
 *   - Aciertos con 1 error: aparecen con una letra mal leída (esos NO los encuentra el buscador hoy).
 *   - Qué letras se confunden (O↔0, I↔1, S↔5...), para saber si vale la pena una búsqueda tolerante.
 *   - Segundos por hoja.
 *
 * Verdad del modo base: los códigos registrados del documento que además salen en la capa de
 * texto del PDF (pdftotext). La hoja se convierte en imagen y se lee con OCR como si fuera un escaneo.
 * OJO: una hoja de un PDF con texto, convertida a imagen, es más limpia que un escaneo de verdad, así que
 * estos números son un TECHO. Para el número real hay que usar PDFs escaneados con su lista hecha a mano
 * (modo --verdad: CSV con "pdf;pagina;codigo", una fila por código impreso en esa hoja).
 *
 * Modo --escaneados: toma los documentos cuyo PDF NO tiene capa de texto (escaneos reales, la situación
 * de los clientes) y usa como verdad los códigos que alguien ya registró de ese documento (importados o
 * escritos a mano: una fuente independiente del OCR). Lee todas las páginas (hasta --paginas, 6 por defecto)
 * y cuenta cuántos de esos códigos aparecen en el texto leído. Es el número real, no el techo.
 *
 * Trabaja solo con archivos temporales; nunca toca clients/. Solo línea de comandos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tmpApp = sys_get_temp_dir() . '/kt_ocr_app_' . getmypid();
@mkdir($tmpApp, 0777, true);
putenv('KT_CLIENTS_DIR=' . $tmpApp);
require_once dirname(__DIR__) . '/helpers/tenant.php';
register_shutdown_function(function () use ($tmpApp) {
    foreach (glob("$tmpApp/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpApp);
});

// ---- Opciones
$pos = [];
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    } else {
        $pos[] = $a;
    }
}
$nDocs = max(1, (int) ($opt['docs'] ?? 8));
$escaneados = in_array('--escaneados', $argv, true);
$nPag = max(1, (int) ($opt['paginas'] ?? ($escaneados ? 6 : 1)));
$configs = array_values(array_filter(array_map('trim', explode(',', $opt['config'] ?? '150/3,200/3,300/3,300/6,150/3/35'))));

$tesseract = trim((string) shell_exec('which tesseract 2>/dev/null'));
$pdftoppm = trim((string) shell_exec('which pdftoppm 2>/dev/null'));
$pdftotext = trim((string) shell_exec('which pdftotext 2>/dev/null'));
if (!$tesseract || !$pdftoppm || !$pdftotext) {
    fwrite(STDERR, "Faltan herramientas: se necesitan tesseract (con idioma spa), pdftoppm y pdftotext.\n");
    exit(1);
}

/** Texto en mayúsculas sin espacios ni separadores: así se compara el buscador. */
function ocr_flujo(string $t): string
{
    return strtoupper(str_replace(CODE_MATCH_IGNORED, '', preg_replace('/\s+/', '', $t)));
}

/** @return array<int, array{pdf:string, pagina:int, codigos:string[]}> hojas a medir con su verdad */
function hojas_de_la_base(string $dbPath, string $carpeta, int $nDocs, int $nPag, string $pdftotext): array
{
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hojas = [];
    $docs = 0;
    $q = $db->query("SELECT id, ruta_archivo FROM documentos WHERE ruta_archivo LIKE '%.pdf' ORDER BY id");
    foreach ($q as $d) {
        if ($docs >= $nDocs) {
            break;
        }
        $pdf = rtrim($carpeta, '/') . '/' . ltrim($d['ruta_archivo'], '/');
        if (!is_file($pdf)) {
            continue;
        }
        $st = $db->prepare('SELECT DISTINCT codigo FROM codigos WHERE documento_id = ?');
        $st->execute([$d['id']]);
        $registrados = $st->fetchAll(PDO::FETCH_COLUMN);
        $uso = false;
        for ($p = 1; $p <= $nPag; $p++) {
            $txt = (string) shell_exec($pdftotext . ' -f ' . $p . ' -l ' . $p . ' ' . escapeshellarg($pdf) . ' - 2>/dev/null');
            $flujo = ocr_flujo($txt);
            if (strlen($flujo) < 200) {
                continue; // sin capa de texto: no hay verdad contra qué comparar
            }
            $verdad = [];
            foreach ($registrados as $c) {
                $k = code_match_key($c);
                if (strlen($k) >= 4 && strpos($flujo, $k) !== false) {
                    $verdad[$k] = $c;
                }
            }
            if ($verdad) {
                $hojas[] = ['pdf' => $pdf, 'pagina' => $p, 'codigos' => array_values($verdad)];
                $uso = true;
            }
        }
        $docs += $uso ? 1 : 0;
    }
    return $hojas;
}

/**
 * PDFs escaneados (sin capa de texto): la verdad son los códigos registrados del documento.
 * Cada "hoja" es el documento entero (hasta $nPag páginas).
 * @return array<int, array{pdf:string, paginas:int[], codigos:string[]}>
 */
function hojas_escaneadas(string $dbPath, string $carpeta, int $nDocs, int $nPag, string $pdftotext): array
{
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hojas = [];
    foreach ($db->query("SELECT id, ruta_archivo FROM documentos WHERE ruta_archivo LIKE '%.pdf' ORDER BY id") as $d) {
        if (count($hojas) >= $nDocs) {
            break;
        }
        $pdf = rtrim($carpeta, '/') . '/' . ltrim($d['ruta_archivo'], '/');
        if (!is_file($pdf)) {
            continue;
        }
        $txt = (string) shell_exec($pdftotext . ' ' . escapeshellarg($pdf) . ' - 2>/dev/null');
        if (strlen(ocr_flujo($txt)) >= 50) {
            continue; // tiene texto: no es un escaneo
        }
        $st = $db->prepare('SELECT DISTINCT codigo FROM codigos WHERE documento_id = ?');
        $st->execute([$d['id']]);
        $cods = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($c) => strlen(code_match_key($c)) >= 4));
        if (!$cods) {
            continue;
        }
        $info = (string) shell_exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null');
        $n = preg_match('/Pages:\s+(\d+)/', $info, $m) ? (int) $m[1] : 1;
        $hojas[] = ['pdf' => $pdf, 'paginas' => range(1, min($n, $nPag)), 'codigos' => $cods];
    }
    return $hojas;
}

/** @return array<int, array{pdf:string, pagina:int, codigos:string[]}> */
function hojas_del_csv(string $csv, string $carpeta): array
{
    $por = [];
    foreach (file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $c = str_getcsv($linea, ';');
        if (count($c) < 3 || !ctype_digit(trim($c[1]))) {
            continue; // encabezado u otra cosa
        }
        $k = trim($c[0]) . '|' . (int) $c[1];
        $por[$k]['pdf'] = rtrim($carpeta, '/') . '/' . ltrim(trim($c[0]), '/');
        $por[$k]['pagina'] = (int) $c[1];
        $por[$k]['codigos'][] = trim($c[2]);
    }
    return array_values(array_filter($por, fn($h) => is_file($h['pdf'])));
}

/** OCR de una hoja con una configuración "dpi/psm[/calidad JPEG]". Devuelve [texto, segundos]. */
function ocr_hoja(string $tesseract, string $pdftoppm, string $pdf, int $pagina, string $cfg): array
{
    $p = explode('/', $cfg);
    $dpi = (int) ($p[0] ?? 150);
    $psm = (int) ($p[1] ?? 3);
    $jpg = isset($p[2]) ? (int) $p[2] : 0;
    $dir = sys_get_temp_dir() . '/ktocrm_' . uniqid('', true);
    mkdir($dir, 0777, true);
    $t0 = microtime(true);
    try {
        $fmt = $jpg > 0 ? ' -jpeg -jpegopt quality=' . $jpg : ' -png';
        exec($pdftoppm . $fmt . ' -gray -r ' . $dpi . ' -f ' . $pagina . ' -l ' . $pagina . ' '
            . escapeshellarg($pdf) . ' ' . escapeshellarg($dir . '/p') . ' 2>&1');
        $img = (glob($dir . '/*.png') ?: glob($dir . '/*.jpg') ?: [null])[0];
        if (!$img) {
            return ['', 0.0];
        }
        exec($tesseract . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($dir . '/o') . ' -l spa --oem 1 --psm ' . $psm . ' 2>&1');
        $txt = is_file($dir . '/o.txt') ? (string) file_get_contents($dir . '/o.txt') : '';
        return [$txt, microtime(true) - $t0];
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}

/**
 * ¿Aparece $clave en $flujo con una sola letra distinta (cambiada, de más o de menos)?
 * Devuelve null si no, o la confusión encontrada: "O→0", "+x" (sobra), "-x" (falta).
 */
function ocr_un_error(string $flujo, string $clave): ?string
{
    $m = strlen($clave);
    if ($m < 4) {
        return null;
    }
    $n = strlen($flujo);
    // cambiada: misma longitud
    for ($i = 0; $i + $m <= $n; $i++) {
        $w = substr($flujo, $i, $m);
        $dif = 0;
        $par = null;
        for ($j = 0; $j < $m && $dif < 2; $j++) {
            if ($w[$j] !== $clave[$j]) {
                $dif++;
                $par = $clave[$j] . '→' . $w[$j];
            }
        }
        if ($dif === 1) {
            return $par;
        }
    }
    // de más / de menos: ventanas de m±1 con distancia 1
    foreach ([$m - 1, $m + 1] as $len) {
        for ($i = 0; $i + $len <= $n; $i++) {
            $w = substr($flujo, $i, $len);
            if ($w[0] === $clave[0] && levenshtein($w, $clave) === 1) {
                return $len > $m ? '+extra' : '-falta';
            }
        }
    }
    return null;
}

// ---- Hojas a medir
if ($escaneados) {
    if (count($pos) < 2) {
        fwrite(STDERR, "Uso: php tools/medir_ocr.php ruta/cliente.db carpeta/de/los/pdfs --escaneados\n");
        exit(1);
    }
    $hojas = hojas_escaneadas($pos[0], $pos[1], $nDocs, $nPag, $pdftotext);
    $origen = 'códigos registrados de PDFs escaneados sin texto (número real)';
} elseif (isset($opt['verdad'])) {
    $hojas = hojas_del_csv($opt['verdad'], $opt['carpeta'] ?? '.');
    $origen = 'lista hecha a mano';
} else {
    if (count($pos) < 2) {
        fwrite(STDERR, "Uso: php tools/medir_ocr.php ruta/cliente.db carpeta/de/los/pdfs [--docs=8] [--paginas=1] [--config=...]\n"
            . "  o: php tools/medir_ocr.php --verdad=verdad.csv --carpeta=carpeta/de/pdfs\n");
        exit(1);
    }
    $hojas = hojas_de_la_base($pos[0], $pos[1], $nDocs, $nPag, $pdftotext);
    $origen = 'códigos de la base que salen impresos en la hoja (techo: hoja limpia convertida a imagen)';
}
if (!$hojas) {
    fwrite(STDERR, "No encontré hojas con códigos para medir.\n");
    exit(1);
}
$totalCodigos = array_sum(array_map(fn($h) => count($h['codigos']), $hojas));
printf("%d hojas, %d códigos de verdad. Verdad: %s.\n\n", count($hojas), $totalCodigos, $origen);

// ---- Medir
$res = [];
foreach ($configs as $cfg) {
    $ex = $uno = $total = 0;
    $seg = [];
    $conf = [];
    $perdidos = [];
    foreach ($hojas as $h) {
        $txt = '';
        foreach ($h['paginas'] ?? [$h['pagina']] as $pg) {
            [$t1, $s] = ocr_hoja($tesseract, $pdftoppm, $h['pdf'], $pg, $cfg);
            $txt .= "\n" . $t1;
            $seg[] = $s;
        }
        $flujo = ocr_flujo($txt);
        foreach ($h['codigos'] as $c) {
            $k = code_match_key($c);
            if (strlen($k) < 4) {
                continue;
            }
            $total++;
            if (strpos($flujo, $k) !== false) {
                $ex++;
            } elseif (($par = ocr_un_error($flujo, $k)) !== null) {
                $uno++;
                $conf[$par] = ($conf[$par] ?? 0) + 1;
            } else {
                $perdidos[] = $c;
            }
        }
    }
    sort($seg);
    $res[$cfg] = ['exactos' => $ex, 'un_error' => $uno, 'total' => $total, 'seg_hoja' => array_sum($seg) / max(1, count($seg)),
        'confusiones' => $conf, 'perdidos' => array_slice(array_unique($perdidos), 0, 10)];
}

printf("%-12s %9s %12s %10s %12s\n", 'config', 'exactos', 'con 1 error', 'perdidos', 's por hoja');
foreach ($res as $cfg => $r) {
    $t = max(1, $r['total']);
    printf("%-12s %8.1f%% %11.1f%% %9.1f%% %12.1f\n", $cfg, 100 * $r['exactos'] / $t, 100 * $r['un_error'] / $t,
        100 * ($t - $r['exactos'] - $r['un_error']) / $t, $r['seg_hoja']);
}
echo "\nConfig = dpi/psm[/calidad JPEG]; psm 3 = lectura automática (lo de hoy), 6 = un solo bloque de texto.\n";
$ref = array_key_first($res);
if ($res[$ref]['confusiones']) {
    arsort($res[$ref]['confusiones']);
    echo "\nLetras que el OCR confunde con config $ref (código real→leído): ";
    echo implode(', ', array_map(fn($k, $v) => "$k ×$v", array_keys($res[$ref]['confusiones']), $res[$ref]['confusiones'])) . "\n";
}
if ($res[$ref]['perdidos']) {
    echo "Ejemplos de códigos que no se leyeron en $ref: " . implode(', ', $res[$ref]['perdidos']) . "\n";
}
if (isset($opt['json'])) {
    file_put_contents($opt['json'], json_encode(['hojas' => count($hojas), 'codigos' => $totalCodigos, 'resultados' => $res],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
