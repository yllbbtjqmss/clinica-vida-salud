/* Prueba independiente: HTML PHP real, identidades ficticias y endpoint de estado interceptado.
 * No usa bases, cuentas reales, pruebas de pagos ni servicios externos. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const tools = process.env.CVS_BROWSER_TOOLS;
assert.ok(tools, 'Definir CVS_BROWSER_TOOLS con la carpeta externa de Playwright');
const {chromium} = require(path.join(tools, 'node_modules/playwright'));
const php = process.env.CVS_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const render = (rol, modo = '') => execFileSync(php, [path.join(__dirname, 'lumi_visual_fixture.php'), rol, modo], {
    encoding: 'utf8', env: {...process.env, CVS_LUMI_VISUAL_TEST: '1'},
});
const config = JSON.parse(render('config'));
const paginas = Object.fromEntries(['paciente', 'administrador', 'medico', 'recepcionista', 'anonimo'].map(rol => ['/' + rol, render(rol)]));
const assets = new Map([
    ['/assets/lumi.css', ['text/css', 'assets/lumi.css']],
    ['/assets/lumi.js', ['text/javascript', 'assets/lumi.js']],
    ['/assets/lumi-voz.js', ['text/javascript', 'assets/lumi-voz.js']],
    ['/assets/estilos.css', ['text/css', 'assets/estilos.css']],
]);
const server = http.createServer((req, res) => {
    const ruta = new URL(req.url, 'http://localhost').pathname;
    if (paginas[ruta]) {
        res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        return res.end(paginas[ruta]);
    }
    if (assets.has(ruta)) {
        const [tipo, archivo] = assets.get(ruta);
        res.writeHead(200, {'Content-Type': tipo + '; charset=utf-8'});
        return res.end(fs.readFileSync(path.join(root, archivo)));
    }
    res.writeHead(404); res.end();
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    let browser;
    const errores = [];
    try {
        browser = await chromium.launch(fs.existsSync(edge) ? {executablePath: edge, headless: true} : {headless: true});
        for (const rol of ['paciente', 'administrador']) {
            for (const [width, height] of [[1440, 1000], [390, 844], [360, 640], [640, 360]]) {
                const context = await browser.newContext({viewport: {width, height}});
                const page = await context.newPage();
                await page.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
                page.on('pageerror', error => errores.push(error.message));
                page.on('console', msg => { if (msg.type() === 'error') errores.push(msg.text()); });
                let solicitudes = 0, liberar;
                const respuesta = new Promise(resolve => { liberar = resolve; });
                await page.route('**/lumi_api.php?accion=estado', async route => {
                    solicitudes++;
                    await respuesta;
                    await route.fulfill({json: {...config, csrf: 'csrf-fixture-visual', habilitada: false, localDisponible: true, maxMensaje: 700, maxHistorial: 8}});
                });
                await page.goto(url + '/' + rol);
                assert.equal(await page.locator('#lumi-raiz').count(), 1);
                const boton = page.locator('[id="lumi-botón"]');
                const panel = page.locator('#lumi-panel');
                const comprobar = async abierto => {
                    assert.equal(await boton.getAttribute('aria-expanded'), String(abierto));
                    await page.waitForFunction(abierto => {
                        const p = document.getElementById('lumi-panel'), s = getComputedStyle(p);
                        return s.visibility === (abierto ? 'visible' : 'hidden') && Number(s.opacity) === (abierto ? 1 : 0)
                            && s.pointerEvents === (abierto ? 'auto' : 'none') && p.inert === !abierto;
                    }, abierto);
                    assert.equal(await panel.getAttribute('style'), null, 'Sin estilos inline para forzar visibilidad');
                };
                await comprobar(false);
                await boton.focus();
                await page.keyboard.press('Enter');
                await comprobar(true);
                // Cerrar y volver a abrir mientras el endpoint aún está pendiente.
                await boton.click();
                await comprobar(false);
                liberar();
                await page.waitForFunction(() => document.querySelectorAll('#lumi-mensajes li').length === 1);
                await comprobar(false); // La respuesta tardía no reabre el panel.
                for (let i = 0; i < 4; i++) {
                    await boton.click();
                    await comprobar(true);
                    assert.equal(await page.locator('#lumi-mensajes li').count(), 1);
                    assert.equal(await page.locator('#lumi-mensajes li').textContent(), config.saludo);
                    assert.equal(await page.locator('#lumi-entrada').evaluate(el => el === document.activeElement), true);
                    if (i % 2) await page.locator('#lumi-cerrar').click();
                    else await page.keyboard.press('Escape');
                    await comprobar(false);
                    assert.equal(await boton.evaluate(el => el === document.activeElement), true);
                }
                // Una segunda carga accidental de JS no debe instalar eventos ni duplicar saludos.
                await page.addScriptTag({url: url + '/assets/lumi.js'});
                await boton.click();
                await comprobar(true);
                assert.equal(solicitudes, 1);
                assert.equal(await page.locator('#lumi-mensajes li').count(), 1);
                assert.ok(await page.locator('#lumi-voz-consentimiento').isVisible(), 'Autorización inicial accesible, sin escucha automática');
                assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
                assert.ok(await page.locator('#lumi-voz-seleccion').isHidden(), 'Ajustes de voz dentro del apartado discreto');
                assert.equal(await page.locator('#lumi-activacion').count(), 0);
                const decoracion = await page.locator('.lumi-orbe').evaluateAll(orbes => orbes.every(el => {
                    const s = getComputedStyle(el), p = getComputedStyle(el, '::before');
                    return s.position === 'relative' && s.overflow === 'hidden' && el.offsetWidth <= 56
                        && parseFloat(p.width) <= el.clientWidth && parseFloat(p.height) <= el.clientHeight
                        && p.animationName === 'lumi-pulso';
                }));
                assert.ok(decoracion, 'La animación debe seguir activa y recortada dentro de cada esfera');
                assert.ok(await panel.evaluate(el => {
                    const r = el.getBoundingClientRect();
                    return r.left >= 0 && r.top >= 0 && r.right <= innerWidth && r.bottom <= innerHeight;
                }), 'Panel dentro del viewport');
                await page.locator('#lumi-envio').scrollIntoViewIfNeeded();
                assert.ok(await page.locator('#lumi-envio').evaluate(el => {
                    const r = el.getBoundingClientRect(), p = document.getElementById('lumi-panel').getBoundingClientRect();
                    return r.top >= p.top && r.bottom <= p.bottom;
                }), 'Formulario visible incluso en móvil horizontal');
                await page.emulateMedia({reducedMotion: 'reduce'});
                assert.equal(await page.locator('.lumi-orbe').first().evaluate(el => getComputedStyle(el, '::before').animationName), 'none');
                await context.close();
                console.log(`OK ${rol} ${width}x${height}: aperturas/cierres, saludo único, foco, CSS, esfera y movimiento reducido`);
            }
        }
        // Rama habilitada con respuestas de prueba interceptadas, nunca API externa.
        for (const rol of ['paciente', 'administrador']) {
            const page = await browser.newPage({viewport: {width: 390, height: 844}});
            page.on('pageerror', error => errores.push(error.message));
            const enviados = [];
            let modo = 'ok';
            await page.route('**/lumi_api.php?accion=estado', route => route.fulfill({json: {
                ...config, habilitada: true, csrf: 'csrf-solo-fixture', maxMensaje: 700,
                maxHistorial: 2, maxHistorialCaracteres: 160, privacidad: 'Texto de privacidad simulado a efectos de maquetación.',
            }}));
            await page.route('**/lumi_api.php?accion=mensaje', async route => {
                const datos = route.request().postDataJSON();
                enviados.push(datos);
                assert.equal(datos.csrf, 'csrf-solo-fixture');
                assert.equal('historial' in datos, false, 'El contexto ahora lo administra la sesión PHP');
                if (modo === 'error') return route.fulfill({status: 503, json: {estado: 'error', aviso: 'Cuota de Gemini alcanzada (prueba).'}});
                if (modo === 'apagado') return route.fulfill({json: {habilitada: false, aviso: 'Gemini deshabilitado (prueba).'}});
                return route.fulfill({json: {estado: 'ok', texto: 'Respuesta ficticia para probar el chat.'}});
            });
            await page.goto(url + '/' + rol);
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            assert.match(await page.locator('#lumi-privacidad-texto').textContent(), /privacidad simulado/);
            assert.equal(await page.locator('#lumi-aviso').isHidden(), true, 'Sin avisos permanentes con el chat disponible');
            assert.doesNotMatch(await page.locator('#lumi-panel').innerText(), /Pruebas locales con Gemini:|Modo local:|Asistente virtual · orientación general, no reemplaza/);
            assert.equal(await page.locator('.lumi-voz-nota').getAttribute('open'), null);
            await page.locator('.lumi-voz-nota summary').click();
            assert.equal(await page.locator('#lumi-privacidad-texto').isVisible(), true);
            await page.locator('#lumi-envio').scrollIntoViewIfNeeded();
            assert.ok(await page.locator('#lumi-envio').evaluate(el => {
                const r = el.getBoundingClientRect(), p = document.getElementById('lumi-panel').getBoundingClientRect();
                return r.top >= p.top && r.bottom <= p.bottom;
            }), 'Privacidad desplegada no oculta el formulario');
            await page.locator('.lumi-voz-nota summary').click();
            for (let i = 0; i < 6; i++) {
                await page.locator('#lumi-entrada').fill('Consulta ficticia ' + i);
                await page.locator('#lumi-envio button[type="submit"]').click();
                await page.waitForFunction(n => document.querySelectorAll('#lumi-mensajes li').length === n
                    && !document.querySelector('#lumi-envio button[type="submit"]').disabled, 3 + 2 * i);
            }
            assert.equal('historial' in enviados[0], false);
            assert.equal('historial' in enviados.at(-1), false);
            modo = 'error';
            await page.locator('#lumi-entrada').fill('Otra prueba');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.getByText('Cuota de Gemini alcanzada (prueba).', {exact: true}).waitFor();
            modo = 'apagado';
            await page.waitForFunction(() => !document.querySelector('#lumi-envio button[type="submit"]').disabled);
            await page.locator('#lumi-entrada').fill('Una prueba más');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.waitForFunction(() => document.getElementById('lumi-entrada').disabled);
            assert.equal(await page.locator('#lumi-mensajes li').filter({hasText: config.saludo}).count(), 1);
            console.log(`OK ${rol}: entrada habilitada, mensajes simulados, historial limitado, errores y desactivación`);
            await page.close();
        }
        // Navegación de cuatro roles: voz simulada, catálogo PHP real y sin redirección automática.
        for (const rol of ['paciente', 'administrador', 'medico', 'recepcionista']) {
            const page = await browser.newPage({viewport: {width: 390, height: 844}});
            page.on('pageerror', error => errores.push(error.message));
            await page.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
            const funciones = JSON.parse(render(rol, 'funciones'));
            const enviados = [];
            let navegaciones = 0;
            await page.route('**/lumi_api.php?accion=estado', route => route.fulfill({json: {
                ...config, habilitada: true, csrf: 'csrf-fixture', funciones,
            }}));
            await page.route('**/lumi_api.php?accion=mensaje', route => {
                enviados.push(route.request().postDataJSON());
                const guia = enviados.length === 1;
                return route.fulfill({json: {
                    estado: 'ok', origen: guia ? 'guia' : 'gemini',
                    texto: guia ? 'Elige la sección que quieras abrir. No he cambiado ningún registro.' : 'Respuesta ficticia de conversación general.',
                    navegacion: [...funciones, {id: 'javascript:alert(1)', titulo: 'No mostrar'}, {id: 'no_permitido', titulo: 'No mostrar'}],
                }});
            });
            await page.route('**/lumi_api.php?accion=ir&destino=*', route => {
                navegaciones++;
                return route.fulfill({contentType: 'text/html; charset=utf-8', body: '<h1>Destino de prueba por clic explícito</h1>'});
            });
            await page.goto(url + '/' + rol);
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(() => !document.getElementById('lumi-microfono').disabled);
            await page.locator('#lumi-microfono').click();
            await page.evaluate(() => { lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado('Abrir funciones'); });
            await page.locator('#lumi-microfono').click();
            assert.equal(enviados.length, 0, 'Dictado no envía ni navega por sí solo');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.locator('.lumi-destinos a').first().waitFor();
            assert.equal(await page.locator('.lumi-destinos a').count(), funciones.length);
            assert.equal(navegaciones, 0);
            for (const enlace of await page.locator('.lumi-destinos a').all()) {
                assert.match(await enlace.getAttribute('href'), /^lumi_api\.php\?accion=ir&destino=[a-z_]+$/);
            }
            assert.equal(await page.getByText('No mostrar', {exact: true}).count(), 0);
            await page.evaluate(() => lumiVozPrueba.cargarVoces());
            await page.locator('.lumi-leer').click();
            assert.equal(await page.evaluate(() => lumiVozPrueba.frases.at(-1).text), 'Elige la sección que quieras abrir. No he cambiado ningún registro.');
            await page.locator('#lumi-entrada').fill('Conversación general ficticia');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.getByText('Respuesta ficticia de conversación general.', {exact: true}).waitFor();
            assert.equal('historial' in enviados[1], false, 'El navegador no reenvía guías ni historiales');
            assert.equal(await page.locator('#lumi-mensajes li').last().locator('.lumi-destinos').count(), 0, 'Gemini no crea botones');
            assert.equal(navegaciones, 0);
            const enlace = page.locator('.lumi-destinos a').first();
            await enlace.focus();
            await page.keyboard.press('Enter');
            await page.getByRole('heading', {name: 'Destino de prueba por clic explícito'}).waitFor();
            assert.equal(navegaciones, 1);
            console.log(`OK navegación ${rol}: catálogo filtrado, dictado/altavoz, sin enlaces de IA, sin navegación automática y activación por teclado`);
            await page.close();
        }
        const anonimo = await browser.newPage();
        await anonimo.goto(url + '/anonimo');
        assert.equal(await anonimo.locator('#lumi-raiz').count(), 0);
        const sinServicio = await browser.newPage();
        await sinServicio.route('**/lumi_api.php?accion=estado', route => route.fulfill({json: {
            ...config, habilitada: false, localDisponible: false, aviso: 'Servicio temporalmente no disponible.',
        }}));
        await sinServicio.goto(url + '/paciente');
        await sinServicio.locator('[id="lumi-botón"]').click();
        await sinServicio.getByText('Servicio temporalmente no disponible.', {exact: true}).waitFor();
        assert.ok(await sinServicio.locator('#lumi-entrada').isDisabled(), 'El error relevante no se oculta');
        await sinServicio.close();
        // Una respuesta pendiente de la cuenta anterior no puede volver a poblar el chat tras salir.
        const tardia = await browser.newPage();
        await tardia.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
        let liberarRespuesta, peticionRecibida;
        const espera = new Promise(resolve => { liberarRespuesta = resolve; });
        const recibida = new Promise(resolve => { peticionRecibida = resolve; });
        await tardia.route('**/lumi_api.php?accion=estado', route => route.fulfill({json: {...config, habilitada: true, csrf: 'csrf-tardia'}}));
        await tardia.route('**/lumi_api.php?accion=mensaje', async route => {
            peticionRecibida();
            await espera;
            await route.fulfill({json: {estado: 'ok', texto: 'Respuesta de sesión anterior que no debe reaparecer.'}});
        });
        await tardia.goto(url + '/paciente');
        await tardia.locator('[id="lumi-botón"]').click();
        await tardia.locator('#lumi-entrada').fill('Consulta ficticia pendiente');
        await tardia.locator('#lumi-envio button[type="submit"]').click();
        await recibida;
        await tardia.evaluate(() => {
            const canal = new BroadcastChannel('cvs-lumi-sesion');
            canal.postMessage('salida');
            canal.close();
        });
        await tardia.waitForFunction(() => document.querySelectorAll('#lumi-mensajes li').length === 0);
        const completada = tardia.waitForResponse(r => r.url().includes('accion=mensaje'));
        liberarRespuesta();
        await completada;
        await tardia.waitForTimeout(100);
        assert.equal(await tardia.locator('#lumi-mensajes li').count(), 0, 'Una respuesta tardía no restaura datos de otra sesión');
        assert.ok(await tardia.locator('#lumi-entrada').isDisabled());
        assert.equal(await tardia.evaluate(() => lumiVozPrueba.activos.size), 0);
        await tardia.close();
        assert.deepEqual(errores, [], 'Sin errores de JavaScript/consola');
        console.log('OK renderizado anónimo sin widget; sin errores JavaScript. Identidades ficticias: no prueba de login real ni de Gemini.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
