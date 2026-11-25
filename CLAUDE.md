# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

KINO TRACE — sistema PHP multi-cliente (multi-tenant) de gestión documental para rastreo aduanero: sube manifiestos/declaraciones en PDF, extrae códigos automáticamente, permite búsqueda voraz (mínimo conjunto de documentos que cubre una lista de códigos) y valida cruces entre manifiestos y declaraciones. Sin frontend framework: PHP renderiza HTML server-side con JS embebido; PDF.js para el visor/resaltador de PDFs.

## Workflow (leer primero)

- Usuario escribe en español: responder en español, explicaciones simples (está aprendiendo git).
- **Una rama por tarea, PR a `main`, nunca commit directo a `main`.** Guía completa: `docs/FLUJO_RAMAS.md`.
- Este archivo es la memoria del proyecto: leerlo antes de explorar el código y actualizarlo en el mismo PR si algo aquí deja de ser cierto. Para detalle, `MAPA_TECNICO.md` en vez de recorrer el repo.
- Prioridades actuales: 1) velocidad del resaltado (`modules/resaltar/`, PR #12), 2) responsive (ver "UX y celular"), 3) seguridad.

## Agentes (orquestador + lector + escritor)

Subagentes en `.claude/agents/`. La sesión principal (Opus) es el **orquestador**: al entrar un requerimiento de código sigue este orden:

1. **`lector`** (Haiku, solo lectura): ubica archivos, funciones y reglas de este archivo que aplican; devuelve un resumen corto con `ruta:línea`.
2. El orquestador decide el plan (qué cambiar, en qué archivos, qué chequeos) con lo que trajo el lector.
3. **`escritor`** (Sonnet): implementa ese plan concreto, corre `php -l` / `php tools/chequeo.php` / chequeos específicos y reporta el diff. No hace commit ni PR.
4. El orquestador revisa el diff, pide correcciones al escritor si hace falta, y hace rama → commit → PR.

Preguntas simples o cambios de una línea no necesitan agentes. Para varias búsquedas independientes se pueden lanzar varios `lector` en paralelo.

## Commands

```bash
# Servidor local
php -S localhost:8080

# Crear/migrar usuario admin (requerido tras clonar o desplegar)
php migrate.php

# Tests (PHPUnit, bootstrap en tests/bootstrap.php)
# ⚠️ composer.json NO declara require-dev (verificado 2026-09-09): PHPUnit no viene instalado
# por `composer install` tal cual está el repo. Si vendor/bin/phpunit no existe, instalarlo primero:
# composer require --dev phpunit/phpunit --no-interaction
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Api/SearchControllerTest.php          # un archivo
./vendor/bin/phpunit --filter testNombreDelMetodo                # un test
```

No hay `composer.json` con scripts de lint/build; `composer install` solo trae dependencias de producción (`setasign/fpdi-tcpdf`, `phpmailer/phpmailer`).

```bash
# Chequeo antes de publicar (lo corre GitHub Actions en cada PR: .github/workflows/chequeo.yml)
# Sintaxis de todos los .php + deploy simulado en carpeta temporal (migra database_initial
# y un cliente nuevo, y repite los pasos para probar que son idempotentes). Nunca toca clients/.
php tools/chequeo.php

# Poner al día la base de TODOS los clientes (corre solo al arrancar cada deploy, ver Dockerfile)
php tools/migrar_clientes.php
```

⚠️ `optimize_db.php` **no existe** en el repo pese a haber sido documentado antes como comando. Índices y tablas nuevas viven en `client_schema_migrations()` (`helpers/tenant.php`), ver "Cambios para todos los clientes".

Para un mapa exhaustivo y verificado del código real (todas las acciones de `api.php`, esquema SQLite completo, inventario de helpers/módulos, inconsistencias conocidas), ver `MAPA_TECNICO.md`.

## Architecture

### Multi-tenancy vía SQLite por cliente

No hay servidor de BD externo. `config.php` crea/abre `clients/central.db` (tabla `control_clientes`: credenciales y branding de cada cliente) al arrancar cualquier request. Cada cliente tiene su propia carpeta `clients/{codigo}/{codigo}.db` + `clients/{codigo}/uploads/`. `helpers/tenant.php` centraliza `sanitize_code()`, `client_db_path()` y `open_client_db()` (con auto-reparación: si falta la BD del cliente, la copia desde `database_initial/{codigo}/`). En Railway todo `clients/` vive en un volumen persistente — sin volumen se pierde todo en cada deploy. `database_initial/` es la plantilla que se copia a `clients/` solo en el primer arranque (cuando `central.db` no existe).

Login (`login.php`) valida código+contraseña contra `central.db`, guarda `$_SESSION['client_code']`, y desde ahí toda request abre la BD de ese cliente vía `open_client_db($_SESSION['client_code'])`.

### Cambios para todos los clientes (código global + migraciones versionadas)

El código es **uno solo para todos los clientes**; por cliente solo hay datos (`clients/{codigo}/{codigo}.db`, `uploads/`, `logo.*`) y su fila en `central.db`. No hay páginas, config ni plantillas copiadas por cliente: un merge a `main` llega a todos los subdominios a la vez. Lo único que vive *dentro* de cada cliente es el **esquema de su base**, y eso se maneja así:

- `client_schema_migrations()` (`helpers/tenant.php`) es la **única** lista de pasos de esquema de las bases de cliente (también la usa `create_client_structure`). La versión aplicada se guarda en cada base con `PRAGMA user_version`; `ensure_client_schema()` (en cada `open_client_db()`) sale de inmediato si la base está al día y si no aplica los pasos pendientes en una transacción `BEGIN IMMEDIATE`. `central.db` usa el mismo esquema de pasos en `config.php` (`$centralMigrations`).
- **Para cambiar el esquema: AGREGAR un paso nuevo al final** (`2 => function (PDO $db) {...}`). Nunca editar ni borrar un paso publicado (en producción ya corrió y no se repite). Cada paso debe ser idempotente (`IF NOT EXISTS`, `kt_add_column()`) y aditivo (el código viejo debe seguir funcionando con la base nueva). Si cambia `code_match_sql()`, agregar un paso que borre y recree `idx_codigos_match`.
- Al arrancar cada deploy, `start.sh` (Dockerfile) corre `tools/migrar_clientes.php` → `migrate_all_clients()`: todos los clientes quedan al día antes de abrir la web y los errores salen en el log de Railway. El panel admin muestra "Base desactualizada" por cliente y tiene el botón **🗄️ Actualizar bases**.
- Flujo de publicación: rama → PR → **chequeo en verde** (GitHub Actions, `tools/chequeo.php`) → probar en Railway **PRUEBAS** → merge a `main`.

### Alta de clientes y funciones por cliente

- **Alta en un paso:** el formulario "Crear cliente" del panel llama a `kt_alta_cliente()` (`helpers/alta_cliente.php`): valida código, correo y **subdominio** (que no sea de otro cliente ni el código de otro, `kt_subdominio_problema()`), **rechaza si ya existe la carpeta `clients/{codigo}`** (antes el cliente nuevo heredaba la base vieja) o si el código es de una plantilla de `database_initial/`, crea fila + carpeta + base + subdominio + funciones, **deshace todo si algo falla** y corre `kt_verificar_cliente()` (base al día, carpeta escribible, contraseña, subdominio que abre ESE cliente, marca y bases ajenas). El botón **✓ Verificar** de cada tarjeta corre la misma revisión. Cambiar el subdominio también pasa por `kt_subdominio_problema()`. El botón **🔗 Enlaces** de cada tarjeta edita el subdominio y muestra/copia los enlaces de entrada (login, buscador público corto y largo); `codigo.kino-trace.com` siempre sigue abriendo al cliente, pero un subdominio propio anterior deja de funcionar al cambiarlo (el panel avisa).
- **Funciones por cliente:** `helpers/funciones.php` (`KT_FUNCIONES`: buscador público, subir, trazabilidad, importar, IA) + tabla `funciones_cliente` en `central.db` (migración 2; sin fila = activa). Se apagan en la tarjeta del cliente (⚙️ Funciones) o al crearlo. El bloqueo es en el **servidor**: `kt_guardia_funciones()` (al final de `config.php` y de `session_init.php`, lo que cargue segundo) responde 403 por ruta (`modules/...`) o por acción de `api.php`; el buscador público lo revisa `validate_public_client()`. El menú oculta lo apagado. El admin con clave maestra no tiene restricciones. **Función nueva = agregarla a `KT_FUNCIONES` con sus rutas/acciones.**
- Chequeo: `php tests/multicliente/chequeo_alta_funciones.php` (también en CI).

### Base de datos: rendimiento y cuidado de los datos

- **Páginas lentas:** `config.php` activa `helpers/registro_lento.php`: toda página que tarde más de `KT_LENTO_MS` (1000 ms por defecto) queda en `clients/logs/lento.log` (ruta, acción, cliente, ms; nunca lo buscado). El panel admin muestra el resumen "🐢 Páginas lentas". Mirar eso antes de optimizar a ojo.
- **Consultas que recorren todos los códigos están prohibidas en las búsquedas frecuentes:** `tools/chequeo.php` (paso 5) corre `search_by_code`, `greedy_search`, `suggest_codes`, `kt_listar_documentos` y `get_search_stats` sobre la base más grande y falla si alguna hace `SCAN codigos` sin índice. Un `LIKE` sobre `codigo` no usa índice: para prefijos usar el rango sobre `code_match_sql()` como `suggest_codes()`.
- **Listados paginados:** elegir primero la página de documentos y después juntar sus códigos (`kt_listar_documentos()`); un `LEFT JOIN codigos ... GROUP BY` sobre todos los documentos antes del `LIMIT` crece con el total de códigos.
- **Guardar documento + códigos siempre en una transacción** (`beginTransaction`/`commit`): o queda todo o nada, y es ~45 veces más rápido que un guardado por código.
- Cada deploy (`migrate_all_clients()`) revisa cada base con `PRAGMA quick_check` y corre `PRAGMA optimize`.
- Medir antes/después: `php tools/medir_bd.php clients/kino/kino.db 20` (trabaja sobre una copia; el 20 la multiplica: 2.420 documentos y 738 mil códigos).

### Autoload y carga de helpers

`autoload.php` es el punto de entrada obligatorio (`require_once __DIR__ . '/autoload.php';`): registra un autoloader PSR-4 simplificado para el namespace `Kino\` (mapea a `src/`), con fallback a `helpers/{Clase}.php` para código legacy sin namespace. Carga automáticamente `config.php` y un set fijo de helpers "core" (`db`, `config_helper`, `auth`, `tenant`, `secure_uploader`, `file_manager`, `error_codes`, `rate_limiter`, `csrf_protection`). Helpers adicionales se cargan bajo demanda con `load_helper($name)` / `load_helpers([...])` — ver ejemplo en `api.php` (`load_helpers(['search_engine', 'pdf_extractor', 'gemini_ai', 'cache_manager'])`).

### API (`api.php`)

Punto único de entrada REST-ish, dispatcher por `$_REQUEST['action']` hacia controladores en `src/Api/` (namespace `Kino\Api\`): `DocumentController` (upload/update/delete/list/get), `SearchController` (search voraz, search_by_code, suggest, stats, fulltext_search), `PdfController` (extract_codes, search_in_pdf), `AiController` (ai_extract, ai_chat, smart_chat, ai_status — integración Gemini), `SystemController` (reindex_documents, pdf_diagnostic, update_password). Todos heredan de `BaseController`. Requiere sesión autenticada (`$_SESSION['client_code']`); aplica `RateLimiter::middleware()` y `CsrfProtection::middleware()` antes de despachar. Responde siempre JSON, incluso en errores fatales (error handler y shutdown handler personalizados en el propio archivo).

Nota: `DOCUMENTACION_TECNICA.md` describe una versión anterior de los nombres de acción (`search_single`, `suggest_codes`, etc.) — el dispatcher real en `api.php` es la fuente de verdad.

### Módulos (`modules/`)

Cada subcarpeta es una feature server-rendered independiente con su propio `index.php` (y a veces `list.php`/`upload.php`/`process.php`/`view.php` como sub-rutas). No comparten estado entre sí más allá de los helpers y la BD del cliente en sesión. Los más relevantes: `busqueda/` (búsqueda voraz + consulta), `subir/` (upload con extracción automática de códigos), `resaltar/` (resaltado de PDF con PDF.js, patrones inicio/fin), `trazabilidad/` (vincular y validar manifiestos vs declaraciones), `manifiestos/` y `declaraciones/` (CRUD por tipo de documento), `importar_datos/` y `excel_import/` (import masivo CSV/SQL/Excel).

### Motor de búsqueda voraz (`helpers/search_engine.php`)

`greedy_search()`: dado un set de códigos buscados, selecciona iterativamente el documento que cubre más códigos pendientes hasta cubrir todos o agotar candidatos — devuelve el conjunto mínimo de documentos. Complementado por `search_by_code()`, `fulltext_search()`, `suggest_codes()`. `search_by_code()` y `greedy_search()` van **sin caché** a propósito (antes cacheaban 5–10 min sin invalidar al subir/borrar/re-enlazar: un PDF recién subido "no aparecía"). No volver a cachearlas sin borrar la caché en cada escritura. Comparan códigos **ignorando espacios, guiones, puntos, comas, `;`, `:`, `_` y `/`** (`code_match_key()` / `code_match_sql()` en `helpers/tenant.php`, índice por expresión `idx_codigos_match`): en Los Montañeros el mismo código está guardado como "BL-1258" y "BL1258", "DS 230166" y "DS230166", o "NS377675,". Los códigos se guardan y muestran **tal como los escribió el cliente** (a veces usa puntos/guiones/espacios para distinguir productos): la coincidencia exacta tiene prioridad (`exacta=1`, sale primero; en la voraz, si existe la escritura exacta solo cuentan esos documentos) y las variantes solo se suman en la búsqueda por código, marcadas "Escrito en el documento como…". Cualquier consulta nueva por código debe usar esas dos funciones (también `Buscador/api_public.php`). Las sugerencias mientras se escribe (`suggest_codes()`, interno y público) siguen la misma regla: "bl1" sugiere "BL-1258". Cada resultado muestra **"Por qué aparece"** (`motivoBox()` en `index.php`): búsqueda por código y voraz usan `exacta` / `match_info`; "Buscar en Contenido" (`fulltextSearch`, busca en el texto del PDF) devuelve `motivo` {codigos registrados que contienen lo buscado, nombre, veces en el texto}, así se ve cuando un documento sale solo porque el código está en su texto (p. ej. la factura que lista los productos).

### Búsquedas sin resultado y medir el OCR

- **Búsquedas sin resultado:** `search_by_code` (interno y público) y la búsqueda voraz anotan en la base del CLIENTE (`busquedas_sin_resultado`, migración 3) lo que no dio ningún documento: código tal como se escribió, origen (`interno`/`publico`/`voraz`), veces y fechas; nada de quién ni IP (`helpers/busquedas_sin_resultado.php`, cargado desde `tenant.php`). Nunca rompe una búsqueda (try/catch, espera máx. 250 ms si la base está ocupada), tope de 2.000 filas y 90 días (un robot que pruebe códigos al azar no la llena). El panel admin lo muestra en cada tarjeta ("🔎 N búsquedas sin resultado"), sin lo que ya existe. Es la fuente de búsquedas REALES para la lista de pruebas de calidad; **no** va a `lento.log`, que sigue sin guardar lo buscado. Cualquier búsqueda nueva por código debe anotar igual.
- **Medir el OCR:** `php tools/medir_ocr.php clients/kino/kino.db clients/kino` (o `--verdad=verdad.csv --carpeta=...` con una lista hecha a mano: `pdf;pagina;codigo`). Convierte hojas en imagen, las lee con tesseract con varias configuraciones (`dpi/psm[/calidad JPEG]`, la primera es la de hoy: 150 dpi, psm 3) y dice qué % de los códigos impresos salen exactos, con 1 letra mal, o se pierden, qué letras confunde y los segundos por hoja. Con PDFs que tienen texto es un **techo** (más limpio que un escaneo real); el número real exige PDFs escaneados con su lista hecha a mano. Con `--escaneados` toma los PDFs sin capa de texto y usa los códigos ya registrados como verdad (hoy la plantilla `kino` solo trae 1 escaneo y sus "códigos" son nombres de producto, así que no sirve de número real). Medido 2026-11-23 (techo, 21 hojas): hoy (150 dpi) lee exacto ~61% de los códigos impresos y pierde ~24%; a 300 dpi 87% y 2%, a cambio de ~3 s más por hoja. Cambiar el dpi del OCR de producción no está hecho: se decide con escaneos reales. Trabaja en carpetas temporales, solo línea de comandos.

### UX y celular (chequeo en navegador real)

- `tests/ux/chequeo_ux.js` (Playwright, CI: `.github/workflows/ux.yml`) abre 14 pantallas en iPhone 13 y en computador sobre la plantilla `kino` y falla si: alguna **se sale de lado** en el celular, alguna casilla tiene letra **< 16px** (iPhone hace zoom), hay **errores de JavaScript**, **Validar** pesa > 500 KB o tarda > 3 s, el **resaltado** no aparece / no cae sobre el código / la hoja no cabe en la pantalla, o el **CSV** no descarga. Local: `npm install --no-save playwright@1.63.0 && node tests/ux/chequeo_ux.js` (sin internet: `KT_CDN_LOCAL=carpeta/con/pdf.min.js`).
- Reglas: `.main-content` lleva `min-width: 0` (si no, una tabla o PDF ancho estira toda la página); tablas anchas dentro de un contenedor con `overflow-x: auto`; en ≤768px las casillas van a 16px (`styles.css`); toda página con `<meta name="viewport">`; listados largos **siempre paginados** (nunca dibujar todos los códigos).
- **Accesibilidad:** con `KT_AXE=<ruta a axe.min.js>` el chequeo corre axe-core (WCAG 2.2 A/AA) en las 14 pantallas: las fallas **críticas** (casillas sin nombre) hacen fallar el CI; los contrastes ("serious") solo se listan (quedan ~5 pantallas con contrastes sueltos). Casillas nuevas: ponerles `<label>` o `aria-label`; `assets/js/a11y-labels.js` rellena los nombres que falten en el panel y el pie. Colores base en `styles.css` ya cumplen contraste 4.5:1 (azul #2563eb, texto #475569).
- Visores de PDF: la hoja se ajusta al ancho disponible (máx. escala 1.5) y el canvas se dibuja × `devicePixelRatio` (nítido al ampliar); los resaltados se posicionan con el tamaño **CSS** del canvas, no el físico. En celular el panel lateral del visor no es `sticky` (tapaba el PDF).

### Resaltado de PDFs (visor interno y público)

`modules/resaltar/viewer.php` (interno) y `modules/Buscador/viewer_publico.php` (buscador público de todos los clientes) usan el mismo motor: buscan el código en la capa de texto del PDF con PDF.js en el navegador y solo las hojas escaneadas (<150 caracteres) van al servidor (`ocr_text.php` / `ocr_text_public.php` → `helpers/ocr_highlight.php`: caché, `pdftotext -bbox`, OCR). El visor público resalta solo al abrir y compara **sin mayúsculas, tildes ni separadores** (misma regla que `code_match_key()`), con bordes: "1251" no se resalta dentro de "11251" ni de "1.251.000". `ocr_hl_match_terms()` aplica la misma regla en el servidor (afecta a ambos visores). El visor interno usa ahora el mismo motor (antes comparaba literal). **Sin el "-N" final:** si en una hoja no aparece el término tal cual y termina en `-` + 1-2 cifras ("1980-1", "NO.2519A-5"), se busca sin ese sufijo ("1980") con los mismos bordes; se prefieren las apariciones de la base que NO van seguidas de otro "-N"; si todas van seguidas de uno ("1980-2"), se resaltan igual (la base está en el documento, por eso NO sale en "Faltan") y el aviso dice "en el PDF figura como 1980-2" (`otro`). Solo queda en "Faltan" lo que ni su base tiene en el documento. **Hermanos de una misma base** (`clasificarTerminos()` en viewer.php): "1514-1", "1514-2", "1514-3"… y "1514" solo comparten base; si cualquiera se encontró (exacto o sin el -N) y quedó marcado, los demás NO van en "Faltan" sino en la nota "sin resaltado propio, pero su base sí está marcada" (regla pedida por el usuario). **Sin la letra final (tercer intento):** si tampoco está la base con letra, "1523H-1" busca "1523" (`ktBaseDigitos()` / `baseDigitos()` / `ocr_hl_base_digitos()`: 3+ cifras + 1-3 letras, p. ej. 2195-B → 2195, 1955BG → 1955), porque las declaraciones imprimen "Ref: 1523.////" y la letra es del inventario; "1523L" o "11523" no cuentan (bordes). Medido con los PDFs de la plantilla kino: 31 de 150 códigos de una lista real solo salían así. La base necesita ≥3 letras/números ("BL-12" no se recorta). Se marca `via` (borde punteado). Los términos que en TODO el documento solo salen así se listan aparte en un aviso discreto ("no aparece así; se resaltó 1980 (hojas …)"): plegable gris en el panel del visor interno (`.nota-sin-sufijo`) y una línea en la barra de navegación del público (`#notaSinSufijo`); el resumen dice "N exactos y M sin el número final". Misma regla en `ktBaseSinSufijo()` (viewer.php), `baseSinSufijo()` (viewer_publico.php) y `ocr_hl_base_sin_sufijo()` (servidor/OCR). Chequeo: `php tests/resaltado/chequeo_sin_sufijo.php` (también en CI).

### Tope de IA por cliente

`helpers/uso_ia.php` (migración 4, tabla `uso_ia`): `AiController` llama `kt_ia_consumir()` antes de cada consulta a Gemini; tope diario por cliente (200 por defecto, variable `KT_IA_LIMITE_DIARIO`, 0 = apagada). Solo guarda día y conteo. Toda llamada nueva a Gemini debe pasar por ahí. Chequeo: `php tests/ia/chequeo_tope_ia.php` (también en CI).

### Extracción de PDFs

`helpers/pdf_extractor.php` extrae texto (Smalot\PdfParser / `pdftotext` CLI como fallback) y detecta códigos alfanuméricos con regex (mínimo 3 caracteres, admite guiones/espacios). `helpers/gemini_ai.php` ofrece extracción/chat asistido por Google Gemini cuando `GEMINI_API_KEY` está configurada (opcional, degrada sin IA si falta).

### Indexar PDFs (OCR) sin pasar el tiempo del proxy

`reindex_documents` (`SystemController::reindex`) trabaja un **presupuesto de ~25 s por petición** (`REINDEX_PRESUPUESTO_SEG`) y devuelve `processed`, `done`, `partial`, `next_offset`. Los PDFs escaneados se leen con OCR **página por página** (`helpers/indexar_pdf.php`, `kt_indexar_pdf()`): si se acaba el tiempo a mitad de un PDF, el avance queda en `datos_extraidos` (`ocr_pagina`, `ocr_texto`; sin clave `text`/`error`, así sigue "pendiente") y la siguiente petición continúa. `modules/indexar/index.php` hace reintentos (3) ante respuesta no-JSON/timeout y solo dice "completada" si el servidor responde `done`; si se corta, muestra "NO terminó" y "Continuar". Cualquier cliente JS nuevo debe usar `partial`/`done`, no suponer que un lote termina en una petición.

### Enlazar PDFs a documentos importados

Los documentos importados por SQL/Excel quedan con `ruta_archivo = 'pending'` hasta tener su PDF. **Administrador → Enlazar PDFs** (`modules/importar_datos/enlazar_pdfs.php`) abre el ZIP o la carpeta en el navegador (zip.js en `assets/vendor/zipjs/`), pide al servidor el plan solo con los nombres (`action=plan`, mismas reglas que `kt_linker_emparejar()`) y sube de a un PDF, 3 a la vez, solo los que tienen documento (`action=subir`: guarda en `uploads/sql_import/` y enlaza si sigue pendiente). Sin límite de tamaño total, se retoma eligiendo el mismo ZIP, no crea documentos ni extrae códigos. "Subida por Lote" (`modules/lote/`) en cambio **crea un documento nuevo por PDF**. El buscador público marca `tiene_pdf` (`kt_documento_tiene_pdf()`) y muestra "PDF no disponible".

### Seguridad

Passwords con `password_hash()`/PDO prepared statements en todas partes. `helpers/csrf_protection.php` y `helpers/rate_limiter.php` protegen `api.php`. `helpers/secure_uploader.php` valida uploads. **Aislamiento entre clientes:** `resolve_pdf_path()` solo devuelve archivos dentro de `clients/{codigo}/` (o la carpeta antigua `clients/uploads/client_{codigo}/`), verificado con `pdf_path_belongs_to_client()` (realpath): una ruta absoluta o con `../` en la BD ya no abre PDFs de otro cliente. En el subdominio de un cliente solo puede iniciar sesión ese cliente (`login.php`). La cookie de sesión es host-only (sin `Domain`). `clone_client()` copia la base con el nombre del cliente nuevo y le pone SU nombre como título (antes heredaba el título del origen). El panel admin muestra por cliente los documentos de su base y avisa si comparte título con otro o si su carpeta tiene la `.db` de otro cliente. **Scripts de mantenimiento solo por línea de comandos:** `migrate.php`, `create_admin_db.php`, `import_backup.php`, `init_volume.php`, `railway_init.php` y `railway_fix.php` responden 404 desde el navegador (`PHP_SAPI !== 'cli'`). **Clave admin:** solo la variable `ADMIN_SECRET` (mín. 12 caracteres, `helpers/admin_secret.php`, 5 fallos por IP → 15 min de bloqueo); sin ella el acceso admin queda apagado. El código del menú "Administrador" del sidebar se verifica en el servidor (`admin_codigo.php`), nunca en JavaScript. `.htaccess`: `Options -Indexes`, bloquea `.csv`, y `database_initial/`, `backups/`, `tests/`, `docs/`, `tools/`, `vendor/` dan 404. Los datos de clientes (`clients/`, volcados `.sql/.csv`, ZIPs de respaldo) **no van al repo**. Chequeo: `php tests/seguridad/chequeo_urgente.php`. **PDFs privados:** `.htaccess` solo sirve logos dentro de `clients/`; los visores (`resaltar/viewer.php`, `Buscador/viewer_publico.php`, `generate_unified.php`) usan `kt_pdf_url()` → `pdf.php` con enlace firmado HMAC que vence (12 h interno, 4 h público, redondeado a la hora para no perder la caché del navegador) y con rangos para PDF.js; `download.php` sirve con sesión. Nunca volver a construir URLs `clients/{codigo}/uploads/...`. **Cabeceras de seguridad** en `.htaccess` (nosniff, X-Frame-Options, HSTS, CSP en modo solo-reportar; `mod_headers` en el Dockerfile). **Sin CDN:** PDF.js 3.11.174 y SweetAlert2 viven en `assets/vendor/` (no cargar scripts de cdnjs/jsdelivr). Clave: `KT_PDF_KEY` o `clients/.clave_pdf.json` (se crea sola). **Panel admin y gestor** (`panel.php`, `sync_local.php`, `respaldos.php`, `link_zip_admin.php`, enlace del sidebar) exigen `$_SESSION['is_admin']`, que solo da la clave maestra; la contraseña de cliente de `admin` o `kino` ya no abre el panel. Tests de aislamiento por HTTP: `php tests/seguridad/chequeo_aislamiento.php` (también en CI: `.github/workflows/seguridad.yml`). `ADMIN_RESET_PASSWORD` (env var) permite resetear password de `admin`/`kino` en cada arranque — solo para pruebas/deploy nuevo, no dejarla seteada en producción normal.

### Respaldos (automáticos)

`helpers/respaldo.php` + `tools/respaldo.php` (solo CLI) + panel **🛟 Respaldos** (`Admin-gestor/respaldos.php`). El contenedor corre `php tools/respaldo.php auto` cada hora (`/start.sh` del `Dockerfile`): respalda `central.db` y cada cliente cada 20 h (base con `VACUUM INTO` comprimida + espejo de archivos con enlaces duros que nunca borra) y prueba la restauración cada 7 días. Todo en `clients/_respaldos/` (404 por web). Restaurar un cliente guarda antes el estado actual (`antes_restaurar`) y no toca a los demás. Copia fuera del volumen opcional en Cloudflare R2/S3 con `KT_S3_ENDPOINT/BUCKET/KEY/SECRET`. **Alertas:** `kt_resp_alertas()` avisa en el panel admin si no hay respaldo en 48 h, el último tuvo errores o la prueba de restauración falló/no se hace. **Salud:** `salud.php` (healthcheck de Railway en `railway.toml`) da 200 solo si `clients/` se puede escribir y `central.db` responde; no mira los respaldos. Chequeo: `php tests/salud/chequeo_salud.php` (también en CI). Guía: `docs/RESPALDOS.md`. Chequeo: `php tests/respaldo/chequeo_respaldo.php`.

### Modo local (copia de trabajo en el PC de un cliente)

Paquete Windows portable (PHP + `php -S` en 127.0.0.1, navegador como interfaz) con la base de UN cliente, licencia Ed25519 atada al equipo y sincronización con la nube por paquetes (`.ktdata` nube→PC, `.ktsync` PC→nube: reemplazo si la nube no cambió, fusión aditiva si cambió, siempre con respaldo previo). Se activa solo si existe `local/MODO_LOCAL` (lo crea `local/herramientas/kt.php paquete`; ignorado en Railway). Núcleo en `helpers/sync_local.php` (no incluye config.php). Toda la operación, estructura y seguridad: `docs/modo_local/` (empezar por `README.md` y `PROCESO.md`).

### Deploy

Railway vía Docker (`Dockerfile`: PHP 8.2 + Apache, poppler-utils + tesseract-ocr para procesamiento de PDF/OCR). Volumen obligatorio en `/var/www/html/clients`. Las sesiones PHP se guardan en `clients/_sesiones` (volumen, 404 por web, 8 h sin actividad; `helpers/session_init.php`): antes iban a `/tmp` y cada deploy cerraba la sesión de todos. Las llamadas AJAX del panel admin usan `leerJson()`, que avisa "sesión cerrada" en vez de "Unexpected token '<'". Variables relevantes: `GEMINI_API_KEY` (IA opcional), `ADMIN_RESET_PASSWORD`, `RESEND_API_KEY`/SMTP (recuperación de contraseña, ver `.env.example`), `APP_BRANCH` (indicador visual de rama activa).
