/* Solo runner aislado. Dependencias de pruebas externas al proyecto, nunca en producción. */
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
if (process.env.CVS_TEST_AISLADO !== '1' || process.env.CVS_DB_NAME !== 'cvs_test_pagos') process.exit(2);
const {chromium} = require(path.join(process.env.CVS_BROWSER_TOOLS, 'node_modules/playwright'));
const config = JSON.parse(process.env.CVS_TEST_BROWSER);
assert.match(config.url, /^http:\/\/127\.0\.0\.1:\d+\/$/);
const pruebas = [];
const ok = (id, caso) => pruebas.push({id, caso, estado: 'OK'});
(async () => {
    const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    const browser = await chromium.launch(fs.existsSync(edge) ? {executablePath: edge, headless: true} : {headless: true});
    try {
        const capturas=path.join(process.env.CVS_BROWSER_TOOLS,'capturas'); fs.mkdirSync(capturas,{recursive:true});
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}, locale: 'es-BO'});
        const page = await context.newPage();
        const errores = [], externos = [];
        page.on('pageerror', e => errores.push(e.message));
        page.on('request', r => { if (!r.url().startsWith(config.url) && !r.url().startsWith('blob:')) externos.push(new URL(r.url()).origin); });
        await page.goto(config.url + 'login.php');
        await page.locator('[name="nombre_usuario"]').fill('ensayo_paciente_uno');
        await page.locator('[name="contrasena"]').fill(process.env.CVS_TEST_PASSWORD);
        await Promise.all([page.waitForURL('**/mis_citas.php'), page.locator('button[type="submit"]').click()]);
        await page.goto(config.url + 'reservar_cita.php');
        await page.locator('#cvp-especialidad').selectOption(String(config.fixture.usuarios.especialidad));
        await page.locator('#cvp-medico option[value="'+config.fixture.usuarios.ficha_medico+'"]').waitFor({state: 'attached'});
        await page.locator('#cvp-medico').selectOption(String(config.fixture.usuarios.ficha_medico));
        await page.locator('#cvp-fecha').fill(config.fixture.fecha);
        await page.locator('[name="hora_inicio"][value="16:00"]').check();
        await page.getByRole('button', {name: 'Revisar resumen'}).click();
        await page.waitForFunction(()=>document.getElementById('cvp-metodos-online')?.disabled === true);
        await page.locator('[name="modalidad"][value="demo"]').check();
        assert.equal(await page.locator('#cvp-metodos-online').evaluate(el=>el.disabled), false);
        await page.locator('[name="metodo_online"][value="tarjeta"]').check();
        await page.getByRole('button', {name: 'Confirmar reserva', exact: true}).click();
        await page.locator('#cvp-tarjeta').waitFor();
        const cardURL = page.url();
        assert.equal(await page.locator('#cvp-pan').getAttribute('name'), null);
        assert.equal(await page.locator('#cvp-cvv').getAttribute('type'), 'password');
        assert.equal(await page.locator('#cvp-pan').getAttribute('readonly'), '');
        await page.screenshot({path:path.join(capturas,'tarjeta-escritorio.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(capturas,'tarjeta-movil.png'),fullPage:true});
        await page.setViewportSize({width:1440,height:1000});
        ok('B01','Navegador: modalidad anidada, selección de tarjeta, campos sintéticos formateados de solo lectura');
        await page.locator('#cvp-pan').evaluate(el => { el.readOnly = false; el.value = 'NO ADMITIDA'; });
        let pagosPost = 0;
        const observar = req => { if (req.url() === cardURL && req.method() === 'POST') pagosPost++; };
        page.on('request', observar);
        await page.locator('.cvp-pagar').click();
        assert.match(await page.locator('#cvp-tarjeta-error').textContent(), /Tarjeta no admitida/);
        assert.equal(pagosPost, 0);
        await page.reload();
        const solicitud = page.waitForRequest(r => r.url() === cardURL && r.method() === 'POST');
        await page.locator('.cvp-pagar').click();
        const payload = new URLSearchParams((await solicitud).postData());
        assert.deepEqual([...payload.keys()].sort(), ['accion','csrf','operacion','referencia_prueba']);
        assert.equal(payload.get('referencia_prueba'), 'CVS-TARJETA-SINTETICA-V1');
        await page.getByText('Estamos procesando tu pago. Pendiente de verificación.', {exact: true}).waitFor();
        assert.match(await page.locator('main').textContent(), /Cita: Todavía no confirmada/);
        ok('B02','Navegador: campos manipulados bloqueados, envío sin PAN/CVV y procesamiento sin aprobación del cliente');
        page.off('request', observar);
        const admin = await browser.newContext({viewport:{width:1440,height:1000}});
        const gestion = await admin.newPage();
        await gestion.goto(config.url + 'login.php');
        await gestion.locator('[name="nombre_usuario"]').fill('ensayo_admin');
        await gestion.locator('[name="contrasena"]').fill(process.env.CVS_TEST_PASSWORD);
        await Promise.all([gestion.waitForURL(url => !url.pathname.endsWith('/login.php')), gestion.locator('button[type="submit"]').click()]);
        await gestion.goto(cardURL);
        await gestion.locator('#escenario').selectOption('fallido');
        await gestion.getByRole('button',{name:'Registrar resultado',exact:true}).click();
        await page.reload();
        assert.match(await page.locator('main').textContent(), /Pago rechazado/);
        ok('B03','Navegador: rechazo autorizado de tarjeta se refleja en la cuenta del paciente');
        await page.goto(config.url + 'pago.php?id=' + config.qr);
        await page.locator('[data-qr-fijo]').waitFor();
        const imagenDisponible = fs.existsSync(path.join(__dirname, '../assets/img/qr-pago-demo.png'));
        if (imagenDisponible) {
            const img = page.locator('[data-qr-fijo] img');
            assert.equal(await img.getAttribute('src'), 'assets/img/qr-pago-demo.png');
            await img.evaluate(el => el.decode());
            assert.ok(await img.evaluate(el => el.naturalWidth > 0 && el.naturalHeight > 0));
        } else {
            assert.equal(await page.locator('[data-qr-fijo] img').count(), 0);
            assert.match(await page.locator('[data-qr-fijo]').textContent(), /El código QR no está disponible/);
        }
        assert.equal(await page.locator('[data-qr], .cvp-qr svg, script[src*="qrcode"]').count(), 0);
        await page.screenshot({path:path.join(capturas,'qr-escritorio.png'),fullPage:true,animations:'disabled'});
        assert.match(await page.locator('[data-cuenta-atras]').textContent(), /^\d+:\d{2}$/);
        const antes = await page.locator('[data-cuenta-atras]').textContent();
        await page.waitForFunction(texto => document.querySelector('[data-cuenta-atras]').textContent !== texto, antes);
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(capturas,'qr-movil.png'),fullPage:true,animations:'disabled'});
        // El recurso y los datos no dependen del generador ni de JavaScript para mostrarse.
        const sinJS = await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});
        const estatico = await sinJS.newPage();
        await estatico.goto(page.url());
        assert.equal(await estatico.locator('[data-qr-fijo]').count(), 1);
        assert.match(await estatico.locator('.cvp-qr-datos').textContent(), /Bs 120,20/);
        if (imagenDisponible) assert.ok(await estatico.locator('[data-qr-fijo] img').evaluate(el => el.complete && el.naturalWidth > 0));
        await sinJS.close();
        ok('B04',imagenDisponible ? 'Imagen QR fija cargada con y sin JavaScript; datos visibles y contador activo' : 'Sin archivo adjunto: aviso controlado con y sin JavaScript, datos visibles y contador activo; imagen pendiente');
        // Comprobar desbordamiento del documento; las tablas móviles conservan scroll local.
        const paths = ['pago.php?id='+config.qr, 'pagos.php','ficha_reserva.php?id='+config.qr,'ficha_reserva.php?id='+config.fixture.pagos.demo];
        for (const width of [1440,768,390,360]) {
            await page.setViewportSize({width,height:900});
            for (const target of paths) {
                await page.goto(config.url+target);
                const dimensiones=await page.evaluate(()=>({ancho:document.documentElement.clientWidth,contenido:document.documentElement.scrollWidth}));
                assert.ok(dimensiones.contenido<=dimensiones.ancho+1,`${target} width=${width} overflow=${dimensiones.contenido}`);
                if (target==='pagos.php' && width===1440) {
                    assert.ok(await page.locator('.tabla').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Tabla de escritorio no debe necesitar scroll');
                }
            }
        }
        await gestion.goto(config.url+'tarifas.php');
        for (const width of [1440,390]) {
            await gestion.setViewportSize({width,height:900});
            for (const target of ['tarifas.php','reportes_financieros.php?entorno=demo']) {
                await gestion.goto(config.url+target);
                assert.ok(await gestion.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+1),target);
            }
        }
        await page.goto(config.url+'ficha_reserva.php?id='+config.qr);
        await page.emulateMedia({media:'print'});
        assert.equal(await page.locator('.cvp-nota-academica').isVisible(),true);
        assert.equal(await page.locator('[data-qr-fijo]').isVisible(),true);
        assert.equal(await page.locator('.cvp-no-imprimir').isVisible(),false);
        // El paciente sigue sin poder aprobar: administración confirma, el QR desaparece.
        await gestion.goto(config.url+'pago.php?id='+config.qr);
        await gestion.locator('#escenario').selectOption('exitoso');
        await gestion.getByRole('button',{name:'Registrar resultado',exact:true}).click();
        await page.emulateMedia({media:'screen'});
        await page.goto(config.url+'pago.php?id='+config.qr);
        assert.match(await page.locator('main').textContent(), /Pago: Pagado/);
        assert.match(await page.locator('main').textContent(), /Cita: Confirmada/);
        assert.equal(await page.locator('[data-qr-fijo]').count(),0);
        await page.goto(config.url+'ficha_reserva.php?id='+config.qr+'&tipo=comprobante');
        assert.equal(await page.locator('[data-qr-fijo]').count(),0);
        assert.deepEqual(errores,[]);assert.deepEqual(externos,[]);
        ok('B05','Responsive 360/390/768/1440, tablas de escritorio sin scroll, aviso visible en impresión y sin solicitudes externas');
        await context.close(); await admin.close();
        console.log(JSON.stringify({pruebas,version:browser.version()}));
    } finally { await browser.close(); }
})().catch(error=>{ console.error(error.stack);process.exitCode=1; });
