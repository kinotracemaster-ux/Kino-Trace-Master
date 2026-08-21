# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

KINO TRACE — sistema PHP multi-cliente (multi-tenant) de gestión documental para rastreo aduanero: sube manifiestos/declaraciones en PDF, extrae códigos automáticamente, permite búsqueda voraz (mínimo conjunto de documentos que cubre una lista de códigos) y valida cruces entre manifiestos y declaraciones. Sin frontend framework: PHP renderiza HTML server-side con JS embebido; PDF.js para el visor/resaltador de PDFs.

## Commands

```bash
# Servidor local
php -S localhost:8080

# Crear/migrar usuario admin (requerido tras clonar o desplegar)
php migrate.php

# Tests (PHPUnit, bootstrap en tests/bootstrap.php)
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Api/SearchControllerTest.php          # un archivo
./vendor/bin/phpunit --filter testNombreDelMetodo                # un test

# Optimizar bases de datos SQLite (índices, ANALYZE, VACUUM)
php optimize_db.php          # todos los clientes
php optimize_db.php KINO     # un cliente específico
```

No hay `composer.json` con scripts de lint/build; `composer install` solo trae dependencias (`setasign/fpdi-tcpdf`, `phpmailer/phpmailer`, PHPUnit vía require-dev si aplica).

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

`greedy_search()`: dado un set de códigos buscados, selecciona iterativamente el documento que cubre más códigos pendientes hasta cubrir todos o agotar candidatos — devuelve el conjunto mínimo de documentos. Complementado por `search_by_code()`, `fulltext_search()`, `suggest_codes()`.

### Extracción de PDFs

`helpers/pdf_extractor.php` extrae texto (Smalot\PdfParser / `pdftotext` CLI como fallback) y detecta códigos alfanuméricos con regex (mínimo 3 caracteres, admite guiones/espacios). `helpers/gemini_ai.php` ofrece extracción/chat asistido por Google Gemini cuando `GEMINI_API_KEY` está configurada (opcional, degrada sin IA si falta).

### Seguridad

Passwords con `password_hash()`/PDO prepared statements en todas partes. `helpers/csrf_protection.php` y `helpers/rate_limiter.php` protegen `api.php`. `helpers/secure_uploader.php` valida uploads. `ADMIN_RESET_PASSWORD` (env var) permite resetear password de `admin`/`kino` en cada arranque — solo para pruebas/deploy nuevo, no dejarla seteada en producción normal.

### Deploy

Railway vía Docker (`Dockerfile`: PHP 8.2 + Apache, poppler-utils + tesseract-ocr para procesamiento de PDF/OCR). Volumen obligatorio en `/var/www/html/clients`. Variables relevantes: `GEMINI_API_KEY` (IA opcional), `ADMIN_RESET_PASSWORD`, `RESEND_API_KEY`/SMTP (recuperación de contraseña, ver `.env.example`), `APP_BRANCH` (indicador visual de rama activa).
