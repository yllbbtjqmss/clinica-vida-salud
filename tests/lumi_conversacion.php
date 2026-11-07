<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/lumi.php';
$_SESSION = [];
$usuario = ['id_usuario' => 101, 'rol' => 'paciente'];
$casos = 0;
function verificar_continuidad(bool $ok, string $nombre): void {
    global $casos;
    if (!$ok) throw new RuntimeException('FALLO: ' . $nombre);
    $casos++;
}
verificar_continuidad(!lumi_conversacion_iniciar($usuario), 'Creación nueva');
verificar_continuidad(lumi_conversacion_iniciar($usuario), 'Misma sesión reanuda');
$guia = lumi_resolver_guia('Cómo reservar una cita', 'paciente');
lumi_conversacion_agregar('Cómo reservar una cita', $guia);
lumi_conversacion_agregar('Consulta ficticia A', ['estado'=>'ok', 'texto'=>'Respuesta ficticia A']);
lumi_conversacion_agregar('Consulta ficticia B', ['estado'=>'ok', 'texto'=>'Respuesta ficticia B']);
$publica = lumi_conversacion_publica('paciente');
verificar_continuidad(count($publica['mensajes']) === 7, 'Se conservan guías, usuario y Gemini');
verificar_continuidad(count(array_filter($publica['mensajes'], fn($m) => $m['origen'] === 'bienvenida')) === 1, 'Una bienvenida');
$contexto = lumi_conversacion_contexto();
verificar_continuidad(count($contexto) === 4 && $contexto[0]['parts'][0]['text'] === 'Consulta ficticia A', 'Contexto Gemini sin guía local');
verificar_continuidad(!isset($publica['propietario']) && !str_contains(json_encode($publica), 'id_usuario'), 'No se expone la identidad interna');
verificar_continuidad($publica['mensajes'][2]['navegacion'][0]['id'] === 'reservar', 'Tarjeta conservada con IDs');
verificar_continuidad(lumi_conversacion_publica('medico')['mensajes'][2]['navegacion'] === [], 'Tarjeta filtrada con permisos vigentes');
verificar_continuidad(!lumi_conversacion_iniciar(['id_usuario'=>102,'rol'=>'paciente']) && count(lumi_conversacion_publica('paciente')['mensajes']) === 1, 'Cuenta distinta, mismo rol, sin mezcla');
lumi_conversacion_agregar('Consulta de prueba', ['estado'=>'ok','texto'=>'Respuesta de prueba']);
verificar_continuidad(!lumi_conversacion_iniciar(['id_usuario'=>102,'rol'=>'administrador']) && count(lumi_conversacion_publica('administrador')['mensajes']) === 1, 'Cambio de rol limpia mensajes');
for ($i = 0; $i < 20; $i++) lumi_conversacion_agregar('Consulta ' . $i, ['estado'=>'ok','texto'=>str_repeat('Texto ficticio. ', 100)]);
$publica = lumi_conversacion_publica('administrador');
verificar_continuidad(count($publica['mensajes']) <= LUMI_MAX_MENSAJES_SESION && array_sum(array_map(fn($m)=>mb_strlen($m['contenido']),$publica['mensajes'])) <= LUMI_MAX_CARACTERES_SESION, 'Historial visual limitado');
$contexto = lumi_conversacion_contexto();
verificar_continuidad(count($contexto) <= 16 && mb_strlen(implode('', array_map(fn($m)=>$m['parts'][0]['text'],$contexto))) <= LUMI_MAX_HISTORIAL_CARACTERES, 'Contexto Gemini limitado');
lumi_conversacion_agregar('Mi nombre es PERSONA FICTICIA', ['estado'=>'ok','origen'=>'guia','texto'=>'Orientación general.']);
verificar_continuidad(!str_contains(json_encode(lumi_conversacion_publica('administrador')), 'PERSONA FICTICIA'), 'No conserva información detectada sensible');
$_SESSION['lumi_uso'] = ['ventana'=>time(),'solicitudes'=>20];
unset($_SESSION['lumi_conversacion']);
lumi_conversacion_iniciar($usuario);
verificar_continuidad(count(lumi_conversacion_publica('paciente')['mensajes']) === 1 && $_SESSION['lumi_uso']['solicitudes'] === 20, 'Nueva conversación sin evadir cuota');
$_SESSION = []; // Comportamiento del logout existente.
verificar_continuidad(!lumi_conversacion_iniciar($usuario) && count(lumi_conversacion_publica('paciente')['mensajes']) === 1, 'Cerrar sesión elimina la conversación');
verificar_continuidad(lumi_resolver_guia('¿Qué hago ahora?', 'paciente', 'reservar')['navegacion'][0]['id'] === 'reservar', 'Seguimiento local de sección');
echo "OK: $casos comprobaciones de continuidad, separación, privacidad y límites; sin BD ni Gemini.\n";
