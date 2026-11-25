# Plan de mejoras desde la Auditoría v4 (2026-11-25)

La "Auditoría integral v4" es una **guía de método** (qué revisar y cómo), no una lista de fallas de KINO TRACE. Este plan la cruza con el estado real del repo (PRs #12–#35) y deja solo lo que falta, en orden. **Nada de esto está implementado todavía.**

## Agentes y especialidades

| Rol | Quién | Qué hace |
|---|---|---|
| Orquestador | Opus (sesión principal) | decide el plan, revisa el diff, rama → commit → PR |
| `lector` | Haiku, solo lectura | ubica archivos/funciones antes de tocar nada |
| `escritor` | Sonnet | implementa el cambio acotado y corre `tools/chequeo.php` |

Regla de siempre: una rama por mejora, PR a `main`, CI en verde, probar en Railway PRUEBAS, el usuario fusiona. Especialidades (skills): **SEG** seguridad web · **PERF** rendimiento · **FE** frontend/responsive/a11y · **BD** SQLite y migraciones · **OPS** CI/despliegue/respaldos · **QA** pruebas · **LEGAL** datos personales (Colombia).

## Ya cubierto (no repetir)

Triaje 0.11 y sección 4: respaldos automáticos con restauración probada (#21), PDFs con enlace firmado y aislamiento entre clientes con test HTTP en CI (#22), scripts de mantenimiento solo CLI y clave admin con bloqueo (#20), cookie HttpOnly/Secure/SameSite/host-only, migraciones versionadas (#23), registro de lentitud y chequeo de índices (#23), funciones por cliente y alta en un paso (#24), UX móvil con Playwright en CI (#25), sesiones persistentes (#29), indexado OCR por tiempo (#31), búsquedas sin resultado y medición OCR (#32), resaltado sin "-N" (#33, #34). Esto responde a "PDFs descargables por enlace directo": ya hay `pdf.php` firmado y `.htaccess` que solo sirve logos (#22).

## Plan priorizado

### P1 · Seguridad y abuso (prioridad 3 del usuario, pero barato y urgente)

| # | Mejora | Evidencia en el repo | Skill | Agente |
|---|---|---|---|---|
| 1 | ✅ HECHO (PR #36) **Cabeceras de seguridad** (CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, HSTS) en `.htaccess`/`config.php`. CSP en modo "solo reportar" primero: hay JS embebido y PDF.js desde cdnjs | `grep` no encuentra ninguna fuera de `local/arranque.php` | SEG | lector → escritor |
| 2 | ✅ HECHO (PR #36) **PDF.js con SRI** (`integrity`) o copia local en `assets/vendor/` como zip.js; si cdnjs cae o se compromete, caen todos los visores | 0 `integrity` en `modules/` | SEG, FE | escritor |
| 3 | **Límite de gasto de IA (Gemini)**: tope diario por cliente y por IP en `ai_*`/`smart_chat`, hoy solo hay un límite global por IP en archivo JSON | `helpers/rate_limiter.php`, `api.php:155-164` | SEG, OPS | lector → escritor |
| 4 | **Escáner de secretos en CI** (gitleaks sobre todo el historial) + Dependabot (composer y Actions) + fijar Actions por commit. Hubo claves y datos en el historial en #20 | no hay workflow ni `dependabot.yml` | SEG, OPS | escritor |
| 5 | **Tabla rol × recurso × acción** (admin / cliente / público) y un test por celda que hoy no esté en `chequeo_aislamiento.php` | solo cubre aislamiento entre clientes | SEG, QA | lector arma la tabla; Opus decide; escritor tests |
| 6 | **Bitácora de acciones** (quién borró/subió/enlazó/cambió clave) por cliente, sin guardar lo buscado | solo existe `lento.log` | SEG, BD | escritor (paso nuevo de migración) |

### P2 · Velocidad de resaltado y búsqueda (prioridad 1 del usuario)

| # | Mejora | Skill | Agente |
|---|---|---|---|
| 7 | **Decidir dpi del OCR** con PDFs escaneados reales (hoy 150 dpi lee ~61 %; 300 dpi ~87 % pero +3 s/hoja). Entregable: usuario aporta 3–5 escaneos con lista `pdf;pagina;codigo`; se corre `tools/medir_ocr.php` y se decide (p. ej. 300 dpi solo si la hoja sale con poca confianza) | PERF | escritor mide; **decide el usuario** |
| 8 | **Métricas p50/p95 en CI** del resaltado (tiempo hasta primer resaltado) además del chequeo de existencia | PERF, QA | escritor |
| 9 | **Lista de 50–100 búsquedas reales** (de `busquedas_sin_resultado` + las que el usuario dicte) como prueba de calidad con exactitud y p95 | PERF, QA | escritor |
| 10 | Greedy: bucle PHP ~140 ms con 738 mil códigos; solo optimizar si `lento.log` lo muestra | PERF, BD | lector primero |

### P3 · Responsive y accesibilidad (prioridad 2)

| # | Mejora | Skill | Agente |
|---|---|---|---|
| 11 | **Panel admin sigue desbordando en celular** (777 px, pendiente de #24/#25): tablas en contenedor con scroll, tarjetas apiladas | FE | escritor; chequeo_ux ya lo valida |
| 12 | **Accesibilidad WCAG 2.2 AA básica**: `aria-label`/`alt`, foco visible, contraste, objetivos táctiles ≥ 24 px; agregar axe a `chequeo_ux.js`. Solo 3 archivos de módulos usan `aria-`/`alt` | FE, QA | lector mide; escritor corrige |
| 13 | Estados claros: pendiente / completado / fallido en subida, enlazar PDFs e indexar (ya hay `partial/done`; revisar el resto) | FE | escritor |

### P4 · Continuidad y calidad de ingeniería

| # | Mejora | Skill | Agente |
|---|---|---|---|
| 14 | **PHPUnit de verdad en CI**: `composer.json` no declara `require-dev`; los tests de `tests/Api` no corren. Agregar `phpunit/phpunit` y un paso en `chequeo.yml` | QA, OPS | escritor |
| 15 | **Copia fuera del volumen** (Cloudflare R2): código listo (`KT_S3_*`), falta decisión y credenciales del usuario. Cumple "copia fuera de la cuenta principal" | OPS | usuario decide; escritor solo verifica |
| 16 | **Alertas**: aviso si el respaldo automático no corre en 48 h o si el chequeo de integridad falla (hoy solo queda en log/panel) | OPS | escritor |
| 17 | **Health check real** (`/salud.php`: base central y volumen escribibles) en vez de `/login.php` | OPS | escritor |
| 18 | Entorno reproducible: `docs/` con "clonar → corriendo en 10 min" y datos de semilla (ya existe plantilla `kino`) | OPS | lector + escritor |

### P5 · Datos personales (Colombia, Ley 1581) — requiere criterio legal

| # | Mejora | Skill | Quién |
|---|---|---|---|
| 19 | Inventario por campo y finalidad (usuarios, correos de recuperación, IP en rate limit, PDFs aduaneros con datos de terceros); política de tratamiento y retención de respaldos | LEGAL | lector arma el inventario; **validación jurídica es humana** |
| 20 | Procedimiento de incidente (reporte a la SIC en 15 días hábiles) y de supresión de un cliente (borrar también de `_respaldos/`) | LEGAL, OPS | usuario + escritor para la herramienta |
| 21 | Gemini recibe texto de PDFs: documentar qué se envía y dejarlo apagable por cliente (ya existe la función "IA") | LEGAL, SEG | escritor |

## Decisiones que son del usuario

1. dpi del OCR (#7) y qué escaneos aportar.
2. Cloudflare R2 para respaldos (#15).
3. Mantener `password_plain` visible en el panel (decidido 2026-11-03; la auditoría v4 sección 4 lo marcaría como riesgo: alternativa "entrar como cliente").
4. Qué tan estricta la CSP (#1) si rompe algún visor.

## Orden sugerido de PRs

1. #1 + #2 (cabeceras y PDF.js local) → 2. #4 + #14 (CI: secretos, Dependabot, PHPUnit) → 3. #3 (tope IA) → 4. #11 + #12 (admin móvil y a11y) → 5. #7–#9 (OCR y calidad, cuando haya escaneos) → 6. #6 + #16 + #17 → 7. P5 con asesoría legal.

## Lo que la auditoría v4 sugiere y NO aplica ahora

Sección 8 (modularizar/microservicios): el Go/No-Go no se cumple todavía (faltan #14 y #15) y KINO TRACE ya es un solo código multi-cliente que funciona; no hay necesidad medida de separar despliegues. Secciones de OTP/SMS, webhooks, OAuth, MCP y apps nativas: la app no los usa.
