<?php
/**
 * Buscador Independiente - KINO TRACE
 *
 * Módulo PÚBLICO standalone para búsqueda por código.
 * No requiere autenticación - accesible para cualquier usuario.
 * Cada cliente tiene su URL con parámetro ?cliente=CODIGO
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Obtener cliente desde parámetro (PÚBLICO - sin sesión)
$clientCode = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
if (empty($clientCode)) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
        <h2>Error</h2>
        <p>Debe especificar el cliente en la URL</p>
        <p>Ejemplo: ?cliente=KINO</p>
    </div>');
}

// Verificar que el cliente existe
$clientDir = CLIENTS_DIR . "/{$clientCode}";
if (!is_dir($clientDir)) {
    die('<div style="text-align:center; padding:50px; font-family:Arial;">
        <h2>Error</h2>
        <p>Cliente no encontrado</p>
    </div>');
}

$db = open_client_db($clientCode);

// Cargar contenido personalizado de página pública
$ppData = [];
if (isset($centralDb)) {
    $ppStmt = $centralDb->prepare('SELECT * FROM pagina_publica WHERE codigo = ? LIMIT 1');
    $ppStmt->execute([$clientCode]);
    $ppData = $ppStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Defaults
$ppIntroTitulo = $ppData['intro_titulo'] ?? '';
$ppIntroTexto = $ppData['intro_texto'] ?? '';
$ppInstrucciones = $ppData['instrucciones'] ?? '';
$ppFooterTexto = $ppData['footer_texto'] ?? '';
$ppFooterUbicacion = $ppData['footer_ubicacion'] ?? '';
$ppFooterTelefono = $ppData['footer_telefono'] ?? '';
$ppFooterUrl = $ppData['footer_url'] ?? '';
$ppAvisoLegal = $ppData['aviso_legal'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda por Código - KINO COMPANY</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <?php
    $clientConfig = get_client_config($clientCode);
    $cP = $clientConfig['color_primario'] ?? '#c41e3a';
    $cS = $clientConfig['color_secundario'] ?? '#333';
    $clientName = $clientConfig['titulo'] ?? $clientConfig['nombre'] ?? 'KINO COMPANY';

    // Detect logo
    $clientLogo = '';
    $extensions = ['png', 'jpg', 'jpeg', 'gif'];
    foreach ($extensions as $ext) {
        if (file_exists(__DIR__ . '/../../clients/' . $clientCode . '/logo.' . $ext)) {
            $clientLogo = 'clients/' . $clientCode . '/logo.' . $ext;
            break;
        }
    }
    ?>
    <style>
        :root {
            --primary-color:
                <?= $cP ?>
            ;
            --secondary-color:
                <?= $cS ?>
            ;
        }

        /* Reset y estilos base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Contenedor principal centrado */
        .buscador-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Tarjeta principal */
        .buscador-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            max-width: 700px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        /* ======== LOGO PLACEHOLDER ======== */
        .logo-container {
            margin-bottom: 30px;
        }

        .logo-placeholder {
            /* Espacio reservado para logo - 200x80px recomendado */
            width: 200px;
            height: 80px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            /* Estilo temporal mientras no haya logo */
            border: 2px dashed #ccc;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .logo-placeholder img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        /* Texto temporal del logo */
        .logo-text {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }

        .logo-text .kino {
            color: var(--primary-color);
        }

        .logo-text .company {
            color: var(--secondary-color);
            font-weight: 400;
            letter-spacing: 8px;
            font-size: 14px;
            display: block;
            margin-top: -5px;
        }

        /* ======== FIN LOGO PLACEHOLDER ======== */

        /* Texto introductorio */
        .intro-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
        }

        .intro-text {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
            font-style: italic;
        }

        /* Modo de uso */
        .modo-uso {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
        }

        .modo-uso h4 {
            text-align: center;
            margin-bottom: 12px;
            color: #333;
            font-size: 16px;
        }

        .modo-uso ul {
            list-style: none;
            padding: 0;
        }

        .modo-uso li {
            padding: 6px 0;
            color: #555;
            font-size: 14px;
            text-align: center;
        }

        .modo-uso .highlight {
            background: #ffeeba;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .modo-uso .link-red {
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Sección de búsqueda */
        .search-section {
            border-top: 1px solid #eee;
            padding-top: 30px;
        }

        .search-section h3 {
            font-size: 22px;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
        }

        .search-form {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 250px;
            max-width: 400px;
            padding: 14px 18px;
            font-size: 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .search-input:focus {
            border-color: var(--primary-color);
        }

        .search-input::placeholder {
            color: #aaa;
        }

        .btn-buscar {
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            color: white;
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-buscar:hover {
            background: var(--primary-color);
            filter: brightness(0.9);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-limpiar {
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            background: #e9ecef;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-limpiar:hover {
            background: #dee2e6;
        }

        /* Resultados */
        .results-container {
            margin-top: 30px;
            text-align: left;
        }

        .results-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }

        .result-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .result-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .result-badge {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .result-date {
            color: #888;
            font-size: 14px;
        }

        .result-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 12px;
        }

        .result-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-ver-pdf {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            color: white;
            background: var(--primary-color);
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-ver-pdf:hover {
            background: var(--primary-color);
            filter: brightness(0.9);
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #888;
        }

        .loading {
            text-align: center;
            padding: 30px;
            color: #666;
        }

        .loading .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .hidden {
            display: none !important;
        }

        /* Footer */
        .buscador-footer {
            background: #f8f9fa;
            padding: 30px 20px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }

        .footer-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .footer-contact {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .footer-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        .footer-legal {
            margin-top: 15px;
        }

        .footer-legal a {
            color: var(--primary-color);
            text-decoration: underline;
            font-size: 13px;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .buscador-card {
                padding: 25px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-input {
                max-width: 100%;
            }

            .btn-buscar,
            .btn-limpiar {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="buscador-container">
        <div class="buscador-card">
            <!-- LOGO - Espacio reservado para agregar después -->
            <div class="logo-container">
                <div class="logo-placeholder" style="border: none; background: transparent;">
                    <?php if ($clientLogo): ?>
                        <img src="../../<?= $clientLogo ?>" alt="<?= htmlspecialchars($clientName) ?>">
                    <?php else: ?>
                        <div class="logo-text">
                            <span class="kino"><?= htmlspecialchars(explode(' ', $clientName)[0]) ?></span>
                            <span
                                class="company"><?= htmlspecialchars(substr(strstr($clientName, ' ') ?: '', 1) ?: 'COMPANY') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Texto introductorio -->
            <?php if ($ppIntroTitulo): ?>
                <h2 class="intro-title"><?= htmlspecialchars($ppIntroTitulo) ?></h2>
            <?php endif; ?>
            <?php if ($ppIntroTexto): ?>
                <p class="intro-text"><?= htmlspecialchars($ppIntroTexto) ?></p>
            <?php endif; ?>

            <!-- Modo de uso -->
            <?php if ($ppInstrucciones): ?>
                <div class="modo-uso">
                    <h4>Modo de uso:</h4>
                    <ul>
                        <?php foreach (explode("\n", $ppInstrucciones) as $linea): ?>
                            <?php $linea = trim($linea);
                            if ($linea): ?>
                                <li><?= htmlspecialchars($linea) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Sección de búsqueda -->
            <div class="search-section">
                <h3>Búsqueda por Código</h3>
                <div class="search-form">
                    <input type="text" class="search-input" id="codigoInput" placeholder="Código a buscar"
                        autocomplete="off">
                    <button class="btn-buscar" onclick="buscarCodigo()">Buscar</button>
                    <button class="btn-limpiar" onclick="limpiar()">Limpiar</button>
                </div>
            </div>

            <!-- Área de carga -->
            <div id="loadingArea" class="loading hidden">
                <div class="spinner"></div>
                <p>Buscando documentos...</p>
            </div>

            <!-- Área de resultados -->
            <div id="resultsContainer" class="results-container hidden">
                <h4 class="results-title">Documentos encontrados:</h4>
                <div id="resultsList"></div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php if ($ppFooterTexto || $ppFooterUbicacion || $ppFooterTelefono || $ppFooterUrl || $ppAvisoLegal): ?>
        <footer class="buscador-footer">
            <?php if ($ppFooterTexto): ?>
                <p class="footer-text"><?= htmlspecialchars($ppFooterTexto) ?></p>
            <?php endif; ?>
            <?php if ($ppFooterUbicacion): ?>
                <p class="footer-text">Estamos ubicados en <?= htmlspecialchars($ppFooterUbicacion) ?>.</p>
            <?php endif; ?>
            <?php if ($ppFooterTelefono): ?>
                <p class="footer-contact">Línea de Atención: <?= htmlspecialchars($ppFooterTelefono) ?></p>
            <?php endif; ?>
            <?php if ($ppFooterUrl): ?>
                <a href="<?= htmlspecialchars($ppFooterUrl) ?>" target="_blank"
                    class="footer-link"><?= htmlspecialchars($ppFooterUrl) ?></a>
            <?php endif; ?>
            <?php if ($ppAvisoLegal): ?>
                <div class="footer-legal">
                    <a href="#" onclick="document.getElementById('avisoLegalModal').style.display='flex'; return false;">Aviso
                        Legal</a>
                </div>
            <?php endif; ?>
        </footer>
    <?php endif; ?>

    <!-- Modal Aviso Legal -->
    <?php if ($ppAvisoLegal): ?>
        <div id="avisoLegalModal"
            style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:999; justify-content:center; align-items:center;"
            onclick="if(event.target===this)this.style.display='none';">
            <div style="background:white; border-radius:12px; padding:30px; max-width:500px; width:90%; position:relative;">
                <h3 style="margin-bottom:15px;">Aviso Legal</h3>
                <button onclick="this.parentElement.parentElement.style.display='none'"
                    style="position:absolute;top:12px;right:15px;background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
                <?php foreach (explode("\n", $ppAvisoLegal) as $parrafo): ?>
                    <?php $parrafo = trim($parrafo);
                    if ($parrafo): ?>
                        <p style="color:#555; line-height:1.6; margin-bottom:10px;"><?= htmlspecialchars($parrafo) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const apiUrl = '../../api.php';
        const clientCode = '<?= $clientCode ?>';
        const codigoInput = document.getElementById('codigoInput');
        const loadingArea = document.getElementById('loadingArea');
        const resultsContainer = document.getElementById('resultsContainer');
        const resultsList = document.getElementById('resultsList');

        // Buscar al presionar Enter
        codigoInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                buscarCodigo();
            }
        });

        async function buscarCodigo() {
            const codigo = codigoInput.value.trim().toUpperCase();

            if (!codigo) {
                alert('Por favor ingrese un código para buscar');
                return;
            }

            // Actualizar input a mayúsculas
            codigoInput.value = codigo;

            // Mostrar loading
            loadingArea.classList.remove('hidden');
            resultsContainer.classList.add('hidden');

            try {
                const response = await fetch(`${apiUrl}?action=search_by_code&code=${encodeURIComponent(codigo)}`);
                const result = await response.json();

                loadingArea.classList.add('hidden');

                if (!result.documents || result.documents.length === 0) {
                    resultsContainer.classList.remove('hidden');
                    resultsList.innerHTML = `
                        <div class="no-results">
                            <p>No se encontraron documentos con el código: <strong>${codigo}</strong></p>
                            <p style="margin-top: 10px; font-size: 13px;">Verifique que el código esté escrito correctamente en mayúsculas.</p>
                        </div>
                    `;
                    return;
                }

                // Mostrar resultados
                resultsContainer.classList.remove('hidden');
                resultsList.innerHTML = result.documents.map(doc => `
                    <div class="result-item">
                        <div class="result-header">
                            <span class="result-badge">${(doc.tipo || 'DOCUMENTO').toUpperCase()}</span>
                            <span class="result-date">${doc.fecha || ''}</span>
                        </div>
                        <div class="result-title">${doc.numero || 'Sin nombre'}</div>
                        <div class="result-actions">
                            <a href="viewer_publico.php?cliente=${clientCode}&doc=${doc.id}&term=${encodeURIComponent(codigo)}" 
                               target="_blank" 
                               class="btn-ver-pdf">
                                📄 VER PDF
                            </a>
                        </div>
                    </div>
                `).join('');

            } catch (error) {
                loadingArea.classList.add('hidden');
                alert('Error al buscar: ' + error.message);
            }
        }

        function limpiar() {
            codigoInput.value = '';
            resultsContainer.classList.add('hidden');
            resultsList.innerHTML = '';
            codigoInput.focus();
        }

        // Focus automático al cargar
        codigoInput.focus();
    </script>
</body>

</html>