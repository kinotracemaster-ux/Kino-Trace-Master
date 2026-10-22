# Estructura — archivos, carpetas y formatos del modo local

## En el repositorio

```
helpers/sync_local.php        Núcleo: huella, rastreo, exportar, paquete, validar, previsualizar,
                              aplicar (reemplazo/fusión), respaldos, restaurar. Sin config.php.
sync_recibir.php              NUBE · endpoint POST que recibe el .ktsync (código + contraseña).
Admin-gestor/sync_local.php   NUBE · panel: exportar .ktdata, recibir/revisar/aplicar .ktsync,
                              historial, respaldos y restaurar. Botón "💻 Modo local" en panel.php.
local/
  arranque.php                PC · reglas por petición (solo este equipo, sin admin, licencia).
                              Lo carga config.php si existe local/MODO_LOCAL (nunca en Railway).
  licencia.php                PC · código de equipo, verificación Ed25519, vencimiento, reloj.
  router.php                  PC · enrutador de `php -S` (reemplaza .htaccess, sirve PDFs con sesión).
  activar.php                 PC · pantalla de activación/renovación.
  sincronizar.php             PC · pantalla "Sincronizar con la nube" (subir o descargar paquete).
  herramientas/kt.php         ADMIN · consola: llaves, licencia, verificar, equipo, exportar,
                              paquete, aplicar, clave. No viaja al paquete.
  construir/construir.ps1     ADMIN · Windows: baja PHP portable (SHA-256) y ejecuta kt.php.
docs/modo_local/              Esta documentación.
```

Ignorados por git (`.gitignore`): `local/MODO_LOCAL`, `local/licencia.txt`, `local/clave_publica.txt`,
`local/.reloj`, `*.ktdata`, `*.ktsync`.

## Paquete entregado al cliente (`KinoTrace_<cliente>_<fecha>.zip`)

```
KinoTrace_losmonta/
├── INICIAR KINO TRACE.bat        Lanzador (puerto libre, PATH, abre Edge modo app, php -S)
├── CREAR ACCESO DIRECTO.bat      Icono en el Escritorio
├── LEAME.txt                     Instrucciones para el usuario final
├── app/                          Código PHP (lista blanca) — docroot del servidor
│   ├── local/MODO_LOCAL          Marcador que activa el modo local
│   ├── local/clave_publica.txt   Llave pública Ed25519 (solo verifica)
│   ├── local/licencia.txt        (se crea al activar)
│   ├── local/.reloj              (último uso, anti-atraso de fecha)
│   └── clients/
│       ├── central.db            SOLO este cliente (sin password_plain ni tokens; VACUUM)
│       └── losmonta/
│           ├── losmonta.db       Base del cliente + kt_estado + kt_cambios + triggers
│           ├── uploads/…         Sus PDFs
│           └── logo.*            (si tiene)
└── runtime/
    ├── php/                      PHP x64 NTS para Windows + php.ini
    ├── poppler/bin/              pdftotext.exe, pdftoppm.exe (+ DLLs)
    ├── cacert.pem                Certificados raíz (HTTPS al sincronizar)
    ├── puerto.php                Busca puerto libre 8765–8799
    ├── sesiones/                 Sesiones PHP
    └── logs/php_errores.log
```

## Formatos

### `.ktdata` (nube/respaldo → paquete) — zip
```
kt_manifest.json   {tipo:"ktdata", version:1, cliente, snapshot_id, huella_base, exportado_en, origen,
                    conteos:{documentos, codigos, vinculos}}
clients/central.db
clients/<c>/<c>.db
clients/<c>/uploads/...
clients/<c>/logo.*
```

### `.ktsync` (PC → nube) — zip
```
kt_manifest.json   {tipo:"ktsync", version:1, paquete_id, cliente, snapshot_id, huella_base, huella_local,
                    max_cambio, creado_en, licencia_id, equipo, resumen, archivos:{ruta:{sha256,bytes}},
                    archivos_faltantes}
db/<c>.db
uploads/<ruta>     solo documentos nuevos/modificados
```

### Licencia
```
KTL1.<base64url(JSON)>.<base64url(firma Ed25519 de "KTL1.<payload>")>
JSON = {v:1, id, cliente, equipo:"KT-XXXX-XXXX-XXXX-XXXX" | "*", emitida:"AAAA-MM-DD", vence:"AAAA-MM-DD"}
```
Código de equipo = `KT-` + 16 hex de `SHA-256("kinotrace-equipo|" + id_máquina)`, donde id_máquina es
`MachineGuid` (Windows, registro), `IOPlatformUUID` (Mac) o `/etc/machine-id` (Linux).

## Tablas nuevas en la base del cliente (solo en la copia local)

| Tabla | Columnas | Uso |
|---|---|---|
| `kt_estado` | clave, valor | cliente, snapshot_id, huella_base, exportado_en, origen, ultima_sync, ultima_sync_paquete |
| `kt_cambios` | id, tabla, fila_id, op (I/U/D), fecha | Diario de cambios desde el corte |
| triggers `kt_trg_*` | — | Llenan `kt_cambios` |

Al volver a la nube por **reemplazo**, todo `kt_*` se elimina antes de instalar la base.

## Carpetas nuevas en la nube (dentro del volumen `clients/<c>/`)
```
_respaldos/<AAAAMMDD_HHMMSS>_<etiqueta>/<c>.db   (+ archivos/ sobrescritos)
_sync/registro.jsonl                             historial / anti-repetición
_sync/recibidos/<fecha>_<paquete_id>.ktsync      paquetes recibidos
.kt_sync.lock                                    bloqueo durante una aplicación
```
`_respaldos` crece con cada sincronización: revisar y depurar los antiguos de vez en cuando.

## Flujo de una petición en el PC
```
Navegador ──► php -S 127.0.0.1:PUERTO ──► local/router.php
                                          ├─ IP/Host no locales → 403
                                          ├─ rutas internas/sensibles → 404
                                          ├─ clients/<c>/uploads/* → licencia + sesión de <c> → archivo
                                          └─ resto → script PHP normal
                                                     └─ config.php → local/arranque.php
                                                          ├─ IP/Host, Admin-gestor y scripts de servidor bloqueados
                                                          └─ licencia válida? no → /local/activar.php
```
