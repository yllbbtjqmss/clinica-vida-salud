/* Exclusivamente el PHP temporal de lumi_gemini_http.py: sin PostgreSQL/proveedor reales. */
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
        for (const rol of ['paciente','medico','recepcionista','administrador']) for (const width of [1440,390]) {
            const context = await browser.newContext({viewport:{width,height:900}});
            await context.addInitScript({path:path.join(__dirname,'lumi_voz_mock.js')});
            const page = await context.newPage();
            page.on('pageerror',e=>errores.push(e.message));
            await page.goto(url+'login.php?rol='+rol+'&escenario=apagada');
            await page.goto(url+'index.php');
            await page.locator('[id="lumi-botón"]').click();
            await page.waitForFunction(()=>!document.getElementById('lumi-entrada').disabled);
            assert.equal(await page.locator('#lumi-aviso').isHidden(), true, 'Sin avisos permanentes de modo local');
            assert.doesNotMatch(await page.locator('#lumi-panel').textContent(),/Modo local|texto ficticio|prueb/);
            assert.match(await page.locator('.lumi-voz-nota summary').textContent(),/privacidad/i);
            const enviar = async texto => {
                await page.locator('#lumi-entrada').fill(texto);
                await page.locator('#lumi-envio button[type="submit"]').click();
                await page.waitForFunction(()=>!document.querySelector('#lumi-envio button[type="submit"]').disabled);
                return page.locator('#lumi-mensajes li').last().textContent();
            };
            assert.match(await enviar('Qué especialidades existen'),/Cardiología/);
            assert.match(await enviar('Qué médicos hay en Cardiología'),/Profesional Alfa/);
            // Preguntas consecutivas: cada respuesta corresponde al mensaje enviado (variantes de la falla real 4B).
            const pares = [
                ['Qué médicos atienden en Pediatría',/Profesional Gamma/],
                ['Quién atiende en Medicina General',/no hay médicos asociados/],
                ['Qué médicos hay en Especialidad Inexistente',/No encontré una especialidad activa/],
            ];
            if (rol === 'paciente') pares.push(
                ['Qué sigue después de seleccionar un médico',/Revisar resumen/],
                ['Ya elegí especialidad y médico, ¿qué hago ahora?',/bloque disponible/],
            ); else pares.push(
                ['Qué sigue después de seleccionar un médico',/Puedo orientarte paso a paso/],
                ['Ya elegí especialidad y médico, ¿qué hago ahora?',/Puedo orientarte paso a paso/],
            );
            for (const [pregunta, esperado] of pares) {
                const mensajes = await page.locator('#lumi-mensajes li').count();
                const respuesta = await enviar(pregunta);
                assert.match(respuesta, esperado, 'Respuesta del mensaje: '+pregunta);
                assert.equal(await page.locator('#lumi-mensajes li').count(), mensajes + 2, 'Sin borrar el historial: '+pregunta);
            }
            assert.doesNotMatch(await page.locator('#lumi-mensajes').textContent(),/PRIVADO_|Profesional Inactivo/);
            const anterior = await page.locator('#lumi-mensajes li').allTextContents();
            const total = anterior.length;
            await page.reload();
            await page.waitForFunction(t=>document.querySelectorAll('#lumi-mensajes li').length===t && !document.getElementById('lumi-entrada').disabled, total);
            assert.deepEqual(await page.locator('#lumi-mensajes li').allTextContents(),anterior);
            await page.evaluate(()=>lumiVozPrueba.cargarVoces());
            await page.locator('.lumi-leer').last().click();
            assert.equal(await page.locator('#lumi-raiz').getAttribute('data-lumi-estado'),'hablando');
            await page.locator('#lumi-voz-detener').click();
            const contador = await page.locator('#lumi-mensajes li').count();
            await page.locator('#lumi-microfono').click();
            await page.evaluate(()=>{ lumiVozPrueba.actual.iniciar(); lumiVozPrueba.actual.resultado('Qué médicos hay en Pediatría',true); });
            assert.equal(await page.locator('#lumi-mensajes li').count(),contador,'Dictar no envía automáticamente');
            await page.locator('#lumi-microfono').click(); // Stop: dictado revisable; el usuario decide cuándo enviar.
            assert.equal(await page.locator('#lumi-microfono').getAttribute('aria-pressed'),'false');
            assert.match(await page.locator('#lumi-entrada').inputValue(),/Pediatría/);
            await page.locator('#lumi-envio button[type="submit"]').click();
            await page.waitForFunction(()=>!document.querySelector('#lumi-envio button[type="submit"]').disabled);
            assert.match(await page.locator('#lumi-mensajes li').last().textContent(),/Profesional Gamma/);
            assert.match(await enviar('Prueba general sin proveedor'),/catálogo de la clínica/);
            assert.match(await enviar('Qué especialidades existen'),/Cardiología/);
            const metricas=await (await page.request.get(url+'metricas.php')).json();
            assert.equal(metricas.llamadas,0,'Catálogo/guías no llaman al proveedor');
            if (rol==='paciente') {
                assert.match(await enviar('Abrir Médicos'),/no está disponible para tu cuenta/);
                await Promise.all([page.waitForURL('**/reservar_cita.php'),page.locator('.lumi-destinos a[href$="destino=reservar"]').first().click()]);
                await page.waitForFunction(()=>document.getElementById('lumi-raiz').dataset.lumiAbierto==='true' && !document.getElementById('lumi-entrada').disabled);
                assert.match(await page.locator('#lumi-mensajes').textContent(),/Profesional Alfa/);
            }
            console.log(`OK catálogo local ${rol} ${width}px: Gemini apagado, texto, voz, continuidad y enlaces autorizados`);
            await context.close();
        }
        assert.deepEqual(errores,[]);
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
