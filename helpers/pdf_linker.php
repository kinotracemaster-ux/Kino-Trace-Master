<?php
/**
 * helpers/pdf_linker.php
 * 
 * Enlaza PDFs de un ZIP a documentos existentes en la DB.
 * Matching simple: nombre del PDF en ZIP ↔ columna original_path en DB.
 */

if (!function_exists('logMsg')) {
    function logMsg($msg, $type = "info")
    {
        // silent — caller can define their own logMsg before including this file
    }
}

if (!function_exists('stripTimestamp')) {
    /**
     * Remove leading timestamp prefix from filename.
     * e.g. "1751323422_DOCUMENT NAME.pdf" → "DOCUMENT NAME.pdf"
     */
    function stripTimestamp($filename)
    {
        return preg_replace('/^\d{6,}[_\-\s]+/', '', $filename);
    }
}

if (!function_exists('kt_linker_nombre_utf8')) {
    /**
     * Nombre de una entrada del ZIP en UTF-8.
     * Los ZIP hechos con "Enviar a carpeta comprimida" de Windows guardan los nombres en
     * la página de códigos del sistema (CP850 en Windows en español) sin marca UTF-8; la
     * librería los lee como CP437 y Á, Í, Ó, Ú o ´ salen como otros caracteres, así que el
     * nombre no coincidía con el documento. CP850 cubre también lo que CP437 tiene igual.
     */
    function kt_linker_nombre_utf8(ZipArchive $zip, int $i): string
    {
        $crudo = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
        if (is_string($crudo) && !mb_check_encoding($crudo, 'UTF-8')) {
            $conv = @iconv('CP850', 'UTF-8', $crudo);
            if (is_string($conv) && $conv !== '') {
                $nombre = $conv;
            }
        }
        $nombre = $nombre ?? (string) $zip->getNameIndex($i);
        // ZIP hechos en Mac guardan las tildes "descompuestas" (O + ´): se unifican
        if (class_exists('Normalizer')) {
            $nombre = Normalizer::normalize($nombre, Normalizer::FORM_C) ?: $nombre;
        }
        return str_replace('\\', '/', $nombre);
    }
}

if (!function_exists('kt_linker_extraer')) {
    /** Extrae la entrada $i (por índice: no depende del nombre ni de "#" en el nombre). */
    function kt_linker_extraer(ZipArchive $zip, int $i, string $destino): bool
    {
        if (method_exists($zip, 'getStreamIndex')) {
            $in = $zip->getStreamIndex($i);
            if (!$in) {
                return false;
            }
            $out = @fopen($destino, 'wb');
            if (!$out) {
                fclose($in);
                return false;
            }
            $ok = stream_copy_to_stream($in, $out) !== false;
            fclose($in);
            fclose($out);
            return $ok;
        }
        $data = $zip->getFromIndex($i);
        return $data !== false && file_put_contents($destino, $data) !== false;
    }
}

if (!function_exists('kt_linker_indices')) {
    /**
     * Índices de los documentos SIN PDF ("pending"): clave → lista de ids (puede haber
     * repetidos). Los usan processZipAndLink() y relinkFromServer(), así ambos emparejan
     * con exactamente las mismas reglas.
     *
     * $rotos: ids de documentos con enlace roto (su archivo ya no está) que se tratan
     * también como sin PDF; para ellos sirve además el nombre del archivo que tenían.
     * 'esperado' es id → nombres de archivo que le corresponden.
     */
    function kt_linker_indices(PDO $db, array $rotos = []): array
    {
        $idx = ['archivo' => [], 'sin_fecha' => [], 'nombre' => [], 'esperado' => []];
        $sql = "SELECT id, numero, original_path, ruta_archivo FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''";
        if ($rotos) {
            $sql .= ' OR id IN (' . implode(',', array_map('intval', $rotos)) . ')';
        }
        $q = $db->query($sql . ' ORDER BY id');
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $nombres = [];
            if (!in_array($row['ruta_archivo'], [null, '', 'pending'], true)) {
                $nombres[] = basename(str_replace('\\', '/', $row['ruta_archivo']));
            }
            if (!empty($row['original_path'])) {
                $nombres[] = basename(str_replace('\\', '/', $row['original_path']));
            }
            foreach (array_unique(array_map('kt_linker_clave', $nombres)) as $orig) {
                $idx['esperado'][$id][] = $orig;
                foreach (array_unique([$orig, kt_linker_sin_pdf($orig)]) as $k) {
                    $idx['archivo'][$k][] = $id;
                }
                $sf = kt_linker_clave(stripTimestamp($orig));
                foreach (array_unique([$sf, kt_linker_sin_pdf($sf)]) as $k) {
                    $idx['sin_fecha'][$k][] = $id;
                }
            }
            if (!empty($row['numero'])) {
                $idx['nombre'][kt_linker_clave($row['numero'])][] = $id;
            }
        }
        return $idx;
    }

    function kt_linker_clave(string $t): string
    {
        return mb_strtolower(trim($t), 'UTF-8');
    }

    function kt_linker_sin_pdf(string $t): string
    {
        return preg_replace('/\.[Pp][Dd][Ff]$/', '', $t);
    }

    /**
     * Documento pendiente para un archivo, en orden: 1) nombre exacto, 2) nombre sin
     * prefijo de fecha si hay UN candidato, 3) nombre del documento si hay UNO.
     *
     * @return array{id: ?int, metodo: string, exacto: bool}
     */
    function kt_linker_emparejar(array $idx, array $linkedIds, string $base): array
    {
        $libre = function (array $ids) use ($linkedIds) {
            foreach ($ids as $id) {
                if (!isset($linkedIds[$id])) {
                    return $id;
                }
            }
            return null;
        };
        $unico = function (array $ids) use ($linkedIds) {
            $ids = array_values(array_unique($ids));
            return (count($ids) === 1 && !isset($linkedIds[$ids[0]])) ? $ids[0] : null;
        };
        $baseLower = kt_linker_clave($base);
        $strippedLower = kt_linker_clave(stripTimestamp($base));

        $exacto = false;
        foreach ([$baseLower, kt_linker_sin_pdf($baseLower)] as $k) {
            if (isset($idx['archivo'][$k])) {
                $exacto = true;
                $id = $libre($idx['archivo'][$k]);
                if ($id !== null) {
                    return ['id' => $id, 'metodo' => 'ARCHIVO', 'exacto' => true];
                }
            }
        }
        if ($exacto) {
            return ['id' => null, 'metodo' => '', 'exacto' => true]; // su documento ya se enlazó
        }
        foreach ([$strippedLower, kt_linker_sin_pdf($strippedLower)] as $k) {
            if (isset($idx['sin_fecha'][$k]) && ($id = $unico($idx['sin_fecha'][$k])) !== null) {
                return ['id' => $id, 'metodo' => 'ARCHIVO_SIN_FECHA', 'exacto' => false];
            }
        }
        foreach ([kt_linker_sin_pdf($strippedLower), kt_linker_sin_pdf($baseLower)] as $k) {
            if (isset($idx['nombre'][$k]) && ($id = $unico($idx['nombre'][$k])) !== null) {
                return ['id' => $id, 'metodo' => 'NOMBRE', 'exacto' => false];
            }
        }
        return ['id' => null, 'metodo' => '', 'exacto' => false];
    }

    /**
     * Nombre "reparado" de un archivo que se guardó con el nombre mal leído (ZIP de
     * Windows leído como CP437: "CAMIαN" → "CAMIÓN"). Null si no aplica.
     */
    function kt_linker_reparar_nombre(string $nombre): ?string
    {
        if (!preg_match('/[^\x00-\x7F]/', $nombre)) {
            return null;
        }
        $bytes = @iconv('UTF-8', 'CP437', $nombre);
        if ($bytes === false) {
            return null;
        }
        $rep = @iconv('CP850', 'UTF-8', $bytes);
        return (is_string($rep) && $rep !== '' && $rep !== $nombre) ? $rep : null;
    }
}

if (!function_exists('processZipAndLink')) {
    /**
     * Process ZIP and link PDFs to existing documents.
     *
     * Empareja cada PDF del ZIP con un documento SIN PDF ("pending"), en este orden:
     *   1. Nombre exacto del archivo (original_path, con el prefijo de fecha "1751…_").
     *   2. Nombre sin el prefijo de fecha, solo si hay UN documento posible.
     *   3. Número/nombre del documento, solo si hay UN documento con ese nombre.
     * Antes el paso 3 iba antes que el nombre exacto y, con documentos repetidos con el
     * mismo nombre (p. ej. dos "VINCULO FACT 113"), los dos PDF caían en el mismo documento:
     * uno quedaba en el documento equivocado y el otro documento quedaba huérfano.
     * Nunca se crean documentos; lo que no empareja se informa.
     */
    function processZipAndLink(PDO $db, $zipTmpPath, $uploadDir, $relativeBase = 'sql_import/')
    {
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath) !== TRUE) {
            throw new Exception("No se pudo abrir el ZIP.");
        }

        $idx = kt_linker_indices($db);
        logMsg("📋 Documentos pendientes indexados: " . count($idx['archivo']) . " por archivo, " . count($idx['nombre']) . " por nombre");

        $linked = 0;
        $unmatched = [];
        $duplicates = 0;
        $linkedIds = [];
        $stmt = $db->prepare("UPDATE documentos SET ruta_archivo = ?, original_path = ? WHERE id = ?");

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = kt_linker_nombre_utf8($zip, $i);

            // Skip non-PDF files
            if (strcasecmp(pathinfo($filename, PATHINFO_EXTENSION), 'pdf') !== 0)
                continue;

            $base = basename($filename);
            if ($base === '' || $base === '.' || $base === '..' || strpos($base, "\0") !== false) {
                continue;
            }
            $stripped = stripTimestamp($base);  // Remove timestamp prefix

            // Extract PDF to upload directory
            $targetPath = rtrim($uploadDir, "/") . "/" . $base;
            if (!kt_linker_extraer($zip, $i, $targetPath)) {
                logMsg("❌ No se pudo extraer: $filename", "error");
                continue;
            }

            $relativePath = rtrim($relativeBase, "/") . "/" . $base;
            $m = kt_linker_emparejar($idx, $linkedIds, $base);
            $docId = $m['id'];
            $matchMethod = $m['metodo'];
            // El archivo es de un documento que ya se enlazó en esta carga: es el mismo PDF repetido
            if ($docId === null && $m['exacto']) {
                $duplicates++;
                logMsg("♻️ Duplicado (doc ya vinculado): $base", "info");
                continue;
            }

            if ($docId !== null) {
                // Link: update ruta_archivo and original_path
                $stmt->execute([$relativePath, $stripped, $docId]);
                $linkedIds[$docId] = true;
                $linked++;
                logMsg("✅ $base => doc_id=$docId ($matchMethod)", "success");
            } else {
                $unmatched[] = $base;
                logMsg("❓ Sin match: $base (stripped: $stripped)", "warn");
            }
        }

        $zip->close();

        logMsg("\n📊 RESUMEN", "info");
        logMsg("────────────────────────────", "info");
        logMsg("✅ PDFs vinculados: $linked", "success");
        logMsg("♻️ Duplicados saltados: $duplicates", "info");
        logMsg("❓ Sin match: " . count($unmatched), count($unmatched) > 0 ? "warn" : "info");

        if (!empty($unmatched)) {
            logMsg("\n❓ PDFs SIN MATCH (no se encontró documento):", "warn");
            foreach (array_slice($unmatched, 0, 50) as $f) {
                logMsg(" - $f", "warn");
            }
            if (count($unmatched) > 50) {
                logMsg(" ... y " . (count($unmatched) - 50) . " más", "warn");
            }
        }

        return [
            'updated' => $linked,
            'created' => 0,
            'duplicates' => $duplicates,
            'unmatched' => count($unmatched),
            'unmatched_files' => array_slice($unmatched, 0, 100),
        ];
    }
}

if (!function_exists('kt_linker_rotos')) {
    /**
     * Documentos con enlace roto: tienen ruta_archivo, pero el PDF no está en el servidor.
     * Mismo criterio que resolve_pdf_path() (helpers/tenant.php), que como último recurso
     * busca el nombre del archivo en todo uploads/; aquí ese recorrido se hace una sola vez.
     */
    function kt_linker_rotos(PDO $db, string $clientCode): array
    {
        $uploadsDir = CLIENTS_DIR . '/' . $clientCode . '/uploads';
        $enDisco = [];
        if (is_dir($uploadsDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $enDisco[$f->getFilename()] = true;
                }
            }
        }
        $ids = [];
        foreach ($db->query("SELECT id, ruta_archivo FROM documentos WHERE ruta_archivo IS NOT NULL AND ruta_archivo NOT IN ('', 'pending')") as $d) {
            $r = $d['ruta_archivo'];
            if (isset($enDisco[basename($r)]) || is_file($uploadsDir . '/' . $r)) {
                continue;
            }
            // Ruta absoluta o con prefijo de una importación antigua: que decida resolve_pdf_path()
            if (preg_match('#^(/|[A-Za-z]:|uploads/client_)#', str_replace('\\', '/', $r)) && resolve_pdf_path($clientCode, $d)) {
                continue;
            }
            $ids[] = (int) $d['id'];
        }
        if (!$ids) {
            return [];
        }
        return $db->query("SELECT d.id, d.numero, d.tipo, d.original_path, d.ruta_archivo,
                (SELECT COUNT(*) FROM codigos c WHERE c.documento_id = d.id) AS num_codes
            FROM documentos d WHERE d.id IN (" . implode(',', $ids) . ") ORDER BY d.numero")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('relinkFromServer')) {
    /**
     * Re-enlaza documentos huérfanos con PDF que YA están en el servidor (sin subir nada).
     *
     * El enlazador anterior guardaba en uploads/ TODOS los PDF del ZIP, también los que no
     * lograba emparejar; esos documentos quedaban "pending" aunque su archivo estuviera ahí:
     *   - Repetidos con el mismo nombre: el PDF de uno quedaba en el otro (cruce). Se repara:
     *     cada uno recibe el archivo con su nombre exacto.
     *   - Nombres con Á/Í/Ó/Ú/´ de un ZIP de Windows: el archivo quedó con el nombre mal
     *     leído ("CAMIαN"). Se reconoce, se renombra al nombre correcto y se enlaza.
     *   - Además, las mismas reglas que al subir un ZIP (sin fecha / nombre, si es único).
     *
     * Con $aplicar = false solo informa qué haría.
     * $rotos: ids con enlace roto (ver kt_linker_rotos()) que también se buscan.
     * $soloId: limita el resultado (y lo que se aplica) a ese documento.
     *
     * @return array{enlaces: array, cruces: array, sin_archivo: array, conflictos: array, aplicado: bool}
     */
    function relinkFromServer(PDO $db, string $uploadsDir, bool $aplicar = false, array $rotos = [], ?int $soloId = null): array
    {
        $uploadsDir = rtrim(str_replace('\\', '/', $uploadsDir), '/');
        $rotos = array_map('intval', $rotos);
        $esRoto = array_flip($rotos);
        $numeros = $db->query("SELECT id, numero FROM documentos")->fetchAll(PDO::FETCH_KEY_PAIR);

        // 1. PDF en el disco (ruta relativa a uploads/), por nombre y por nombre reparado
        $porNombre = [];
        $porReparado = [];
        $todos = [];
        if (is_dir($uploadsDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || strcasecmp($f->getExtension(), 'pdf') !== 0) {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($uploadsDir))), '/');
                $todos[] = $rel;
                $porNombre[kt_linker_clave($f->getFilename())][] = $rel;
                $rep = kt_linker_reparar_nombre($f->getFilename());
                if ($rep !== null) {
                    $porReparado[kt_linker_clave($rep)][] = ['rel' => $rel, 'nombre' => $rep];
                }
            }
        }

        // 2. Qué archivos ya usa algún documento (y cuál)
        $usadoPor = [];
        $usadoPorBase = []; // rutas sin carpeta (Subida en lote guarda solo el nombre)
        $rutaDe = [];
        foreach ($db->query("SELECT id, ruta_archivo, original_path FROM documentos WHERE ruta_archivo IS NOT NULL AND ruta_archivo NOT IN ('', 'pending')") as $d) {
            if (isset($esRoto[(int) $d['id']])) {
                continue; // su archivo ya no está: no ocupa nada
            }
            $r = str_replace('\\', '/', $d['ruta_archivo']);
            if (strpos($r, $uploadsDir . '/') === 0) {
                $r = substr($r, strlen($uploadsDir) + 1); // ruta absoluta dentro de uploads/
            }
            $r = preg_replace('#^uploads/#', '', ltrim($r, '/'));
            $usadoPor[kt_linker_clave($r)] = $d;
            if (strpos($r, '/') === false) {
                $usadoPorBase[kt_linker_clave($r)] = $d;
            }
            $rutaDe[(int) $d['id']] = $r;
        }
        $reservados = []; // rel (minúsculas) ya asignado en este plan
        $quien = function (string $rel) use (&$usadoPor, &$usadoPorBase, &$reservados) {
            $k = kt_linker_clave($rel);
            if (isset($reservados[$k])) {
                return ['id' => $reservados[$k]];
            }
            return $usadoPor[$k] ?? ($usadoPorBase[kt_linker_clave(basename($rel))] ?? null);
        };

        $idx = kt_linker_indices($db, $rotos);
        $linkedIds = [];
        $enlaces = [];
        $cruces = [];
        $conflictos = [];
        $reservar = function (int $id, string $rel, string $metodo, ?string $renombrar = null) use (&$linkedIds, &$reservados, &$enlaces, $numeros) {
            $linkedIds[$id] = true;
            $reservados[kt_linker_clave($rel)] = $id;
            $enlaces[] = ['id' => $id, 'numero' => $numeros[$id] ?? '', 'archivo' => $rel, 'metodo' => $metodo, 'renombrar' => $renombrar];
        };

        // 3. Cada huérfano con el archivo de su nombre exacto (o su nombre mal leído)
        foreach ($idx['esperado'] as $id => $esperados) {
            $esperado = end($esperados); // el nombre original (o el del archivo que tenía)
            $claves = [];
            foreach ($esperados as $e) {
                $claves[] = $e;
                $claves[] = kt_linker_sin_pdf($e) . '.pdf';
            }
            $claves = array_unique($claves);
            $exactos = [];
            foreach ($claves as $k) {
                $exactos = array_merge($exactos, $porNombre[$k] ?? []);
            }
            $libre = null;
            foreach ($exactos as $rel) {
                if ($quien($rel) === null) {
                    $libre = $rel;
                    break;
                }
            }
            if ($libre !== null) {
                $reservar($id, $libre, 'ARCHIVO');
                continue;
            }
            if ($exactos) {
                // Su archivo lo tiene otro documento: ¿es un cruce entre repetidos?
                $rel = $exactos[0];
                $otro = $quien($rel);
                $otroId = (int) $otro['id'];
                $familia = kt_linker_clave(kt_linker_sin_pdf(stripTimestamp($esperado)));
                $otroOrig = kt_linker_clave(kt_linker_sin_pdf(stripTimestamp(basename((string) ($otro['original_path'] ?? '')))));
                $gemelos = [];
                foreach ($todos as $t) {
                    if ($t !== $rel && $quien($t) === null
                        && kt_linker_clave(kt_linker_sin_pdf(stripTimestamp(basename($t)))) === $familia) {
                        $gemelos[] = $t;
                    }
                }
                if ($otroId !== $id && !isset($linkedIds[$otroId]) && $otroOrig === $familia && count($gemelos) === 1) {
                    $cruces[] = [
                        'id_otro' => $otroId, 'numero_otro' => $numeros[$otroId] ?? '',
                        'de' => $rel, 'a' => $gemelos[0], 'para' => $id,
                    ];
                    $linkedIds[$otroId] = true;
                    $reservados[kt_linker_clave($gemelos[0])] = $otroId;
                    $reservados[kt_linker_clave($rel)] = $id;
                    $linkedIds[$id] = true;
                    $enlaces[] = ['id' => $id, 'numero' => $numeros[$id] ?? '', 'archivo' => $rel, 'metodo' => 'CRUCE_REPARADO', 'renombrar' => null];
                } else {
                    $conflictos[] = "Documento #$id (" . ($numeros[$id] ?? '') . "): su archivo $rel ya lo usa el documento #$otroId; revisar a mano.";
                }
                continue;
            }
            foreach ($claves as $k) {
                foreach ($porReparado[$k] ?? [] as $c) {
                    if ($quien($c['rel']) === null) {
                        $nuevo = (dirname($c['rel']) !== '.' ? dirname($c['rel']) . '/' : '') . $c['nombre'];
                        $reservar($id, $c['rel'], 'NOMBRE_REPARADO', $nuevo);
                        continue 3;
                    }
                }
            }
        }

        // 4. Resto: mismas reglas que al subir un ZIP (sin fecha / nombre del documento, si es único)
        foreach ($todos as $rel) {
            if ($quien($rel) !== null) {
                continue;
            }
            $nombres = [basename($rel)];
            $rep = kt_linker_reparar_nombre(basename($rel));
            if ($rep !== null) {
                $nombres[] = $rep;
            }
            foreach ($nombres as $n) {
                $m = kt_linker_emparejar($idx, $linkedIds, $n);
                if ($m['id'] !== null && !$m['exacto']) {
                    $nuevo = $n !== basename($rel) ? (dirname($rel) !== '.' ? dirname($rel) . '/' : '') . $n : null;
                    $reservar($m['id'], $rel, $m['metodo'], $nuevo);
                    break;
                }
            }
        }

        // 5. Huérfanos que siguen sin archivo en el servidor
        $sinArchivo = [];
        $sql = "SELECT id, numero, original_path, ruta_archivo FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''"
            . ($rotos ? ' OR id IN (' . implode(',', $rotos) . ')' : '');
        foreach ($db->query($sql . ' ORDER BY numero') as $d) {
            if (!isset($linkedIds[(int) $d['id']])) {
                $roto = isset($esRoto[(int) $d['id']]);
                $sinArchivo[] = ['id' => (int) $d['id'], 'numero' => $d['numero'],
                    'esperado' => $roto ? basename(str_replace('\\', '/', $d['ruta_archivo'])) : $d['original_path'], 'roto' => $roto];
            }
        }

        if ($soloId !== null) {
            $enlaces = array_values(array_filter($enlaces, fn($e) => $e['id'] === $soloId));
            $cruces = array_values(array_filter($cruces, fn($c) => $c['para'] === $soloId));
            $sinArchivo = array_values(array_filter($sinArchivo, fn($d) => $d['id'] === $soloId));
            $conflictos = array_values(array_filter($conflictos, fn($c) => strpos($c, "Documento #$soloId ") === 0));
        }

        if ($aplicar && ($enlaces || $cruces)) {
            // Si el indexador lo había marcado "Archivo no encontrado", se limpia la marca
            // para que "Indexar pendientes" lo vuelva a tomar
            $upd = $db->prepare("UPDATE documentos SET ruta_archivo = ?,
                datos_extraidos = CASE WHEN datos_extraidos LIKE '%\"error\":%' AND datos_extraidos NOT LIKE '%\"text\":%' THEN NULL ELSE datos_extraidos END
                WHERE id = ?");
            $db->beginTransaction();
            try {
                foreach ($cruces as $c) {
                    $upd->execute([$c['a'], $c['id_otro']]);
                }
                foreach ($enlaces as &$e) {
                    if ($e['renombrar'] !== null && !file_exists("$uploadsDir/{$e['renombrar']}")
                        && @rename("$uploadsDir/{$e['archivo']}", "$uploadsDir/{$e['renombrar']}")) {
                        $e['archivo'] = $e['renombrar'];
                    }
                    $upd->execute([$e['archivo'], $e['id']]);
                }
                unset($e);
                $db->commit();
            } catch (Throwable $ex) {
                $db->rollBack();
                throw $ex;
            }
        }

        return ['enlaces' => $enlaces, 'cruces' => $cruces, 'sin_archivo' => $sinArchivo, 'conflictos' => $conflictos, 'aplicado' => $aplicar];
    }
}
