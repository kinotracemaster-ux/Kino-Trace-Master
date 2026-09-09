# MAPA_TECNICO.md — Referencia exhaustiva de Kino Trace Master

Mapa técnico completo del código real (no de la documentación aspiracional). Objetivo: que una sesión futura de Claude Code pueda resolver una tarea leyendo este archivo en vez de re-grepear todo el repo. Generado el 2026-09-09 leyendo el código fuente completo (`api.php`, `src/Api/*`, `helpers/*`, `modules/*`, `index.php`, `config.php`, `autoload.php`, esquemas SQLite reales).

Si algo de acá contradice `CLAUDE.md` o `DOCUMENTACION_TECNICA.md`, **este archivo es más nuevo y fue verificado directamente contra el código** — pero el código siempre es la fuente final de verdad; si vuelve a haber deriva, re-verificar antes de confiar ciegamente.

---

## 0. Inconsistencias / deuda técnica ya detectadas (para no perder tiempo re-descubriéndolas)

- **`optimize_db.php` NO EXISTE** en el repo, aunque `CLAUDE.md` lo documenta como comando (`php optimize_db.php`). Toda la optimización de índices real vive dentro de `ensure_client_schema()` en `helpers/tenant.php`, aplicada en caliente en cada `open_client_db()`.
- **Botón "CSV" roto**: `index.php` (tab Consultar, función `downloadCSV()`) llama a `api.php?action=export_csv&tipo=...`, pero esa acción **no existe** en el switch de `api.php` → siempre devuelve `{"error":"Acción no válida"}`.
- **Dos "core helpers" fantasma**: `autoload.php` intenta cargar `helpers/db.php` y `helpers/config_helper.php` como parte de la lista fija de helpers "core", pero **ninguno de los dos archivos existe**. El loop usa `file_exists()` antes de `require_once`, así que se saltan en silencio — no rompe nada, es solo ruido en la lista.
- **`helpers/ai_engine.php` es un stub no funcional** (`ai_extract_data_from_pdf`, `ai_chat_with_docs`, `ai_generate_report` devuelven `[]` o mensajes "no implementada"). Lo siguen llamando `modules/declaraciones/upload.php` y `modules/manifiestos/upload.php` — en esos dos módulos específicos la "extracción con IA" no hace nada. La integración real con IA es `helpers/gemini_ai.php`, usada desde `src/Api/AiController.php` (acciones `ai_extract`/`ai_chat`/`smart_chat`).
- **`helpers/validator.php::validate_document()` es un stub** que siempre devuelve `{'status':'ok','messages':[]}` sin validar nada. Lo usan `modules/declaraciones/view.php` y `modules/manifiestos/view.php`.
- **`database_structure.sql`** (raíz) es documentación de referencia MySQL/Railway, **desactualizada** y **no ejecutada en runtime** (la app siempre usa SQLite vía PDO). Le faltan `configuracion_extraccion`, `tipos_documento`, `log_actividad`, y varias columnas nuevas.
- **Doble fuente de esquema por cliente** (ver §3): `create_client_structure()` (solo al crear cliente) vs `ensure_client_schema()` (en cada apertura de conexión, auto-migración lazy). Las DBs plantilla en `database_initial/{0001,admin,cliente}/*.db` todavía tienen el esquema viejo; se actualizan solas la primera vez que alguien las abre.
- **Módulos duplicados / funcionalidad solapada**: `importar`, `importar_datos` y `restaurar_sistema` implementan variantes muy similares de "importar SQL+ZIP". `excel_import` y `importar_datos` (modo CSV) también se superponen. Ninguno de estos módulos pasa por `api.php` ni por `src/Api/DocumentController` — reimplementan su propia inserción PDO directa.
- **`modules/sincronizar/`** depende de un archivo legado específico en la raíz (`if0_39064130_buscador (10).sql`) — no es un flujo generalizable.
- Hay **dos definiciones de `toggleTableCodes()`** en `index.php` (líneas ~973 y ~1323); la segunda pisa a la primera.
- `voraz_openMultiViewer()` en `index.php` parece código huérfano (no se ve invocado desde el HTML actual).
- `helpers/file_manager.php` no tiene funciones propias: es solo un `require_once tenant.php` por compatibilidad histórica.
- `helpers/pdf_extractor.php::extract_with_native_php()` no se usa en la cadena principal de `extract_text_from_pdf()` (pdftotext → Smalot → OCR) — es código muerto en la práctica salvo llamada manual.

---

## 1. Flujo de un request en `api.php` (orden real de ejecución)

1. `require helpers/session_init.php` — nombra la sesión por hostname (`KT_<host>`), `session_start()`, garantiza `csrf_token`, y hace `session_write_close()` para no bloquear requests concurrentes.
2. `set_error_handler('apiErrorHandler')` — errores PHP no fatales → JSON `{"error":"PHP Error",...}` + `exit`.
3. `register_shutdown_function('apiShutdownHandler')` — si el último error fue fatal (`E_ERROR|E_PARSE|E_CORE_ERROR`), limpia buffers y responde JSON `{"error":"Critical System Error",...}`.
4. `require autoload.php` → autoload PSR-4 (`Kino\` → `src/`, fallback a `helpers/{Clase}.php`) + carga automática de helpers "core": `db, config_helper, auth, tenant, secure_uploader, file_manager, error_codes, rate_limiter, csrf_protection` (los 2 primeros no existen, se ignoran silenciosamente). Define `load_helper()`/`load_helpers()`.
5. `load_helpers(['search_engine', 'pdf_extractor', 'gemini_ai', 'cache_manager'])` — **se cargan siempre, para toda acción**, no solo para búsqueda. Por eso `DocumentController::upload/update` puede usar funciones globales como `normalize_code_token()` o `extract_codes_from_pdf()` sin requerirlas de nuevo.
6. `RateLimiter::middleware()` — 100 req/60s por IP (archivo compartido `clients/logs/rate_limits.json`, no por tenant). Excede → 429 + `Retry-After`.
7. `CsrfProtection::middleware()` — solo en `POST/PUT/DELETE/PATCH`. Token en `$_POST['csrf_token']` / `$_GET['csrf_token']` / header `X-CSRF-TOKEN`, comparado con `hash_equals()` contra `$_SESSION['csrf_token']`. Inválido → 403.
8. Verifica `$_SESSION['client_code']` → si falta, 401 (`AUTH_002`).
9. `$db = open_client_db($clientCode)` (try/catch: `PDOException` → `DB_001`, `Exception` → `SYS_001`, ambos logueados con `Logger::exception()`).
10. `switch($_REQUEST['action'])` dentro de un try/catch general (`Throwable` → `SYS_001` + log).

---

## 2. Tabla completa de acciones de `api.php`

| Acción | Controlador::método | Params esperados | Notas |
|---|---|---|---|
| `extract_codes` | `PdfController::extractCodes($_FILES, $_POST)` | `file`, `prefix, terminator, min_length, max_length, dpi` | |
| `search_in_pdf` | `PdfController::searchInPdf($_FILES, $_POST)` | `file`, `codes` (multilínea) | |
| `upload` | `DocumentController::upload($_POST, $_FILES)` | `tipo, numero, fecha, proveedor, codes, file` | ver §4 |
| `update` | `DocumentController::update($_POST, $_FILES)` | `id, tipo, numero, fecha, proveedor, codes`, `file` opcional | ver §4 |
| `delete` | `DocumentController::delete($_REQUEST)` | `id` | |
| `list` | `DocumentController::list($_GET)` | `page, per_page (def 50), tipo` | |
| `get` | `DocumentController::get($_GET)` | `id` | |
| `search` | `SearchController::search($_REQUEST)` | `codes` (multilínea, 1ª columna de cada línea) | Búsqueda voraz — ver §5 |
| `search_by_code` | `SearchController::searchByCode($_REQUEST)` | `code` | |
| `suggest` | `SearchController::suggest($_GET)` | `term` | autocompletado |
| `stats` | `SearchController::stats()` | — | |
| `fulltext_search` | `SearchController::fulltextSearch($_REQUEST)` | `query` (mín 3), `limit` (1-200, def 100) | |
| `ai_extract` | `AiController::extract($_POST)` | `document_id` o `text`, `document_type` | requiere Gemini configurado |
| `ai_chat` | `AiController::chat($_POST)` | `question` | contexto: últimos 10 docs |
| `smart_chat` | `AiController::smartChat($_POST)` | `question` | |
| `ai_status` | `AiController::status()` | — | `{configured, model}` |
| `reindex_documents` | `SystemController::reindex($_REQUEST)` | `force, batch (1-200, def 120), offset` | |
| `pdf_diagnostic` | `SystemController::diagnostic($_REQUEST)` | `doc_id` opcional | |
| `update_password` | `SystemController::updatePassword($_POST)` | `new_password, confirm_password` | abre `clients/central.db` directo, bypassa `$db` de cliente |
| `clear_cache` | inline en `api.php` (no controller) | — | `CacheManager::clear($clientCode)` |
| *(cualquier otra / vacía)* | — | — | `{"error":"Acción no válida"}` — incluye `export_csv`, que no existe |

---

## 3. Base de datos

### 3.1 `clients/central.db`

Creada/migrada en `config.php` (no en `tenant.php`).

```sql
CREATE TABLE control_clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo TEXT UNIQUE,
    nombre TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    titulo TEXT,
    color_primario TEXT,
    color_secundario TEXT,
    activo INTEGER DEFAULT 1,
    fecha_creacion TEXT DEFAULT (datetime('now'))
    -- agregadas después vía ALTER TABLE (try/catch idempotente):
    , email TEXT                 -- recuperación de password
    , reset_token TEXT
    , reset_token_expiry TEXT
    , subdominio TEXT            -- resuelto por helpers/subdomain.php
    , password_plain TEXT        -- para mostrar en panel admin
);

CREATE TABLE IF NOT EXISTS pagina_publica (
    codigo TEXT PRIMARY KEY,
    intro_titulo TEXT, intro_texto TEXT, instrucciones TEXT,
    footer_texto TEXT, footer_ubicacion TEXT, footer_telefono TEXT,
    footer_url TEXT, aviso_legal TEXT
);
```

`config.php` también aplica, en cada arranque, el reseteo de password de `admin`/`kino` si la env var `ADMIN_RESET_PASSWORD` está seteada.

### 3.2 `clients/{code}/{code}.db` (por cliente)

**A) `create_client_structure()`** (`helpers/tenant.php`) — solo al crear un cliente nuevo:

```sql
CREATE TABLE documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL, numero TEXT NOT NULL, fecha DATE NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    proveedor TEXT, naviera TEXT, peso_kg REAL, valor_usd REAL,
    ruta_archivo TEXT NOT NULL, hash_archivo TEXT, datos_extraidos TEXT,
    ai_confianza REAL, requiere_revision INTEGER DEFAULT 0,
    estado TEXT DEFAULT 'pendiente', notas TEXT
);

CREATE TABLE codigos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_id INTEGER NOT NULL, codigo TEXT NOT NULL,
    descripcion TEXT, cantidad INTEGER, valor_unitario REAL,
    validado INTEGER DEFAULT 0, alerta TEXT,
    FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE
);

CREATE TABLE vinculos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    documento_origen_id INTEGER NOT NULL, documento_destino_id INTEGER NOT NULL,
    tipo_vinculo TEXT NOT NULL, codigos_coinciden INTEGER DEFAULT 0,
    codigos_faltan INTEGER DEFAULT 0, codigos_extra INTEGER DEFAULT 0,
    discrepancias TEXT,
    FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE,
    FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE
);

CREATE TABLE configuracion_extraccion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    prefix TEXT DEFAULT '', terminator TEXT DEFAULT '/',
    min_length INTEGER DEFAULT 4, max_length INTEGER DEFAULT 50,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**B) `ensure_client_schema(PDO $db)`** (`helpers/tenant.php`) — corre en **cada** `open_client_db()`, es la auto-migración real y fuente de verdad vigente:

- Crea `configuracion_extraccion` (si no existe) + seed.
- Crea `tipos_documento(id, codigo UNIQUE, nombre, activo)` + seed (`documento, manifiesto, declaracion, factura`).
- Crea `log_actividad(id, accion, detalle, ip, fecha)`.
- Si existe `documentos`, agrega vía `ALTER TABLE ... ADD COLUMN` (chequeando `PRAGMA table_info` primero): `texto_extraido TEXT`, `estado_extraccion TEXT DEFAULT 'pendiente'`, `original_path TEXT`. Índices: `idx_documentos_numero/tipo/fecha/estado`.
- Índices en `codigos`: `idx_codigos_documento_id`, `idx_codigos_codigo`.
- Índices en `vinculos`: `idx_vinculos_origen`, `idx_vinculos_destino`.

Confirmado leyendo `clients/kino/kino.db` directamente: el esquema vigente de `documentos` incluye todas las columnas originales + `original_path` (insertada entre `ruta_archivo` y `hash_archivo`) + `texto_extraido`/`estado_extraccion` al final. Las DBs plantilla en `database_initial/{0001,admin,cliente}/` **todavía no** tienen `tipos_documento`/`log_actividad` ni las columnas nuevas — se aplican solas la primera vez que se abren.

No se detectaron columnas usadas en queries (`helpers/search_engine.php`, `src/Api/*`) que falten en el esquema real.

### 3.3 Scripts relacionados con la BD

- **`migrate.php`** (raíz): si `control_clientes` está vacía, crea el cliente `kino` (password `kino123`) vía `create_client_structure()`. Es el único paso manual requerido tras clonar/desplegar.
- **`optimize_db.php`**: **no existe** (ver §0).
- **`database_initial/`**: plantilla copiada a `clients/` solo si `clients/central.db` no existe (primer arranque). Contiene `central.db`, `logs.db`, y carpetas `0001/`, `admin/`, `cliente/`, `kino/`.
- **`create_admin_db.php`, `init_volume.php`, `railway_init.php`, `railway_fix.php`** (raíz): scripts operacionales manuales para Railway (creación de cliente admin, copia de volumen, diagnóstico de env vars `RAILWAY_VOLUME_MOUNT_PATH`/`RAILWAY_ENVIRONMENT`/`PORT`). No forman parte del flujo normal de request.

---

## 4. Controladores `src/Api/*` (namespace `Kino\Api`)

Todos extienden `BaseController` (`__construct($db,$clientCode)`, `jsonExit($data)`, `sendError($code,$message,$details)`, `validateRequired($data,$fields)`). `src/` no tiene nada fuera de `src/Api/` (6 archivos en total).

- **`DocumentController`**:
  - `upload($post,$files)`: valida `tipo,numero,fecha` → `sanitize_code($tipo)` → normaliza `codes` (split `\n`, `normalize_code_token()`) → `SecureFileUploader::secureMove()` → `SecureFileUploader::checkDuplicate()` (si existe, responde `warning` sin insertar) → si PDF, `extract_codes_from_pdf()` (guarda 50000 chars + `auto_codes`) → INSERT `documentos` + INSERT `codigos`. Responde `{success,message,document_id,codes_count}`.
  - `update($post,$files)`: mismo patrón; si viene archivo nuevo, `unlink` del viejo + re-extracción; borra e reinserta `codigos`.
  - `delete($post)`: por `id`, borra archivo físico + fila.
  - `list($get)`: paginado, `GROUP_CONCAT` de códigos.
  - `get($get)`: detalle + códigos.
- **`SearchController`**: ver §5 y §6 más abajo, y `TROUBLESHOOTING.md` #8 para el bug de normalización ya corregido.
- **`PdfController`**: `extractCodes()`, `searchInPdf()` — ambos operan sobre un PDF subido en la misma request (no sobre documentos ya guardados).
- **`AiController`**: `extract()`, `chat()`, `smartChat()`, `status()` — todos delegan a `helpers/gemini_ai.php`. Requieren `GEMINI_API_KEY`.
- **`SystemController`**:
  - `reindex($request)`: re-extrae texto/códigos de PDFs pendientes (o todos si `force`) en lotes; usado por `modules/indexar/` y `modules/trazabilidad/dashboard.php` (`autoIndex()`).
  - `diagnostic($request)`: chequea disponibilidad de `pdftotext`/Smalot, prueba extracción sobre un doc de muestra.
  - `updatePassword($request)`: cambia password del cliente actual (abre `central.db` directo).

---

## 5. Motor de búsqueda voraz (`helpers/search_engine.php`)

- `normalize_code_token(string $code): string` — limpia NBSP/zero-width space y puntuación residual final (`, . ; : |`). Se aplica tanto a códigos buscados como a códigos tipeados a mano al guardar un documento (ver `TROUBLESHOOTING.md` #8).
- `search_by_code($db, $searchTerm)` — match exacto `UPPER(codigo)=UPPER(?)`, cache 5 min (`CacheManager`).
- `greedy_search($db, array $codes)` — algoritmo voraz real: en cada iteración selecciona el documento que cubre más códigos restantes (empate → doc más reciente por fecha), hasta cubrir todos o agotar candidatos. Devuelve `{documents, covered, not_found, total_searched, total_covered}`. Cache 10 min.
- `fulltext_search($db, $query)` — `LIKE` case-insensitive sobre `datos_extraidos`/`numero`, límite 200.
- `search_in_pdf_content($db, $searchTerm, $clientCode)` — extrae texto en vivo de cada PDF y busca coincidencias con snippet (no usado por `api.php`, es de uso interno/legacy).
- `suggest_codes($db, $term, $limit=10)` — autocompletado `LIKE 'term%'`.
- `get_search_stats($db)` — stats para dashboard.

Entrada real de usuario: `SearchController::search()` toma el textarea `#bulkInput` de `index.php` (tab Voraz), separa por línea, toma solo el **primer token** de cada línea (para pegar tablas tipo Excel con descripción en columnas siguientes), normaliza con `normalize_code_token()`, y llama `greedy_search()`.

---

## 6. Helpers (`helpers/*.php`) — inventario de funciones

**Core (cargados siempre por `autoload.php`)**: lista declarada `db, config_helper, auth, tenant, secure_uploader, file_manager, error_codes, rate_limiter, csrf_protection` — en la práctica `db` y `config_helper` no existen y se saltan.

| Archivo | Core/on-demand | Funciones / responsabilidad |
|---|---|---|
| `tenant.php` | core | `sanitize_code`, `client_db_path`, `open_client_db` (auto-repara copiando desde `database_initial/`, WAL + FK ON, llama `ensure_client_schema`), `create_client_structure`, `copy_dir_files_only`, `clone_client`, `get_available_folders`, `resolve_pdf_path` (resolución robusta de ruta física de PDF), `get_client_config`, `ensure_client_schema`, `validate_public_client` |
| `auth.php` | core | incluye `session_init.php`; `is_logged_in()` (con fingerprint IP/UA anti-hijacking), `get_current_client()`, `require_login_or_redirect()` |
| `secure_uploader.php` | core | clase `SecureFileUploader`: `validate` (≤10MB, ext pdf, MIME real, magic bytes), `sanitizeFilename`, `secureMove`, `checkDuplicate` (por hash sha256) |
| `file_manager.php` | core | sin funciones propias, solo `require_once tenant.php` |
| `error_codes.php` | core | `get_error_map()` (catálogo `AUTH_*/DB_*/FILE_*/PDF_*/VALIDATION_*/API_*/SEARCH_*/DOC_*/SYS_*`), `api_error()`, `validate_required_fields()`, `validate_file_type()`, `validate_file_size()`, `send_error_response()` |
| `rate_limiter.php` | core | clase `RateLimiter`: `check`, `middleware` (100/60s por IP, `clients/logs/rate_limits.json`), `reset`, `getStats` |
| `csrf_protection.php` | core | clase `CsrfProtection`: `generateToken`, `getToken`, `validate`, `middleware`, `tokenField`, `metaTag` |
| `session_init.php` | incluido por `auth.php`/`csrf_protection.php`, no listado como core pero siempre activo | nombra sesión por host, cookie aislada, `session_start`, `csrf_token`, `session_write_close`; expone `session_reopen()` |
| `search_engine.php` | on-demand (cargado siempre por `api.php`) | ver §5 |
| `pdf_extractor.php` | on-demand (cargado siempre por `api.php`) | `extract_text_from_pdf` (orquesta pdftotext → Smalot → OCR), `extract_with_pdftotext`, `extract_with_smalot`, `extract_with_native_php` (muerto), `extract_with_ocr`, `extract_with_ocr_coordinates`, `parse_hocr_words`, `find_tesseract/pdftoppm/pdftotext`, `extract_codes_with_pattern`, `clean_extracted_code` (trim + quita puntuación final + corrige G↔6 de OCR), `validate_code`, `extract_codes_from_pdf`, `search_codes_in_pdf`, `prepare_for_ai_extraction` |
| `gemini_ai.php` | on-demand (cargado siempre por `api.php`) | `is_gemini_configured`, `call_gemini`, `ai_extract_document_data`, `ai_chat_with_context`, `ai_analyze_discrepancies`, `ai_suggest_document_type`, `ai_smart_chat` (con acceso a stats/búsqueda/`APP_MANUAL.md`), `formatTiposDocumentos` |
| `cache_manager.php` | on-demand (cargado siempre por `api.php`) | clase `CacheManager`: `set/get/delete/clear` (archivo `.cache` JSON en `clients/{code}/cache/` + caché en memoria intra-request), `gc` (2% probabilístico), `stats` |
| `ai_engine.php` | on-demand | **stub no funcional** (ver §0) |
| `import_engine.php` | on-demand | `parse_csv`, `parse_sql_inserts` (parser manual balanceado, respeta comas dentro de valores — ver `TROUBLESHOOTING.md` #7), `suggest_column_mapping`, `import_to_database`, `validate_import_data` |
| `logger.php` | cargado por `error_codes.php` | clase `Logger`: `debug/info/warning/error/critical/exception`, `enableConsole`; escribe a `clients/logs/app.log`, `error.log`, y `clients/logs/{cliente}/{cliente}.log`; rota >10MB |
| `mailer.php` | on-demand | `mail_env`, `is_mail_configured`, `build_reset_email_html`, `send_via_resend`, `send_via_smtp`, `send_reset_email` (Resend primero, SMTP fallback) |
| `pdf_linker.php` | on-demand | `stripTimestamp`, `processZipAndLink` (vincula PDFs de un ZIP a documentos existentes por `original_path`/`numero`) |
| `subdomain.php` | on-demand | `getSubdomain`, `resolveClientCode`, `getClientFromSubdomain` (multi-tenant por subdominio, columna `control_clientes.subdominio`) |
| `validator.php` | on-demand | **stub no funcional** (ver §0) |

---

## 7. `config.php` / `autoload.php` / variables de entorno

- `config.php`: define `BASE_DIR`, `APP_VERSION` (md5 del propio archivo, cache-buster), fuerza `$_SERVER['HTTPS']='on'` tras proxy, `APP_BRANCH`, `CLIENTS_DIR`, `CENTRAL_DB`; auto-copia `database_initial/` → `clients/` en el primer arranque; conecta/migra `central.db`; aplica `ADMIN_RESET_PASSWORD` si está seteada; `die()` si falla la conexión central.
- `autoload.php`: `spl_autoload_register` para `Kino\` → `src/` (PSR-4 simplificado) con fallback a `helpers/{Clase}.php` para clases legacy sin namespace; carga helpers core; define `load_helper()`/`load_helpers()` con caché estática para no recargar dos veces.

| Variable | Dónde se usa | Propósito |
|---|---|---|
| `APP_BRANCH` | `config.php` | indicador visual de rama activa |
| `ADMIN_RESET_PASSWORD` | `config.php` | reset forzado de password `admin`/`kino` al bootear |
| `GEMINI_API_KEY` | `helpers/gemini_ai.php` | API key de Google Gemini |
| `APP_ENV`, `DEBUG` | `helpers/error_codes.php` | incluir `context` en errores solo si `development`/`true` |
| `APP_BASE_DOMAIN` | `helpers/subdomain.php` | dominio base para subdominios (default `kino-trace.com`) |
| `RESEND_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` | `helpers/mailer.php` | envío de correo vía Resend |
| `SMTP_HOST/USER/PASS/PORT/FROM` | `helpers/mailer.php` | fallback SMTP (PHPMailer) |
| `ADMIN_SECRET` | `Admin-gestor/login.php`, `login.php` | contraseña maestra del panel admin |
| `RAILWAY_VOLUME_MOUNT_PATH`, `RAILWAY_ENVIRONMENT`, `PORT` | `railway_fix.php` | diagnóstico Railway |

---

## 8. Módulos (`modules/*`)

| Módulo | Standalone / usa `api.php` | Qué hace |
|---|---|---|
| `Buscador/` | Público, sin sesión, endpoint propio `api_public.php` | Buscador público por cliente vía `?cliente=CODIGO` o subdominio (`validate_public_client`, `getClientFromSubdomain`). Incluye visor propio (`viewer_publico.php`) y OCR propio (`ocr_text_public.php`). |
| `busqueda/` | `index.php` es solo `header('Location: ../../index.php?tab=voraz')` | La Búsqueda Voraz real vive en `index.php` (raíz), tab `#section-voraz`. `merge.php` (standalone) unifica PDFs con resaltado multi-término. |
| `declaraciones/`, `manifiestos/` | Standalone, PDO directo | CRUD por tipo fijo (`tipo='declaracion'`/`'manifiesto'`); `upload.php` llama al stub `ai_extract_data_from_pdf()` (no hace nada útil); `view.php` llama al stub `validate_document()`. |
| `excel_import/` | Standalone (`process.php` propio) | Importación CSV/XLSX con heurística de columnas (`archivo/nombre/documento`↔`codigo/code`). |
| `importar/`, `importar_datos/`, `restaurar_sistema/` | Standalone | Tres variantes solapadas de "importar SQL + ZIP de PDFs" (ver §0). `importar_datos/link_zip_admin.php` es variante multi-cliente solo para `admin`/`kino`. |
| `indexar/` | **Sí usa `api.php`** | Loop de `action=reindex_documents&batch=N` hasta agotar pendientes. |
| `lote/` | Standalone | Subida masiva vía ZIP con extracción opcional, usa `pdf_extractor.php` directo. |
| `mantenimiento/organizar.php` | Standalone | Reubica PDFs a `uploads/<tipo>/` según BD, usa `resolve_pdf_path()`. |
| `recientes/` | Endpoint propio `api_recientes.php` | Lista paginada de documentos recientes con conteo de códigos. |
| `resaltar/` | `index.php` usa `api.php?action=fulltext_search`; el resto (`viewer.php`, `ocr_text.php`, `download.php`, `generate_unified.php`) son endpoints propios | Visor PDF.js con resaltado, OCR con coordenadas, generación de PDF unificado para resultados de la búsqueda voraz. Es el módulo que abre `index.php` (raíz) al hacer clic en "Resaltar"/"PDF Unificado" desde el tab Voraz. |
| `sincronizar/` | Standalone | Depende de un archivo SQL legado específico en la raíz del proyecto. |
| `subir/` | Mixto: guardado (`action=save/update`) es POST a sí mismo; pero `extractCodes()`/`aiExtract()` en su JS sí llaman a `api.php` | Módulo principal de subida/edición, embebido como `<iframe>` en `index.php` tab Subir. Dispara extracción async fire-and-forget vía `fsockopen()` a `process_extraction.php`. |
| `trazabilidad/` | `dashboard.php` usa `api.php?action=reindex_documents`; `validar.php`/`vincular.php` son standalone | Dashboard, validación de códigos (`codigos.validado=1`), vinculación manifiesto↔declaración (tabla `vinculos`). |

Todos los módulos (excepto `Buscador/*`) requieren `$_SESSION['client_code']`.

---

## 9. `index.php` (raíz) — SPA simple sin framework

Secciones (`id="section-*"`, activadas por `switchSection()` en `includes/sidebar.php`):

| Sección | Contenido | Función JS clave → acción `api.php` |
|---|---|---|
| `section-voraz` (default) | Textarea `#bulkInput` | `processBulkSearch()` → `POST action=search`; resultados abren `voraz_highlightAllCodes()` (→ `modules/resaltar/viewer.php`) o `voraz_generateUnifiedPDF()` (→ `modules/resaltar/generate_unified.php`), ninguno de los dos pasa por `api.php` |
| `section-subir` | `<iframe src="modules/subir/">` | delega todo al módulo `subir/` |
| `section-consultar` | Tabla + filtros + full-text | `loadDocuments()` → `action=list`; `searchFulltext()` → `action=fulltext_search`; `reindexDocuments()` → loop `action=reindex_documents`; `deleteDoc()` → `action=delete`; `downloadCSV()` → `action=export_csv` **(roto, ver §0)** |
| `section-codigo` | Input + autocompletado | `selectCode()`/input → `action=suggest`; `searchSingleCode()` → `action=search_by_code` |
| `section-backup` | Placeholder cargado por fetch | `fetch('Admin-gestor/backup.php?partial=1')` |

Submenú "Admin" del sidebar enlaza directo (no AJAX) a `excel_import/`, `importar/`, `lote/`, `sincronizar/`, `trazabilidad/vincular.php`, `indexar/`, `trazabilidad/validar.php`, `Admin-gestor/panel.php`.

Constantes JS globales: `const apiUrl = 'api.php'`, `const clientCode = '<?= $code ?>'`.

---

## 10. Dónde mirar para tareas comunes

- **"La búsqueda voraz no encuentra un código"** → `helpers/search_engine.php` (`greedy_search`, `normalize_code_token`) + `TROUBLESHOOTING.md` #8. Revisar también cómo se guardó el código (`DocumentController::upload/update` vs auto-extracción PDF `clean_extracted_code`).
- **"Un PDF no extrae texto/códigos"** → `helpers/pdf_extractor.php` (`extract_text_from_pdf`, cadena pdftotext→Smalot→OCR) + `SystemController::diagnostic` (`pdf_diagnostic`) para diagnosticar qué método falla.
- **"Falta un cliente / la BD de un cliente está corrupta"** → `helpers/tenant.php` (`open_client_db`, auto-repara desde `database_initial/`), `migrate.php`.
- **"Cambiar/agregar una acción de API"** → `api.php` (switch) + controlador en `src/Api/` + helper si aplica. No tocar los módulos standalone (`importar_datos`, `subir`, etc.) esperando que pasen por ahí — no lo hacen.
- **"Nuevo campo en `documentos`/`codigos`"** → agregar el `ALTER TABLE` en `ensure_client_schema()` (`helpers/tenant.php`), NO solo en `create_client_structure()` (si no, los clientes ya existentes no lo reciben).
- **"Login público / subdominios"** → `helpers/subdomain.php` + `helpers/tenant.php::validate_public_client()` + `modules/Buscador/`.
