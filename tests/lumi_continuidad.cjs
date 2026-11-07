/* Se ejecuta contra el PHP temporal de lumi_gemini_http.py, nunca la aplicación/base real. */
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const {chromium} = require(path.join(process.env.CVS_BROWSER_TOOLS, 'node_modules/playwright'));
const url = process.env.CVS_LUMI_TEST_URL;
assert.match(url || '', /^http:\/\/127\.0\.0\.1:\d+\/$/);
(async () => {
    const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    const browser = await chromium.launch(fs.existsSync(edge) ? {executablePath: edge, headless: true} : {headless: true});
    const errores = [];
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({viewport: {width, height: 900}});
            await context.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
            const page = await context.newPage();
            page.on('pageerror', error => errores.push(error.message));
            const estado = async () => (await page.request.get(url + 'lumi_api.php?accion=estado')).json();
            const metricas = async () => (await page.request.get(url + 'metricas.php')).json();
            const lista = () => page.locator('#lumi-mensajes li');
            const esperarCantidad = async n => {
                await page.waitForFunction(n => document.querySelectorAll('#lumi-mensajes li').length === n
                    && !document.getElementById('lumi-nueva').disabled, n);
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            };
            const enviar = async (texto, cantidad) => {
                await page.locator('#lumi-entrada').fill(texto);
                await page.locator('#lumi-envio button[type="submit"]').click();
                await esperarCantidad(cantidad);
            };
            const comprobarAbierta = async () => {
                await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiAbierto === 'true');
                assert.equal(await page.locator('[id="lumi-botón"]').getAttribute('aria-expanded'), 'true');
            };
            await page.goto(url + 'login.php?rol=paciente&escenario=completa&cuenta=501');
            await page.goto(url + 'index.php');
            await esperarCantidad(1);
            await page.locator('[id="lumi-botón"]').click();
            await enviar('Prueba general guardada', 3);
            assert.equal((await metricas()).llamadas, 1);
            await enviar('Cómo reservar una cita', 5);
            const anterior = await lista().allTextContents();
            await Promise.all([
                page.waitForURL('**/reservar_cita.php'),
                page.locator('.lumi-destinos a[href$="destino=reservar"]').click(),
            ]);
            await comprobarAbierta(); await esperarCantidad(5);
            assert.deepEqual(await lista().allTextContents(), anterior);
            assert.equal((await metricas()).llamadas, 1, 'Navegar no vuelve a enviar preguntas a Gemini');
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios + lumiVozPrueba.frases.length), 0, 'No reanuda voz automáticamente');
            const saludo = (await estado()).saludo;
            assert.equal(await lista().filter({hasText: saludo}).count(), 1);
            await page.locator('#lumi-cerrar').click();
            await page.locator('[id="lumi-botón"]').click();
            assert.deepEqual(await lista().allTextContents(), anterior, 'Cerrar no borra');
            await enviar('Ya elegí especialidad y médico, ¿qué hago después?', 7);
            assert.match(await lista().last().textContent(), /selecciona la fecha y un bloque/);
            assert.equal((await metricas()).llamadas, 1, 'Seguimiento local sin Gemini');
            await page.evaluate(() => lumiVozPrueba.cargarVoces());
            await page.locator('.lumi-leer').last().click();
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-estado'), 'hablando');
            await enviar('Abrir mis pagos', 9);
            await page.locator('.lumi-leer').last().click();
            const navegacion = page.waitForURL('**/pagos.php');
            const cancelaciones = await page.locator('.lumi-destinos a[href$="destino=pagos"]').evaluate(el => {
                el.click(); return lumiVozPrueba.cancelaciones;
            });
            assert.ok(cancelaciones > 0, 'La navegación detiene el audio');
            await navegacion; await comprobarAbierta(); await esperarCantidad(9);
            await enviar('¿Y ahora?', 11);
            assert.match(await lista().last().textContent(), /Mis reservas y pagos/);
            const posicion = await page.locator('#lumi-mensajes').evaluate(el => {
                el.scrollTop = 37; el.dispatchEvent(new Event('scroll')); return el.scrollTop;
            });
            const almacen = await page.evaluate(() => Object.entries(sessionStorage).filter(([k]) => k.startsWith('cvs-lumi-vista:')).map(([,v]) => JSON.parse(v)));
            assert.equal(almacen.length, 1);
            assert.deepEqual(Object.keys(almacen[0]).sort(), ['abierto', 'dialogo', 'scroll']);
            assert.equal(typeof almacen[0].abierto, 'boolean');
            await page.reload(); await comprobarAbierta(); await esperarCantidad(11);
            assert.ok(Math.abs(await page.locator('#lumi-mensajes').evaluate(el => el.scrollTop) - posicion) <= 2, 'Restauración del desplazamiento');
            assert.equal((await metricas()).llamadas, 1);
            page.once('dialog', dialogo => dialogo.dismiss());
            await page.locator('#lumi-nueva').click();
            assert.equal(await lista().count(), 11, 'Cancelar reinicio conserva todo');
            page.once('dialog', dialogo => dialogo.accept());
            await page.locator('#lumi-nueva').click(); await esperarCantidad(1);
            assert.equal((await estado()).conversacion.mensajes.length, 1);
            assert.equal((await metricas()).llamadas, 1);
            await enviar('Prueba general nueva', 3);
            await enviar('Continuación general ficticia', 5);
            const consulta = await metricas();
            assert.equal(consulta.llamadas, 3);
            assert.equal(consulta.contexto.length, 3, 'Contexto anterior del nuevo hilo más nueva consulta');
            assert.equal(consulta.contexto[0].parts[0].text, 'Prueba general nueva');
            assert.ok(!JSON.stringify(consulta.contexto).includes('Prueba general guardada'));
            await page.locator('#lumi-cerrar').click();
            await page.reload(); await esperarCantidad(5);
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-abierto'), 'false', 'Recargar respeta cierre manual');
            await page.locator('[id="lumi-botón"]').click();
            await page.request.get(url + 'login.php?rol=administrador&escenario=completa&cuenta=502');
            await page.goto(url + 'index.php'); await esperarCantidad(1);
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-abierto'), 'false');
            assert.ok(!(await lista().allTextContents()).join('').includes('Prueba general nueva'));
            await page.locator('[id="lumi-botón"]').click();
            await enviar('Abrir usuarios', 3);
            const enlaceAdmin = await page.locator('.lumi-destinos a[href$="destino=usuarios"]').getAttribute('href');
            await enviar('Abrir Lista de espera', 5);
            const antesEspera = await lista().allTextContents();
            const llamadasEspera = (await metricas()).llamadas;
            await Promise.all([page.waitForURL('**/lista_espera.php'), page.locator('.lumi-destinos a[href$="destino=lista_espera"]').click()]);
            await comprobarAbierta(); await esperarCantidad(5);
            assert.deepEqual(await lista().allTextContents(), antesEspera, 'Lista de espera conserva todo el historial');
            await enviar('¿Y ahora?', 7);
            assert.match(await lista().last().textContent(), /Lista de espera/);
            assert.equal((await metricas()).llamadas, llamadasEspera, 'Nueva sección y seguimiento sin llamadas duplicadas a Gemini');
            await Promise.all([page.waitForURL('**/login.php'), page.locator('form[action="logout.php"] button').click()]);
            assert.equal(await page.locator('#lumi-raiz').count(), 0);
            assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter(k => k.startsWith('cvs-lumi-vista:')).length), 0);
            assert.equal((await page.request.get(url + 'lumi_api.php?accion=estado')).status(), 401);
            await page.goto(url + 'login.php?rol=paciente&escenario=completa&cuenta=501');
            await page.goto(url + 'index.php'); await esperarCantidad(1);
            assert.equal((await page.request.get(url + enlaceAdmin, {maxRedirects: 0})).status(), 403, 'Permisos de enlaces antiguos se revalidan');
            // Página directa, sin enlace previo de Lumi; módulo ausente simulado en servidor.
            await page.goto(url + 'login.php?rol=paciente&escenario=completa&cuenta=501&pagina=pendiente');
            await page.goto(url + 'reservar_cita.php'); await esperarCantidad(1);
            await page.locator('[id="lumi-botón"]').click();
            await enviar('Ya elegí especialidad y médico, ¿qué hago después?', 3);
            assert.match(await lista().last().textContent(), /pendiente de instalación/);
            assert.match(await lista().last().textContent(), /No es una restricción de tu cuenta/);
            assert.doesNotMatch(await lista().last().textContent(), /selecciona la fecha/);
            await page.reload(); await comprobarAbierta(); await esperarCantidad(3);
            assert.match(await lista().last().textContent(), /pendiente de instalación/);
            console.log(`OK continuidad ${width}px: navegación, apertura, scroll, cierre, recarga, saludo único, contexto, reinicio confirmado, cambio de cuenta/rol y logout`);
            await context.close();
        }
        assert.deepEqual(errores, []);
        console.log('OK cero errores JavaScript; PHP real con autenticación, pantallas y transporte Gemini ficticios. Sin bases ni proveedor externos.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
