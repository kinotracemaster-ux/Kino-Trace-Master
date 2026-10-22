# Seguridad del modo local

## 1. Qué se protege
| Activo | Dónde vive |
|---|---|
| Documentos y códigos del cliente (información aduanera) | `app/clients/<c>/` en el PC; volumen de Railway en la nube |
| Contraseña del cliente | Solo su **hash bcrypt** en `app/clients/central.db` |
| Llave privada de licencias | **Solo** en el equipo del administrador (`~/.kinotrace/llave_privada.txt`) |
| Datos de los demás clientes | Nunca salen de la nube (cada paquete lleva un solo cliente) |
| Código de la aplicación | En el paquete, legible (ver §4: límites honestos) |

## 2. Amenazas y controles

| Amenaza | Control | Dónde |
|---|---|---|
| Alguien en la red de la oficina abre el programa | El servidor escucha solo en `127.0.0.1`; además se valida IP y `Host` en cada petición | `INICIAR…bat`, `router.php`, `arranque.php` |
| Una web maliciosa apunta su dominio a 127.0.0.1 (*DNS rebinding*) | `Host` debe ser `127.0.0.1`/`localhost` o se responde 403 | `router.php`, `arranque.php` |
| Otra pestaña del navegador envía formularios al programa (CSRF) | Cookie de sesión `SameSite=Lax` + token CSRF en sincronizar y en el panel | `session_init.php`, `sincronizar.php`, `Admin-gestor/sync_local.php` |
| Leer la base, respaldos, herramientas o la licencia por URL | Bloqueo por carpeta (`Admin-gestor`, `helpers`, `vendor`, `local/herramientas`…), por extensión (`.db .sql .zip .json .md .txt .ini .pem …`) y archivos ocultos; contención por `realpath` (atajos/nombres cortos de Windows) | `router.php` |
| Abrir un PDF del cliente sin haber iniciado sesión | Solo con sesión **de ese cliente** y licencia válida | `router.php` |
| Entrar como administrador en el PC | Acceso admin deshabilitado (login, router y arranque); el Gestor no aparece | `login.php`, `router.php`, `arranque.php` |
| **Copiar la carpeta a otro PC** | Licencia atada al código de equipo (hash de `MachineGuid`) | `licencia.php` |
| Inventar o editar una licencia | Firma **Ed25519**; el paquete solo trae la llave pública (verifica, no firma) | `licencia.php`, `kt.php` |
| Atrasar el reloj para estirar la licencia | Se guarda el último uso (`local/.reloj`); atraso > 1 día bloquea | `licencia.php` |
| Licencia de otro cliente | Debe coincidir con el único cliente de `central.db` | `arranque.php`, `activar.php` |
| Filtrar datos de otros clientes en el paquete | `central.db` filtrada (borra las demás filas y tablas) + `VACUUM` (no quedan en páginas libres; verificado por bytes) | `kt_exportar_datos` |
| Secretos dentro del paquete | No viaja ninguno: sin `GEMINI_API_KEY`, SMTP, `ADMIN_SECRET`; `password_plain` y tokens de recuperación se borran | `kt_exportar_datos`, lista blanca de `kt.php` |
| Paquete `.ktsync` manipulado (zip-slip, archivos colados, base alterada) | Lista blanca exacta de entradas, rutas validadas, SHA-256 por archivo, `quick_check`, huella de la base = manifiesto | `kt_leer_paquete_sync` |
| Subir un paquete a nombre de otro cliente | Autenticación con código + contraseña; el cliente del paquete debe coincidir | `sync_recibir.php` |
| Adivinar la contraseña vía `sync_recibir.php` | `password_verify` + 2 s de espera por fallo + límite general 100 peticiones/min/IP | `sync_recibir.php`, `RateLimiter` |
| Repetir un paquete viejo (*replay*) | `paquete_id` aplicado queda en `registro.jsonl` y no se reaplica; huella igual = "ya aplicado" | `kt_previsualizar_sync` |
| Una sincronización daña la nube | Respaldo automático **antes** de aplicar + Restaurar; fusión nunca borra ni edita | `kt_aplicar_sync`, panel |
| Descargar binarios falsos al armar el paquete | SHA-256 verificado (PHP desde `releases.json` de php.net, cacert con su `.sha256`, Poppler si GitHub publica el digest) | `kt.php`, `construir.ps1` |
| El marcador de modo local llega a la nube por error | `config.php` lo ignora si existe `RAILWAY_ENVIRONMENT`; además está en `.gitignore` | `config.php` |

**Sin cifrado en disco.** SQLite y los PDFs quedan legibles en el PC (igual que hoy en el volumen de
Railway). Recomendaciones: BitLocker activado en el PC, usuario de Windows con contraseña, y
para el traslado del zip un canal privado (Drive compartido solo con el correo del cliente) o 7-Zip
con AES-256 y la contraseña enviada por otro medio.

## 3. Llaves y licencias

- `kt.php llaves` crea el par Ed25519. **La llave privada no va a GitHub, ni a Railway, ni a ningún
  paquete.** La nube no la necesita (no emite licencias).
- Copia de la llave privada en un USB guardado. Si se pierde: `kt.php llaves --forzar`, rearmar
  paquetes (llevan la pública nueva) y reemitir licencias.
- **Si se filtra la llave privada**: alguien podría fabricar licencias. Igual que si se pierde: rehacer
  llaves; las licencias viejas dejan de valer al cambiar la pública en los paquetes.
- Licencias **cortas** (15–30 días) por defecto: el modo local es provisional. Cada licencia tiene un
  `id` que viaja en cada `.ktsync` → trazabilidad de qué equipo envió qué.
- `--equipo *` (licencia sin atar a equipo) existe solo para pruebas internas; no usar con clientes.

## 4. Anticopia y clonación (incluida la asistida por IA) — lo que sí y lo que no

**Lo que la licencia sí impide** (sin conocimientos técnicos): copiar la carpeta a otro PC, compartir
el zip ya activado, editar la fecha de vencimiento, fabricar una licencia, seguir usando el programa
después del vencimiento atrasando el reloj.

**Lo que ninguna protección en PHP impide:** el código viaja legible. Una persona con conocimientos —
o cualquiera con un asistente de IA — puede leer `local/arranque.php` y desactivar la verificación, o
usar el código para rehacer una aplicación parecida. Esto vale para cualquier software PHP
distribuido; la licencia es una **cerradura**, no una caja fuerte. Decir lo contrario sería falso.

**Mitigaciones reales, en orden de efectividad:**
1. **Lo valioso no viaja.** El paquete no incluye el Gestor multi-cliente, la recepción de
   sincronizaciones, las herramientas de licencias ni la documentación interna. Sin la nube, una copia
   "crackeada" es un buscador de un solo cliente sin actualizaciones ni sincronización.
2. **Sin secretos en el paquete.** Desactivar la licencia no da acceso a nada más (ni a la nube, ni a
   claves de API, ni a otros clientes).
3. **Vencimiento corto + licencia por equipo + `id` en cada sincronización** → uso acotado y trazable.
4. **Contrato.** Que el cliente firme (o acepte por correo) un acuerdo de uso: el paquete es
   provisional, intransferible, se devuelve/borra al volver a la nube, y prohíbe modificarlo o
   redistribuirlo. Es lo que respalda una reclamación si alguien clona.
5. **Opcional, de pago: codificar el PHP** (ionCube o SourceGuardian). Requiere instalar su *loader*
   en el `runtime/php` del paquete. Sube mucho el costo de modificar o leer el código (también para
   una IA), pero no lo hace imposible y complica el soporte. Recomendado solo si se distribuye el
   modo local de forma permanente y a muchos clientes.
6. **No ofuscar a mano**: no aporta seguridad real y rompe el mantenimiento.

## 5. Después del incidente de Railway (2026-10-21/22)

Railway informó acceso no autorizado a variables de entorno de clientes. **Rotar** (generar nuevas
y revocar las viejas en cada proveedor):
- Kino Trace: `GEMINI_API_KEY`, `RESEND_API_KEY` o usuario/clave SMTP, `ADMIN_SECRET`,
  `ADMIN_RESET_PASSWORD` (y quitarla si no se usa).
- TablasK (si está en Railway): `TABLASK_PASSWORD`, credenciales de Google, tokens de Shopify,
  `DATABASE_URL` (cambiar la contraseña de Postgres).
- Revisar en GitHub los *deploy keys*/tokens conectados a Railway.

## 6. Hallazgos en la nube actual (no corregidos en este cambio)

| Gravedad | Hallazgo | Qué hacer |
|---|---|---|
| **ALTA** | `ADMIN_SECRET` tiene valor por defecto **`3312`** (`login.php`, `Admin-gestor/login.php`). Son 4 dígitos, los logins no tienen límite de intentos (el `RateLimiter` no los cubre) → cualquiera puede probar las 10.000 combinaciones y entrar al Gestor de todos los clientes. | **Hoy:** definir en Railway un `ADMIN_SECRET` largo y aleatorio. Luego: quitar el valor por defecto (fallar cerrado si no está) y limitar intentos. |
| **ALTA** | Contraseñas en **texto plano**: columna `password_plain` en `central.db`, la escribe el panel al crear/cambiar contraseñas y `ADMIN_RESET_PASSWORD`, y el panel la muestra. Quien obtenga `central.db` (p. ej. un respaldo del volumen) tiene todas las contraseñas. | Dejar de guardarla, `UPDATE control_clientes SET password_plain = NULL`, y pedir cambio de contraseña. (El paquete local ya la elimina.) |
| **MEDIA** | PDFs accesibles **sin sesión** por URL (`/clients/<c>/uploads/…`); el `.htaccess` solo bloquea `.db/.sql/.zip/.env/.log/.json`. Los nombres llevan un sufijo aleatorio, pero un enlace filtrado funciona para siempre. | Servir los PDFs por un script con sesión (como `local/router.php`) y denegar `/clients/` en `.htaccess`. |
| **MEDIA** (arreglado aquí) | El botón **Backup** buscaba `data.db` y entregaba respaldos **sin base de datos**. | Arreglado en `Admin-gestor/backup.php`. Los respaldos viejos no sirven para restaurar la base. |
| BAJA | La cuenta `kino` (contraseña normal) también entra al Gestor. | Tratar su contraseña como de administrador (larga, única). |

## 7. Checklist antes de entregar un paquete
- [ ] El `.ktdata` es del cliente correcto (nombre del archivo y `conteos` coherentes).
- [ ] Paquete armado con la versión actual del repo (`git pull`).
- [ ] Zip entregado por canal privado; nunca adjunto en un chat grupal.
- [ ] Licencia emitida con el **código del equipo** del cliente y vencimiento corto.
- [ ] Cliente informado: copia diaria de la carpeta, BitLocker, no compartir la carpeta.
- [ ] Registrar (planilla interna) cliente, equipo, `id` de licencia, fecha de entrega y vencimiento.
