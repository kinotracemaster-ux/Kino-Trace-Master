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

if (!function_exists('processZipAndLink')) {
    /**
     * Process ZIP and link PDFs to existing documents.
     * 
     * Strategy: Simple column-to-column matching.
     * 1. Extract PDF from ZIP
     * 2. Strip timestamp prefix from PDF filename
     * 3. Match against original_path column in documentos table
     * 4. If no match by original_path, try matching by numero column
     * 5. Report unmatched PDFs (do NOT auto-create documents)
     */
    function processZipAndLink(PDO $db, $zipTmpPath, $uploadDir, $relativeBase = 'sql_import/')
    {
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath) !== TRUE) {
            throw new Exception("No se pudo abrir el ZIP.");
        }

        // Build index: original_path (lowercase) → doc id
        $pathIndex = [];  // original_path → id
        $nameIndex = [];  // numero (lowercase) → id

        $q = $db->query("SELECT id, numero, original_path FROM documentos WHERE ruta_archivo = 'pending' OR ruta_archivo IS NULL OR ruta_archivo = ''");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];

            if (!empty($row['original_path'])) {
                $key = mb_strtolower(trim(basename($row['original_path'])), 'UTF-8');
                $pathIndex[$key] = $id;

                // Also index without extension
                $keyNoExt = preg_replace('/\.[Pp][Dd][Ff]$/', '', $key);
                if ($keyNoExt !== $key)
                    $pathIndex[$keyNoExt] = $id;
            }

            if (!empty($row['numero'])) {
                $key = mb_strtolower(trim($row['numero']), 'UTF-8');
                $nameIndex[$key] = $id;
            }
        }

        logMsg("📋 Documentos pendientes indexados: " . count($pathIndex) . " por path, " . count($nameIndex) . " por nombre");

        $linked = 0;
        $unmatched = [];
        $duplicates = 0;
        $linkedIds = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip non-PDF files
            if (strcasecmp(pathinfo($filename, PATHINFO_EXTENSION), 'pdf') !== 0)
                continue;

            $base = basename($filename);
            $stripped = stripTimestamp($base);  // Remove timestamp prefix
            $strippedLower = mb_strtolower(trim($stripped), 'UTF-8');
            $strippedNoExt = preg_replace('/\.[Pp][Dd][Ff]$/', '', $strippedLower);

            // Extract PDF to upload directory
            $targetPath = rtrim($uploadDir, "/") . "/" . $base;
            $ok = copy("zip://" . $zipTmpPath . "#" . $filename, $targetPath);
            if (!$ok) {
                logMsg("❌ No se pudo extraer: $filename", "error");
                continue;
            }

            $relativePath = rtrim($relativeBase, "/") . "/" . $base;
            $docId = null;
            $matchMethod = '';

            // --- MATCH 1: original_path (with .pdf) ---
            if (isset($pathIndex[$strippedLower])) {
                $docId = $pathIndex[$strippedLower];
                $matchMethod = 'PATH';
            }
            // --- MATCH 2: original_path (without .pdf) ---
            elseif (isset($pathIndex[$strippedNoExt])) {
                $docId = $pathIndex[$strippedNoExt];
                $matchMethod = 'PATH_NOEXT';
            }
            // --- MATCH 3: numero ---
            elseif (isset($nameIndex[$strippedNoExt])) {
                $docId = $nameIndex[$strippedNoExt];
                $matchMethod = 'NOMBRE';
            }
            // --- MATCH 4: Try base without timestamp as-is against path ---
            else {
                $baseLower = mb_strtolower(trim($base), 'UTF-8');
                $baseNoExt = preg_replace('/\.[Pp][Dd][Ff]$/', '', $baseLower);
                if (isset($pathIndex[$baseLower])) {
                    $docId = $pathIndex[$baseLower];
                    $matchMethod = 'PATH_FULL';
                } elseif (isset($pathIndex[$baseNoExt])) {
                    $docId = $pathIndex[$baseNoExt];
                    $matchMethod = 'PATH_FULL_NOEXT';
                } elseif (isset($nameIndex[$baseNoExt])) {
                    $docId = $nameIndex[$baseNoExt];
                    $matchMethod = 'NOMBRE_FULL';
                }
            }

            if ($docId !== null) {
                if (isset($linkedIds[$docId])) {
                    $duplicates++;
                    logMsg("♻️ Duplicado (doc ya vinculado): $base => doc_id=$docId", "info");
                    continue;
                }

                // Link: update ruta_archivo and original_path
                $stmt = $db->prepare("UPDATE documentos SET ruta_archivo = ?, original_path = ? WHERE id = ?");
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
