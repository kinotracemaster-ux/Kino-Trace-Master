<?php
/**
 * Motor de Importación de Datos
 *
 * Permite importar datos desde diferentes formatos:
 * - CSV (valores separados por comas)
 * - SQL (sentencias INSERT)
 * - Excel (xlsx) - requiere librería adicional
 *
 * Valida y mapea columnas antes de insertar en la base de datos.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/tenant.php';

/**
 * Procesa un archivo CSV y devuelve los datos como array.
 *
 * @param string $filePath Ruta al archivo CSV.
 * @param string $delimiter Delimitador (por defecto coma).
 * @return array Datos parseados con headers y filas.
 */
function parse_csv(string $filePath, string $delimiter = ','): array
{
    if (!file_exists($filePath)) {
        throw new Exception("Archivo no encontrado: $filePath");
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new Exception("No se puede abrir el archivo");
    }

    // Detectar BOM UTF-8
    $bom = fread($handle, 3);
    if ($bom !== "\xef\xbb\xbf") {
        rewind($handle);
    }

    // Primera línea como headers
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        throw new Exception("Archivo vacío o formato inválido");
    }

    // Limpiar headers
    $headers = array_map(function ($h) {
        return trim(mb_strtolower($h));
    }, $headers);

    $rows = [];
    $lineNumber = 2;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === count($headers)) {
            $rows[] = array_combine($headers, $row);
        }
        $lineNumber++;
    }

    fclose($handle);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'count' => count($rows)
    ];
}

/**
 * Parsea un archivo SQL y extrae sentencias INSERT.
 *
 * @param string $filePath Ruta al archivo SQL.
 * @return array Datos extraídos de las sentencias INSERT.
 */
/**
 * Parsea un archivo SQL y extrae sentencias INSERT.
 *
 * @param string $filePath Ruta al archivo SQL.
 * @return array Datos extraídos de las sentencias INSERT.
 */
function parse_sql_inserts(string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new Exception("Archivo no encontrado: $filePath");
    }

    $content = file_get_contents($filePath);

    // Buscar sentencias INSERT
    // Modificado para capturar `INSERT INTO table (...) VALUES ...`
    $pattern = '/INSERT\s+INTO\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)\s*VALUES\s*(.+?);/is';
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        return ['tables' => [], 'total_rows' => 0];
    }

    $tables = [];
    $totalRows = 0;

    foreach ($matches as $match) {
        $tableName = $match[1];

        // Parsear columnas (respetando comillas)
        $minifiedCols = $match[2];
        $columns = [];
        $token = strtok($minifiedCols, ",");
        while ($token !== false) {
            $columns[] = trim(trim($token), "`\"' \n\r\t");
            $token = strtok(",");
        }

        $valuesRaw = $match[3];

        // Parsear valores: Es complejo porque puede haber (), (), ...
        // Usamos una estregia de balanceo de paréntesis
        $rowValuesSets = [];
        $len = strlen($valuesRaw);
        $currentSet = '';
        $inParenthesis = 0;
        $inQuotes = false;
        $quoteChar = '';
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $valuesRaw[$i];

            if ($escape) {
                $currentSet .= $char;
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $currentSet .= $char;
                $escape = true;
                continue;
            }

            if (!$inQuotes) {
                if ($char === "'" || $char === '"') {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === '(') {
                    $inParenthesis++;
                } elseif ($char === ')') {
                    $inParenthesis--;
                } elseif ($char === ',' && $inParenthesis === 0) {
                    // Separador de FILAS: (val1), (val2)
                    // Ignoramos la coma fuera de grupos
                    continue;
                }
            } else {
                if ($char === $quoteChar) {
                    $inQuotes = false;
                }
            }
            $currentSet .= $char;

            // Si cerramos un grupo principal (id, name, ...), lo guardamos
            if ($inParenthesis === 0 && $char === ')' && !$inQuotes) {
                if (trim($currentSet) !== '') {
                    $rowValuesSets[] = $currentSet;
                }
                $currentSet = '';
            }
        }

        $rows = [];
        foreach ($rowValuesSets as $valueSet) {
            // Limpiar paréntesis exteriores
            $valueSet = trim($valueSet, " \t\n\r,");
            if (substr($valueSet, 0, 1) === '(' && substr($valueSet, -1) === ')') {
                $valueSet = substr($valueSet, 1, -1);
            }

            // USAR str_getcsv: Mucho más robusto para comillas y escapes estándar SQL
            $vals = str_getcsv($valueSet, ',', "'", "\\");

            // Limpiamos NULLs y cosas raras
            $cleanVals = [];
            foreach ($vals as $v) {
                if ($v === 'NULL')
                    $cleanVals[] = null;
                else
                    $cleanVals[] = $v;
            }

            // Asignamos a columnas solo si coincide cantidad
            if (count($cleanVals) >= count($columns)) {
                // Truncate (si sobran, ej. trailing comma vacía)
                $cleanVals = array_slice($cleanVals, 0, count($columns));
                $rows[] = array_combine($columns, $cleanVals);
                $totalRows++;
            } elseif (count($cleanVals) < count($columns)) {
                // Pad (si faltan)
                $cleanVals = array_pad($cleanVals, count($columns), null);
                $rows[] = array_combine($columns, $cleanVals);
                $totalRows++;
            }
        }

        if (!isset($tables[$tableName])) {
            $tables[$tableName] = [
                'columns' => $columns,
                'rows' => []
            ];
        }

        $tables[$tableName]['rows'] = array_merge($tables[$tableName]['rows'], $rows);
    }

    return [
        'tables' => $tables,
        'total_rows' => $totalRows
    ];
}

/**
 * Mapea columnas de origen a columnas de destino.
 *
 * @param array $sourceColumns Columnas del archivo importado.
 * @return array Mapeo sugerido.
 */
function suggest_column_mapping(array $sourceColumns): array
{
    $targetColumns = [
        'tipo' => ['tipo', 'type', 'document_type', 'doc_type'],
        'numero' => ['numero', 'number', 'num', 'document_number', 'doc_num', 'id'],
        'fecha' => ['fecha', 'date', 'fecha_doc', 'document_date'],
        'proveedor' => ['proveedor', 'provider', 'supplier', 'vendor'],
        'codigo' => ['codigo', 'code', 'product_code', 'item_code', 'sku'],
        'descripcion' => ['descripcion', 'description', 'desc', 'nombre', 'name'],
        'cantidad' => ['cantidad', 'quantity', 'qty', 'cant'],
        'valor' => ['valor', 'value', 'price', 'precio', 'amount']
    ];

    $mapping = [];

    foreach ($sourceColumns as $sourceCol) {
        $sourceLower = strtolower(trim($sourceCol));

        foreach ($targetColumns as $target => $variations) {
            if (in_array($sourceLower, $variations)) {
                $mapping[$sourceCol] = $target;
                break;
            }
        }

        if (!isset($mapping[$sourceCol])) {
            $mapping[$sourceCol] = null; // No mapping found
        }
    }

    return $mapping;
}

/**
 * Importa datos a la base de datos del cliente.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param array $rows Filas a importar.
 * @param array $mapping Mapeo de columnas.
 * @param string $importType Tipo de importación (documentos o códigos).
 * @return array Resultado de la importación.
 */
function import_to_database(PDO $db, array $rows, array $mapping, string $importType = 'documentos'): array
{
    $imported = 0;
    $errors = [];

    $db->beginTransaction();

    try {
        if ($importType === 'documentos') {
            $stmt = $db->prepare("
                INSERT INTO documentos (tipo, numero, fecha, proveedor)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $tipo = $row[$mapping['tipo'] ?? ''] ?? 'otro';
                $numero = $row[$mapping['numero'] ?? ''] ?? '';
                $fecha = $row[$mapping['fecha'] ?? ''] ?? date('Y-m-d');
                $proveedor = $row[$mapping['proveedor'] ?? ''] ?? '';

                if (empty($numero)) {
                    $errors[] = "Fila " . ($index + 1) . ": número vacío";
                    continue;
                }

                $stmt->execute([$tipo, $numero, $fecha, $proveedor]);
                $imported++;
            }

        } elseif ($importType === 'codigos') {
            // Primero crear documento contenedor
            $stmtDoc = $db->prepare("
                INSERT INTO documentos (tipo, numero, fecha, proveedor)
                VALUES ('importacion', ?, ?, 'Importación masiva')
            ");
            $stmtDoc->execute(['IMPORT_' . date('YmdHis'), date('Y-m-d')]);
            $docId = $db->lastInsertId();

            $stmtCode = $db->prepare("
                INSERT INTO codigos (documento_id, codigo, descripcion, cantidad, valor_unitario)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $codigo = $row[$mapping['codigo'] ?? ''] ?? '';
                $descripcion = $row[$mapping['descripcion'] ?? ''] ?? '';
                $cantidad = $row[$mapping['cantidad'] ?? ''] ?? 1;
                $valor = $row[$mapping['valor'] ?? ''] ?? 0;

                if (empty($codigo)) {
                    $errors[] = "Fila " . ($index + 1) . ": código vacío";
                    continue;
                }

                $stmtCode->execute([$docId, $codigo, $descripcion, $cantidad, $valor]);
                $imported++;
            }
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'total_rows' => count($rows)
    ];
}

/**
 * Valida los datos antes de importar.
 *
 * @param array $rows Filas a validar.
 * @param array $mapping Mapeo de columnas.
 * @return array Errores encontrados.
 */
function validate_import_data(array $rows, array $mapping): array
{
    $errors = [];
    $warnings = [];

    foreach ($rows as $index => $row) {
        $lineNum = $index + 2; // +1 por 0-index, +1 por header

        // Validaciones básicas
        if (isset($mapping['fecha'])) {
            $fecha = $row[$mapping['fecha']] ?? '';
            if ($fecha && !strtotime($fecha)) {
                $warnings[] = "Línea $lineNum: formato de fecha inválido";
            }
        }

        if (isset($mapping['cantidad'])) {
            $cantidad = $row[$mapping['cantidad']] ?? '';
            if ($cantidad && !is_numeric($cantidad)) {
                $warnings[] = "Línea $lineNum: cantidad no numérica";
            }
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}
