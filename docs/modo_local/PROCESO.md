# Proceso operativo — modo local

Quién hace qué, en orden. **Kino** = administrador (tiene la llave privada).
**Cliente** = la empresa que trabaja en su PC.

---

## 0. Preparación (una sola vez, hacerlo YA, con la nube funcionando)

1. **Crear las llaves de licencia** en el PC del administrador:
   ```
   php local/herramientas/kt.php llaves
   ```
   (en Windows sin PHP: `powershell -ExecutionPolicy Bypass -File local\construir\construir.ps1 -Comando llaves`)
   - Quedan en `%USERPROFILE%\.kinotrace\` (Windows) o `~/.kinotrace/` (Linux/Mac).
   - `llave_privada.txt` es el secreto que permite emitir licencias: **copia en un USB guardado**;
     nunca en GitHub, Railway, Drive compartido ni chat. Si se pierde, hay que rehacer llaves y
     re-licenciar a todos.
2. **Respaldos periódicos de cada cliente** (lo que salva una caída futura): en
   `Gestor → 💻 Modo local → Exportar .ktdata` para cada cliente, al menos semanal, guardados fuera
   de Railway (disco/Drive privado de Kino). Con eso, en la próxima caída se arma el paquete en minutos.
   > Hoy (2026-10-22) los datos más recientes están dentro del volumen de Railway. Mientras la nube
   > esté caída, la única fuente son respaldos previos (los `.zip` del botón Backup hechos **antes**
   > de este arreglo **no traen la base de datos**, solo PDFs).
3. **Configurar en Railway** (ver SEGURIDAD.md): `ADMIN_SECRET` largo y aleatorio, rotar todos los
   secretos tras el incidente.

---

## 1. Caída de la nube → el cliente pasa a trabajar en su PC

| # | Quién | Acción |
|---|---|---|
| 1 | Kino | Obtiene el `.ktdata` del cliente: el último exportado (paso 0.2) o, si solo hay un zip de **Backup**, lo convierte: `php kt.php exportar --respaldo backup.zip --cliente losmonta --nombre "Los Montañeros"` (pide una contraseña para el PC). |
| 2 | Kino | Arma el paquete Windows: `construir.ps1 -Datos losmonta_….ktdata` (o `php kt.php paquete --datos …`). Sale `KinoTrace_losmonta_AAAAMMDD.zip`. |
| 3 | Kino | Entrega el zip por canal **privado** (Drive compartido solo con el correo del cliente, USB). Contiene sus datos. |
| 4 | Cliente | Descomprime en `C:\KinoTrace` (no en "Archivos de programa") y abre **INICIAR KINO TRACE.bat**. |
| 5 | Cliente | Aparece la pantalla **Activar** con un código `KT-XXXX-XXXX-XXXX-XXXX`. Lo envía a Kino (WhatsApp/correo). |
| 6 | Kino | `php kt.php licencia --cliente losmonta --equipo KT-XXXX-… --dias 30` → devuelve el texto `KTL1.…`. |
| 7 | Cliente | Pega la licencia → **Activar** → entra con **su mismo código y contraseña** de la nube (o la definida en el paso 1 si vino de un Backup). |
| 8 | Cliente | Trabaja normal: subir, buscar, consultar, indexar. Opcional: **CREAR ACCESO DIRECTO.bat**. |

Tiempo típico: 10–20 min (la mayor parte es la descarga del paquete).

---

## 2. Mientras trabaja en local

- Cada alta/cambio/baja queda anotada (tabla `kt_cambios`); el menú **Sincronizar con la nube**
  muestra cuántos cambios hay pendientes.
- **Copia de seguridad diaria**: cerrar el programa y copiar la carpeta `C:\KinoTrace` completa a un USB.
- La ventana negra debe quedar abierta; cerrarla apaga el programa (los datos ya están guardados).
- Licencia por vencer: Kino emite otra con el mismo código de equipo (paso 1.6) y el cliente la pega
  en `http://127.0.0.1:…/local/activar.php` (o al vencer, aparece sola).
- Si la nube vuelve **y** alguien trabaja también allá, no pasa nada grave: la sincronización
  detecta el caso y fusiona (ver SINCRONIZACION.md). Aun así, lo recomendable es **un solo lugar
  de trabajo a la vez**.

---

## 3. La nube vuelve → sincronizar

**Camino A (recomendado) — el cliente sube solo:**
1. Menú **Sincronizar con la nube** → *Opción 1* → dirección `https://kino-trace.com` y su contraseña.
2. La nube respalda la base, aplica y responde:
   - *"la nube quedó igual a la copia de su equipo"* → **reemplazo** (la nube no cambió mientras tanto).
   - *"se agregaron los documentos nuevos"* → **fusión** (la nube también cambió); si hay conflictos
     se listan para revisarlos a mano en la nube. Después de una fusión, **no seguir trabajando en esa
     copia**: volver a la nube o pedir un `.ktdata` nuevo (las ediciones posteriores no se aplicarían).
   - Se puede sincronizar varias veces (por ejemplo al final de cada día): mientras nadie trabaje en la
     nube, cada envío es un reemplazo, aunque un envío anterior se haya hecho a mano (Camino B).
   - 5 contraseñas equivocadas seguidas bloquean el envío de ese cliente por 15 minutos.

**Camino B — sin internet o la nube rechaza el envío:**
1. *Opción 2 — Descargar paquete* → archivo `sync_losmonta_….ktsync`.
2. Se lo envía a Kino por canal privado.
3. Kino: `Gestor → 💻 Modo local → cliente → Subir y revisar` → lee el resultado → **Aplicar**.
   (Sin la web: `php kt.php aplicar --clients <carpeta> --paquete archivo.ktsync`.)

**Verificación (Kino):** en el panel, *Historial* muestra fecha, modo, quién, conteos y el respaldo
creado. Si algo salió mal: *Respaldos automáticos → Restaurar*.

---

## 4. Volver a la nube y retirar la copia local

1. Confirmar en el Historial que la última sincronización quedó aplicada.
2. El cliente vuelve a entrar por `https://kino-trace.com` (o su subdominio).
3. La copia local se puede guardar como respaldo, pero **no seguir usándola** para trabajo nuevo
   (si se usa, sus cambios solo llegan con otra sincronización).
4. Dejar que la licencia venza (o emitirla corta desde el principio: `--dias 15`).

---

## Preguntas rápidas

- **¿Varias personas del cliente a la vez?** Cada PC necesita su propia licencia. El servidor local
  atiende a una persona cómodamente; para varios, lo correcto es la nube.
- **¿Se puede abrir desde otro PC de la oficina?** No, a propósito: solo responde en el propio equipo.
- **¿Olvidó la contraseña en el PC?** La recuperación por correo no funciona sin la nube. Soporte
  remoto: `php kt.php clave --app C:\KinoTrace\app --cliente losmonta` (cambia solo la local).
- **¿Funciona la IA (Gemini)?** No: el paquete no lleva claves de API (a propósito).
- **¿Funciona el OCR?** Sí si el PC tiene Tesseract instalado (ver DESPLIEGUE_PC.md). Sin él, los PDF
  con texto se indexan igual; los escaneados quedan "con error" y, ya en la nube, se reintentan con
  el módulo **Indexar → forzar**.
