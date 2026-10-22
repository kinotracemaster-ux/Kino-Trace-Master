# CONTEXTO.md — Leer primero (punto de entrada para sesiones nuevas)

Resumen de una página para retomar el proyecto sin volver a explorar todo el repo. Verificado contra el código el 2026-10-22.
Profundidad por tema → ver la tabla "Qué leer para qué" al final. **El código siempre manda**: si algo de acá no cuadra, re-verificar y actualizar este archivo.

---

## 1. Qué es la app (en 30 segundos)

**KINO TRACE**: gestor documental multi-cliente para rastreo aduanero. Cada cliente (empresa importadora) sube PDFs (manifiestos, declaraciones, facturas), la app extrae los **códigos de producto** que aparecen en ellos, y después permite:

- **Búsqueda Voraz** (la feature estrella): pegás una lista de códigos → devuelve el **mínimo conjunto de PDFs** que los cubre todos, y permite abrirlos resaltados o generar un PDF unificado.
- Buscar un código suelto, búsqueda full-text, consulta/listado de documentos.
- Trazabilidad: vincular manifiesto ↔ declaración y validar códigos.
- **Buscador público** por cliente (sin login, por `?cliente=X` o subdominio `X.kino-trace.com`) para que terceros consulten códigos.
- Panel de administración para crear/clonar/borrar clientes, branding, página pública, subdominios.

Stack: PHP 8.2 puro (sin framework) + SQLite (un archivo por cliente) + JS embebido + PDF.js. Deploy en Railway con Docker. Todo el texto de UI y código está en español.

---

## 2. Mapa mental del repo

```
login.php ──► index.php (SPA: tabs voraz / consultar / codigo / subir(iframe) / backup)
   │              │  JS llama a ──► api.php ──► src/Api/*Controller ──► helpers/*
   │              └─ sidebar (includes/sidebar.php) enlaza a modules/* (páginas standalone)
   └─ admin_secret ─► Admin-gestor/panel.php (gestión de clientes, central.db)

modules/Buscador/  ── público, sin sesión, su propio api_public.php
config.php         ── se ejecuta en TODO request: abre/migra clients/central.db
autoload.php       ── entrada obligatoria: autoload Kino\ → src/, carga helpers core
helpers/tenant.php ── open_client_db() + auto-migración ensure_client_schema()
```

Datos en disco (volumen Railway en `/var/www/html/clients`, **gitignored**):

```
clients/central.db               → control_clientes (login, branding, email, subdominio), pagina_publica
clients/{codigo}/{codigo}.db     → documentos, codigos, vinculos, configuracion_extraccion, tipos_documento, log_actividad
clients/{codigo}/uploads/...     → PDFs
clients/{codigo}/cache/          → CacheManager (JSON)
clients/logs/                    → app.log, error.log, rate_limits.json
database_initial/                → plantilla copiada a clients/ solo si central.db no existe (primer arranque)
```

---

## 3. Flujos clave (dónde vive cada uno)

| Flujo | Recorrido real |
|---|---|
| **Login cliente** | `login.php`: código **o email** + password → `password_verify` contra `central.db` (solo `activo=1`) → `$_SESSION['client_code']`, fingerprint IP/UA → `index.php`. Sesión nombrada por host (`helpers/session_init.php`). |
| **Login admin** | Campo `admin_secret` en `login.php` o `Admin-gestor/login.php`: compara con env `ADMIN_SECRET` (**default hardcodeado `'3312'`** si no está seteada) → sesión `client_code='admin'`. `panel.php` acepta solo `admin`/`kino` y se bloquea desde subdominios de cliente. |
| **Subir documento** | Tab Subir = iframe `modules/subir/` (guarda con POST a sí mismo; extracción async vía `fsockopen` a `process_extraction.php`). Alternativa API: `api.php?action=upload` → `DocumentController::upload`. Masivo: `modules/lote/` (ZIP). |
| **Extraer códigos de PDF** | `helpers/pdf_extractor.php`: `pdftotext` → Smalot → OCR (tesseract). ⚠️ Smalot **no está en `vendor/`** ni en `composer.json`, así que en la práctica es pdftotext → OCR. Limpieza: `clean_extracted_code()`. |
| **Búsqueda Voraz** | `index.php` `#bulkInput` → `processBulkSearch()` → `api.php?action=search` → `SearchController::search` (1er token de cada línea + `normalize_code_token`) → `greedy_search()` en `helpers/search_engine.php`. Resaltar/unificar → `modules/resaltar/viewer.php` / `generate_unified.php`. |
| **Buscador público** | `modules/Buscador/index.php` + `api_public.php` (`search_by_code`, `suggest`; rate-limited; `validate_public_client`) + `viewer_publico.php` (resaltado **manual** con botón) + `ocr_text_public.php`. |
| **Crear cliente** | `Admin-gestor/panel.php` acción `create` → `create_client_structure()` (+ logo, + ZIP de PDFs opcional, + copiar datos de kino). Otras acciones: `clone`, `change_password`, `update_colors`, `import_sql`, `toggle`, `delete` (borra carpeta entera), `diagnose_orphans`, `update_public_page`, `update_subdomain`, AJAX `send_reset_email`. Guarda también `password_plain` para mostrarlo al admin. |
| **Backup** | Tab Backup → `Admin-gestor/backup.php?partial=1` → ZIP de BD + uploads del cliente en sesión. |
| **Recuperar password** | `forgot_password.php` / `reset_password.php` + `helpers/mailer.php` (Resend, fallback SMTP). |
| **Reindexar** | `modules/indexar/` o tab Consultar → loop `api.php?action=reindex_documents&batch=N`. |

---

## 4. Reglas para no romper nada

- Todo PHP nuevo empieza con `require_once __DIR__ . '/autoload.php';` (ajustar ruta).
- **Columna nueva en BD del cliente → `ensure_client_schema()`** (`helpers/tenant.php`), no solo en `create_client_structure()`; si no, los clientes existentes no la reciben.
- Acción de API nueva → `case` en el switch de `api.php` + método en `src/Api/`. Los POST requieren CSRF (`X-CSRF-TOKEN` o `csrf_token`); rate limit 100 req/60s por IP.
- Los módulos de import (`importar`, `importar_datos`, `restaurar_sistema`, `excel_import`, `lote`, `subir`) **no pasan por `api.php`**: escriben con PDO directo. Un cambio de lógica de guardado hay que replicarlo ahí.
- Comparación de códigos es exacta (`UPPER(codigo)=UPPER(?)`): cualquier entrada nueva de códigos debe pasar por `normalize_code_token()`.
- `clients/` y `*.db` están en `.gitignore`; `vendor/` **sí se commitea** (Railway no corre `composer install`).
- Commits/PRs: no mencionar modelo; trabajar en la rama asignada por la sesión.

---

## 5. Comandos

```bash
php -S localhost:8080            # servidor local
php migrate.php                  # crea cliente kino/kino123 si central.db está vacía
composer require --dev phpunit/phpunit --no-interaction   # PHPUnit NO viene instalado
./vendor/bin/phpunit             # tests: tests/{Csrf,RateLimiter,SecureUploader}Test.php, tests/Api/*
```

Deploy: push → Railway (Dockerfile, healthcheck `/login.php`). Volumen obligatorio en `/var/www/html/clients`. Env vars: `GEMINI_API_KEY`, `ADMIN_SECRET`, `ADMIN_RESET_PASSWORD`, `RESEND_API_KEY`/`SMTP_*`, `APP_BASE_DOMAIN`, `APP_BRANCH`.

---

## 6. Bugs / deuda conocida (lista corta — detalle en `MAPA_TECNICO.md` §0)

- **Backup sin base de datos**: `Admin-gestor/backup.php` busca `clients/{codigo}/data.db`, pero la BD real es `clients/{codigo}/{codigo}.db` (`client_db_path()`) → el ZIP solo trae PDFs. *(detectado 2026-10-22, sin corregir)*
- **`ADMIN_SECRET` con default `'3312'`** en `login.php` y `Admin-gestor/login.php` si la env var no está seteada. *(detectado 2026-10-22)*
- **Smalot PdfParser no instalado** → el método 2 de extracción nunca corre. *(detectado 2026-10-22)*
- Botón CSV de Consultar llama a `action=export_csv`, que no existe.
- `helpers/ai_engine.php` y `helpers/validator.php` son stubs (usados por `modules/declaraciones|manifiestos`). La IA real es `helpers/gemini_ai.php`.
- Módulos de import duplicados; `modules/sincronizar/` depende de un `.sql` legado en la raíz.
- `pwa/` es un placeholder (escaneo manual, sin lógica real).
- `password_plain` guardado en `central.db` (decisión explícita para el panel admin, pero es un riesgo).
- Docs viejos: `DOCUMENTACION_TECNICA.md` y `APP_MANUAL.md` describen nombres/rutas desactualizados (ej. `modules/busqueda/` ahora redirige a `index.php?tab=voraz`). `APP_MANUAL.md` igual se usa como contexto del chat IA (`ai_smart_chat`).

---

## 7. Qué leer para qué

| Necesito… | Archivo |
|---|---|
| Arquitectura y comandos (lo carga Claude Code solo) | `CLAUDE.md` |
| Acciones de `api.php`, esquema SQLite completo, inventario de helpers/módulos, "dónde mirar" | `MAPA_TECNICO.md` |
| Qué se cambió recientemente y qué quedó pendiente | `CHANGELOG.md` |
| Bugs ya resueltos y su causa raíz | `TROUBLESHOOTING.md` |
| Funcionalidad vista por el usuario final | `APP_MANUAL.md` (parcialmente desactualizado) |
| Recuperación ante desastre / Railway | `EMERGENCY_PLAN.md`, `docs/RECUPERACION_EMERGENCIA.md`, `RAILWAY_DB_INIT.md` |
| Seguridad (CSRF, rate limit, uploads) | `IMPLEMENTACION_SEGURIDAD.md` |
| Históricos, no confiar como estado actual | `DOCUMENTACION_TECNICA.md`, `PLAN_MEJORA_MULTICLIENTE.md`, `PUNTO_RESTAURACION_*.md`, `RESTORATION_POINTS.md` |

**Al terminar una sesión con cambios**: agregar entrada arriba en `CHANGELOG.md` y, si cambió un flujo o se arregló un bug de la §6, actualizar este archivo.
