"""Endpoint Lumi real en copia temporal, sesiones ficticias y PDO simulado. Sin BD/red externa."""
from pathlib import Path
import argparse
import http.cookiejar
import json
import os
import secrets
import shutil
import socket
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('CVS_TEST_PHP', r'C:\xampp\php\php.exe')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--browser-tools', type=Path)
    args = parser.parse_args()
    padre = Path(os.environ['LOCALAPPDATA']) / 'Temp' / 'opencode'
    with tempfile.TemporaryDirectory(prefix='lumi-http-', dir=padre) as temporal:
        work = Path(temporal)
        (work / 'includes').mkdir()
        (work / 'sesiones').mkdir()
        shutil.copyfile(ROOT / 'lumi_api.php', work / 'lumi_api.php')
        shutil.copyfile(ROOT / 'includes/lumi.php', work / 'includes/lumi.php')
        shutil.copyfile(ROOT / 'includes/lumi_navegacion.php', work / 'includes/lumi_navegacion.php')
        shutil.copyfile(ROOT / 'includes/lumi_conversacion.php', work / 'includes/lumi_conversacion.php')
        shutil.copyfile(ROOT / 'includes/lumi_estado_pagina.php', work / 'includes/lumi_estado_pagina.php')
        shutil.copyfile(ROOT / 'includes/lumi_catalogo.php', work / 'includes/lumi_catalogo.php')
        shutil.copyfile(ROOT / 'tests/lumi_catalogo_dobles.php', work / 'includes/lumi_catalogo_dobles.php')
        shutil.copyfile(ROOT / 'includes/ui.php', work / 'includes/ui.php')
        shutil.copyfile(ROOT / 'includes/sesion.php', work / 'includes/sesion.php')
        shutil.copyfile(ROOT / 'logout.php', work / 'logout.php')
        (work / 'assets').mkdir()
        for asset in ['lumi.js', 'lumi-voz.js', 'lumi.css', 'estilos.css']:
            shutil.copyfile(ROOT / 'assets' / asset, work / 'assets' / asset)
        # Inyección de transporte SOLO en la copia temporal; nunca una opción productiva.
        api = (work / 'lumi_api.php').read_text(encoding='utf8')
        llamada = 'lumi_consulta_ia(lumi_config(cvs_conexion()), $historial, $mensaje)'
        assert api.count(llamada) == 1, 'Actualizar la inyección del test al contrato PHP real'
        (work / 'lumi_api.php').write_text(api.replace(llamada, llamada[:-1] + ", '', 'lumi_http_ficticio')"), encoding='utf8')
        sesion = (ROOT / 'includes/sesion.php').as_posix()
        # Reemplazo de autenticación solo en esta copia efímera. El repositorio no se altera.
        (work / 'includes/auth.php').write_text('''<?php
require_once ''' + repr(sesion) + ''';
require_once __DIR__.'/lumi_catalogo_dobles.php';
// Configuraciones ficticias, exclusivas de la copia temporal para pruebas GET.
if (isset($_SESSION['escenario'])) {
    putenv('CVS_AI_ENABLED=' . ($_SESSION['escenario'] === 'apagada' ? '0' : '1'));
    $marcador = in_array($_SESSION['escenario'], ['auth', 'auth_espacios'], true)
        ? getenv('LUMI_TEST_AUTH_MARKER') : getenv('LUMI_TEST_MARKER');
    if ($_SESSION['escenario'] === 'clave_invalida') $marcador = 'cadena-arbitraria-no-admitida';
    $espacios = in_array($_SESSION['escenario'], ['espacios', 'auth_espacios'], true);
    putenv('GEMINI_API_KEY=' . ($espacios ? " " . $marcador . "\\r\\n" : $marcador));
    putenv('CVS_AI_MODEL=' . ($_SESSION['escenario'] === 'modelo_invalido' ? 'modelo-no-gemini' : " gemini-2.5-flash-lite\\t"));
}
function cvs_rol_valido(mixed $rol): bool { return in_array($rol, ['paciente','administrador','medico','recepcionista'], true); }
function cvs_usuario_actual(): ?array {
    return isset($_SESSION['rol_ficticio']) ? ['id_usuario'=>$_SESSION['cuenta_ficticia'], 'nombre_usuario'=>'Cuenta ficticia', 'rol'=>$_SESSION['rol_ficticio']] : null;
}
function lumi_http_ficticio(string $url, array $headers, string $body): array {
    $_SESSION['prueba_llamadas'] = ($_SESSION['prueba_llamadas'] ?? 0) + 1;
    $_SESSION['prueba_contexto'] = json_decode($body, true)['contents'];
    return ['http'=>200, 'error'=>'', 'cuerpo'=>json_encode(['candidates'=>[[
        'finishReason'=>'STOP', 'content'=>['parts'=>[['text'=>'Respuesta ficticia conservada.']]]
    ]]])];
}
function cvs_activo(mixed $v): bool { return $v === true; }
class LumiFakeStatement extends PDOStatement {
    public function __construct(private mixed $valor = false) {}
    public function fetchColumn(int $column = 0): mixed { return $this->valor; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->valor; }
}
class LumiFakePDO extends LumiCatalogoFakePDO {
    public function __construct() { parent::__construct($_SESSION['catalogo_ficticio'] ?? 'normal'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, "to_regclass('public.pagos')")) {
            if (($_SESSION['estado_pagina_ficticio'] ?? '') === 'error') throw new PDOException('Fallo ficticio');
            return new LumiFakeStatement(($_SESSION['estado_pagina_ficticio'] ?? '') !== 'pendiente');
        }
        if (str_contains($query, 'AS especialidades')) {
            $oferta = array_fill_keys(['especialidades','medicos','tarifas','horarios'], true);
            $faltante = $_SESSION['estado_pagina_ficticio'] ?? '';
            if (isset($oferta[$faltante])) $oferta[$faltante] = false;
            return new LumiFakeStatement($oferta);
        }
        return new LumiFakeStatement();
    }
}
function cvs_conexion(): PDO { return new LumiFakePDO(); }
''', encoding='utf8')
        (work / 'login.php').write_text('''<?php
require_once __DIR__.'/includes/auth.php';
if (!isset($_GET['rol'])) { header('Content-Type: text/html; charset=utf-8'); echo '<h1>Login de prueba sin sesión iniciada</h1>'; exit; }
session_regenerate_id(true);
$_SESSION['rol_ficticio'] = $_GET['rol'] ?? 'paciente';
$_SESSION['cuenta_ficticia'] = (int)($_GET['cuenta'] ?? 1);
$_SESSION['estado_pagina_ficticio'] = $_GET['pagina'] ?? 'normal';
$_SESSION['catalogo_ficticio'] = $_GET['catalogo'] ?? 'normal';
$_SESSION['cvs_csrf'] = bin2hex(random_bytes(32));
unset($_SESSION['lumi_uso']);
if (isset($_GET['escenario'])) $_SESSION['escenario'] = $_GET['escenario'];
else unset($_SESSION['escenario']);
header('Content-Type: application/json');
echo json_encode(['csrf'=>cvs_csrf_token()]);
''', encoding='utf8')
        (work / 'vista.php').write_text('''<?php
require_once __DIR__.'/includes/auth.php';
if (!cvs_usuario_actual()) { header('Location: login.php',true,303); exit; }
require_once __DIR__.'/includes/ui.php';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/estilos.css"><title>Vista ficticia</title></head><body>';
cvsu_area('inicio','Vista ficticia de continuidad');
echo '<main class="ui-contenido"><h1>Sección ficticia de prueba</h1></main>';
cvsu_cierre(); echo '</body></html>';
''', encoding='utf8')
        rutas = json.loads(subprocess.check_output([PHP, '-r', "require " + repr((ROOT / 'includes/lumi_navegacion.php').as_posix()) + ";echo json_encode(array_column(lumi_catalogo_funciones(),'ruta'));"], encoding='utf8'))
        for ruta in rutas:
            (work / ruta).write_text("<?php require __DIR__.'/vista.php';", encoding='utf8')
        (work / 'metricas.php').write_text('''<?php
require_once __DIR__.'/includes/auth.php';
header('Content-Type: application/json');
echo json_encode(['llamadas'=>$_SESSION['prueba_llamadas'] ?? 0,'contexto'=>$_SESSION['prueba_contexto'] ?? []]);
''', encoding='utf8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = {k: v for k, v in os.environ.items() if not k.startswith(('CVS_DB_', 'GEMINI_', 'OPENAI_'))}
        env.update(CVS_AI_ENABLED='0', CVS_AI_MODEL='', CVS_HTTPS='0',
                   LUMI_TEST_MARKER='AIza' + secrets.token_urlsafe(26),
                   LUMI_TEST_AUTH_MARKER='AQ.' + secrets.token_urlsafe(37))
        with (work / 'http.log').open('w', encoding='utf8') as log:
            server = subprocess.Popen([PHP, '-d', 'disable_functions=curl_exec', '-d', f'session.save_path={work / "sesiones"}', '-S',
                                       f'127.0.0.1:{port}', '-t', str(work)], env=env,
                                      stdin=subprocess.DEVNULL, stdout=log, stderr=log)
            try:
                url = f'http://127.0.0.1:{port}/'
                # Bloqueo corto en conexión, sin acceder a la aplicación principal.
                for _ in range(200):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=0.05):
                            break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError('No inició el servidor PHP aislado')
                else:
                    raise RuntimeError('Tiempo agotado al iniciar PHP')
                class SinRedireccion(urllib.request.HTTPRedirectHandler):
                    def redirect_request(self, req, fp, code, msg, headers, newurl):
                        return None
                cliente = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), SinRedireccion())

                def req(ruta, datos=None, metodo=None, cabeceras=None, crudo=None):
                    headers = {'Content-Type': 'application/json'}
                    headers.update(cabeceras or {})
                    body = crudo if crudo is not None else (None if datos is None else json.dumps(datos).encode())
                    request = urllib.request.Request(url + ruta, body, headers, method=metodo)
                    try:
                        response = cliente.open(request, timeout=5)
                    except urllib.error.HTTPError as ex:
                        response = ex
                    return response.status, json.loads(response.read() or b'{}')

                def ir(destino):
                    request = urllib.request.Request(url + 'lumi_api.php?accion=ir&destino=' + urllib.parse.quote(destino, safe=''))
                    try:
                        response = cliente.open(request, timeout=5)
                    except urllib.error.HTTPError as ex:
                        response = ex
                    return response.status, response.headers.get('Location')

                assert req('lumi_api.php?accion=estado')[0] == 401
                assert req('lumi_api.php?accion=configuracion')[0] == 401
                print('OK sesión ausente responde JSON 401')
                for rol in ['paciente', 'administrador', 'medico', 'recepcionista']:
                    token = req('login.php?rol=' + rol)[1]['csrf']
                    status, estado = req('lumi_api.php?accion=estado')
                    assert status == 200 and estado['habilitada'] is False and estado['localDisponible'] is True and estado['aviso']
                    assert not any(k in estado for k in ['GEMINI_API_KEY', 'clave', 'apiKey'])
                print('OK cuatro roles ficticios, estado deshabilitado y sin secretos')
                assert req('lumi_api.php?accion=mensaje')[0] == 405
                assert req('lumi_api.php?accion=estado', {}, 'POST')[0] == 405
                assert req('lumi_api.php?accion=mensaje', {}, cabeceras={'Content-Type': 'text/plain'})[0] == 415
                assert req('lumi_api.php?accion=mensaje', crudo=b'{invalido', metodo='POST')[0] == 400
                assert req('lumi_api.php?accion=mensaje', {'csrf': 'invalido', 'mensaje': 'Hola'})[0] == 403
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola'}, cabeceras={'Sec-Fetch-Site': 'cross-site'})[0] == 403
                print('OK métodos, JSON, CSRF y origen')
                for mensaje in [[], '', 'a' * 701, 'Mi nombre es PERSONA FICTICIA', 'ficticio@example.invalid']:
                    assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': mensaje})[0] == 400
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola', 'model': 'no-permitido'})[0] == 400
                assert req('lumi_api.php?accion=mensaje', crudo=b'x' * 32769, metodo='POST')[0] == 400
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola', 'historial': {}})[0] == 200  # JSON {} se decodifica como array vacío PHP
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola', 'historial': 'invalido'})[0] == 400
                print('OK tamaño, formato e información sensible rechazados antes del proveedor')
                token = req('login.php?rol=paciente')[1]['csrf']
                for _ in range(20):
                    status, datos = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola'})
                    assert status == 200 and datos['origen'] == 'guia' and 'catálogo de la clínica' in datos['texto']
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola'})[0] == 429
                assert req('lumi_api.php?accion=nueva', {'csrf': token})[0] == 200
                assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Hola'})[0] == 429
                print('OK límite de solicitudes; ninguna respuesta IA inventada')
                esperados = {
                    'paciente': {'inicio', 'reservar', 'mis_citas', 'pagos'},
                    'medico': {'inicio', 'mi_agenda'},
                    'recepcionista': {'inicio', 'pacientes', 'registrar', 'reprogramar', 'cancelar', 'asistencia', 'historial', 'agenda', 'tarifas', 'pagos'},
                    'administrador': {'inicio', 'pacientes', 'registrar', 'reprogramar', 'cancelar', 'asistencia', 'historial', 'agenda', 'tarifas', 'pagos', 'medicos', 'especialidades', 'horarios', 'usuarios', 'reportes', 'economicos', 'preferencias_lumi'},
                }
                for rol, ids in esperados.items():
                    token = req('login.php?rol=' + rol)[1]['csrf']
                    status, estado = req('lumi_api.php?accion=estado')
                    assert {f['id'] for f in estado['funciones']} == ids
                    status, guia = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Muéstrame las funciones del sistema'})
                    assert status == 200 and guia['origen'] == 'guia' and {f['id'] for f in guia['navegacion']} == ids
                    # Redirección autorizada, sin abrir páginas de negocio ni conectar BD.
                    for destino in set.union(*esperados.values()):
                        status, location = ir(destino)
                        assert (status == 303 and location.endswith('.php') and '?' not in location) if destino in ids else (status == 403 and location is None)
                    assert ir('https://example.invalid')[0] == 403
                    assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Abrir usuarios', 'rol': 'administrador'})[0] == 400
                req('login.php?rol=administrador')
                assert ir('usuarios')[0] == 303
                req('login.php?rol=paciente')
                assert ir('usuarios')[0] == 403  # enlace antiguo, rol revalidado al pulsarlo
                print('OK catálogo y navegación por cuatro roles; destinos arbitrarios y escalada bloqueados; sin seguir enlaces automáticamente')
                # Solo GET tras habilitar escenarios: jamás se llama al transporte Gemini.
                for escenario in ['completa', 'espacios', 'auth', 'auth_espacios', 'modelo_invalido', 'clave_invalida']:
                    req('login.php?rol=paciente&escenario=' + escenario)
                    status, diagnostico = req('lumi_api.php?accion=configuracion')
                    assert status == 200 and all(type(v) is bool for v in diagnostico.values())
                    assert diagnostico['configuracion_valida'] is (escenario not in ['modelo_invalido', 'clave_invalida'])
                    assert diagnostico['clave_formato_valido'] is (escenario != 'clave_invalida')
                    status, estado = req('lumi_api.php?accion=estado')
                    assert status == 200 and estado['habilitada'] is diagnostico['configuracion_valida']
                    assert ('se envían a Google Gemini' in estado['privacidad']) is estado['habilitada']
                    assert diagnostico['clave_con_espacios_exteriores'] is (escenario in ['espacios', 'auth_espacios'])
                    assert not any(env[k] in json.dumps(estado) for k in ['LUMI_TEST_MARKER', 'LUMI_TEST_AUTH_MARKER'])
                print('OK PHP HTTP admite AIza y AQ., con normalización; diagnóstico booleano sin tokens; clave y modelo inválidos bloqueados')
                token = req('login.php?rol=paciente&escenario=completa&cuenta=10')[1]['csrf']
                estado = req('lumi_api.php?accion=estado')[1]
                assert len(estado['conversacion']['mensajes']) == 1 and estado['conversacion']['reanudada'] is False
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Prueba general uno'})
                assert req('metricas.php')[1]['llamadas'] == 1
                primera = req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes']
                assert len(primera) == 3
                assert ir('reservar')[0] == 303
                assert req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes'] == primera
                assert req('metricas.php')[1]['llamadas'] == 1
                seguimiento = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': '¿Qué hago ahora?'})[1]
                assert seguimiento['origen'] == 'guia' and seguimiento['navegacion'][0]['id'] == 'reservar'
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Prueba general dos', 'historial': [
                    {'rol': 'usuario', 'contenido': 'CONTEXTO INVENTADO POR EL CLIENTE'},
                    {'rol': 'lumi', 'contenido': 'Otra respuesta ficticia'},
                ]})
                metricas = req('metricas.php')[1]
                assert metricas['llamadas'] == 2 and len(metricas['contexto']) == 3
                assert metricas['contexto'][0]['parts'][0]['text'] == 'Prueba general uno'
                assert 'CONTEXTO INVENTADO' not in json.dumps(metricas['contexto'])
                assert req('lumi_api.php?accion=nueva', {'csrf': 'invalido'})[0] == 403
                assert req('lumi_api.php?accion=nueva', {'csrf': token})[1]['conversacion']['mensajes'] == estado['conversacion']['mensajes']
                assert req('metricas.php')[1]['llamadas'] == 2
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Abrir pagos'})
                req('login.php?rol=paciente&escenario=completa&cuenta=11')
                assert len(req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes']) == 1
                req('login.php?rol=administrador&escenario=completa&cuenta=11')
                assert len(req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes']) == 1
                token = req('login.php?rol=paciente&escenario=completa&cuenta=10')[1]['csrf']
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Abrir mis citas'})
                status, _ = req('logout.php', crudo=urllib.parse.urlencode({'csrf': token}).encode(), metodo='POST', cabeceras={'Content-Type': 'application/x-www-form-urlencoded'})
                assert status == 303 and req('lumi_api.php?accion=estado')[0] == 401
                req('login.php?rol=paciente&escenario=completa&cuenta=10')
                assert len(req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes']) == 1
                print('OK continuidad PHP: restaura sin Gemini duplicado, contexto limitado del servidor, seguimiento, reinicio, cuenta/rol/sesión y logout')
                llamadas = req('metricas.php')[1]['llamadas']
                for pagina, situacion in [('pendiente', 'instalacion_pendiente'), ('especialidades', 'sin_datos'),
                                           ('medicos', 'sin_datos'), ('tarifas', 'sin_datos'), ('horarios', 'sin_datos'),
                                           ('normal', 'normal'), ('error', 'no_verificado')]:
                    token = req(f'login.php?rol=paciente&escenario=completa&pagina={pagina}')[1]['csrf']
                    req('lumi_api.php?accion=estado')
                    # Sin clic en Lumi: la página directa determina el seguimiento, no el historial viejo.
                    respuesta = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': '¿Qué hago después?'},
                                    cabeceras={'Referer': url + 'reservar_cita.php?ignorado=1'})[1]
                    assert respuesta['seccion'] == 'reservar' and respuesta['situacion'] == situacion, respuesta
                    exacta = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Ya elegí especialidad y médico, ¿qué hago después?'})[1]
                    assert exacta['situacion'] == situacion and exacta['navegacion'][0]['id'] == 'reservar'
                    assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Abrir Médicos'})[1]['situacion'] == 'acceso_denegado'
                    assert req('metricas.php')[1]['llamadas'] == llamadas
                    conservada = req('lumi_api.php?accion=estado')[1]['conversacion']['mensajes']
                    assert exacta['texto'] in [m['contenido'] for m in conservada]
                    assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': '¿Qué hago después?', 'estado_pagina': 'normal'})[0] == 400
                print('OK estado de página: permisos, instalación, cuatro catálogos insuficientes, flujo normal y fallo seguro; sin llamadas Gemini ni estados del cliente')
                llamadas = req('metricas.php')[1]['llamadas']
                for rol in ['paciente', 'medico', 'recepcionista', 'administrador']:
                    token = req(f'login.php?rol={rol}&escenario=apagada')[1]['csrf']
                    estado = req('lumi_api.php?accion=estado')[1]
                    assert estado['habilitada'] is False and estado['localDisponible'] is True
                    assert 'conversación general con servicios externos no está disponible' in estado['privacidad']
                    especialidades = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué especialidades existen'})[1]
                    assert especialidades['fuente'] == 'catalogo_local' and 'Cardiología' in especialidades['texto'] and 'Neurología' not in especialidades['texto']
                    for nombre, esperado in [('Cardiología', 'Profesional Beta'), ('Pediatría', 'Profesional Gamma')]:
                        medicos = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': f'Qué médicos hay en {nombre}'})[1]
                        assert esperado in medicos['texto'] and medicos['origen'] == 'guia'
                        assert not any(p in json.dumps(medicos) for p in ['PRIVADO_', 'id_medico', 'registro_profesional', 'telefono', 'correo'])
                        assert {n['id'] for n in medicos['navegacion']} <= esperados[rol]
                    ausente = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué médicos hay en Especialidad Inexistente'})[1]
                    assert ausente['situacion'] == 'sin_datos'
                    sin_medicos = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué médicos hay en Medicina General'})[1]
                    assert sin_medicos['situacion'] == 'sin_datos' and 'no hay médicos asociados' in sin_medicos['texto']
                    general = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Prueba general sin proveedor'})[1]
                    assert general['origen'] == 'guia' and 'catálogo de la clínica' in general['texto']
                    assert req('lumi_api.php?accion=estado')[1]['localDisponible'] is True
                    assert req('metricas.php')[1]['llamadas'] == llamadas
                    assert req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué especialidades existen', 'sql': 'SELECT * FROM usuarios'})[0] == 400
                for catalogo, situacion in [('vacio', 'sin_datos'), ('fallo', 'no_verificado')]:
                    token = req(f'login.php?rol=paciente&escenario=completa&catalogo={catalogo}')[1]['csrf']
                    error = req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué especialidades existen'})[1]
                    assert error['situacion'] == situacion and 'DETALLE_PRIVADO' not in json.dumps(error)
                    assert req('metricas.php')[1]['llamadas'] == llamadas
                token = req('login.php?rol=paciente&escenario=completa')[1]['csrf']
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Qué médicos hay en Cardiología'})
                req('lumi_api.php?accion=mensaje', {'csrf': token, 'mensaje': 'Consulta general ficticia posterior'})
                posterior = req('metricas.php')[1]
                assert posterior['llamadas'] == llamadas + 1
                assert len(posterior['contexto']) == 1 and 'Profesional' not in json.dumps(posterior['contexto']) and 'Cardio' not in json.dumps(posterior['contexto'])
                print('OK catálogo local sin Gemini: cuatro roles, asociaciones, ausencias, fallos seguros, continuidad y aislamiento del contexto del proveedor')
                if args.browser_tools:
                    browser_env = env.copy()
                    browser_env.update(CVS_BROWSER_TOOLS=str(args.browser_tools.resolve()), CVS_LUMI_TEST_URL=url)
                    subprocess.run(['node', str(ROOT / 'tests/lumi_continuidad.cjs')], env=browser_env, check=True, timeout=120)
                    subprocess.run(['node', str(ROOT / 'tests/lumi_catalogo.cjs')], env=browser_env, check=True, timeout=120)
                    subprocess.run(['node', str(ROOT / 'tests/lumi_activacion.cjs')], env=browser_env, check=True, timeout=180)
            finally:
                server.terminate()
                server.wait(timeout=10)
    print('TOTAL: endpoint probado con sesiones/DB simuladas; sin Gemini ni PostgreSQL reales.')


if __name__ == '__main__':
    main()
