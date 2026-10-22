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

        $minus = fn($t) => mb_strtolower(trim($t), 'UTF-8');
        $sinPdf = fn($t) => preg_replace('/\.[Pp][Dd][Ff]$/', '', $t);

        // Índices de documentos pendientes: clave → lista de ids (puede haber repetidos)
        $porArchivo = [];      // nombre exacto (con y sin .pdf)
        $porArchivoSinFecha = [];
        $porNombre = [];

        $q = $db->query("SELECT id, numero, original_path FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = '' ORDER BY id");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            if (!empty($row['original_path'])) {
                $orig = $minus(basename(str_replace('\\', '/', $row['original_path'])));
                foreach (array_unique([$orig, $sinPdf($orig)]) as $k) {
                    $porArchivo[$k][] = $id;
                }
                $sf = $minus(stripTimestamp($orig));
                foreach (array_unique([$sf, $sinPdf($sf)]) as $k) {
                    $porArchivoSinFecha[$k][] = $id;
                }
            }
            if (!empty($row['numero'])) {
                $porNombre[$minus($row['numero'])][] = $id;
            }
        }

        logMsg("📋 Documentos pendientes indexados: " . count($porArchivo) . " por archivo, " . count($porNombre) . " por nombre");

        $linked = 0;
        $unmatched = [];
        $duplicates = 0;
        $linkedIds = [];
        $stmt = $db->prepare("UPDATE documentos SET ruta_archivo = ?, original_path = ? WHERE id = ?");

        // Primer pendiente libre de una lista de candidatos (exacto: puede haber repetidos)
        $libre = function (array $ids) use (&$linkedIds) {
            foreach ($ids as $id) {
                if (!isset($linkedIds[$id])) {
                    return $id;
                }
            }
            return null;
        };
        // Único candidato (para coincidencias aproximadas): si hay más de uno, es ambiguo
        $unico = function (array $ids) use (&$linkedIds) {
            $ids = array_values(array_unique($ids));
            if (count($ids) !== 1) {
                return null;
            }
            return isset($linkedIds[$ids[0]]) ? null : $ids[0];
        };

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
            $baseLower = $minus($base);
            $strippedLower = $minus($stripped);

            // Extract PDF to upload directory
            $targetPath = rtrim($uploadDir, "/") . "/" . $base;
            if (!kt_linker_extraer($zip, $i, $targetPath)) {
                logMsg("❌ No se pudo extraer: $filename", "error");
                continue;
            }

            $relativePath = rtrim($relativeBase, "/") . "/" . $base;
            $docId = null;
            $matchMethod = '';

            // 1. Nombre exacto del archivo (con o sin .pdf)
            $exacto = false;
            foreach ([$baseLower, $sinPdf($baseLower)] as $k) {
                if ($docId === null && isset($porArchivo[$k])) {
                    $exacto = true;
                    $docId = $libre($porArchivo[$k]);
                    $matchMethod = 'ARCHIVO';
                }
            }
            // El archivo es de un documento que ya se enlazó en esta carga: es el mismo PDF repetido
            if ($docId === null && $exacto) {
                $duplicates++;
                logMsg("♻️ Duplicado (doc ya vinculado): $base", "info");
                continue;
            }
            // 2. Sin prefijo de fecha, si hay un único documento posible
            if ($docId === null) {
                foreach ([$strippedLower, $sinPdf($strippedLower)] as $k) {
                    if ($docId === null && isset($porArchivoSinFecha[$k])) {
                        $docId = $unico($porArchivoSinFecha[$k]);
                        $matchMethod = 'ARCHIVO_SIN_FECHA';
                    }
                }
            }
            // 3. Nombre del documento, si hay uno solo con ese nombre
            if ($docId === null) {
                foreach ([$sinPdf($strippedLower), $sinPdf($baseLower)] as $k) {
                    if ($docId === null && isset($porNombre[$k])) {
                        $docId = $unico($porNombre[$k]);
                        $matchMethod = 'NOMBRE';
                    }
                }
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
