<?php
declare(strict_types=1);
/**
 * Único punto de entrada de LUMI en esta etapa. Requiere sesión autenticada,
 * CSRF y cuota; con CVS_AI_ENABLED=0 nunca se sale hacia el proveedor y se
 * avisa sin inventar una respuesta. No registra el contenido de las consultas.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lumi.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$json = static function (array $datos, int $http = 200): never {
    http_response_code($http);
    exit(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
// Petición de la propia aplicación; más duro que solo cookies SameSite.
$sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
if ((in_array($accion, ['estado', 'configuracion', 'ir'], true) && $metodo !== 'GET') || (in_array($accion, ['mensaje', 'nueva'], true) && $metodo !== 'POST')) {
    header('Allow: ' . (in_array($accion, ['mensaje', 'nueva'], true) ? 'POST' : 'GET'));
    $json(['error' => 'Método no permitido.'], 405);
}
if (!in_array($accion, ['estado', 'configuracion', 'ir', 'mensaje', 'nueva'], true)) $json(['error' => 'Acción no válida.'], 400);
if ($sitio !== 'same-origin') $json(['error' => 'Solicitud no permitida.'], 403);

try {
    $usuario = cvs_usuario_actual();
    if ($usuario === null) {
        unset($_SESSION['lumi_conversacion']);
        $json(['error' => 'Tu sesión venció. Vuelve a iniciar sesión.'], 401);
    }
    if (!cvs_rol_valido($usuario['rol'] ?? null)) $json(['error' => 'Acceso no permitido.'], 403);
    if ($accion === 'ir') {
        $destino = lumi_destino_permitido($_GET['destino'] ?? null, $usuario['rol']);
        if ($destino === null) $json(['error' => 'Destino no disponible para tu cuenta.'], 403);
        lumi_conversacion_iniciar($usuario);
        $_SESSION['lumi_conversacion']['seccion'] = $_GET['destino'];
        header('Location: ' . $destino, true, 303); // Solo tras el clic explícito del usuario.
        exit;
    }
    if ($accion === 'configuracion') {
        $diagnostico = lumi_diagnostico_configuracion();
        if (!$diagnostico['acceso_local']) $json(['error' => 'Diagnóstico disponible solo en localhost.'], 403);
        $json($diagnostico); // Exclusivamente booleanos; no realiza ninguna llamada a Google.
    }
    if ($metodo === 'GET' && $accion === 'estado') {
        $config = lumi_config(cvs_conexion());
        $reanudada = lumi_conversacion_iniciar($usuario, $config);
        $json([
            'habilitada' => lumi_habilitada(),
            'localDisponible' => lumi_catalogo_permitido($usuario['rol']),
            'aviso' => lumi_aviso_configuracion(),
            'privacidad' => 'El catálogo y la guía se resuelven dentro del sistema. '
                . (lumi_habilitada() ? 'Para la conversación general, el texto de tu consulta y los turnos recientes pertinentes se envían a Google Gemini para generar la respuesta. ' : 'La conversación general con servicios externos no está disponible en este momento. ')
                . 'Los mensajes se conservan temporalmente en tu sesión; Nueva conversación borra el historial actual. Usa solo mensajes ficticios, sin nombres, documentos, contactos, claves ni información clínica personal. El dictado y algunas voces pueden usar servicios externos del navegador. No guardamos grabaciones de audio.',
            'nombre' => $config['nombre'],
            'saludo' => $config['saludo'],
            'tema' => $config['tema'],
            'voz' => $config['voz'],
            'csrf' => cvs_csrf_token(),
            'maxMensaje' => LUMI_MAX_MENSAJE,
            'maxHistorial' => LUMI_MAX_HISTORIAL,
            'maxHistorialCaracteres' => LUMI_MAX_HISTORIAL_CARACTERES,
            'funciones' => lumi_enlaces_permitidos($usuario['rol']),
            'conversacion' => lumi_conversacion_publica($usuario['rol'], $reanudada),
        ]);
    }
    if ($metodo === 'POST' && in_array($accion, ['mensaje', 'nueva'], true)) {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            $json(['error' => 'Se requiere contenido JSON.'], 415);
        }
        $bruto = file_get_contents('php://input', false, null, 0, LUMI_MAX_CUERPO + 1);
        if (!is_string($bruto) || $bruto === '' || strlen($bruto) > LUMI_MAX_CUERPO) {
            $json(['error' => 'Consulta demasiado extensa o vacía.'], 400);
        }
        try { $datos = json_decode($bruto, true, 6, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $json(['error' => 'Solicitud no válida.'], 400); }
        if (!is_array($datos) || array_is_list($datos) || array_diff(array_keys($datos), $accion === 'nueva' ? ['csrf'] : ['csrf', 'mensaje', 'historial'])) {
            $json(['error' => 'Formato de solicitud no válido.'], 400);
        }
        if (!cvs_csrf_valido($datos['csrf'] ?? null)) {
            $json(['error' => 'Sesión no válida. Recarga la página y vuelve a intentarlo.'], 403);
        }
        if ($accion === 'nueva') {
            unset($_SESSION['lumi_conversacion']); // No reiniciar la cuota de solicitudes.
            lumi_conversacion_iniciar($usuario, lumi_config(cvs_conexion()));
            $json(['estado' => 'ok', 'conversacion' => lumi_conversacion_publica($usuario['rol'], false)]);
        }
        if (!lumi_consumir_cuota()) {
            $json(['error' => 'Alcanzaste el límite temporal de consultas. Descansa un momento antes de escribir nuevamente.'], 429);
        }
        $mensaje = lumi_normalizar_mensaje($datos['mensaje'] ?? null);
        if ($mensaje === '') {
            $json(['error' => 'Escribe tu consulta en pocas palabras y sin datos personales.'], 400);
        }
        lumi_conversacion_iniciar($usuario);
        // La página referida solo orienta el contexto; nunca concede permisos.
        // Se ignoran query, formulario, texto del DOM y supuestos estados del cliente.
        $pagina = lumi_seccion_referida($_SERVER);
        if ($pagina !== null && lumi_destino_permitido($pagina, $usuario['rol']) !== null) {
            $_SESSION['lumi_conversacion']['seccion'] = $pagina;
        }
        // Catálogo resuelto en PHP antes de la guía genérica y de cualquier llamada IA.
        $catalogo = lumi_resolver_catalogo($mensaje, $usuario['rol'], cvs_conexion());
        if ($catalogo !== null) {
            lumi_conversacion_agregar($mensaje, $catalogo);
            $catalogo['conversacion'] = lumi_conversacion_publica($usuario['rol']);
            $json($catalogo);
        }
        $guia = lumi_resolver_guia($mensaje, $usuario['rol'], $_SESSION['lumi_conversacion']['seccion']);
        if ($guia !== null) {
            $guia = lumi_orientar_estado($guia, cvs_conexion(), $usuario['rol']);
            lumi_conversacion_agregar($mensaje, $guia);
            $guia['conversacion'] = lumi_conversacion_publica($usuario['rol']);
            $json($guia);
        }
        lumi_validar_privacidad($mensaje);
        // Compatibilidad de formato con clientes anteriores, nunca fuente de contexto.
        if (isset($datos['historial'])) lumi_normalizar_historial($datos['historial']);
        $historial = lumi_conversacion_contexto();
        if (!lumi_habilitada()) {
            // El chat local sigue disponible aunque Gemini esté deshabilitado.
            $aviso = ['estado'=>'ok', 'origen'=>'guia', 'texto'=>'Puedo ayudarte con el catálogo de la clínica («Qué especialidades ofrece la clínica», «Qué médicos atienden en [especialidad]») y a orientarte en el sistema. La conversación general sobre otros temas no está disponible en este momento.', 'navegacion'=>lumi_enlaces_permitidos($usuario['rol'])];
            lumi_conversacion_agregar($mensaje, $aviso);
            $aviso['conversacion'] = lumi_conversacion_publica($usuario['rol']);
            $json($aviso);
        }
        $resultado = lumi_consulta_ia(lumi_config(cvs_conexion()), $historial, $mensaje);
        lumi_conversacion_agregar($mensaje, $resultado);
        $resultado['conversacion'] = lumi_conversacion_publica($usuario['rol']);
        $json($resultado, ($resultado['estado'] ?? 'error') === 'ok' ? 200 : 503);
    }
    $json(['error' => 'Acción no válida.'], 400);
} catch (DomainException $ex) {
    $json(['error' => $ex->getMessage()], 400);
} catch (Throwable $ex) {
    // Ni contenido, clave, mensajes del proveedor ni trazas en logs.
    error_log('LUMI: fallo interno al atender una consulta.');
    $json(['error' => 'La asistente no está disponible en este momento.'], 503);
}
