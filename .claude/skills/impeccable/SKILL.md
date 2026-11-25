---
name: impeccable
description: Pulido de interfaz de KINO TRACE (PHP + CSS + JS sin framework). Úsala al crear o cambiar cualquier pantalla, módulo, visor de PDF, Buscador público o panel admin, o cuando pidan "pulir", "revisar diseño", "se ve mal", "responsive" o "accesibilidad".
---

# Impeccable — interfaz sin defectos en KINO TRACE

Objetivo: que cada pantalla se vea **consistente, clara, rápida y usable en celular**, sin inventar un estilo nuevo por página. KINO TRACE no tiene framework de frontend: PHP dibuja el HTML, el estilo vive en `assets/css/styles.css` y el JS va embebido en cada página.

## Cuándo usarla

- Antes de tocar HTML/CSS/JS de `modules/`, `includes/`, `Admin-gestor/`, `modules/Buscador/` o los visores (`modules/resaltar/viewer.php`, `modules/Buscador/viewer_publico.php`).
- Para auditar una pantalla existente ("pulir", "se ve mal", "no se ve en el celular").
- El **escritor** la sigue al implementar; el **orquestador** la usa para revisar el diff.

## 1. Usar lo que ya existe (no inventar)

- **Colores, radios, sombras, fuentes y transiciones: solo variables de `:root`** en `assets/css/styles.css` (`--accent-primary`, `--text-secondary`, `--border-color`, `--radius-md`, `--shadow-sm`, `--transition-fast`…). Nada de hex sueltos nuevos; si falta un color, se agrega como variable en `:root`.
- **Componentes:** `includes/components.php` (`render_button`, `render_badge`, `render_form_group`, `render_input`, `render_select`, `render_table`, `render_empty_state`, `render_loading`, `render_tabs`, `render_card_header`, `render_stat_card`) y las clases `.card`, `.btn-primary/.btn-secondary/.btn-danger`, `.form-input/.form-select/.form-textarea`, `.table-container/.table`.
- **Diálogos:** SweetAlert2 local (`assets/vendor/`), nunca `alert()`/`confirm()` nativos en pantallas nuevas. **Sin CDN** (PDF.js y SweetAlert2 viven en `assets/vendor/`).
- **Menos `style="..."` en línea.** Si se repite en 2+ lugares, va a una clase en `styles.css`. Módulos con más estilos en línea hoy: `importar_datos`, `lote`, `importar`, `Buscador` (limpiarlos cuando se toquen, no en un PR aparte sin pedirlo).

## 2. Jerarquía y lenguaje

- Una acción principal por pantalla (`.btn-primary`); el resto secundarias. Acciones que borran: `.btn-danger` + confirmación SweetAlert que diga **qué** se borra.
- Textos en español simple, para el operador de aduanas: "Subir PDF", no "Ejecutar upload". Mensajes de error que digan qué pasó y qué hacer ("El PDF pesa más de 20 MB. Divídelo y súbelo de nuevo").
- Códigos y números de documento en `render_code_tag()` / fuente mono, **tal como los escribió el cliente** (no normalizarlos al mostrar).
- Estados siempre cubiertos: **cargando** (`render_loading`), **vacío** (`render_empty_state` con siguiente paso), **error** (mensaje + reintento) y **éxito**.
- Resultados de búsqueda: mantener "Por qué aparece" (`motivoBox()`), "Faltan" en rojo solo para lo que de verdad falta, y las notas de coincidencias aproximadas en gris discreto (reglas del resaltado en `CLAUDE.md`).

## 3. Celular y responsive (lo revisa el CI)

- Toda página con `<meta name="viewport" content="width=device-width, initial-scale=1">`.
- `.main-content` con `min-width: 0`; tablas anchas dentro de `.table-container` (`overflow-x: auto`). Nada se sale de lado en 390 px.
- Casillas (`input`, `select`, `textarea`) a **16 px** en ≤768 px (si no, iPhone hace zoom).
- Botones y enlaces tocables de al menos **44×44 px** en celular.
- Listados largos **siempre paginados**; nunca dibujar todos los códigos.
- Visores PDF: hoja ajustada al ancho (máx. escala 1.5), canvas × `devicePixelRatio`, resaltados con el tamaño **CSS** del canvas; panel lateral no `sticky` en celular.

## 4. Accesibilidad (WCAG 2.2 AA)

- Cada casilla con `<label for>` o `aria-label` (axe lo hace fallar en CI si falta).
- Contraste 4.5:1 para texto: usar `--text-primary`/`--text-secondary`, no grises más claros que `--text-muted` sobre blanco.
- Foco visible (no quitar `outline` sin reemplazo), orden de tabulación lógico, botones de solo ícono con `aria-label` o `title`.
- No comunicar solo con color: "Faltan" lleva texto, no solo rojo.

## 5. Rendimiento percibido

- Respuesta inmediata al clic (botón deshabilitado + "Procesando…") en subidas, OCR, indexado y PDF unificado.
- Procesos largos con avance real (`partial`/`done`, como `modules/indexar/`), nunca "completado" sin confirmación del servidor.
- Validar < 500 KB y < 3 s (lo mide `tests/ux/chequeo_ux.js`). No cargar librerías pesadas en pantallas que no las usan.

## 6. Multi-cliente y seguridad en la interfaz

- El branding (logo, nombre) sale de `central.db`; nunca dejar nombres de un cliente escritos en el código.
- PDFs solo por `kt_pdf_url()`; nunca enlaces `clients/{codigo}/uploads/...`.
- Escapar todo lo que se imprime (`htmlspecialchars`) y en JS usar `textContent`, no `innerHTML` con datos del usuario.
- Funciones apagadas por cliente (`KT_FUNCIONES`) también se ocultan en el menú.

## Cómo auditar una pantalla (checklist de entrega)

1. Abrirla en computador y en iPhone 13 (Playwright, `tests/ux/chequeo_ux.js` o captura propia) y mirar: se sale de lado, textos cortados, botones pequeños, estados vacíos/error.
2. Revisar el diff contra las secciones 1–6: variables en vez de hex, componentes existentes, labels, 16 px, paginado, escapado.
3. Correr: `php -l` en lo tocado, `php tools/chequeo.php` y, si cambió una pantalla, `node tests/ux/chequeo_ux.js` (con `KT_AXE` para accesibilidad).
4. Reportar en español breve: qué se pulió, capturas antes/después si es visual, y lo que quedó pendiente.

## Qué NO hacer

- No rediseñar todo de una vez ni cambiar la paleta sin que el usuario lo pida.
- No agregar frameworks (Tailwind, Bootstrap, React) ni fuentes/CDN externos.
- No efectos decorativos que no ayudan (gradientes llamativos, animaciones largas, emojis de relleno).
- No tocar `clients/` ni datos de clientes para "probar cómo se ve": usar la plantilla `kino` de `database_initial/`.
