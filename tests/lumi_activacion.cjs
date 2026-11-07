/* Exclusivamente el PHP temporal de lumi_gemini_http.py: sin micrófono ni síntesis reales. */
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
        for (const rol of ['administrador', 'recepcionista', 'medico', 'paciente']) for (const ancho of [1440, 390]) {
            const context = await browser.newContext({viewport: {width: ancho, height: 900}});
            await context.addInitScript({path: path.join(__dirname, 'lumi_voz_mock.js')});
            await context.addInitScript(() => {
                window.lumiOcultaFicticia = null;
                const ocultaReal = Object.getOwnPropertyDescriptor(Document.prototype, 'hidden').get;
                Object.defineProperty(document, 'hidden', {get: () => window.lumiOcultaFicticia ?? ocultaReal.call(document), configurable: true});
            });
            const page = await context.newPage();
            page.on('pageerror', e => errores.push(e.message));
            let envios = 0;
            page.on('request', req => { if (req.url().includes('accion=mensaje')) envios++; });
            const escenario = ancho === 1440 ? 'completa' : 'apagada';
            const paginaInicial = {administrador: 'index.php', recepcionista: 'index.php', medico: 'mi_agenda.php', paciente: 'mis_citas.php'}[rol];
            await page.goto(url + 'login.php?rol=' + rol + '&escenario=' + escenario);
            await page.goto(url + paginaInicial);
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            const autorizar = page.locator('#lumi-voz-autorizar');
            const detalles = page.locator('.lumi-voz-nota summary');
            const reactivar = page.locator('#lumi-voz-reactivar');
            const esperarEscucha = () => page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            assert.equal(await page.locator('#lumi-raiz').count(), 1, 'Widget compartido para todos los roles');
            assert.equal(await page.locator('#lumi-activacion').count(), 0, 'Sin checkbox técnico');
            assert.equal(await page.locator('#lumi-activacion-ayuda').count(), 0);
            assert.equal(await page.locator('#lumi-voz-consentimiento').isVisible(), true, 'Invitación inicial clara, sin abrir el micrófono');
            assert.equal(await autorizar.isEnabled(), true);
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
            assert.equal(await page.locator('#lumi-aviso').isHidden(), true, 'Ni modo local ni Gemini muestran pancartas permanentes');
            assert.equal(await page.locator('.lumi-voz-nota').getAttribute('open'), null, 'Privacidad discreta, no eliminada');
            assert.doesNotMatch(await page.locator('#lumi-panel').innerText(), /Modo local:|Pruebas locales con Gemini:|Asistente virtual · orientación general, no reemplaza/);
            await page.locator('.lumi-voz-nota summary').click();
            const privacidad = await page.locator('#lumi-privacidad-texto').innerText();
            assert.match(privacidad, escenario === 'completa' ? /se envían a Google Gemini/ : /no está disponible/);
            assert.match(privacidad, /mensajes ficticios/);
            await page.locator('.lumi-voz-nota summary').click();
            // Conservar una conversación real de la sesión ficticia, no solo el saludo.
            await page.locator('#lumi-entrada').fill('Qué especialidades existen');
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.waitForFunction(() => document.querySelectorAll('#lumi-mensajes li').length === 3 && !document.getElementById('lumi-nueva').disabled);
            const saludos = () => page.locator('#lumi-mensajes li', {hasText: /Hola! Soy Lumi/}).count();
            const iniciales = await page.locator('#lumi-mensajes li').count();
            const funciones = (await (await page.request.get(url + 'lumi_api.php?accion=estado')).json()).funciones.map(f => f.id);
            for (const href of await page.locator('.lumi-destinos a').evaluateAll(enlaces => enlaces.map(e => e.getAttribute('href')))) {
                assert.ok(funciones.includes(new URL(href, url).searchParams.get('destino')), 'Solo enlaces autorizados por el rol');
            }
            if (rol !== 'administrador') assert.equal((await page.request.get(url + 'lumi_api.php?accion=ir&destino=usuarios')).status(), 403);
            await autorizar.click();
            assert.ok(await page.evaluate(() => lumiVozPrueba.inicios > 0), 'El clic solicita permiso inicialmente, sin esperar a otro gesto');
            await page.waitForFunction(() => lumiVozPrueba.activos.size === 0);
            assert.equal(await page.locator('#lumi-voz-consentimiento').isHidden(), true);
            await page.locator('#lumi-cerrar').click();
            await page.waitForFunction(() => window.lumiVozPrueba.inicios >= 1, undefined, {timeout: 5000});
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha', undefined, {timeout: 5000});
            const dictar = async texto => {
                await page.evaluate(t => { lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado(t, true); }, texto);
                await page.waitForTimeout(300);
            };
            // Frases que no deben activar nada.
            await dictar('¿Qué especialidades de cardiología hay en la clínica?');
            await dictar('hola');
            await dictar('hola luzmi');
            await dictar('hey lumi');
            assert.equal(await page.evaluate(() => document.getElementById('lumi-raiz').dataset.lumiAbierto), 'false',
                'Otras conversaciones no abren el panel');
            assert.equal(await saludos(), 1, 'Sin saludos duplicados');
            // Frases de activación: abrir por voz sin enviar consulta ni duplicar saludo.
            await dictar('Hola, Lumi');
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiAbierto === 'true', undefined, {timeout: 5000});
            assert.equal(await page.locator('#lumi-mensajes li').count(), iniciales, 'Abrir por voz no agrega mensajes');
            assert.equal(await saludos(), 1);
            assert.equal(envios, 1, 'La activación no envía mensajes a Gemini ni al catálogo');
            // El panel abierto suspende la detección; sin dos sistemas de reconocimiento.
            const estadoDespertar = await page.evaluate(() => document.getElementById('lumi-raiz').dataset.lumiDespertar);
            assert.notEqual(estadoDespertar, 'escucha', 'Sin doble escucha con el panel abierto');
            // El dictado normal sigue funcionando después de la activación por voz.
            await page.click('#lumi-microfono');
            await page.waitForFunction(() => document.getElementById('lumi-microfono').getAttribute('aria-pressed') === 'true');
            await page.evaluate(() => { lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado('ya dictando', false); });
            assert.equal(await page.locator('#lumi-entrada').inputValue(), 'ya dictando');
            await page.locator('#lumi-microfono').click();
            await page.waitForFunction(() => document.getElementById('lumi-microfono').getAttribute('aria-pressed') === 'false');
            // La voz propia y su cancelación no pueden rearmar la escucha con el panel abierto.
            await page.locator('.lumi-leer').first().click();
            assert.equal(await page.evaluate(() => lumiVozPrueba.activos.size), 0);
            await page.locator('#lumi-voz-detener').click();
            // Desactivar la escucha: la frase no abre.
            await detalles.click();
            await page.locator('#lumi-voz-desactivar').click();
            await detalles.click();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === '');
            await page.locator('#lumi-cerrar').click();
            await dictar('Hola, Lumi');
            assert.equal(await page.evaluate(() => document.getElementById('lumi-raiz').dataset.lumiAbierto), 'false',
                'Con la escucha desactivada no abre');
            // Preferencia persistente en la misma pestaña: recargar rearma sin nuevo gesto.
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            await detalles.click();
            await page.locator('#lumi-voz-permitir').click();
            await detalles.click();
            await page.locator('#lumi-cerrar').click();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            await page.reload();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha', undefined, {timeout: 8000});
            const mensajesPrevia = await page.locator('#lumi-mensajes li').count();
            await dictar('Oye, Lumi');
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiAbierto === 'true', undefined, {timeout: 5000});
            assert.equal(await page.locator('#lumi-mensajes li').count(), mensajesPrevia, 'Historial completo tras activación');
            // Repetir llamada después de cerrar, sin volver a pedir consentimiento.
            await page.locator('#lumi-cerrar').click();
            await esperarEscucha();
            await dictar('Hola, Lumi');
            assert.equal(await page.locator('#lumi-voz-consentimiento').isHidden(), true);
            assert.equal(await page.locator('#lumi-mensajes li').count(), mensajesPrevia);
            // Chrome libera el micrófono de forma asíncrona: el dictado debe esperar onend.
            await page.locator('#lumi-cerrar').click();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            await page.evaluate(() => { lumiVozPrueba.retrasarFin = true; window.recAnterior = lumiVozPrueba.actual; });
            const antes = await page.evaluate(() => lumiVozPrueba.inicios);
            await page.locator('[id="lumi-botón"]').click();
            await page.locator('#lumi-microfono').click();
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), antes, 'Espera el cierre del detector antes de dictar');
            await page.evaluate(() => { lumiVozPrueba.retrasarFin = false; recAnterior.finalizar(); });
            await page.waitForFunction(n => lumiVozPrueba.inicios === n + 1, antes);
            await page.locator('#lumi-microfono').click();
            await page.locator('#lumi-cerrar').click();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            // Ocultar y volver a la página exige revalidar la sesión antes de escuchar.
            await page.evaluate(() => { lumiOcultaFicticia = true; document.dispatchEvent(new Event('visibilitychange')); });
            assert.equal(await page.evaluate(() => lumiVozPrueba.activos.size), 0);
            const ocultos = await page.evaluate(() => lumiVozPrueba.inicios);
            await page.waitForTimeout(900);
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), ocultos);
            await page.evaluate(() => { lumiOcultaFicticia = false; document.dispatchEvent(new Event('visibilitychange')); });
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            // Los errores no generan bucles de permisos o red; requieren acción explícita.
            for (const codigo of ['not-allowed', 'network', 'audio-capture']) {
                await page.evaluate(c => lumiVozPrueba.actual.error(c), codigo);
                assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-despertar'), 'requiere');
                const fallidos = await page.evaluate(() => lumiVozPrueba.inicios);
                await page.waitForTimeout(900);
                assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), fallidos);
                assert.equal(await reactivar.isVisible(), true, 'Recuperación discreta sin abrir el panel');
                await reactivar.click();
                await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'escucha');
            }
            // Navegar con el detector autorizado intenta restaurarlo sin otra configuración.
            await page.goto(url + (rol === 'medico' ? 'index.php' : paginaInicial));
            await esperarEscucha();
            assert.equal(await page.locator('#lumi-mensajes li').count(), mensajesPrevia);
            assert.equal(await page.locator('#lumi-voz-consentimiento').isHidden(), true);
            await dictar('Oye, Lumi');
            await page.locator('#lumi-cerrar').click();
            await esperarEscucha();
            // Una autorización explícita de la versión anterior se conserva sin otra pregunta.
            await page.evaluate(() => {
                const [clave, valor] = Object.entries(sessionStorage).find(([k]) => k.startsWith('cvs-lumi-activacion:'));
                sessionStorage.setItem(clave, JSON.parse(valor).sesion);
            });
            await page.reload();
            await esperarEscucha();
            assert.equal(await page.locator('#lumi-voz-consentimiento').isHidden(), true);
            // Restauración desde atrás/adelante: primero detener/revalidar, luego reanudar.
            await page.evaluate(() => {
                window.dispatchEvent(new PageTransitionEvent('pagehide', {persisted: true}));
                window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true}));
            });
            await esperarEscucha();
            // Chrome exige un gesto en un documento nuevo: un solo intento, sin bucles.
            await page.evaluate(() => sessionStorage.setItem('lumi-prueba-exigir-gesto', 'si'));
            await page.reload();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'requiere');
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
            const intentosBloqueados = await page.evaluate(() => lumiVozPrueba.intentos);
            await page.waitForTimeout(900);
            assert.equal(await page.evaluate(() => lumiVozPrueba.intentos), intentosBloqueados);
            await page.reload();
            await page.waitForFunction(() => document.getElementById('lumi-raiz').dataset.lumiDespertar === 'requiere');
            assert.equal(await page.evaluate(() => lumiVozPrueba.intentos), 0, 'Un bloqueo recordado no vuelve a solicitar permiso al navegar');
            await reactivar.click(); // El mock exige navigator.userActivation real para este start.
            await esperarEscucha();
            await page.evaluate(() => { sessionStorage.removeItem('lumi-prueba-exigir-gesto'); lumiVozPrueba.exigirGesto = false; });
            await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
            assert.equal(await page.evaluate(() => lumiVozPrueba.activos.size), 0);
            await page.waitForTimeout(900);
            assert.equal(await page.evaluate(() => lumiVozPrueba.activos.size), 0);
            assert.equal(await page.evaluate(() => lumiVozPrueba.simultaneos), 1, 'Nunca dos reconocedores simultáneos, incluso con abort asíncrono');
            assert.equal(envios, 1);
            // Una cuenta nueva no hereda la autorización del micrófono ni los mensajes.
            const otra = await context.newPage();
            await otra.goto(url + 'login.php?rol=' + rol + '&cuenta=2&escenario=' + escenario);
            await otra.request.get(url + 'lumi_api.php?accion=estado'); // La cuenta nueva ya tiene una conversación reanudable.
            await otra.close();
            await page.goto(url + paginaInicial);
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
            assert.equal(await page.locator('#lumi-mensajes li').count(), 1);
            await page.locator('[id="lumi-botón"]').click();
            await page.locator('#lumi-voz-posponer').click();
            await page.reload();
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            assert.equal(await page.locator('#lumi-voz-consentimiento').isHidden(), true, 'Ahora no se recuerda en la sesión');
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
            // Autorizar otra vez desde privacidad y salir revoca toda preferencia.
            if (await page.locator('#lumi-raiz').getAttribute('data-lumi-abierto') !== 'true') await page.locator('[id="lumi-botón"]').click();
            await detalles.click();
            await page.locator('#lumi-voz-permitir').click();
            await page.locator('#lumi-cerrar').click();
            await esperarEscucha();
            await Promise.all([page.waitForURL('**/login.php'), page.locator('form[action="logout.php"] button').click()]);
            assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter(k => k.startsWith('cvs-lumi-activacion:')).length), 0);
            await page.goto(url + 'login.php?rol=' + rol + '&cuenta=3&escenario=' + escenario);
            await page.goto(url + paginaInicial);
            await page.waitForFunction(() => !document.getElementById('lumi-entrada').disabled);
            assert.equal(await page.evaluate(() => lumiVozPrueba.inicios), 0);
            assert.equal(await page.locator('#lumi-mensajes li').count(), 1);
            assert.equal((await (await page.request.get(url + 'metricas.php')).json()).llamadas, 0, 'Ninguna llamada a Gemini al activar, navegar o cambiar cuenta');
            console.log(`OK activación ${rol} ${ancho}px (${escenario}): consentimiento único, ambas frases repetibles, continuidad, privacidad, permisos, bloqueo realista de Chrome, salida y separación de cuentas`);
            await context.close();
        }
        assert.deepEqual(errores, []);
    } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
