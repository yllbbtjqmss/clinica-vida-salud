<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/lumi.php';
require_once __DIR__ . '/lumi_catalogo_dobles.php';
$casos=0;
function comprobar_catalogo(bool $ok,string $caso): void {
    global $casos;
    if (!$ok) throw new RuntimeException('FALLO: '.$caso);
    $casos++;
}
foreach (['paciente','medico','recepcionista','administrador'] as $rol) {
    $p=new LumiCatalogoFakePDO();
    $especialidades=lumi_resolver_catalogo('¿Qué especialidades existen?',$rol,$p);
    comprobar_catalogo(str_contains($especialidades['texto'],'Cardiología') && str_contains($especialidades['texto'],'Pediatría') && !str_contains($especialidades['texto'],'Neurología'), 'Activas solamente, rol '.$rol);
    foreach (['Cardiología'=>['Alfa','Beta'], 'Pediatría'=>['Alfa','Gamma'], 'Medicina General'=>[]] as $nombre=>$esperados) {
        $r=lumi_resolver_catalogo('¿Qué médicos hay en '.$nombre.'?',$rol,$p);
        comprobar_catalogo($r['origen']==='guia' && $r['fuente']==='catalogo_local','Origen local');
        foreach ($esperados as $esperado) comprobar_catalogo(str_contains($r['texto'],$esperado),'Asociación '.$nombre.' '.$esperado);
        comprobar_catalogo(!str_contains($r['texto'],'Inactivo') && !str_contains($r['texto'],'Otra'),'No mezclar inactivos/otras relaciones');
        comprobar_catalogo(($r['situacion']==='sin_datos') === !$esperados,'Especialidad sin médicos');
        comprobar_catalogo(!preg_match('/PRIVADO_|id_medico|id_usuario|registro_profesional|telefono|correo/',json_encode($r)),'Sin datos privados ni filas internas');
        comprobar_catalogo(array_diff(array_column($r['navegacion'],'id'),array_keys(lumi_funciones_permitidas($rol)))===[],'Enlaces filtrados por rol');
    }
}
$p=new LumiCatalogoFakePDO();
foreach (['¿Qué médicos hay en cardiologia?', 'Qué médicos están asociados a la especialidad de Cardiología', 'Muéstrame los médicos de Cardiología', '¿Qué médicos atienden Cardiología?'] as $frase) {
    comprobar_catalogo(str_contains(lumi_resolver_catalogo($frase,'paciente',$p)['texto'],'Profesional Alfa'),'Tildes y variantes de consulta');
}
foreach (['¿Existe la especialidad Cardiología?', '¿Existe la especialidad cardiologia?'] as $frase) comprobar_catalogo(str_contains(lumi_resolver_catalogo($frase,'paciente',$p)['texto'],'está disponible'),'Especialidad existente');
foreach (['Especialidad Inexistente', 'Neurología', "Cardiología'; DELETE FROM medicos; --", '%', '_'] as $nombre) {
    $r=lumi_resolver_catalogo('Qué médicos hay en '.$nombre,'paciente',$p);
    comprobar_catalogo($r['situacion']==='sin_datos' && !str_contains($r['texto'],'Alfa'),'Ausencia/inactividad/inyección sin resultados inventados');
}
comprobar_catalogo(lumi_resolver_catalogo('Qué especialidades existen','paciente',new LumiCatalogoFakePDO('vacio'))['situacion']==='sin_datos','Catálogo vacío');
comprobar_catalogo(lumi_resolver_catalogo('Qué médicos hay en Cardiología','paciente',new LumiCatalogoFakePDO('sin_medicos'))['situacion']==='sin_datos','Sin médicos');
$r=lumi_resolver_catalogo('Qué médicos hay en Cardiología','paciente',new LumiCatalogoFakePDO('fallo'));
comprobar_catalogo($r['situacion']==='no_verificado' && !str_contains($r['texto'],'DETALLE_PRIVADO'),'Error de PostgreSQL seguro');
$r=lumi_resolver_catalogo('Qué médicos hay','paciente',$p);
comprobar_catalogo(str_contains($r['texto'],'¿De qué especialidad'),'Aclarar antes de elegir');
foreach (['¿Qué médicos tienen citas libres hoy?', '¿Hay médicos disponibles?', 'Qué médicos tienen horarios libres'] as $frase) {
    $sinConsulta=new LumiCatalogoFakePDO();
    $r=lumi_resolver_catalogo($frase,'paciente',$sinConsulta);
    comprobar_catalogo($r!==null && str_contains($r['texto'],'No consulto horarios') && !$sinConsulta->consultasCatalogo,'No consultar disponibilidad');
}
foreach (['Abrir médicos','Abrir Especialidades','Cómo seleccionar especialidad y médico para reservar','Ya elegí especialidad y médico, ¿qué hago después?','Cómo reservar una cita','Prueba general ficticia'] as $frase) comprobar_catalogo(lumi_resolver_catalogo($frase,'paciente',$p)===null,'Mantener navegación/guía/Gemini');
foreach (['paciente', 'medico', 'recepcionista', 'administrador'] as $rol) {
    foreach (['Qué médicos hay en la lista de espera', 'Qué especialidades hay en lista de espera', 'Registrar una solicitud en lista de espera'] as $frase) {
        $sinConsulta = new LumiCatalogoFakePDO();
        comprobar_catalogo(lumi_resolver_catalogo($frase, $rol, $sinConsulta) === null && !$sinConsulta->consultasCatalogo, 'Lista de espera se resuelve por guía autorizada, sin SQL de catálogo');
    }
}
// Regresión etapa 4B: la intención de guía gana aunque aparezca la palabra «médico»; sin catálogo.
foreach (['¿Qué sigue después de seleccionar un médico?', '¿Qué sigue?', '¿Qué viene después de elegir especialidad?', 'Después de seleccionar un médico, ¿qué sigue?'] as $frase) {
    comprobar_catalogo(lumi_resolver_catalogo($frase,'paciente',$p)===null,'Guida ganó sobre «médico» en la frase de seguimiento: '.$frase);
    foreach ([null, 'inicio', 'reservar'] as $contexto) {
        $guia=lumi_resolver_guia($frase,'paciente',$contexto);
        comprobar_catalogo(($guia['seccion'] ?? '')==='reservar','«¿Qué sigue después…?» orienta el formulario sin catálogo y sin ser secuestrada por la página: '.$frase.' (contexto '.$contexto.')');
        comprobar_catalogo(str_contains($guia['texto'] ?? '','bloque disponible') && str_contains($guia['texto'],'Revisar resumen'),'Instrucciones del formulario real de reservar: '.$frase);
    }
}
// Regresión de rol: las frases de paso de otros roles no eligen secciones administrativas ni el formulario ajeno.
foreach (['medico', 'recepcionista', 'administrador'] as $rolOtro) {
    foreach (['¿Qué sigue después de seleccionar un médico?', 'Ya elegí especialidad y médico, ¿qué hago ahora?'] as $fraseOtro) {
        $guiaOtro = lumi_resolver_guia($fraseOtro, $rolOtro, 'inicio');
        comprobar_catalogo(($guiaOtro['seccion'] ?? '')==='' && str_contains($guiaOtro['texto'] ?? '','Puedo orientarte paso a paso'),'Orientación por rol sin formulario ajeno: '.$rolOtro.' — '.$fraseOtro);
    }
}
// Regresión: conectores intermedios («atienden en X», «quién atiende en X») y variantes naturales.
$p=new LumiCatalogoFakePDO();
foreach (['¿Qué médicos atienden en Pediatría?', '¿Qué médicos atiende en pediatria?', '¿Quién atiende en Pediatría?', '¿Quiénes atienden en la Pediatría?', '¿Qué médicos hay en la especialidad de Pediatría?'] as $frase) {
    $r=lumi_resolver_catalogo($frase,'paciente',$p);
    comprobar_catalogo(str_contains($r['texto'],'Profesional Alfa') && str_contains($r['texto'],'Profesional Gamma'),'Conector intermedio sin perder el nombre: '.$frase);
}
foreach (['¿Qué médicos hay en Medicina General?', '¿Quién atiende en Medicina General?'] as $frase) {
    $r=lumi_resolver_catalogo($frase,'paciente',$p);
    comprobar_catalogo($r['situacion']==='sin_datos' && str_contains($r['texto'],'no hay médicos asociados'),'Existe sin médicos: se informa la ausencia, no inexistencia: '.$frase);
}
foreach (['¿Qué sigue después de seleccionar un medico?', 'Ya elegí especialidad y médico, ¿qué hago ahora?'] as $frase) {
    $r=lumi_resolver_catalogo($frase,'paciente',$p);
    comprobar_catalogo($r===null,'Dictado sin tildes va a la guía: '.$frase);
}
$denegado=new LumiCatalogoFakePDO();
comprobar_catalogo(lumi_resolver_catalogo('Qué especialidades existen','anonimo',$denegado)['situacion']==='acceso_denegado' && !$denegado->consultasCatalogo,'Denegar antes de SQL');
try { lumi_resolver_catalogo('Qué médicos hay en Alfa de contacto ficticio@example.invalid','paciente',$p); comprobar_catalogo(false,'Sin filtro de privacidad'); }
catch (DomainException $ex) { comprobar_catalogo(str_contains($ex->getMessage(),'No se envió la consulta'),'Privacidad conservada en catálogo'); }
foreach (['Qué especialidades existen','Qué médicos hay en Cardiología'] as $frase) {
    $r=lumi_resolver_catalogo($frase,'paciente',new LumiCatalogoFakePDO('extenso'));
    comprobar_catalogo(mb_strlen($r['texto'])<=LUMI_MAX_RESPUESTA && str_contains($r['texto'],'lista es parcial'),'Límites sin truncar silenciosamente');
}
foreach ($p->consultasCatalogo as $consulta) {
    comprobar_catalogo(preg_match('/^SELECT\b/',$consulta['sql'])===1,'Solo SELECT preparados');
    comprobar_catalogo(!preg_match('/\b(pacientes|usuarios|citas|pagos|horarios|tarifas_consulta|reservas_temporales|contrasena_hash|correo|telefono)\b/',$consulta['sql']),'Solo tres tablas de catálogo y campos públicos');
    comprobar_catalogo(!str_contains($consulta['sql'],'DELETE FROM'),'Consulta nunca interpolada');
}
$_SESSION=[];
lumi_conversacion_iniciar(['id_usuario'=>101,'rol'=>'paciente']);
lumi_conversacion_agregar('Qué médicos hay en Cardiología',lumi_resolver_catalogo('Qué médicos hay en Cardiología','paciente',$p));
comprobar_catalogo(str_contains(json_encode(lumi_conversacion_publica('paciente'),JSON_UNESCAPED_UNICODE),'Profesional Alfa'),'Catálogo conservado en PHP');
comprobar_catalogo(lumi_conversacion_contexto()===[],'Ningún turno de catálogo se envía a Gemini');
lumi_conversacion_agregar('Consulta general ficticia',['estado'=>'ok','texto'=>'Respuesta general ficticia']);
comprobar_catalogo(!str_contains(json_encode(lumi_conversacion_contexto()),'Cardio') && !str_contains(json_encode(lumi_conversacion_contexto()),'Profesional'),'Aislamiento también al continuar con Gemini');
echo "OK: $casos comprobaciones de catálogo local, privacidad, roles, límites y SQL preparado; sin BD ni proveedor.\n";
