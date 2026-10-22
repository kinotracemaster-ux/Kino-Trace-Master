# Despliegue en PC — armar, instalar y resolver problemas

## Requisitos

**PC del cliente (Windows 10/11, 64 bits):** nada instalado previamente. ~200 MB + el tamaño de sus
PDFs. Sin permisos de administrador. Microsoft Edge (viene con Windows) para abrir en "modo app";
si no está, usa el navegador predeterminado.
OCR opcional: [Tesseract para Windows (UB Mannheim)](https://github.com/UB-Mannheim/tesseract/wiki)
con el idioma **Spanish** marcado al instalar; el lanzador lo detecta en `C:\Program Files\Tesseract-OCR`.

**PC del administrador (quien arma paquetes):**
- Windows: nada (el script `construir.ps1` baja un PHP portable verificado), o
- Linux/Mac: PHP 8.2+ con `sodium`, `zip`, `pdo_sqlite`, `openssl` (los paquetes de PHP de las
  distribuciones los traen) y salida a internet hacia `windows.php.net`, `api.github.com`,
  `github.com`, `curl.se`.

## Armar el paquete de un cliente

### Opción A — PC Windows del administrador
```powershell
cd C:\ruta\Kino-Trace-Master
# una sola vez:
powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Comando llaves
# cada paquete:
powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Datos C:\descargas\losmonta_20261022.ktdata
# licencia cuando el cliente envíe su código:
powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Comando licencia -Cliente losmonta -Equipo KT-1A2B-3C4D-5E6F-7A8B -Dias 30
```
El paquete queda en `.\paquetes\KinoTrace_losmonta_AAAAMMDD.zip`.

### Opción B — Linux / Mac
```bash
php local/herramientas/kt.php llaves                      # una sola vez
php local/herramientas/kt.php paquete --datos losmonta_20261022.ktdata --salida ./paquetes
php local/herramientas/kt.php licencia --cliente losmonta --equipo KT-1A2B-3C4D-5E6F-7A8B --dias 30
```
El paquete es **para Windows** aunque se arme en Linux/Mac (descarga los binarios de Windows).

### ¿De dónde sale el `.ktdata`?
| Situación | Comando |
|---|---|
| Nube funcionando | Gestor → 💻 Modo local → cliente → **Descargar .ktdata** |
| Copia de la carpeta `clients/` (p. ej. bajada del volumen) | `php kt.php exportar --clients ./clients --cliente losmonta` |
| Solo un zip del botón **Backup** (hecho después del arreglo) | `php kt.php exportar --respaldo backup_losmonta.zip --cliente losmonta --nombre "Los Montañeros"` |

### Qué hace `kt.php paquete` (verificable en el código)
1. Copia la app con **lista blanca**: `api.php, autoload.php, config.php, index.php, index_tabs.php,
   login.php, logout.php, forgot_password.php, reset_password.php` + carpetas `assets, helpers, includes,
   modules, pwa, src, vendor, local`. Excluye `Admin-gestor/`, `database_initial/`, `backups/`, `docs/`,
   `tests/`, scripts de Railway/migración, `sync_recibir.php`, `local/herramientas`, `local/construir`,
   y todo `*.md, *.sql, *.csv, *.zip, *.db, *.log`.
2. Crea `app/local/MODO_LOCAL` y copia `clave_publica.txt` (solo la pública).
3. Extrae los datos del `.ktdata` en `app/clients/` validando cada ruta.
4. Descarga **PHP para Windows x64 NTS** (versión 8.3/8.2 desde `releases.json`, **verifica SHA-256**),
   **Poppler** (último release de `oschwartz10612/poppler-windows`, verifica SHA-256 si GitHub lo publica)
   y **cacert.pem** (verifica su `.sha256`). Se guardan en caché (`--cache`).
   Sin internet: `--php-zip`, `--poppler-zip`, `--cacert` con archivos bajados a mano.
5. Escribe `php.ini` (solo extensiones presentes), `INICIAR KINO TRACE.bat`, `CREAR ACCESO DIRECTO.bat`,
   `LEAME.txt` (con saltos de línea de Windows).
6. Comprime todo en `KinoTrace_<cliente>_<fecha>.zip`.

Opciones: `--nube https://…` (dirección por defecto para sincronizar), `--forzar` (rehacer),
`--sin-zip`, `--sin-runtime` (solo pruebas en Linux).

## Instalar en el PC del cliente
1. Descomprimir el zip en `C:\KinoTrace` (o `Documentos\KinoTrace`). **No** en `C:\Program Files`
   (Windows bloquea escrituras ahí y la base no podría guardarse).
2. Doble clic en `INICIAR KINO TRACE.bat`. Windows puede mostrar "Windows protegió su PC"
   (SmartScreen, porque el .bat no está firmado): *Más información → Ejecutar de todas formas*.
3. Activar (ver PROCESO.md §1).

### Qué hace el lanzador
- Agrega al `PATH` (solo para esa ventana) `runtime\poppler\bin`, `runtime\php` y `Tesseract-OCR`.
- Busca un puerto libre entre 8765 y 8799 (`runtime\puerto.php`).
- Abre `http://127.0.0.1:<puerto>/` en Edge modo app tras 2 segundos.
- Corre `php -S 127.0.0.1:<puerto> -t app app\local\router.php` en primer plano con rutas absolutas
  para extensiones, certificados, sesiones (`runtime\sesiones`) y log (`runtime\logs\php_errores.log`).

## Prueba local en Linux (desarrollo)
```bash
php local/herramientas/kt.php paquete --datos kino.ktdata --salida /tmp/p --sin-runtime --sin-zip
cd /tmp/p/KinoTrace_kino/app && php -S 127.0.0.1:8781 -t . local/router.php
# abrir http://127.0.0.1:8781 → activar con: php kt.php licencia --cliente kino --equipo <código mostrado>
```

## Solución de problemas

| Síntoma | Causa / solución |
|---|---|
| La ventana negra se cierra al instante | Paquete incompleto: volver a descomprimir **todo** el zip. Revisar `runtime\logs\php_errores.log`. |
| "Esta licencia es de otro equipo" | Se copió la carpeta a otro PC o se cambió la placa. Enviar el código nuevo a Kino. |
| "La fecha del equipo está atrasada" | Se atrasó el reloj. Corregir fecha/hora de Windows. |
| "No se pudo guardar la licencia" | Carpeta de solo lectura (Program Files, USB protegido). Mover a `C:\KinoTrace`. |
| No abre el navegador | Abrir a mano la dirección que muestra la ventana negra. |
| Indexar deja PDFs "con error" | Escaneados sin Tesseract: instalar Tesseract (Spanish) y reiniciar el programa; luego Indexar → forzar. |
| "No se pudo conectar con la nube" al sincronizar | Sin internet o nube caída: usar *Descargar paquete*. Si dice error de certificado, falta `runtime\cacert.pem` (rearmar paquete). |
| Lento con muchos usuarios | `php -S` atiende de a una petición; es para 1 usuario por PC. |
| Antivirus bloquea `php.exe` | Agregar excepción para la carpeta `C:\KinoTrace\runtime`. |
