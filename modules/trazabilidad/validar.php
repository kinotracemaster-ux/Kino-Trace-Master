<?php
/**
 * Página para validar códigos asociados a documentos.
 *
 * Permite marcar cada código como validado o pendiente. Los cambios se
 * reflejan directamente en la base de datos del cliente.
 *
 * Va por páginas de 100 códigos con filtros (pendientes / validados / buscar código).
 * Antes dibujaba TODOS los códigos de una vez: con Kino eran 36.900 filas y 47 MB de
 * HTML, y el navegador se quedaba colgado sin terminar de abrirla.
 */
require_once __DIR__ . '/../../helpers/session_init.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

const KT_VALIDAR_POR_PAGINA = 100;

// Filtros (van en la URL, así "Anterior/Siguiente" y los botones los conservan)
$estado = in_array($_GET['estado'] ?? '', ['pendientes', 'validados'], true) ? $_GET['estado'] : 'todos';
$buscar = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Acciones masivas
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'validate_all') {
                $stmt = $db->prepare('UPDATE codigos SET validado = 1 WHERE validado = 0');
                $stmt->execute();
                $count = $stmt->rowCount();
                $message = "¡Éxito! Se han validado TODOS los códigos ({$count}).";
            } elseif ($_POST['action'] === 'validate_selected') {
                if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
                    $ids = array_values(array_filter(array_map('intval', $_POST['ids'])));
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $db->prepare("UPDATE codigos SET validado = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $count = $stmt->rowCount();
                    $message = "Se han validado {$count} códigos seleccionados.";
                } else {
                    $error = "No has seleccionado ningún código.";
                }
            }
        }
        // Un solo código: botón "Validar" / "Marcar pendiente" de cada fila (valor "id:estado")
        elseif (isset($_POST['toggle']) && preg_match('/^(\d+):([01])$/', (string) $_POST['toggle'], $m)) {
            $stmt = $db->prepare('UPDATE codigos SET validado = ? WHERE id = ?');
            $stmt->execute([(int) $m[2], (int) $m[1]]);
            $message = 'Código actualizado correctamente.';
        }
        // Acción individual (formato anterior)
        elseif (isset($_POST['code_id'])) {
            $codeId = (int) $_POST['code_id'];
            $val = (int) ($_POST['validado'] ?? 0) ? 1 : 0;
            if ($codeId) {
                $stmt = $db->prepare('UPDATE codigos SET validado = ? WHERE id = ?');
                $stmt->execute([$val, $codeId]);
                $message = 'Código actualizado correctamente.';
            }
        }
    }
} catch (Exception $ex) {
    if (strpos($ex->getMessage(), 'database is locked') !== false) {
        $error = 'Error: La base de datos está ocupada (Lock). Intenta de nuevo en unos segundos.';
    } else {
        $error = 'Error crítico: ' . $ex->getMessage();
    }
}

// --- Consulta de la página pedida
$where = [];
$params = [];
if ($estado === 'pendientes') {
    $where[] = 'c.validado = 0';
} elseif ($estado === 'validados') {
    $where[] = 'c.validado = 1';
}
if ($buscar !== '') {
    // Código que empieza por lo escrito, con la misma regla que el buscador
    // (sin espacios, guiones, puntos...) y por el índice idx_codigos_match.
    $key = code_match_key($buscar);
    $match = code_match_sql('c.codigo');
    $where[] = "($match >= ? AND $match < ?)";
    $params[] = $key;
    $params[] = $key . "\u{10FFFF}";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $db->prepare("SELECT COUNT(*) FROM codigos c $whereSql");
$st->execute($params);
$total = (int) $st->fetchColumn();
$paginas = max(1, (int) ceil($total / KT_VALIDAR_POR_PAGINA));
$pagina = min($pagina, $paginas);
$offset = ($pagina - 1) * KT_VALIDAR_POR_PAGINA;

// Sin búsqueda: CROSS JOIN obliga a recorrer los documentos por el índice de fecha y
// traer los códigos de cada uno, sin ordenar todos los códigos (×20: de 92 ms a 0,5 ms).
// Con búsqueda manda el índice del código (pocos resultados).
$union = $buscar === '' ? 'documentos d CROSS JOIN codigos c' : 'codigos c JOIN documentos d';
$st = $db->prepare("SELECT c.id, c.codigo AS codigo_text, c.descripcion, c.validado, d.tipo, d.numero
    FROM $union ON c.documento_id = d.id
    $whereSql
    ORDER BY d.fecha DESC, d.id DESC, c.id
    LIMIT " . KT_VALIDAR_POR_PAGINA . " OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pendientesTotal = (int) $db->query('SELECT COUNT(*) FROM codigos WHERE validado = 0')->fetchColumn();

/** URL de esta página con los filtros actuales y lo que cambie. */
function kt_validar_url(array $cambios = []): string
{
    global $estado, $buscar, $pagina;
    $q = array_merge(['estado' => $estado, 'q' => $buscar, 'p' => $pagina], $cambios);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== 'todos' && $v !== 1);
    return 'validar.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validar Códigos</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .container {
            max-width: 960px;
            margin: 1.5rem auto;
            padding: 0 1rem;
        }

        .filtros {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filtros input[type=search] {
            flex: 1 1 180px;
            min-width: 0;
        }

        .chip {
            padding: 0.45rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            color: #374151;
            text-decoration: none;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .chip.activo {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .tabla-caja {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f9fafb;
            font-size: 0.85rem;
        }

        td.codigo {
            font-weight: 600;
            white-space: nowrap;
        }

        .btn-mini {
            padding: 0.4rem 0.7rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            min-height: 32px;
            white-space: nowrap;
        }

        .btn-valid {
            background: #16a34a;
            color: #fff;
        }

        .btn-invalid {
            background: #ef4444;
            color: #fff;
        }

        .btn-todo {
            background: #0ea5e9;
            color: #fff;
        }

        .estado-ok {
            color: #16a34a;
            font-weight: 600;
        }

        .estado-pend {
            color: #d97706;
            font-weight: 600;
        }

        .message,
        .error {
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 6px;
        }

        .message {
            background: #dcfce7;
            color: #065f46;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .paginacion {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
            margin: 1rem 0;
            flex-wrap: wrap;
        }

        .muted {
            color: #6b7280;
            font-size: 0.9rem;
        }

        input[type=checkbox] {
            width: 24px;
            height: 24px;
        }

        @media (max-width: 640px) {
            .col-desc {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <p><a href="../../index.php">← Volver al inicio</a></p>
        <h1>Validar Códigos</h1>
        <p class="muted"><?= number_format($pendientesTotal) ?> códigos pendientes de validar.</p>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="get" class="filtros">
            <?php foreach (['todos' => 'Todos', 'pendientes' => 'Pendientes', 'validados' => 'Validados'] as $k => $txt): ?>
                <a class="chip <?= $estado === $k ? 'activo' : '' ?>" href="<?= htmlspecialchars(kt_validar_url(['estado' => $k, 'p' => 1])) ?>"><?= $txt ?></a>
            <?php endforeach; ?>
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>">
            <input type="search" name="q" class="form-input" value="<?= htmlspecialchars($buscar) ?>" placeholder="Buscar código...">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </form>

        <form method="post" action="<?= htmlspecialchars(kt_validar_url()) ?>">
            <div class="acciones">
                <button type="submit" name="action" value="validate_selected" class="btn-mini btn-valid">✅ Validar seleccionados</button>
                <button type="submit" name="action" value="validate_all" class="btn-mini btn-todo"
                    onclick="return confirm('¿Estás seguro de validar TODOS los códigos pendientes (<?= number_format($pendientesTotal) ?>)?');">
                    🚀 Validar TODO
                </button>
            </div>

            <?php if (!$rows): ?>
                <p class="muted">No hay códigos con este filtro.</p>
            <?php else: ?>
                <div class="tabla-caja">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll" aria-label="Seleccionar todos los de esta página"></th>
                                <th>Código</th>
                                <th>Documento</th>
                                <th class="col-desc">Descripción</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <?php if (!$r['validado']): ?>
                                            <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="row-checkbox">
                                        <?php endif; ?>
                                    </td>
                                    <td class="codigo"><?= htmlspecialchars($r['codigo_text']) ?></td>
                                    <td><?= htmlspecialchars($r['tipo']) ?> Nº<?= htmlspecialchars($r['numero']) ?></td>
                                    <td class="col-desc"><?= htmlspecialchars($r['descripcion'] ?? '') ?></td>
                                    <td>
                                        <?php if ($r['validado']): ?>
                                            <span class="estado-ok">Validado</span>
                                        <?php else: ?>
                                            <span class="estado-pend">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['validado']): ?>
                                            <button type="submit" name="toggle" value="<?= (int) $r['id'] ?>:0" class="btn-mini btn-invalid">Marcar pendiente</button>
                                        <?php else: ?>
                                            <button type="submit" name="toggle" value="<?= (int) $r['id'] ?>:1" class="btn-mini btn-valid">Validar</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </form>

        <div class="paginacion">
            <span class="muted">
                <?php if ($total): ?>
                    <?= number_format($offset + 1) ?>–<?= number_format($offset + count($rows)) ?> de <?= number_format($total) ?> · página <?= $pagina ?> de <?= $paginas ?>
                <?php endif; ?>
            </span>
            <span style="display:flex; gap:0.5rem;">
                <?php if ($pagina > 1): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(kt_validar_url(['p' => $pagina - 1])) ?>">← Anterior</a>
                <?php endif; ?>
                <?php if ($pagina < $paginas): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(kt_validar_url(['p' => $pagina + 1])) ?>">Siguiente →</a>
                <?php endif; ?>
            </span>
        </div>

        <script>
            const selectAll = document.getElementById('selectAll');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
                });
            }
        </script>
        <p><a href="dashboard.php">← Volver al tablero</a></p>
    </div>
</body>

</html>
