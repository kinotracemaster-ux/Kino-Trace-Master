// Chequeo de UX en un navegador de verdad (celular y computador), sin tocar clients/ del repo:
//   npm install --no-save playwright@1.63.0 && npx playwright install chromium   (una vez)
//   node tests/ux/chequeo_ux.js
//
// Copia la app a una carpeta temporal (arranca con la plantilla database_initial/: cliente
// "kino" con 121 documentos y sus PDFs), levanta `php -S` y revisa que:
//  - ninguna pantalla se salga de lado en el celular (scroll horizontal de la página);
//  - ninguna casilla haga zoom al tocarla en iPhone (letra < 16px);
//  - ninguna pantalla tenga errores de JavaScript;
//  - "Validar" abra rápido y liviana (antes: 47 MB y el navegador colgado);
//  - de la búsqueda por código al PDF resaltado: el resaltado aparece, cae sobre el código
//    y la hoja cabe en la pantalla (antes en el celular se cortaba por los dos lados);
//  - el botón CSV de "Consultar" descargue un CSV;
//  - la clave maestra (12+ caracteres) se pueda escribir en el inicio de sesión.
// Sale con código 1 si algo falla. Con KT_CDN_LOCAL=carpeta sirve pdf.js desde esa carpeta
// (para entornos sin salida a internet; en GitHub Actions no hace falta).
const { chromium, devices } = require('playwright');
const { execSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const raiz = path.resolve(__dirname, '..', '..');
let fallos = 0;
const ok = (c, msg) => { console.log((c ? 'OK    ' : 'FALLA ') + msg); if (!c) fallos++; };

// --- 1. Copia de la app y cliente "kino" con clave conocida
const app = fs.mkdtempSync(path.join(os.tmpdir(), 'kt_ux_'));
execSync(`tar --exclude=./.git --exclude=./clients --exclude=./node_modules -cf - . | tar -xf - -C ${JSON.stringify(app)}`, { cwd: raiz, shell: '/bin/bash' });
fs.writeFileSync(path.join(app, '_setup.php'), `<?php
chdir($argv[1]);
require 'config.php';
require 'helpers/tenant.php';
$centralDb->prepare('UPDATE control_clientes SET password_hash = ?, activo = 1 WHERE codigo = ?')
    ->execute([password_hash('clave-kino', PASSWORD_DEFAULT), 'kino']);
echo (int) open_client_db('kino')->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
`);
const docs = parseInt(execSync(`php ${JSON.stringify(path.join(app, '_setup.php'))} ${JSON.stringify(app)}`).toString(), 10);
fs.unlinkSync(path.join(app, '_setup.php'));
ok(docs > 50, `cliente kino de la plantilla con ${docs} documentos`);

// --- 2. Servidor
const puerto = 19000 + Math.floor(Math.random() * 900);
const BASE = `http://127.0.0.1:${puerto}`;
const srv = spawn('php', ['-S', `127.0.0.1:${puerto}`, '-t', app], {
    cwd: app, stdio: 'ignore', env: { ...process.env, ADMIN_SECRET: 'clave-maestra-de-prueba-123' },
});
const cerrar = () => { try { srv.kill(); } catch (e) { } fs.rmSync(app, { recursive: true, force: true }); };

const PAGINAS = [
    ['login', '/login.php', null],
    ['inicio (búsqueda voraz)', '/index.php', 'cliente'],
    ['consultar', '/index.php#consultar', 'cliente'],
    ['búsqueda por código', '/index.php#codigo', 'cliente'],
    ['visor resaltado', '/modules/resaltar/viewer.php?doc=4&term=1103G', 'cliente'],
    ['subir', '/modules/subir/', 'cliente'],
    ['lote', '/modules/lote/', 'cliente'],
    ['vincular', '/modules/trazabilidad/vincular.php', 'cliente'],
    ['validar', '/modules/trazabilidad/validar.php', 'cliente'],
    ['tablero trazabilidad', '/modules/trazabilidad/dashboard.php', 'cliente'],
    ['importar excel', '/modules/excel_import/', 'cliente'],
    ['buscador público', '/modules/Buscador/?cliente=kino', null],
    ['panel admin', '/Admin-gestor/panel.php', 'admin'],
    ['respaldos', '/Admin-gestor/respaldos.php', 'admin'],
];
const VISTAS = { celular: devices['iPhone 13'], computador: { viewport: { width: 1366, height: 800 } } };

async function contexto(browser, opts, quien) {
    const ctx = await browser.newContext({ ...opts, deviceScaleFactor: 1 });
    if (process.env.KT_CDN_LOCAL) {
        await ctx.route('**/cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/*', r => r.fulfill({
            path: path.join(process.env.KT_CDN_LOCAL, path.basename(new URL(r.request().url()).pathname)),
            contentType: 'application/javascript',
        }));
    }
    const p = await ctx.newPage();
    if (quien === 'cliente') {
        await p.goto(BASE + '/login.php');
        await p.fill('#codigo', 'kino');
        await p.fill('#password', 'clave-kino');
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    } else if (quien === 'admin') {
        await p.goto(BASE + '/Admin-gestor/login.php');
        await p.fill('input[name=admin_secret]', 'clave-maestra-de-prueba-123');
        await Promise.all([p.waitForNavigation(), p.press('input[name=admin_secret]', 'Enter')]);
    }
    await p.close();
    return ctx;
}

(async () => {
    for (let i = 0; i < 50; i++) {
        try { await fetch(BASE + '/login.php'); break; } catch (e) { await new Promise(r => setTimeout(r, 100)); }
    }
    const browser = await chromium.launch();
    try {
        // --- 3. Cada pantalla en celular y computador
        for (const [vista, opts] of Object.entries(VISTAS)) {
            const ctxs = {};
            for (const q of [null, 'cliente', 'admin']) ctxs[q] = await contexto(browser, opts, q);
            for (const [nombre, ruta, quien] of PAGINAS) {
                const p = await ctxs[quien].newPage();
                const errores = [];
                p.on('pageerror', e => errores.push(e.message.slice(0, 120)));
                const [url, seccion] = ruta.split('#');
                const t0 = Date.now();
                const resp = await p.goto(BASE + url, { waitUntil: 'load', timeout: 20000 }).catch(e => null);
                const ms = Date.now() - t0;
                if (seccion) await p.evaluate(s => window.switchSection && window.switchSection(s), seccion);
                await p.waitForTimeout(ruta.includes('viewer') ? 2500 : 600);
                const m = await p.evaluate(() => {
                    const W = document.documentElement.clientWidth;
                    const zoom = [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),textarea,select')]
                        .filter(e => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.id || e.tagName);
                    return { desborde: document.documentElement.scrollWidth - W, zoom };
                });
                const etiqueta = `${vista.padEnd(10)} ${nombre}`;
                ok(resp && resp.status() === 200, `${etiqueta}: abre (${resp ? resp.status() : 'sin respuesta'}, ${ms} ms)`);
                ok(m.desborde <= 1, `${etiqueta}: no se sale de lado${m.desborde > 1 ? ` (sobran ${m.desborde}px)` : ''}`);
                if (vista === 'celular') ok(m.zoom.length === 0, `${etiqueta}: casillas sin zoom en iPhone${m.zoom.length ? ' (' + m.zoom.slice(0, 4).join(', ') + ')' : ''}`);
                ok(errores.length === 0, `${etiqueta}: sin errores de JavaScript${errores.length ? ': ' + errores.join(' | ') : ''}`);
                if (nombre === 'validar') ok(ms < 3000, `${etiqueta}: abre en menos de 3 s (${ms} ms)`);
                await p.close();
            }
            for (const c of Object.values(ctxs)) await c.close();
        }

        // --- 4. Validar: liviana aunque el cliente tenga decenas de miles de códigos
        const ctx = await contexto(browser, VISTAS.computador, 'cliente');
        const r = await ctx.request.get(BASE + '/modules/trazabilidad/validar.php');
        const kb = (await r.body()).length / 1024;
        ok(kb < 500, `validar pesa ${Math.round(kb)} KB (antes 47 MB con Kino)`);
        const filtro = await (await ctx.request.get(BASE + '/modules/trazabilidad/validar.php?estado=pendientes&q=1103')).text();
        ok(filtro.includes('1103G') && !filtro.includes('YM-3797'), 'validar filtra por código');

        // --- 5. CSV de "Consultar"
        const csv = await ctx.request.get(BASE + '/api.php?action=export_csv');
        const txt = (await csv.text()).replace(/^﻿/, '');
        ok(csv.status() === 200 && (csv.headers()['content-type'] || '').includes('text/csv') && txt.startsWith('Tipo;Documento;Fecha'),
            'el botón CSV descarga los documentos en CSV');
        await ctx.close();

        // --- 6. De la búsqueda por código al PDF resaltado
        for (const [vista, opts] of Object.entries(VISTAS)) {
            const c = await contexto(browser, opts, 'cliente');
            const p = await c.newPage();
            await p.goto(BASE + '/index.php');
            const t0 = Date.now();
            await p.evaluate(() => switchSection('codigo'));
            await p.fill('#singleCodeInput', '1103G');
            await p.press('#singleCodeInput', 'Enter');
            await p.waitForSelector('#singleCodeList .result-card');
            const [visor] = await Promise.all([c.waitForEvent('page'), p.locator('#singleCodeList button').first().click()]);
            const hl = await visor.waitForSelector('.ocr-highlight', { timeout: 20000 }).catch(() => null);
            const ms = Date.now() - t0;
            ok(!!hl, `${vista}: el código aparece resaltado en el PDF (${ms} ms desde la búsqueda)`);
            if (hl) {
                await visor.waitForTimeout(1500); // termina el desplazamiento suave al resaltado
                const v = await visor.evaluate(() => {
                    const h = document.querySelector('.ocr-highlight');
                    const r = h.getBoundingClientRect();
                    const hoja = h.closest('.pdf-page-wrapper').getBoundingClientRect();
                    const encima = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                    return {
                        cabe: hoja.left >= 0 && hoja.right <= document.documentElement.clientWidth + 1,
                        tapado: !!(encima && encima.closest('.viewer-sidebar')),
                        enPantalla: r.top >= 0 && r.bottom <= innerHeight,
                    };
                });
                ok(v.cabe, `${vista}: la hoja del PDF cabe a lo ancho de la pantalla`);
                ok(v.enPantalla && !v.tapado, `${vista}: el resaltado queda a la vista (no lo tapa el panel)`);
                // el resaltado cae sobre el código: mismo lugar que el texto del PDF
                const caja = await visor.evaluate(async () => {
                    const res = await findInTextLayer(2, ['1103G']);
                    return res.highlights[0] || null;
                });
                ok(caja && Math.abs(caja.x - 76.8) < 2 && Math.abs(caja.y - 587.2) < 3, 'la caja del resaltado coincide con la posición del código en el PDF (pdftotext -bbox)');
            }
            await c.close();
        }

        // --- 7. La clave maestra se puede escribir completa en el inicio de sesión
        const c = await browser.newContext();
        const p = await c.newPage();
        await p.goto(BASE + '/login.php');
        const max = await p.evaluate(() => { const i = document.querySelector('input[name=admin_secret]'); return i ? i.maxLength : -2; });
        ok(max === -1 || max >= 64, `la casilla de la clave maestra admite claves largas (maxlength ${max})`);
        await c.close();
    } catch (e) {
        ok(false, 'error inesperado: ' + e.message);
    } finally {
        await browser.close();
        cerrar();
    }
    console.log(fallos ? `\n${fallos} FALLAS` : '\nTodo OK');
    process.exit(fallos ? 1 : 0);
})();
