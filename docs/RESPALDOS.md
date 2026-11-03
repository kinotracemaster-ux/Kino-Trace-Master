# Respaldos de KINO TRACE

Todo se respalda solo. Esta guía explica qué se guarda, cómo restaurar un cliente y cómo
activar la copia fuera del servidor.

## Qué pasa solo

- **Cada día** (cada 20 horas como mínimo) se respalda `central.db` (usuarios, marca y
  subdominios) y cada cliente: su base y sus archivos (PDFs, logo).
- **Cada semana** se prueba que cada respaldo se pueda restaurar: se abre en una carpeta
  temporal, se revisa que la base esté sana, se cuentan documentos y códigos y se comprueba
  que cada PDF que la base menciona tenga copia. Nunca toca los datos reales.
- Lo lanza el contenedor de Railway (`/start.sh` en el `Dockerfile`): cada hora corre
  `php tools/respaldo.php auto`, que decide si toca algo. El registro queda en
  `clients/logs/respaldo.log`.

## Dónde se guarda

En `clients/_respaldos/` (dentro del volumen, bloqueado para la web):

```
central/                         bases de central.db, comprimidas
clientes/{codigo}/bd/            bases del cliente, comprimidas (.db.gz)
clientes/{codigo}/archivos/      espejo de la carpeta del cliente
estado.json                      último respaldo y última prueba
```

- Las bases se copian con `VACUUM INTO`: la copia es consistente aunque alguien esté usando la app.
- Se guardan **todas las bases de los últimos 14 días** y luego **una por semana hasta 8 semanas**.
- El espejo de archivos **nunca borra**: si alguien borra un PDF por error, sigue en el espejo.
  En el mismo disco usa enlaces duros, así que **no ocupa espacio extra**.

## Restaurar un cliente

Panel admin → **🛟 Respaldos** → en la fila del cliente elige el respaldo, escribe el código
del cliente para confirmar y pulsa **Restaurar**.

1. Primero se guarda el estado actual como `antes_restaurar` (para deshacer, restaura ese).
2. Se revisa que el respaldo esté sano; si está dañado no se toca nada.
3. Se reemplaza la base y se reponen los archivos que falten (no pisa ni borra los que están).
4. Los demás clientes no se tocan.

Por terminal (Railway → servicio → Shell):

```bash
php tools/respaldo.php listar <cliente>
php tools/respaldo.php restaurar <cliente> <AAAAMMDD_HHMMSS_etiqueta.db.gz>
php tools/respaldo.php ahora      # respaldar todo ya
php tools/respaldo.php probar     # prueba de restauración
```

## Copia fuera del servidor (recomendado)

Sin esto, si se pierde el volumen de Railway se pierden también los respaldos. Con Cloudflare R2
(ya usan Cloudflare para el dominio; los primeros 10 GB son gratis):

1. Cloudflare → **R2** → **Create bucket**, por ejemplo `kino-trace-respaldos`.
2. R2 → **Manage API tokens** → **Create API token** con permiso *Object Read & Write* solo para
   ese bucket. Anota *Access Key ID*, *Secret Access Key* y el *endpoint* (`https://<cuenta>.r2.cloudflarestorage.com`).
3. En Railway → servicio de la app → **Variables**, agrega:

| Variable | Valor |
|---|---|
| `KT_S3_ENDPOINT` | el endpoint de R2 |
| `KT_S3_BUCKET` | `kino-trace-respaldos` |
| `KT_S3_KEY` | Access Key ID |
| `KT_S3_SECRET` | Secret Access Key |

Desde el siguiente respaldo, todo lo nuevo sube solo (solo lo que no esté ya en la nube).
El panel de respaldos muestra si la copia está al día.

### Si se pierde el volumen

1. Crear el volumen nuevo en `/var/www/html/clients` y desplegar.
2. Shell de Railway: `php tools/respaldo.php desde-nube` (baja todo a `clients/_respaldos/`).
3. Copiar el `central.db` más reciente: `gunzip -c clients/_respaldos/central/<último>.db.gz > clients/central.db`.
4. Restaurar cada cliente desde el panel o con `php tools/respaldo.php restaurar ...`.

## Comprobar que funciona

```bash
php tests/respaldo/chequeo_respaldo.php
```

Arma clientes de prueba en una carpeta temporal, respalda, simula un desastre (base vaciada y PDFs
borrados), restaura y mide cuánto tarda. No toca `clients/`.
