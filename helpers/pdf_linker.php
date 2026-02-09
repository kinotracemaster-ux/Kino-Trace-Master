<?php
/**
 * helpers/pdf_linker.php
 * 
 * Shared logic for linking PDFs from a ZIP file to existing database documents.
 * Extracted from modules/importar_sql/process.php to support CSV/Excel mass import.
 */

if (!function_exists('normalizeKey')) {
    function normalizeKey($s)
    {
        // 1) keep only filename (remove directories)
        $s = basename($s);

        // 2) remove extension
        $s = preg_replace('/\.[Pp][Dd][Ff]$/', '', $s);

        // 3) remove leading timestamp-like prefixes: "1748...._" or "1748....-"
        $s = preg_replace('/^\d{6,}[_\-\s]+/', '', $s);

        // 4) normalize spaces
        $s = str_replace(["\r", "\n", "\t"], " ", $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);

        // 5) lowercase for stable compare
        $s = mb_strtolower($s, 'UTF-8');

        return $s;
    }
}

if (!function_exists('buildDocumentoIndex')) {
    function buildDocumentoIndex(PDO $db)
    {
        // Build a PHP-side index for robust matching (no fragile SQL LIKE).
        // We index by:
        // - normalized numero
        // - normalized original_path (filename)
        // - normalized original_path without extension
        $idx = [];  // key => id

        $q = $db->query("SELECT id, numero, original_path, ruta_archivo FROM documentos");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];

            if (!empty($row['numero'])) {
                $k = normalizeKey($row['numero']);
                if ($k !== "")
                    $idx[$k] = $id;
            }

            if (!empty($row['original_path'])) {
                $k1 = normalizeKey($row['original_path']);
                if ($k1 !== "")
                    $idx[$k1] = $id;

                // Also index without timestamp prefix if original_path includes it
                $k2 = normalizeKey(basename($row['original_path']));
                if ($k2 !== "")
                    $idx[$k2] = $id;
            }
        }
        return $idx;
    }
}

if (!function_exists('linkById')) {
    /**
     * Link a PDF file to a documento row by id (single source of truth).
     * Updates:
     * - ruta_archivo: relative path where we stored the extracted PDF
     * - original_path: store the ZIP original filename (with extension), NOT just base
     */
    function linkById(PDO $db, $id, $relativePath, $fullFilename)
    {
        $stmt = $db->prepare("UPDATE documentos
                              SET ruta_archivo = ?, original_path = ?
                              WHERE id = ?");
        $stmt->execute([$relativePath, $fullFilename, $id]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('processZipAndLink')) {
    /**
     * Process ZIP and link PDFs.
     * - $zipTmpPath: the uploaded ZIP tmp file
     * - $uploadDir: absolute directory where PDFs will be extracted
     * - $relativeBase: relative base used in DB (e.g. 'sql_import/')
     */
    function processZipAndLink(PDO $db, $zipTmpPath, $uploadDir, $relativeBase = 'sql_import/')
    {
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath) !== TRUE) {
            throw new Exception("No se pudo abrir el ZIP.");
        }

        // Index existing documents once (fast)
        $idx = buildDocumentoIndex($db);

        // Track what document ids already got a PDF in this import run
        $linkedDocIds = [];
        $updatedDocs = 0;
        $createdDocs = 0;
        $duplicates = [];
        $unmatched = [];

        // Prepared statements for fast exact checks
        // (1) Exact match by original_path (case-insensitive)
        $stmtFindByPath = $db->prepare("SELECT id FROM documentos WHERE LOWER(original_path) = LOWER(?) LIMIT 1");
        // (2) Exact match by numero (case-insensitive)
        $stmtFindByNumero = $db->prepare("SELECT id FROM documentos WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?)) LIMIT 1");

        // Insert (best-effort) — no assumption about DB engine
        $stmtCreate = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, estado, ruta_archivo, original_path)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)");

        // Local set to avoid creating same "new" doc twice in the same ZIP run
        $createdKeys = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'pdf')
                continue;

            $base = basename($filename);
            $targetPath = rtrim($uploadDir, "/") . "/" . $base;

            // Extract file
            $ok = copy("zip://" . $zipTmpPath . "#" . $filename, $targetPath);
            if (!$ok) {
                logMsg("❌ No se pudo extraer: $filename", "error");
                continue;
            }

            $relativePath = rtrim($relativeBase, "/") . "/" . $base;   // 'sql_import/xxx.pdf'
            $numero = pathinfo($base, PATHINFO_FILENAME);              // without .pdf

            // ---------- MATCH STEP 1: original_path exact ----------
            $stmtFindByPath->execute([$base]); // store only basename in DB by convention
            $id = $stmtFindByPath->fetchColumn();

            if (!$id) {
                // Also try full filename (if DB stored with folders)
                $stmtFindByPath->execute([$filename]);
                $id = $stmtFindByPath->fetchColumn();
            }

            if ($id) {
                $id = (int) $id;

                if (isset($linkedDocIds[$id])) {
                    $duplicates[] = [$base, $id, "PATH"];
                    continue;
                }

                if (linkById($db, $id, $relativePath, $base)) {
                    $linkedDocIds[$id] = true;
                    $updatedDocs++;
                    logMsg("✅ Vinculado por PATH: $base (doc_id=$id)", "success");
                    continue;
                }
            }

            // ---------- MATCH STEP 2: numero exact ----------
            $stmtFindByNumero->execute([$numero]);
            $id = $stmtFindByNumero->fetchColumn();
            if ($id) {
                $id = (int) $id;

                if (isset($linkedDocIds[$id])) {
                    $duplicates[] = [$base, $id, "NUMERO"];
                    continue;
                }

                if (linkById($db, $id, $relativePath, $base)) {
                    $linkedDocIds[$id] = true;
                    $updatedDocs++;
                    logMsg("✅ Vinculado por NUMERO: $numero (doc_id=$id)", "success");
                    continue;
                }
            }

            // ---------- MATCH STEP 3: normalized "semantic" key ----------
            // This is the key fix to link PDFs that include timestamps/prefixes
            $kFile = normalizeKey($base);     // removes timestamp prefix, lower, etc.
            if ($kFile !== "" && isset($idx[$kFile])) {
                $id = (int) $idx[$kFile];

                if (isset($linkedDocIds[$id])) {
                    $duplicates[] = [$base, $id, "NORM"];
                    continue;
                }

                if (linkById($db, $id, $relativePath, $base)) {
                    $linkedDocIds[$id] = true;
                    $updatedDocs++;
                    logMsg("✅ Vinculado por NORMALIZACIÓN: $base (doc_id=$id)", "success");
                    continue;
                }
            }

            // ---------- UNMATCHED: auto-link/self-heal or auto-create ----------
            // Self-heal: try to find doc by normalized numero (removing timestamp prefix)
            $numeroNorm = normalizeKey($numero);
            if ($numeroNorm !== "" && isset($idx[$numeroNorm])) {
                $id = (int) $idx[$numeroNorm];

                if (!isset($linkedDocIds[$id])) {
                    if (linkById($db, $id, $relativePath, $base)) {
                        $linkedDocIds[$id] = true;
                        $updatedDocs++;
                        logMsg("🔗 Auto-Vinculado (Self-Healing): $base (doc_id=$id)", "success");
                        continue;
                    }
                } else {
                    $duplicates[] = [$base, $id, "SELFHEAL"];
                    continue;
                }
            }

            // Auto-create (ONLY if truly new)
            // Deduplicate inside the same run by normalized key, not by raw filename
            $createKey = $kFile !== "" ? $kFile : normalizeKey($numero);
            if ($createKey !== "" && isset($createdKeys[$createKey])) {
                // same doc name repeated in ZIP (different timestamps) -> treat as duplicate file
                $duplicates[] = [$base, null, "CREATE_DEDUP"];
                continue;
            }
            $createdKeys[$createKey] = true;

            $fecha = date('Y-m-d');
            try {
                $stmtCreate->execute([
                    'generado_auto',
                    pathinfo($base, PATHINFO_FILENAME), // keep full filename (no ext) as numero
                    $fecha,
                    'Importación Auto',
                    'procesado',
                    $relativePath,
                    $base  // store basename WITH extension to keep uniqueness stable
                ]);
                $createdDocs++;
                logMsg("✨ Documento creado autom.: $base", "success");
            } catch (Exception $e) {
                // If UNIQUE(original_path) exists, this prevents fatal crashes.
                // We log and continue.
                logMsg("⚠️ No se pudo crear (posible duplicado): $base | " . $e->getMessage(), "warn");
                $unmatched[] = $base;
            }
        }

        $zip->close();

        logMsg("\n📊 RESUMEN ZIP", "info");
        logMsg("----------------------------------------", "info");
        logMsg("✅ Documentos vinculados/actualizados: $updatedDocs", "info");
        logMsg("✨ Documentos creados: $createdDocs", "info");
        logMsg("♻️ PDFs duplicados (mismo documento): " . count($duplicates), "info");
        logMsg("❓ PDFs sin procesar por error: " . count($unmatched), "info");

        if (!empty($duplicates)) {
            logMsg("\n♻️ LISTA DE DUPLICADOS (se saltaron para no crear copias):", "info");
            foreach ($duplicates as $d) {
                $file = $d[0];
                $id = $d[1] === null ? "N/A" : $d[1];
                $why = $d[2];
                logMsg(" - $file => doc_id=$id ($why)", "info");
            }
        }

        if (!empty($unmatched)) {
            logMsg("\n❗ ARCHIVOS CON ERROR (revisar nombres/DB):", "warn");
            foreach ($unmatched as $f)
                logMsg(" - $f", "warn");
        }
    }
}
