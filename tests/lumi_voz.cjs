/* Pruebas de voz aisladas: APIs del navegador y Gemini simulados, sin BD ni red externa. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
assert.ok(process.env.CVS_BROWSER_TOOLS, 'Definir CVS_BROWSER_TOOLS con Playwright externo');
const {chromium} = require(path.join(process.env.CVS_BROWSER_TOOLS, 'node_modules/playwright'));
const render = rol => execFileSync(process.env.CVS_TEST_PHP || 'C:\\xampp\\php\\php.exe',
    [path.join(__dirname, 'lumi_visual_fixture.php'), rol], {encoding: 'utf8', env: {...process.env, CVS_LUMI_VISUAL_TEST: '1'}});
const config = JSON.parse(render('config'));
const paginas = Object.fromEntries(['paciente', 'administrador'].map(rol => ['/' + rol, render(rol)]));
const tipos = {'assets/lumi.js': 'text/javascript', 'assets/lumi-voz.js': 'text/javascript',
    'assets/lumi.css': 'text/css', 'assets/estilos.css': 'text/css'};
const server = http.createServer((req, res) => {
    const ruta = new URL(req.url, 'http://localhost').pathname;
    if (paginas[ruta]) { res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}); return res.end(paginas[ruta]); }
    const archivo = ruta.slice(1);
    if (tipos[archivo]) { res.writeHead(200, {'Content-Type': tipos[archivo]}); return res.end(fs.readFileSync(path.join(root, archivo))); }
    res.writeHead(204); res.end();
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}`;
    const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    let browser;
    const errores = [];
    try {
        browser = await chromium.launch(fs.existsSync(edge) ? {executablePath: edge, headless: true} : {headless: true});
        const preparar = async (rol, viewport, sinReconocimiento = false, sinSintesis = false) => {
            const page = await browser.newPage({viewport});
            page.on('pageerror', error => errores.push(error.message));
            await page.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
            if (sinReconocimiento) await page.addInitScript(() => Object.defineProperty(window, 'SpeechRecognition', {value: undefined}));
            if (sinSintesis) await page.addInitScript(() => Object.defineProperty(window, 'speechSynthesis', {value: undefined}));
            const enviados = [];
            await page.route('**/lumi_api.php?accion=estado', route => route.fulfill({json: {
                ...config, habilitada: true, csrf: 'solo-prueba', maxMensaje: 700, maxHistorial: 8,
            }}));
            await page.route('**/lumi_api.php?accion=mensaje', route => {
                enviados.push(route.request().postDataJSON());
                return route.fulfill({json: {estado: 'ok', texto: 'Respuesta ficticia para la prueba de voz.'}});
            });
            await page.goto(url + '/' + rol);
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            return {page, enviados};
        };
        for (const rol of ['paciente', 'administrador']) for (const width of [1440, 390]) {
            const {page, enviados} = await preparar(rol, {width, height: 900});
            const micro = page.locator('#lumi-microfono'), entrada = page.locator('#lumi-entrada');
            const enviar = page.locator('#lumi-envio button[type="submit"]');
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0, 'Nunca escucha al abrir');
            await entrada.fill('Borrador');
            await micro.focus(); await page.keyboard.press('Space');
            assert.equal(await micro.getAttribute('aria-pressed'), 'true');
            assert.ok(await enviar.isDisabled());
            assert.equal(await page.evaluate(() => lumiVozPrueba.actual.lang), 'es-BO');
            await page.evaluate(() => { lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado('consulta ficticia', false); });
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-estado'), 'escuchando');
            assert.equal(await entrada.inputValue(), 'Borrador consulta ficticia');
            await page.evaluate(() => lumiVozPrueba.actual.resultado('consulta ficticia', true));
            assert.equal(await entrada.inputValue(), 'Borrador consulta ficticia', 'Resultados provisionales no se duplican');
            await micro.click();
            assert.equal(await micro.getAttribute('aria-pressed'), 'false');
            assert.equal(enviados.length, 0, 'Nunca envía automáticamente');
            await entrada.fill('Consulta ficticia revisada');
            await enviar.click();
            await page.locator('.lumi-leer').waitFor();
            assert.equal(enviados[0].mensaje, 'Consulta ficticia revisada');
            assert.equal(await page.evaluate(() => lumiVozPrueba.frases.length), 0, 'Lectura solo por acción del usuario');
            await page.evaluate(() => lumiVozPrueba.cargarVoces());
            const leer = page.locator('.lumi-leer');
            await leer.click();
            assert.equal(await page.evaluate(() => lumiVozPrueba.frases.at(-1).voice.voiceURI), 'bo');
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-estado'), 'hablando');
            assert.ok(await micro.isDisabled());
            if (rol === 'paciente') {
                const capturas = path.join(process.env.CVS_BROWSER_TOOLS, 'capturas');
                fs.mkdirSync(capturas, {recursive: true});
                await page.screenshot({path: path.join(capturas, `lumi-voz-${width}.png`), animations: 'disabled'});
            }
            const antes = await page.evaluate(() => lumiVozPrueba.inicios);
            await micro.dispatchEvent('click');
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), antes, 'No se escucha a sí misma');
            await page.locator('#lumi-voz-detener').click();
            assert.ok(await micro.isEnabled());
            assert.equal(await leer.getAttribute('aria-pressed'), 'false');
            await page.locator('.lumi-voz-nota summary').click();
            await page.locator('#lumi-voz-seleccion').selectOption('mx');
            await page.locator('.lumi-voz-nota summary').click();
            await leer.click();
            assert.equal(await page.evaluate(() => lumiVozPrueba.frases.at(-1).voice.voiceURI), 'mx');
            await page.evaluate(() => speechSynthesis.terminar());
            assert.ok(await page.locator('#lumi-voz-detener').isDisabled());
            await micro.click();
            const frases = await page.evaluate(() => lumiVozPrueba.frases.length);
            await leer.dispatchEvent('click');
            assert.equal(await page.evaluate(() => lumiVozPrueba.frases.length), frases, 'No reproduce durante dictado');
            await page.locator('#lumi-cerrar').click();
            await page.evaluate(() => { lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado('Evento tardío'); });
            assert.equal(await entrada.inputValue(), '', 'Eventos tardíos no cambian el borrador tras cerrar');
            assert.ok(await page.evaluate(() => lumiVozPrueba.abortos > 0));
            await page.locator('[id="lumi-botón"]').click();
            await leer.click();
            const cancelaciones = await page.evaluate(() => lumiVozPrueba.cancelaciones);
            await page.locator('#lumi-cerrar').click();
            assert.ok(await page.evaluate(n => lumiVozPrueba.cancelaciones > n, cancelaciones));
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-estado'), 'silencio');
            await page.locator('[id="lumi-botón"]').click();
            for (const codigo of ['not-allowed', 'network', 'audio-capture', 'no-speech', 'language-not-supported']) {
                await micro.click();
                await page.evaluate(codigo => lumiVozPrueba.actual.error(codigo), codigo);
                assert.equal(await micro.getAttribute('aria-pressed'), 'false');
                assert.ok(await enviar.isEnabled());
                assert.equal(await entrada.evaluate(el => el.readOnly), false);
            }
            await page.evaluate(() => { lumiVozPrueba.lanzarErrorInicio = true; });
            await micro.click();
            assert.match(await page.locator('#lumi-voz-estado').textContent(), /No se pudo iniciar/);
            await page.evaluate(() => { lumiVozPrueba.lanzarErrorInicio = false; });
            await micro.click();
            await page.evaluate(() => lumiVozPrueba.actual.resultado('a'.repeat(800)));
            await micro.click();
            assert.equal((await entrada.inputValue()).length, 700);
            assert.match(await page.locator('#lumi-voz-estado').textContent(), /recortado/);
            assert.equal(enviados.length, 1);
            await leer.click();
            await page.evaluate(() => speechSynthesis.error());
            assert.match(await page.locator('#lumi-voz-estado').textContent(), /No se pudo reproducir/);
            assert.ok(await micro.isEnabled());
            await page.locator('.lumi-voz-nota summary').click();
            await page.locator('#lumi-voz-seleccion').selectOption('');
            await page.locator('.lumi-voz-nota summary').click();
            await page.evaluate(() => lumiVozPrueba.cargarVoces([{name: 'Alternativa', lang: 'en-US', voiceURI: 'en', default: true}]));
            await leer.click();
            assert.match(await page.locator('#lumi-voz-estado').textContent(), /voz española/);
            await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
            assert.ok(await page.locator('#lumi-voz-detener').isDisabled());
            assert.ok(await page.locator('#lumi-raiz').evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            console.log(`OK voz ${rol} ${width}px: dictado revisable, reproducción, idioma, exclusión mutua, cierre, errores y límites`);
            await page.close();
        }
        for (const [sinReconocimiento, sinSintesis] of [[true, false], [false, true]]) {
            const {page, enviados} = await preparar('paciente', {width: 390, height: 844}, sinReconocimiento, sinSintesis);
            assert.match(await page.locator('#lumi-voz-estado').textContent(), /no disponible/i);
            await page.locator('#lumi-entrada').fill('Consulta escrita ficticia');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.waitForFunction(() => document.querySelectorAll('#lumi-mensajes li').length === 3);
            assert.equal(enviados.length, 1, 'Chat escrito funciona sin API de voz');
            if (sinReconocimiento) {
                assert.ok(await page.locator('#lumi-microfono').isDisabled());
                assert.ok(await page.locator('#lumi-voz-permitir').isDisabled());
                assert.ok(await page.locator('#lumi-voz-consentimiento').isHidden());
            }
            if (sinSintesis) assert.equal(await page.locator('.lumi-leer').count(), 0);
            await page.close();
        }
        assert.deepEqual(errores, []);
        console.log('OK degradación sin reconocimiento/síntesis y cero errores JS. Audio, permisos físicos y calidad de voz no probados: se usaron mocks.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
