# CHANGELOG.md

Registro breve de cambios recientes y contexto abierto, para que sesiones futuras de Claude Code no necesiten re-leer todo el `git log` ni re-derivar decisiones ya tomadas. Para arquitectura y comandos ver `CLAUDE.md`. Este archivo se actualiza incrementalmente al tope (más reciente primero); no reescribas entradas viejas, solo agregá arriba.

## Contexto abierto / pendiente

- **Página Pública de "losmonta" (Los Montañeros S.A.S)**: se armó el texto (título, intro, instrucciones, footer, aviso legal) para pegar en el modal "Página Pública" del panel `Admin-gestor/panel.php` (tabla `pagina_publica`, campo `codigo='losmonta'`). Falta que el usuario confirme la dirección exacta (el HTML fuente tenía "Estamos Cr 53 a 45 41 La Candelaria, Medellín, La Candelaria –", ambigua) y una URL de sitio web real (no tenían una cargada, el link original era `#`). Nadie lo guardó todavía en el formulario — es trabajo manual del usuario, no un cambio de código.

## 2026-08-21 — Límite de subida PHP + CLAUDE.md

- **Causa raíz de bug reportado**: al crear un cliente nuevo en `Admin-gestor/panel.php` (acción `create`, campo `zip_file` con PDFs), un ZIP de ~596 MB superaba `post_max_size` (512M configurado en `.htaccess`), lo que además disparaba un warning en cascada de `session_name()` por "headers already sent" en `helpers/session_init.php`.
- **Fix** (`80f9332`): subidos `upload_max_filesize`/`post_max_size` a ~1 GB (1024M/1100M) y `max_execution_time`/`max_input_time` a 600s en `.htaccess` y `Dockerfile` (`conf.d/uploads.ini`). `session_init.php` ahora no intenta iniciar sesión si `headers_sent()` ya es true, para no generar el warning secundario que tapaba el error real.
- **`CLAUDE.md`** (`0ada0a9`): creado con arquitectura (multi-tenancy SQLite, autoload PSR-4, dispatcher de `api.php`, motor de búsqueda voraz, extracción de PDFs, seguridad, deploy) y comandos (servidor local, `migrate.php`, PHPUnit, `optimize_db.php`). Nota importante ahí: `DOCUMENTACION_TECNICA.md` tiene nombres de acciones de API desactualizados (`search_single`, `suggest_codes`) — el dispatcher real en `api.php` (acciones: `search`, `search_by_code`, `suggest`, etc.) es la fuente de verdad.
- Ambos commits mergeados directo a `main` (fast-forward, sin PR, a pedido explícito del usuario) — requiere que Railway redespliegue para tomar los nuevos límites.

## Antes de esta sesión (resumen del historial reciente en `main`)

Por orden cronológico inverso, del `git log` previo — no verificado en detalle, solo para orientación rápida:

- Supresión de warning `fsockopen` en módulo `subir`.
- Fix overlay OCR usando `cssWidth`/`cssHeight` en vez de canvas físico (bug de DPR en móvil).
- Fix PDF nítido en móvil usando `devicePixelRatio` para canvas de alta densidad.
- Fix botón de códigos en `showFulltextResults` de `index.php` (tab Consultar).
- Feature: botón desplegable de códigos en resultados de búsqueda fulltext.
- Fix PDF viewer responsivo en móvil (scale dinámico según ancho de pantalla).
- Trabajo en `viewer_publico` / resaltado público: auto-highlight, `mix-blend-mode` en text-layer, OCR de todas las ocurrencias.
- Optimización de OCR resaltado para escalabilidad multi-cliente: `getOcrForPage()` centralizado con dedup de promesas, `AbortController`, rate limiter y semáforo de concurrencia (máx. 3 procesos Tesseract) en `ocr_text.php`/`ocr_text_public.php`.
- Fix: prevenir error "headers already sent" en `session_reopen()` (relacionado con el mismo patrón que se volvió a tocar en la entrada de arriba).
