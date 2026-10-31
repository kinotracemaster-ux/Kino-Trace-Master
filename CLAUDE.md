# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

KINO TRACE — sistema PHP multi-cliente (multi-tenant) de gestión documental para rastreo aduanero: sube manifiestos/declaraciones en PDF, extrae códigos automáticamente, permite búsqueda voraz (mínimo conjunto de documentos que cubre una lista de códigos) y valida cruces entre manifiestos y declaraciones. Sin frontend framework: PHP renderiza HTML server-side con JS embebido; PDF.js para el visor/resaltador de PDFs.

## Workflow (leer primero)

- Usuario escribe en español: responder en español, explicaciones simples (está aprendiendo git).
- **Una rama por tarea, PR a `main`, nunca commit directo a `main`.** Guía completa: `docs/FLUJO_RAMAS.md`.
- Este archivo es la memoria del proyecto: leerlo antes de explorar el código y actualizarlo en el mismo PR si algo aquí deja de ser cierto. Para detalle, `MAPA_TECNICO.md` en vez de recorrer el repo.
- Prioridades actuales: 1) velocidad del resaltado (`modules/resaltar/`, PR #12), 2) responsive, 3) seguridad.

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

⚠️ `optimize_db.php` **no existe** en el repo pese a haber sido documentado antes como comando. La optimización de índices real es automática y vive en `ensure_client_schema()` (`helpers/tenant.php`), que corre en cada `open_client_db()` — no requiere ejecutar nada manualmente.

Para un mapa exhaustivo y verificado del código real (todas las acciones de `api.php`, esquema SQLite completo, inventario de helpers/módulos, inconsistencias conocidas), ver `MAPA_TECNICO.md`.

## Architecture

### Multi-tenancy vía SQLite por cliente

No hay servidor de BD externo. `config.php` crea/abre `clients/central.db` (tabla `control_clientes`: credenciales y branding de cada cliente) al arrancar cualquier request. Cada cliente tiene su propia carpeta `clients/{codigo}/{codigo}.db` + `clients/{codigo}/uploads/`. `helpers/tenant.php` centraliza `sanitize_code()`, `client_db_path()` y `open_client_db()` (con auto-reparación: si falta la BD del cliente, la copia desde `database_initial/{codigo}/`). En Railway todo `clients/` vive en un volumen persistente — sin volumen se pierde todo en cada deploy. `database_initial/` es la plantilla que se copia a `clients/` solo en el primer arranque (cuando `central.db` no existe).

Login (`login.php`) valida código+contraseña contra `central.db`, guarda `$_SESSION['client_code']`, y desde ahí toda request abre la BD de ese cliente vía `open_client_db($_SESSION['client_code'])`.

### Autoload y carga de helpers

`autoload.php` es el punto de entrada obligatorio (`require_once __DIR__ . '/autoload.php';`): registra un autoloader PSR-4 simplificado para el namespace `Kino\` (mapea a `src/`), con fallback a `helpers/{Clase}.php` para código legacy sin namespace. Carga automáticamente `config.php` y un set fijo de helpers "core" (`db`, `config_helper`, `auth`, `tenant`, `secure_uploader`, `file_manager`, `error_codes`, `rate_limiter`, `csrf_protection`). Helpers adicionales se cargan bajo demanda con `load_helper($name)` / `load_helpers([...])` — ver ejemplo en `api.php` (`load_helpers(['search_engine', 'pdf_extractor', 'gemini_ai', 'cache_manager'])`).

### API (`api.php`)

Punto único de entrada REST-ish, dispatcher por `$_REQUEST['action']` hacia controladores en `src/Api/` (namespace `Kino\Api\`): `DocumentController` (upload/update/delete/list/get), `SearchController` (search voraz, search_by_code, suggest, stats, fulltext_search), `PdfController` (extract_codes, search_in_pdf), `AiController` (ai_extract, ai_chat, smart_chat, ai_status — integración Gemini), `SystemController` (reindex_documents, pdf_diagnostic, update_password). Todos heredan de `BaseController`. Requiere sesión autenticada (`$_SESSION['client_code']`); aplica `RateLimiter::middleware()` y `CsrfProtection::middleware()` antes de despachar. Responde siempre JSON, incluso en errores fatales (error handler y shutdown handler personalizados en el propio archivo).

Nota: `DOCUMENTACION_TECNICA.md` describe una versión anterior de los nombres de acción (`search_single`, `suggest_codes`, etc.) — el dispatcher real en `api.php` es la fuente de verdad.

### Módulos (`modules/`)

Cada subcarpeta es una feature server-rendered independiente con su propio `index.php` (y a veces `list.php`/`upload.php`/`process.php`/`view.php` como sub-rutas). No comparten estado entre sí más allá de los helpers y la BD del cliente en sesión. Los más relevantes: `busqueda/` (búsqueda voraz + consulta), `subir/` (upload con extracción automática de códigos), `resaltar/` (resaltado de PDF con PDF.js, patrones inicio/fin), `trazabilidad/` (vincular y validar manifiestos vs declaraciones), `manifiestos/` y `declaraciones/` (CRUD por tipo de documento), `importar_datos/` y `excel_import/` (import masivo CSV/SQL/Excel).

### Motor de búsqueda voraz (`helpers/search_engine.php`)

`greedy_search()`: dado un set de códigos buscados, selecciona iterativamente el documento que cubre más códigos pendientes hasta cubrir todos o agotar candidatos — devuelve el conjunto mínimo de documentos. Complementado por `search_by_code()`, `fulltext_search()`, `suggest_codes()`. `search_by_code()` y `greedy_search()` van **sin caché** a propósito (antes cacheaban 5–10 min sin invalidar al subir/borrar/re-enlazar: un PDF recién subido "no aparecía"). No volver a cachearlas sin borrar la caché en cada escritura. Comparan códigos **ignorando espacios, guiones, puntos, comas, `;`, `:`, `_` y `/`** (`code_match_key()` / `code_match_sql()` en `helpers/tenant.php`, índice por expresión `idx_codigos_match`): en Los Montañeros el mismo código está guardado como "BL-1258" y "BL1258", "DS 230166" y "DS230166", o "NS377675,". Los códigos se guardan y muestran **tal como los escribió el cliente** (a veces usa puntos/guiones/espacios para distinguir productos): la coincidencia exacta tiene prioridad (`exacta=1`, sale primero; en la voraz, si existe la escritura exacta solo cuentan esos documentos) y las variantes solo se suman en la búsqueda por código, marcadas "Escrito en el documento como…". Cualquier consulta nueva por código debe usar esas dos funciones (también `Buscador/api_public.php`). Cada resultado muestra **"Por qué aparece"** (`motivoBox()` en `index.php`): búsqueda por código y voraz usan `exacta` / `match_info`; "Buscar en Contenido" (`fulltextSearch`, busca en el texto del PDF) devuelve `motivo` {codigos registrados que contienen lo buscado, nombre, veces en el texto}, así se ve cuando un documento sale solo porque el código está en su texto (p. ej. la factura que lista los productos).

### Resaltado de PDFs (visor interno y público)

`modules/resaltar/viewer.php` (interno) y `modules/Buscador/viewer_publico.php` (buscador público de todos los clientes) usan el mismo motor: buscan el código en la capa de texto del PDF con PDF.js en el navegador y solo las hojas escaneadas (<150 caracteres) van al servidor (`ocr_text.php` / `ocr_text_public.php` → `helpers/ocr_highlight.php`: caché, `pdftotext -bbox`, OCR). El visor público resalta solo al abrir y compara **sin mayúsculas, tildes ni separadores** (misma regla que `code_match_key()`), con bordes: "1251" no se resalta dentro de "11251" ni de "1.251.000". `ocr_hl_match_terms()` aplica la misma regla en el servidor (afecta a ambos visores).

### Extracción de PDFs

`helpers/pdf_extractor.php` extrae texto (Smalot\PdfParser / `pdftotext` CLI como fallback) y detecta códigos alfanuméricos con regex (mínimo 3 caracteres, admite guiones/espacios). `helpers/gemini_ai.php` ofrece extracción/chat asistido por Google Gemini cuando `GEMINI_API_KEY` está configurada (opcional, degrada sin IA si falta).

### Seguridad

Passwords con `password_hash()`/PDO prepared statements en todas partes. `helpers/csrf_protection.php` y `helpers/rate_limiter.php` protegen `api.php`. `helpers/secure_uploader.php` valida uploads. `ADMIN_RESET_PASSWORD` (env var) permite resetear password de `admin`/`kino` en cada arranque — solo para pruebas/deploy nuevo, no dejarla seteada en producción normal.

### Modo local (copia de trabajo en el PC de un cliente)

Paquete Windows portable (PHP + `php -S` en 127.0.0.1, navegador como interfaz) con la base de UN cliente, licencia Ed25519 atada al equipo y sincronización con la nube por paquetes (`.ktdata` nube→PC, `.ktsync` PC→nube: reemplazo si la nube no cambió, fusión aditiva si cambió, siempre con respaldo previo). Se activa solo si existe `local/MODO_LOCAL` (lo crea `local/herramientas/kt.php paquete`; ignorado en Railway). Núcleo en `helpers/sync_local.php` (no incluye config.php). Toda la operación, estructura y seguridad: `docs/modo_local/` (empezar por `README.md` y `PROCESO.md`).

### Deploy

Railway vía Docker (`Dockerfile`: PHP 8.2 + Apache, poppler-utils + tesseract-ocr para procesamiento de PDF/OCR). Volumen obligatorio en `/var/www/html/clients`. Variables relevantes: `GEMINI_API_KEY` (IA opcional), `ADMIN_RESET_PASSWORD`, `RESEND_API_KEY`/SMTP (recuperación de contraseña, ver `.env.example`), `APP_BRANCH` (indicador visual de rama activa).
