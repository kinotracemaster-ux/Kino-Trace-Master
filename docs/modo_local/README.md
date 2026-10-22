# Modo local de KINO TRACE — índice

Copia **completa** del sistema que corre en el PC de un cliente, con su base de datos
local, contraseña, licencia atada al equipo, y **sincronización** con la nube (Railway)
cuando vuelve a estar disponible.

Se creó tras la caída de Railway del 2026-10-22 (incidente de seguridad + builds pausados):
los clientes no podían trabajar. El objetivo es que eso no vuelva a detener a nadie.

| Documento | Para qué |
|---|---|
| [PROCESO.md](PROCESO.md) | Paso a paso operativo: qué hace Kino y qué hace el cliente, de la caída a la vuelta a la nube. **Empiece aquí.** |
| [DESPLIEGUE_PC.md](DESPLIEGUE_PC.md) | Armar el paquete (Windows / Linux / Mac), instalarlo en el PC del cliente, OCR, solución de problemas. |
| [SINCRONIZACION.md](SINCRONIZACION.md) | Cómo se decide y aplica la sincronización (reemplazo / fusión), huella, respaldos, límites. |
| [ESTRUCTURA.md](ESTRUCTURA.md) | Archivos nuevos, carpetas del paquete, formatos `.ktdata` / `.ktsync`, tablas `kt_*`. |
| [SEGURIDAD.md](SEGURIDAD.md) | Modelo de amenazas, licencia anticopia, clonación (incluida la asistida por IA), secretos, hallazgos de la nube actual. |

## La idea en un dibujo

```
            NUBE (Railway, kino-trace.com)                         PC DEL CLIENTE (Windows)
 ┌──────────────────────────────────────────┐            ┌─────────────────────────────────────────┐
 │ clients/central.db  (todos los clientes)  │            │ INICIAR KINO TRACE.bat                  │
 │ clients/losmonta/losmonta.db + uploads/   │            │   └─ php -S 127.0.0.1:8765 (solo local) │
 │                                           │  .ktdata   │ app/  (mismo código PHP)                │
 │ Admin-gestor/sync_local.php ──Exportar────┼──────────► │   clients/central.db (SOLO ese cliente) │
 │                                           │ (Kino arma │   clients/losmonta/… (sus datos)        │
 │                                           │  el .zip)  │   local/licencia.txt (atada al equipo)  │
 │                                           │            │                                         │
 │ sync_recibir.php ◄──── .ktsync (HTTPS) ───┼────────────┤ "Sincronizar con la nube" → Subir      │
 │ Admin-gestor/sync_local.php ◄── .ktsync ──┼── a mano ──┤                        → Descargar      │
 │   respaldo automático → REEMPLAZO o FUSIÓN│            │                                         │
 └──────────────────────────────────────────┘            └─────────────────────────────────────────┘
```

## Decisiones de diseño (y por qué)

| Decisión | Alternativas descartadas | Motivo |
|---|---|---|
| **PHP portable + `php -S`** en Windows, navegador como interfaz (Edge en modo app) | Docker Desktop; Apache portable (XAMPP); reescribir como app de escritorio | Sin instalar nada ni permisos de administrador; es el **mismo código** que la nube (cero divergencia); se abre como "app instalada". Docker exige WSL2/licencia; XAMPP pesa y abre puertos a la red. |
| **Una copia = un cliente** (central.db filtrada) | Copia del sistema multi-cliente completo | Lo que se entrega solo contiene datos de ese cliente: si se pierde el PC, no se filtra a nadie más. |
| **Licencia firmada Ed25519 + código de equipo + vencimiento** | Contraseña de instalación; licencia online | Funciona **sin internet** (justo cuando la nube cae); copiar la carpeta a otro PC no sirve; la llave privada nunca sale de Kino. |
| **Sincronización por paquete con huella** (reemplazo si la nube no cambió, fusión aditiva si cambió) | Replicación fila a fila bidireccional | Simple, auditable y segura: nunca borra ni pisa en la nube algo que no se pueda deshacer (siempre hay respaldo previo). |
| **Respaldo automático antes de aplicar** + botón Restaurar | — | Cualquier error de sincronización se revierte con un clic. |

## Archivos nuevos o modificados

Nuevos: `helpers/sync_local.php`, `sync_recibir.php`, `Admin-gestor/sync_local.php`, `local/*`
(`arranque.php`, `licencia.php`, `router.php`, `activar.php`, `sincronizar.php`,
`herramientas/kt.php`, `construir/construir.ps1`) y esta carpeta.

Modificados (mínimo, sin cambiar el comportamiento en la nube):
- `config.php` — carga `local/arranque.php` solo si existe `local/MODO_LOCAL` (y nunca en Railway).
- `helpers/session_init.php` — cookie sin dominio cuando el host es una IP o `localhost`.
- `login.php` — el acceso de administrador no existe en modo local.
- `includes/sidebar.php` — entrada "Sincronizar con la nube" en modo local; oculta el Gestor.
- `helpers/pdf_extractor.php` — `pdftoppm` (OCR) también en Windows.
- `Admin-gestor/panel.php` — botón "💻 Modo local".
- `Admin-gestor/backup.php` — **arreglo**: el botón Backup buscaba `data.db` (no existe) y entregaba
  respaldos **sin base de datos**; ahora incluye `{codigo}.db` con copia consistente.
