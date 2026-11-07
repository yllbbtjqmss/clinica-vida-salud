"""Pruebas en un clúster NUEVO, efímero y ligado a loopback. Solo biblioteca estándar.
No lee archivos privados ni usa las variables de conexión del usuario.
"""
from pathlib import Path
import argparse
import base64
import concurrent.futures
import datetime
import http.cookiejar
import json
import os
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]

def run(args, env, **kwargs):
    # pg_ctl en Windows puede dejar una tubería heredada abierta por el servidor.
    # Un archivo temporal evita bloquear communicate() aunque pg_ctl ya haya salido.
    if Path(args[0]).name=='pg_ctl.exe':
        with tempfile.TemporaryFile() as output:
            result=subprocess.run([str(a) for a in args],env=env,stdin=subprocess.DEVNULL,
                stdout=output,stderr=subprocess.STDOUT,timeout=90,**kwargs)
            output.seek(0); text=output.read().decode('utf-8',errors='replace')
            if result.returncode: raise RuntimeError('pg_ctl no completó la operación aislada: '+text[-1200:])
            return text
    result = subprocess.run([str(a) for a in args], env=env, stdin=subprocess.DEVNULL, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=90, **kwargs)
    if result.returncode:
        raise RuntimeError(f'Comando de prueba falló: {Path(args[0]).name}; código {result.returncode}; {result.stderr[-1800:]}')
    return result.stdout

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=r'C:\xampp\php\php.exe')
    parser.add_argument('--pg-bin', default=r'C:\Program Files\PostgreSQL\18\bin')
    parser.add_argument('--evidencia', type=Path)
    parser.add_argument('--browser-tools', type=Path, help='Carpeta externa con node_modules/playwright para pruebas de navegador')
    args=parser.parse_args()
    pg=Path(args.pg_bin)
    if not Path(args.php).is_file() or not (pg/'initdb.exe').is_file():
        raise SystemExit('Revisa las rutas de PHP y PostgreSQL. No se ha conectado a ninguna base.')
    temp_root=Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'Temp' / 'opencode'
    temp_root.mkdir(parents=True,exist_ok=True)
    work=Path(tempfile.mkdtemp(prefix='cvs-pagos-',dir=temp_root))
    pg_port=port(); http_port=port()
    while pg_port==5432:
        pg_port=port()
    # No heredar destinos, servicios ni archivos de contraseñas PostgreSQL.
    env={k:v for k,v in os.environ.items() if not k.startswith(('PG','CVS_DB_','CVS_TEST_'))}
    env.update({'CVS_TEST_AISLADO':'1','CVS_DB_HOST':'127.0.0.1','CVS_DB_PORT':str(pg_port),
        'CVS_DB_NAME':'cvs_test_pagos','CVS_DB_USER':'cvs_pruebas','CVS_DB_PASSWORD':'',
        'CVS_DB_SSLMODE':'disable','CVS_HTTPS':'0','CVS_PAGOS_DEMO':'1',
        'CVS_TEST_PASSWORD':secrets.token_urlsafe(24)})
    started=False; server=None; log=None
    evidence={'fecha_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'entorno':'Cluster PostgreSQL nuevo, efimero, loopback; sin bases reales','pruebas':[]}
    try:
        run([pg/'initdb.exe','-D',work/'data','-U','cvs_pruebas','--auth=trust','--no-locale','--encoding=UTF8'],env)
        run([pg/'pg_ctl.exe','-D',work/'data','-l',work/'postgres.log','-o',f'-h 127.0.0.1 -p {pg_port} -F','-w','start'],env); started=True
        psql=[pg/'psql.exe','-X','-h','127.0.0.1','-p',str(pg_port),'-U','cvs_pruebas','-v','ON_ERROR_STOP=1']
        for db in ['cvs_test_pagos','cvs_test_incremental']:
            run(psql+['-d','postgres','-c',f'CREATE DATABASE {db}'],env)
        run(psql+['-d','cvs_test_pagos','-f',ROOT/'database/clinica_vida_salud.sql'],env)
        # Instalación incremental con una cita preexistente, sin cuentas ni datos reales.
        baseline=(ROOT/'database/clinica_vida_salud.sql').read_text(encoding='utf-8').split('\\ir ')[0]
        old=work/'base_anterior.sql'; old.write_text(baseline,encoding='utf-8')
        run(psql+['-d','cvs_test_incremental','-f',old],env)
        legacy="""INSERT INTO pacientes(nombres,apellidos,fecha_nacimiento,telefono) VALUES ('Ensayo','Anterior','2000-01-01','00000000');
INSERT INTO medicos(registro_profesional,nombres,apellidos) VALUES ('ENSAYO-ANTERIOR','Ensayo','Anterior');
INSERT INTO medico_especialidad VALUES (1,1);
INSERT INTO citas(codigo,id_paciente,id_medico,id_especialidad,fecha,hora_inicio,hora_fin) VALUES ('ENSAYO-ANTERIOR',1,1,1,CURRENT_DATE+30,'08:00','08:15');"""
        run(psql+['-d','cvs_test_incremental','-c',legacy],env)
        run(psql+['-d','cvs_test_incremental','-f',ROOT/'database/migraciones/20261104_reservas_pagos.sql'],env)
        counts=run(psql+['-d','cvs_test_incremental','-tAc',"SELECT (SELECT count(*) FROM citas)||','||(SELECT count(*) FROM pagos)||','||(SELECT count(*) FROM pg_constraint WHERE conname='ex_citas_medico_sin_superposicion')"],env).strip()
        assert counts=='1,0,1', counts
        evidence['pruebas'].append({'id':'M01','caso':'Migracion incremental preserva cita y exclusion originales; no inventa pagos','estado':'OK'})
        oldenv=env.copy(); oldenv['CVS_DB_NAME']='cvs_test_incremental'
        compatibility=run([args.php,'-r',"require 'includes/reservas_pagos.php'; require 'includes/reportes_pagos.php'; $p=cvs_conexion(); if (!cvp_disponible($p) || cvp_online_disponible($p)) exit(1); cvp_reporte($p,'2026-01-01','2026-12-31','demo'); echo 'OK';"],oldenv,cwd=ROOT)
        assert compatibility=='OK'
        run(psql+['-d','cvs_test_incremental','-c',"INSERT INTO usuarios(id_rol,nombre_usuario,contrasena_hash) VALUES (1,'ensayo_migracion','no_login'); INSERT INTO pagos(referencia,id_cita,id_paciente,creado_por,importe,modalidad,idempotencia,huella_solicitud) VALUES ('ENSAYO-MIGRACION',1,1,1,15.25,'presencial',repeat('a',64),repeat('b',64));"],env)
        before=run(psql+['-d','cvs_test_incremental','-tAc','SELECT row_to_json(p)::text FROM pagos p'],env).strip()
        run(psql+['-d','cvs_test_incremental','-f',ROOT/'database/migraciones/20261105_metodos_online.sql'],env)
        after=json.loads(run(psql+['-d','cvs_test_incremental','-tAc','SELECT row_to_json(p)::text FROM pagos p'],env).strip())
        assert after.pop('metodo_online') is None and after==json.loads(before)
        evidence['pruebas'].append({'id':'M02','caso':'Compatibilidad con esquema anterior y migracion nueva preserva todos los campos historicos','estado':'OK'})
        result=subprocess.run([args.php,str(ROOT/'tests/reservas_pagos.php')],env=env,stdin=subprocess.DEVNULL,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=90)
        data=json.loads(result.stdout); evidence['pruebas'].extend(data['pruebas']); fixture=data['fixtures']
        if result.returncode:
            print(result.stderr[-2000:])
        # Dos procesos PHP y conexiones distintas; no se serializan mediante sesión HTTP.
        def reserve(actor, mode):
            child=env.copy(); values=dict(fixture['base'],hora_inicio='12:00',modalidad=mode)
            child['CVS_TEST_OPERACION']=json.dumps({'actor':actor,'datos':values})
            return json.loads(run([args.php,ROOT/'tests/operacion_reserva.php'],child))
        env['CVS_TEST_START_AT']=str(time.time()+.5)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            jobs=[pool.submit(reserve,fixture['usuarios']['paciente'],'demo'),pool.submit(reserve,fixture['usuarios']['paciente2'],'presencial')]
            outcomes=[j.result() for j in jobs]
        assert sum(x['ok'] for x in outcomes)==1, outcomes
        evidence['pruebas'].append({'id':'C01','caso':'Dos procesos y conexiones simultaneos: solo una reserva para el mismo intervalo','estado':'OK'})

        def operation(payload):
            child=env.copy(); child['CVS_TEST_OPERACION']=json.dumps(payload)
            return json.loads(run([args.php,ROOT/'tests/operacion_reserva.php'],child))
        receipt=operation({'actor':fixture['usuarios']['paciente'],'datos':dict(fixture['base'],hora_inicio='12:15')})
        assert receipt['ok']
        env['CVS_TEST_START_AT']=str(time.time()+.5)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            jobs=[pool.submit(operation,{'accion':'cobrar','actor':fixture['usuarios'][role],'id':receipt['id'],'referencia':'ENSAYO-CONC-'+role}) for role in ['administrador','recepcionista']]
            assert all(j.result()['ok'] for j in jobs)
        count=run(psql+['-d','cvs_test_pagos','-tAc',f"SELECT count(*) FROM movimientos_pago WHERE id_pago={int(receipt['id'])} AND tipo='cobro'"],env).strip()
        assert count=='1'
        evidence['pruebas'].append({'id':'C02','caso':'Dos cobros simultaneos generan un unico movimiento monetario','estado':'OK'})
        values=dict(fixture['base'],hora_inicio='12:45',modalidad='demo')
        env['CVS_TEST_START_AT']=str(time.time()+.5)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            jobs=[pool.submit(operation,{'actor':fixture['usuarios']['paciente'],'datos':values}),
                  pool.submit(operation,{'accion':'legado','paciente':fixture['usuarios']['ficha_paciente2'],'datos':values})]
            outcomes=[j.result() for j in jobs]
        assert sum(x['ok'] for x in outcomes)==1,outcomes
        evidence['pruebas'].append({'id':'C03','caso':'INSERT legado y reserva temporal simultaneos comparten exclusion','estado':'OK'})

        log=(work/'http.log').open('w',encoding='utf-8')
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{http_port}','-t',str(ROOT)],env=env,stdin=subprocess.DEVNULL,stdout=log,stderr=log)
        url=f'http://127.0.0.1:{http_port}/'
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',http_port),timeout=.1): break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('No inició el servidor PHP aislado.')
        clients={}
        def request(client, path, values=None):
            payload=None if values is None else urllib.parse.urlencode(values).encode()
            try:
                response=client.open(url+path,payload,timeout=15)
            except urllib.error.HTTPError as ex: response=ex
            return response.status,response.read().decode('utf-8',errors='replace')
        def csrf(html):
            match=re.search(r'name="csrf" value="([a-f0-9]+)"',html)
            assert match,'No hay CSRF'; return match[1]
        def hidden(html, action=None):
            forms=re.findall(r'<form\b[^>]*>.*?</form>',html,re.S)
            selected=next((f for f in forms if f'name="accion" value="{action}"' in f),None) if action else None
            source=selected if selected is not None else html
            values={}
            for tag in re.findall(r'<input\b[^>]*>',source):
                attrs=dict(re.findall(r'([\w-]+)="([^"]*)"',tag))
                if attrs.get('type')=='hidden' and 'name' in attrs: values[attrs['name']]=attrs.get('value','')
            return values
        for role,name in [('administrador','ensayo_admin'),('recepcionista','ensayo_recepcion'),('medico','ensayo_medico'),('paciente','ensayo_paciente_uno'),('paciente2','ensayo_paciente_dos')]:
            client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            status,body=request(client,'login.php')
            status,body=request(client,'login.php',{'csrf':csrf(body),'nombre_usuario':name,'contrasena':env['CVS_TEST_PASSWORD']})
            assert status==200 and 'Usuario o contraseña incorrectos' not in body,role
            clients[role]=client
        for role in ['administrador','recepcionista','paciente']:
            for path in ['pagos.php',f'pago.php?id={fixture["pagos"]["demo"]}']:
                status,body=request(clients[role],path)
                assert status==200 and 'Fatal error' not in body and 'No fue posible' not in body,(role,path,status)
        for path in ['tarifas.php','reportes_financieros.php','pagos.php',f'pago.php?id={fixture["pagos"]["demo"]}']:
            status,_=request(clients['medico'],path); assert status==403,(path,status)
        evidence['pruebas'].append({'id':'H01','caso':'Acceso HTTP por roles y medico sin gestion financiera','estado':'OK'})
        for path in [f'pago.php?id={fixture["pagos"]["demo"]}',f'ficha_reserva.php?id={fixture["pagos"]["demo"]}']:
            status,body=request(clients['paciente2'],path); assert status==404 and 'Profesional ficticio' not in body,(path,status)
        evidence['pruebas'].append({'id':'H02','caso':'Aislamiento de ficha y pagos de otro paciente por HTTP','estado':'OK'})
        status,body=request(clients['paciente'],f'ficha_reserva.php?id={fixture["pagos"]["demo"]}&tipo=comprobante')
        assert status==200 and 'Entorno de pruebas' in body and 'factura fiscal' in body
        status,body=request(clients['paciente'],f'ficha_reserva.php?id={fixture["pagos"]["presencial"]}&tipo=comprobante')
        assert status==200 and 'Entorno de pruebas' not in body and 'no es una factura fiscal' in body
        evidence['pruebas'].append({'id':'P17','caso':'Comprobantes HTTP separan demostracion y constancia presencial no fiscal','estado':'OK'})
        status,body=request(clients['administrador'],'tarifas.php',{'csrf':'invalido','importe':'0.01','id_medico':fixture['usuarios']['ficha_medico'],'id_especialidad':fixture['usuarios']['especialidad']})
        assert status==403 and 'Solicitud no válida' in body
        evidence['pruebas'].append({'id':'H03','caso':'CSRF invalido rechaza modificacion de tarifas','estado':'OK'})
        for role,path in [('administrador','reportes_financieros.php'),('administrador','reportes_financieros.php?entorno=demo'),('paciente','mis_citas.php?periodo=todas'),('medico',f'mi_agenda.php?fecha={fixture["fecha"]}'),('administrador','citas.php'),('administrador','reportes.php')]:
            status,body=request(clients[role],path)
            assert status==200 and 'Fatal error' not in body and 'No fue posible' not in body,(role,path,status)
        evidence['pruebas'].append({'id':'H04','caso':'Paginas originales y nuevas renderizan con esquema ampliado','estado':'OK'})
        def book_http(mode, hour, method='qr'):
            query=urllib.parse.urlencode({'consulta':'bloques','id_medico':fixture['base']['id_medico'],
                'id_especialidad':fixture['base']['id_especialidad'],'fecha':fixture['fecha']})
            status,body=request(clients['paciente'],'reservar_cita.php?'+query)
            availability=json.loads(body)
            assert status==200 and availability['tarifa']=='120.20' and any(b['hora_inicio']==hour for b in availability['bloques'])
            status,body=request(clients['paciente'],'reservar_cita.php')
            assert status==200 and 'Fatal error' not in body
            values=hidden(body,'resumir'); values.update(fixture['base']); values.update({'accion':'resumir','hora_inicio':hour,'importe':'0.01','id_paciente':fixture['usuarios']['ficha_paciente2']})
            status,body=request(clients['paciente'],'reservar_cita.php',values)
            assert status==200 and 'Confirmar reserva' in body and 'Bs 120,20' in body
            values=hidden(body,'confirmar'); values.update({'accion':'confirmar','modalidad':mode,'metodo_online':method,'importe':'0.01','id_paciente':fixture['usuarios']['ficha_paciente2']})
            status,body=request(clients['paciente'],'reservar_cita.php',values)
            assert status==200 and 'No fue posible' not in body and 'Persona ficticia paciente</dd>' in body
            match=re.search(r'ficha_reserva.php\?id=([0-9]+)',body); assert match,'No se generó ficha'
            return int(match[1]),body
        onsite,body=book_http('presencial','13:00')
        assert 'Cita reservada con pago presencial pendiente' in body
        evidence['pruebas'].append({'id':'H05','caso':'Flujo HTTP paciente: seleccion, resumen validado, pago presencial y ficha propia','estado':'OK'})
        status,body=request(clients['recepcionista'],f'pago.php?id={onsite}')
        values=hidden(body,'cobrar'); values.update({'accion':'cobrar','metodo':'qr_recepcion','referencia':'ENSAYO-HTTP-QR','verificado':'1'})
        for _ in range(2):
            status,body=request(clients['recepcionista'],f'pago.php?id={onsite}',values)
            assert status==200 and 'Pago: Pagado' in body
        evidence['pruebas'].append({'id':'H06','caso':'Cobro HTTP recepcion, QR verificado y reenvio idempotente','estado':'OK'})
        online,body=book_http('demo','13:15')
        values=hidden(body,'abandonar'); values.update({'accion':'simular','escenario':'exitoso'})
        status,body=request(clients['paciente'],f'pago.php?id={online}',values)
        assert status==403 and 'Pago: Pendiente' in body
        status,body=request(clients['administrador'],f'pago.php?id={online}')
        values=hidden(body,'simular'); values.update({'accion':'simular','escenario':'exitoso'})
        status,body=request(clients['administrador'],f'pago.php?id={online}',values)
        assert status==200 and 'Pago: Pagado' in body and 'Cita: Confirmada' in body
        evidence['pruebas'].append({'id':'H07','caso':'Simulacion HTTP: paciente no falsifica aprobacion; administrador confirma cita','estado':'OK'})
        status,body=request(clients['recepcionista'],'citas.php')
        values=hidden(body,'registrar'); values.update(fixture['base']); values.update({'accion':'registrar','id_paciente':fixture['usuarios']['ficha_paciente2'],'hora_inicio':'13:30','precio_mostrado':'120.20','motivo':'Ensayo aislado'})
        status,body=request(clients['recepcionista'],'citas.php',values)
        assert status==200 and 'Cita reservada con pago presencial pendiente' in body and 'Persona ficticia paciente2' in body
        evidence['pruebas'].append({'id':'H08','caso':'Registro administrativo existente crea cita y cuenta pendiente preservando el formulario','estado':'OK'})
        cita=int(run(psql+['-d','cvs_test_pagos','-tAc',f'SELECT id_cita FROM pagos WHERE id_pago={onsite}'],env).strip())
        path=f'reprogramar_cita.php?id_cita={cita}'
        status,body=request(clients['recepcionista'],path)
        values=hidden(body,'reprogramar'); values.update({'accion':'reprogramar','fecha':fixture['fecha'],'hora_inicio':'14:30','id_cita':cita})
        status,body=request(clients['recepcionista'],path,values)
        assert status==200 and 'Cita reprogramada correctamente' in body
        status,body=request(clients['recepcionista'],f'cancelar_cita.php?confirmar={cita}')
        values=hidden(body,'cancelar'); values.update({'accion':'cancelar','id_cita':cita,'confirmacion':'1','motivo_cancelacion':'Ensayo aislado'})
        status,body=request(clients['recepcionista'],'cancelar_cita.php',values)
        assert status==200 and 'Cita cancelada correctamente' in body
        status,body=request(clients['paciente'],f'pago.php?id={onsite}')
        assert status==200 and 'Reembolso pendiente' in body and 'Bs 120,20' in body
        evidence['pruebas'].append({'id':'H09','caso':'Reprogramacion y cancelacion HTTP originales conservan el pago y registran revision','estado':'OK'})
        status,body=request(clients['paciente'],f'ficha_reserva.php?id={online}&descargar=1')
        assert status==200 and '<style>' in body and 'Presentación académica' in body
        assert 'data-qr-fijo' not in body, 'No ofrecer QR para volver a pagar una operación aprobada'
        assert 'href="assets/estilos.css"' not in body and 'src="assets/reservas.js"' not in body
        evidence['pruebas'].append({'id':'H10','caso':'Ficha descargable autocontenida conserva estilos y rotulo de demostracion','estado':'OK'})
        card,body=book_http('demo','16:45','tarjeta')
        assert 'readonly' in body and 'name="cvv"' not in body and 'name="pan"' not in body
        values=hidden(body,'solicitar_tarjeta'); values['referencia_prueba']='NO-ADMITIDO'
        status,body=request(clients['paciente'],f'pago.php?id={card}',values)
        assert status==200 and 'Instrumento de prueba no admitido' in body and 'Pago: Pendiente' in body
        values['referencia_prueba']='CVS-TARJETA-SINTETICA-V1'
        status,body=request(clients['paciente'],f'pago.php?id={card}',dict(values,pan='VALOR-NO-ADMITIDO'))
        assert status==200 and 'campos no admitidos' in body and 'Pago: Pendiente' in body
        for _ in range(2):
            status,body=request(clients['paciente'],f'pago.php?id={card}',values)
            assert status==200 and 'Pago: Procesando' in body and 'Cita: Todavía no confirmada' in body
        status,body=request(clients['administrador'],f'pago.php?id={card}')
        values=hidden(body,'simular'); values.update(accion='simular',escenario='exitoso')
        status,body=request(clients['administrador'],f'pago.php?id={card}',values)
        assert status==200 and 'Cita: Confirmada' in body and 'Pago: Pagado' in body
        status,body=request(clients['paciente'],f'ficha_reserva.php?id={card}&tipo=comprobante')
        assert status==200 and 'Tarjeta de prueba' in body and 'Responsable del registro' in body and body.count('Entorno de pruebas')==1
        evidence['pruebas'].append({'id':'H11','caso':'Tarjeta HTTP: lista cerrada, sin PAN/CVV, solicitud idempotente sin aprobacion y comprobante autorizado','estado':'OK'})
        qr,body=book_http('demo','17:00','qr')
        assert 'data-qr-fijo' in body and 'Pendiente de verificación' in body
        assert 'data-qr=' not in body and 'qrcode.js' not in body
        assert 'data-cuenta-atras' in body and 'Bs 120,20' in body and 'Referencia de pago' in body
        assert 'Escanea el código QR desde tu app bancaria para completar el pago.' in body
        assert body.count('Presentación académica')==1 and 'Sin transacciones bancarias' not in body
        asset=ROOT/'assets/img/qr-pago-demo.png'
        if asset.is_file():
            assert 'src="assets/img/qr-pago-demo.png"' in body
        else:
            assert 'El código QR no está disponible' in body and '<img src="assets/img/qr-pago-demo.png"' not in body
        evidence['imagen_qr']='Archivo presente' if asset.is_file() else 'Pendiente: guardar el adjunto original en assets/img/qr-pago-demo.png'
        evidence['pruebas'].append({'id':'H12','caso':'Vista QR fija sin generador: importe, referencia, estado, plazo y aviso discreto; ausencia del archivo controlada','estado':'OK'})
        for suffix in ['', '&descargar=1']:
            status,body=request(clients['paciente'],f'ficha_reserva.php?id={qr}{suffix}')
            assert status==200 and 'data-qr-fijo' in body and 'Presentación académica' in body
            if asset.is_file():
                esperado='data:image/png;base64,'+base64.b64encode(asset.read_bytes()).decode() if suffix else 'assets/img/qr-pago-demo.png'
                assert f'src="{esperado}"' in body
            else:
                assert 'El código QR no está disponible' in body
        evidence['pruebas'].append({'id':'H13','caso':'Ficha QR activa reutiliza imagen y descarga autocontenida; no inventa QR si falta el recurso','estado':'OK'})
        if args.browser_tools:
            child=env.copy(); child['CVS_BROWSER_TOOLS']=str(args.browser_tools.resolve()); child['CVS_TEST_BROWSER']=json.dumps({'url':url,'fixture':fixture,'qr':qr})
            browser=json.loads(run(['node',ROOT/'tests/pagos_navegador.cjs'],child))
            evidence['pruebas'].extend(browser['pruebas'])
            evidence['navegador']={'motor':'Chromium (Edge si está instalado)','version':browser['version'],'anchos':[360,390,768,1440],'imagen_qr':evidence['imagen_qr']}
        else:
            evidence['navegador']='No ejecutado: requiere --browser-tools'
        # Solo metadatos de versiones; nunca registrar cuerpos, cookies ni claves.
        evidence['versiones']={'php':run([args.php,'-v'],env).splitlines()[0], 'psql':run([pg/'psql.exe','--version'],env).strip()}
    except Exception as ex:
        evidence['pruebas'].append({'id':'RUNNER','caso':'Ejecucion completa del entorno aislado','estado':'ERROR','detalle':str(ex)})
    finally:
        if server:
            server.terminate(); server.wait(timeout=15)
        if log: log.close()
        if started: run([pg/'pg_ctl.exe','-D',work/'data','-m','fast','-w','stop'],env)
        if work.parent==temp_root and work.name.startswith('cvs-pagos-'): shutil.rmtree(work)
    evidence['resultado']='OK' if all(x['estado']=='OK' for x in evidence['pruebas']) else 'ERROR'
    if args.evidencia:
        args.evidencia.parent.mkdir(parents=True,exist_ok=True)
        args.evidencia.write_text(json.dumps(evidence,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    for case in evidence['pruebas']: print(f'{case["id"]}: {case["estado"]} - {case["caso"]}')
    if evidence['resultado']!='OK':
        for case in evidence['pruebas']:
            if case['estado']!='OK': print(json.dumps(case,ensure_ascii=False))
    return 0 if evidence['resultado']=='OK' else 1

if __name__=='__main__': raise SystemExit(main())
