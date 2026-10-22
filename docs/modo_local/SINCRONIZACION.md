# Sincronización PC ↔ nube

Código: `helpers/sync_local.php` (núcleo, sin dependencias de `config.php`), `local/sincronizar.php`
(PC), `sync_recibir.php` y `Admin-gestor/sync_local.php` (nube), `kt.php exportar/aplicar` (consola).

## Conceptos

**Corte (snapshot).** Al exportar, se toma una copia consistente de la base del cliente
(`VACUUM INTO`, incluye lo pendiente en el WAL) y se registra en la tabla `kt_estado`:
`cliente`, `snapshot_id`, `huella_base`, `exportado_en`, `origen`. Cada vez que el PC arma un paquete
agrega su id a `paquetes_generados` (en la base real): así la nube puede comprobar que un paquete
posterior desciende de uno que ya aplicó.

**Huella.** SHA-256 de las filas que importan, en orden de `id`:
- `documentos`: id, tipo, numero, fecha, proveedor, naviera, peso_kg, valor_usd, ruta_archivo,
  original_path, hash_archivo, estado, notas, requiere_revision
- `codigos`: id, documento_id, codigo, descripcion, cantidad, valor_unitario, validado, alerta
- `vinculos`: id, documento_origen_id, documento_destino_id, tipo_vinculo
- `tipos_documento`: id, codigo, nombre, activo
- `configuracion_extraccion`: id, prefix, terminator, min_length, max_length

(Las dos últimas están para que un cambio de configuración hecho en la nube impida un REEMPLAZO que lo
revertiría.)

Se excluye a propósito el texto extraído (`datos_extraidos`, `texto_extraido`, `estado_extraccion`):
es derivado y re-indexar no debe contar como "cambio". Dos bases con la misma huella tienen el mismo
contenido de negocio aunque los archivos `.db` difieran byte a byte.

**Rastreo de cambios.** En la copia local se instalan triggers `kt_trg_<tabla>_{i,u,d}` que anotan en
`kt_cambios(tabla, fila_id, op, fecha)` cada INSERT, cada UPDATE **de columnas de negocio** y cada
DELETE (incluidos los borrados en cascada de códigos/vínculos). `kt_resumen_cambios()` reduce eso por
fila: *nuevo* (primera op = I y sigue existiendo), *modificado* (existía en el corte y sigue), *eliminado*
(existía en el corte y ya no). Creado y borrado en local = no viaja.

## Paquete `.ktsync`
Zip con:
- `kt_manifest.json`: `paquete_id` (24 hex aleatorio), `cliente`, `snapshot_id`, `huella_base`,
  `huella_local`, `max_cambio`, `creado_en`, `licencia_id`, `equipo`, resumen de cambios, y la lista
  `archivos` con **SHA-256 y tamaño** de cada PDF incluido, `ubicaciones` (ruta_archivo → ruta real bajo
  `uploads/` cuando difieren) y `archivos_faltantes`.
- `db/<cliente>.db`: copia consistente de la base local completa.
- `uploads/<ruta real>`: **solo** los archivos de documentos nuevos o modificados desde el corte. La ruta
  real se busca porque cada módulo guarda `ruta_archivo` distinto: `declaracion/x.pdf` (Subir), solo
  `x.pdf` con el archivo en `uploads/<tipo>/` (Subida en lote), o con prefijo `uploads/…` (importaciones).
  Si un PDF no está en el equipo, el documento viaja igual y el informe lo avisa.

## Validación en la nube (`kt_leer_paquete_sync`) — se rechaza si:
- el zip no abre, no hay manifiesto, o es de otra versión;
- hay **cualquier entrada no esperada** (lista blanca exacta: manifiesto, `db/<cliente>.db` y
  `uploads/<ruta>` declaradas) → protege de *zip-slip* (`../../`) y de archivos colados;
- una ruta tiene `..`, raíz, `\`, `:`, caracteres de control, o extensión fuera de pdf/png/jpg/gif/webp/tif;
- `PRAGMA quick_check` de la base ≠ `ok`;
- la base no es del cliente/corte del manifiesto, o su huella ≠ `huella_local`;
- el SHA-256 de algún archivo no coincide;
- **tamaños** (contra "zip bombs"): el total declarado supera 8 GiB, el tamaño de un PDF en el zip no es
  el del manifiesto, o al extraer algo supera su tope (la base 2 GiB, cada PDF su tamaño exacto). Se
  revisa antes de descomprimir y otra vez al extraer, porque el zip puede mentir;
- (en `sync_recibir.php`) el cliente del paquete ≠ el que se autenticó.

## Decisión: REEMPLAZO, FUSIÓN o YA APLICADO (`kt_previsualizar_sync`)

```
paquete_id ya aplicado (después de la última restauración)  ──► YA APLICADO
huella_nube == huella_local                                  ──► YA APLICADO
huella_nube ∈ {huella_base, resultado de un REEMPLAZO previo
               hecho con un paquete de ESTA MISMA copia}     ──► REEMPLAZO   (la nube no cambió por otro lado)
otro caso                                                    ──► FUSIÓN      (los dos lados cambiaron)
```

**Encadenar envíos.** Si el PC envió un paquete y no se enteró del resultado (respuesta perdida, o paquete
enviado a mano y aplicado desde el panel), su `huella_base` sigue siendo la del corte. Antes eso
terminaba en FUSIÓN y las ediciones/borrados posteriores se perdían. Ahora, si la nube está exactamente
como la dejó el REEMPLAZO de un paquete anterior **que figura en `paquetes_generados` de esta copia**, el
nuevo paquete también es REEMPLAZO. Otra copia (otro PC) con el mismo corte no puede demostrarlo y cae en
FUSIÓN: no puede pisar lo enviado por la primera.

### REEMPLAZO
1. Respaldo: `clients/<c>/_respaldos/<fecha>_antes_sync_<paquete>/<c>.db` (copia consistente).
2. Copia los archivos del paquete a `uploads/` (si alguno se sobrescribe y era distinto, el anterior
   se guarda en el respaldo, subcarpeta `archivos/`).
3. Quita triggers y tablas `kt_*` de la base del paquete y **conserva el texto ya indexado en la nube**
   para los documentos que en el PC no lo tienen (mismo id y misma ruta). El índice no es parte de la
   huella; sin esto, indexar en la nube mientras el cliente estaba en local se perdía. Luego compacta.
4. **Vuelve a calcular la huella de la nube**: si cambió desde la revisión (alguien escribió mientras se
   copiaban archivos), se cancela sin tocar la base.
5. Reemplazo seguro con WAL: `wal_checkpoint(TRUNCATE)` en la base actual → copia a `<c>.db.nuevo` →
   `rename` atómico → borra `-wal`/`-shm` viejos (si quedaran, SQLite podría aplicarlos sobre la base
   nueva y dañarla).

Resultado: la nube queda **idéntica** a la copia del PC (incluye ediciones y borrados hechos en local,
configuración de extracción y tipos de documento).

### FUSIÓN (solo altas, nunca pisa ni borra)
Dentro de una transacción `BEGIN IMMEDIATE`:
1. **Documentos nuevos**: si en la nube ya había (antes de esta fusión) uno con el mismo tipo, número,
   fecha, ruta y hash, es un **reenvío** del mismo documento: no se duplica y se informa. Dos documentos
   distintos que comparten PDF (Subir permite duplicados) **no** se juntan. Si no, se inserta con
   **id nuevo** (mapa id-local → id-nube).
   Si existe en la nube un archivo distinto con el mismo nombre, el nuevo se guarda como `…_localN.pdf`.
2. **Códigos nuevos**: se cuelgan del documento mapeado (nuevo) o del mismo id (si el documento ya
   existía en el corte y sigue en la nube). Si ya existe ese código en ese documento, se omite.
3. **Vínculos nuevos**: igual, mapeando ambos extremos.
4. Se copian los PDFs; si una copia falla → `ROLLBACK` y se borran los copiados. Si todo bien → `COMMIT`.
5. **Conflictos** (se informan, no se aplican): documentos modificados o eliminados en el PC,
   códigos modificados/eliminados de documentos existentes, códigos o vínculos cuyo documento ya no
   existe en la nube.

### Después de aplicar
- `clients/<c>/_sync/registro.jsonl`: una línea por operación (fecha, paquete, corte, huellas, modo,
  quién, informe, respaldo). Sirve de **anti-repetición**: un `paquete_id` aplicado no se vuelve a
  aplicar… salvo que después se haya **restaurado** un respaldo (restaurar deshace, así que el mismo
  paquete se puede volver a aplicar).
- `clients/<c>/_sync/recibidos/`: copia de cada paquete recibido (auditoría / re-aplicar a mano). Un
  reenvío "ya aplicado" no se guarda.
- **Retención**: se conservan los últimos 15 respaldos y 15 paquetes recibidos por cliente (el volumen
  de Railway es compartido).
- En el PC, si el resultado fue **reemplazo** (o ya aplicado), `kt_marcar_sincronizado()` pone
  `huella_base = huella_local` y limpia `kt_cambios` hasta `max_cambio`: el siguiente envío vuelve a
  ser un reemplazo limpio. Tras una **fusión** el PC no puede saber el estado exacto de la nube: los
  cambios siguen apareciendo como pendientes y la pantalla avisa que se pida un paquete nuevo. Reenviar no
  duplica, pero las ediciones hechas después sobre documentos ya fusionados solo se informan (no se
  aplican): lo correcto es **volver a la nube** o pedir un `.ktdata` nuevo.

### Bloqueo y concurrencia
`kt_aplicar_sync` toma `flock` exclusivo sobre `clients/<c>/.kt_sync.lock`: dos sincronizaciones del
mismo cliente no corren a la vez. Al aplicar desde el panel se exige que el modo siga siendo el que el
administrador revisó (si la nube cambió entre revisar y aplicar, se pide revisar de nuevo).

## Restaurar
`kt_restaurar_respaldo` respalda primero el estado actual (`…_antes_restaurar`) y luego reemplaza la
base con el mismo procedimiento seguro. Los PDFs agregados quedan en disco (no estorban).

## Qué NO sincroniza (límites conocidos)
| Qué | Por qué / qué hacer |
|---|---|
| Contraseña cambiada en el PC | `central.db` no viaja (seguridad). Cambiarla en la nube si hace falta. |
| Logo y página pública | Viven en la nube; no se editan en local. |
| PDFs de documentos borrados en local | En reemplazo quedan huérfanos en la nube (inofensivo). |
| En fusión: ediciones, borrados, configuración de extracción, tipos de documento, log de actividad | Se listan como conflicto o se omiten; revisar a mano. |
| Dos PCs del mismo cliente en paralelo | Cada uno sincroniza por separado; el segundo será fusión (no puede pisar al primero). Mejor un solo PC. |
| Escrituras en la nube durante los milisegundos entre la última comprobación y el `rename` | Ventana mínima; el respaldo previo permite volver atrás. Lo correcto es no usar la nube mientras el cliente está en local. |
