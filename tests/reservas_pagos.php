<?php
// Exclusivo del runner aislado. Nunca carga la configuración privada de XAMPP.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CVS_TEST_AISLADO') !== '1'
    || getenv('CVS_DB_HOST') !== '127.0.0.1' || getenv('CVS_DB_NAME') !== 'cvs_test_pagos'
    || getenv('CVS_DB_PORT') === '5432') exit(2);
require_once __DIR__ . '/../includes/reservas_pagos.php';
require_once __DIR__ . '/../includes/reportes_pagos.php';
$pdo=cvs_conexion(); $resultados=[];
function comprobar(bool $condicion): void { if (!$condicion) throw new RuntimeException('No coincide el resultado esperado.'); }
function caso(string $id, string $nombre, callable $ejecutar): void {
    global $resultados,$pdo;
    try { $ejecutar(); $resultados[]=['id'=>$id,'caso'=>$nombre,'estado'=>'OK']; }
    catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resultados[]=['id'=>$id,'caso'=>$nombre,'estado'=>'ERROR','tipo'=>get_class($ex),'codigo'=>(string)$ex->getCode()];
    }
}
function rechaza(callable $ejecutar): void {
    try { $ejecutar(); } catch (DomainException|PDOException $ex) { return; }
    throw new RuntimeException('Se aceptó una operación que debía rechazarse.');
}
function clave(): string { return bin2hex(random_bytes(32)); }
function estado(PDO $pdo, int $id): array {
    $q=$pdo->prepare('SELECT * FROM pagos WHERE id_pago=:id'); $q->execute([':id'=>$id]); return $q->fetch(PDO::FETCH_ASSOC);
}

// Datos exclusivamente ficticios en el clúster nuevo del runner.
$ids=[];
foreach (['administrador'=>'ensayo_admin','recepcionista'=>'ensayo_recepcion','medico'=>'ensayo_medico','paciente'=>'ensayo_paciente_uno'] as $rol=>$nombre) {
    $q=$pdo->prepare('INSERT INTO usuarios(id_rol,nombre_usuario,contrasena_hash) SELECT id_rol,:nombre,:hash FROM roles WHERE nombre=:rol RETURNING id_usuario');
    $q->execute([':nombre'=>$nombre,':hash'=>password_hash((string)getenv('CVS_TEST_PASSWORD'),PASSWORD_DEFAULT),':rol'=>$rol]); $ids[$rol]=(int)$q->fetchColumn();
}
$q=$pdo->prepare("INSERT INTO usuarios(id_rol,nombre_usuario,contrasena_hash) SELECT id_rol,'ensayo_paciente_dos',:hash FROM roles WHERE nombre='paciente' RETURNING id_usuario");
$q->execute([':hash'=>password_hash((string)getenv('CVS_TEST_PASSWORD'),PASSWORD_DEFAULT)]); $ids['paciente2']=(int)$q->fetchColumn();
foreach (['paciente','paciente2'] as $rol) {
    $q=$pdo->prepare("INSERT INTO pacientes(id_usuario,nombres,apellidos,fecha_nacimiento,telefono) VALUES (:u,'Persona ficticia',:apellido,'2000-01-01','00000000') RETURNING id_paciente");
    $q->execute([':u'=>$ids[$rol],':apellido'=>$rol]); $ids['ficha_'.$rol]=(int)$q->fetchColumn();
}
$q=$pdo->prepare("INSERT INTO medicos(id_usuario,registro_profesional,nombres,apellidos) VALUES (:u,'ENSAYO-CLUSTER','Profesional ficticio','Ensayo') RETURNING id_medico");
$q->execute([':u'=>$ids['medico']]); $ids['ficha_medico']=(int)$q->fetchColumn();
$ids['especialidad']=(int)$pdo->query('SELECT MIN(id_especialidad) FROM especialidades')->fetchColumn();
$pdo->prepare('INSERT INTO medico_especialidad VALUES (:m,:e)')->execute([':m'=>$ids['ficha_medico'],':e'=>$ids['especialidad']]);
for ($dia=1;$dia<=7;$dia++) $pdo->prepare("INSERT INTO horarios(id_medico,dia_semana,hora_inicio,hora_fin,duracion_minutos) VALUES (:m,:dia,'08:00','18:00',15)")->execute([':m'=>$ids['ficha_medico'],':dia'=>$dia]);
$fecha=$pdo->query("SELECT (timezone('America/La_Paz',clock_timestamp())::date+14)::text")->fetchColumn();
$base=['id_medico'=>(string)$ids['ficha_medico'],'id_especialidad'=>(string)$ids['especialidad'],'fecha'=>$fecha,'modalidad'=>'presencial'];
cvp_guardar_tarifa($pdo,$ids['administrador'],$ids['ficha_medico'],$ids['especialidad'],'100.10');
$pagos=[];

caso('P01','Paciente reserva solo para su ficha aunque manipule id_paciente',function()use($pdo,$ids,$base,&$pagos){
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'08:00','id_paciente'=>(string)$ids['ficha_paciente2']],clave(),'100.10');
    $p=estado($pdo,$id); comprobar((int)$p['id_paciente']===$ids['ficha_paciente'] && $p['id_cita']!==null); $pagos['presencial']=$id;
});
caso('P02','Precio exacto del servidor; entrada manipulada y cotización obsoleta',function()use($pdo,$ids,$base,&$pagos){
    comprobar(estado($pdo,$pagos['presencial'])['importe']==='100.10');
    rechaza(fn()=>cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'08:15'],clave(),'0.01'));
    foreach (['-1','0','1e2','1.001','99999999999.00'] as $m) rechaza(fn()=>cvp_importe($m));
});
caso('P03','Pago presencial pendiente y horario ocupado',function()use($pdo,$ids,$base,&$pagos){
    comprobar(estado($pdo,$pagos['presencial'])['estado']==='pendiente');
    rechaza(fn()=>cvp_reservar($pdo,$ids['paciente2'],$base+['hora_inicio'=>'08:00'],clave()));
});
caso('P04','Recepción registra cobro completo verificado',function()use($pdo,$ids,&$pagos){
    cvp_cobrar($pdo,$ids['recepcionista'],$pagos['presencial'],'efectivo','ENSAYO-COBRO-1',true);
    $p=estado($pdo,$pagos['presencial']); comprobar($p['estado']==='pagado' && (int)$p['responsable_cobro']===$ids['recepcionista']);
});
caso('P05','Doble cobro y doble envío no duplican movimientos',function()use($pdo,$ids,$base,&$pagos){
    cvp_cobrar($pdo,$ids['recepcionista'],$pagos['presencial'],'efectivo','ENSAYO-OTRA-REF',true);
    $q=$pdo->prepare("SELECT COUNT(*) FROM movimientos_pago WHERE id_pago=:id AND tipo='cobro'");$q->execute([':id'=>$pagos['presencial']]);comprobar((int)$q->fetchColumn()===1);
    $k=clave(); $a=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'08:15'], $k);
    comprobar($a===cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'08:15'],$k));
    rechaza(fn()=>cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'08:30'],$k));
});
caso('P06','Paciente no aprueba pagos; respuesta demo autorizada e idempotente',function()use($pdo,$ids,$base,&$pagos){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='08:30'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave()); $pagos['demo']=$id;
    rechaza(fn()=>cvp_simular($pdo,$ids['paciente'],$id,'exitoso',clave()));
    $k=clave(); cvp_simular($pdo,$ids['administrador'],$id,'exitoso',$k); cvp_simular($pdo,$ids['administrador'],$id,'exitoso',$k);
    comprobar(estado($pdo,$id)['estado']==='pagado');
});
caso('P07','Aprobación demo crea cita confirmada, no atendida',function()use($pdo,&$pagos){
    $p=estado($pdo,$pagos['demo']); $q=$pdo->prepare('SELECT estado FROM citas WHERE id_cita=:id'); $q->execute([':id'=>$p['id_cita']]); comprobar($q->fetchColumn()==='confirmada');
});
caso('P08','Rechazo libera reserva sin confirmar cita',function()use($pdo,$ids,$base,&$pagos){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='08:45'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave()); $pagos['fallido']=$id;
    cvp_simular($pdo,$ids['administrador'],$id,'fallido',clave()); comprobar(estado($pdo,$id)['id_cita']===null && estado($pdo,$id)['estado']==='fallido');
    cvp_reservar($pdo,$ids['paciente2'],$base+['hora_inicio'=>'08:45'],clave());
});
caso('P09','Vencimiento libera intervalo al escribir, sin cron ni JavaScript',function()use($pdo,$ids,$base,&$pagos){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='09:00'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave()); $pagos['vencido']=$id;
    $p=estado($pdo,$id); $pdo->prepare("UPDATE reservas_temporales SET vence_en=clock_timestamp()-INTERVAL '1 second' WHERE id_reserva=:id")->execute([':id'=>$p['id_reserva']]);
    cvp_reservar($pdo,$ids['paciente2'],$base+['hora_inicio'=>'09:00'],clave()); comprobar(estado($pdo,$id)['estado']==='vencido');
    // Esta vez el INSERT legado no llama al mantenimiento PHP: el disparador debe vencerla.
    $d['hora_inicio']='15:00'; $otro=cvp_reservar($pdo,$ids['paciente'],$d,clave()); $p=estado($pdo,$otro);
    $pdo->prepare("UPDATE reservas_temporales SET vence_en=clock_timestamp()-INTERVAL '1 second' WHERE id_reserva=:id")->execute([':id'=>$p['id_reserva']]);
    cvp_insertar_cita($pdo,$ids['ficha_paciente2'],$ids['ficha_medico'],$ids['especialidad'],$base['fecha'],'15:00','15:15','programada');
    comprobar(estado($pdo,$otro)['estado']==='vencido');
});
caso('P10','Exclusión cruzada rechaza INSERT legado sobre reserva temporal',function()use($pdo,$ids,$base,&$pagos){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='09:15'; $pagos['hold']=cvp_reservar($pdo,$ids['paciente'],$d,clave());
    rechaza(fn()=>cvp_insertar_cita($pdo,$ids['ficha_paciente2'],$ids['ficha_medico'],$ids['especialidad'],$base['fecha'],'09:15','09:30','programada'));
});
caso('P11','Paciente no accede a operaciones de otro paciente',function()use($pdo,$ids,&$pagos){
    rechaza(fn()=>cvp_pago($pdo,$pagos['presencial'],$ids['paciente2']));
});
caso('P12','Médico y paciente no pueden registrar cobros',function()use($pdo,$ids,$base){
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'09:30'],clave());
    foreach (['medico','paciente'] as $rol) rechaza(fn()=>cvp_cobrar($pdo,$ids[$rol],$id,'efectivo','ENSAYO-NO',true));
    comprobar(estado($pdo,$id)['estado']==='pendiente');
});
caso('P13','Solo administrador cambia tarifas; acuerdos anteriores inmutables',function()use($pdo,$ids,$base,&$pagos){
    rechaza(fn()=>cvp_guardar_tarifa($pdo,$ids['recepcionista'],$ids['ficha_medico'],$ids['especialidad'],'20.00'));
    cvp_guardar_tarifa($pdo,$ids['administrador'],$ids['ficha_medico'],$ids['especialidad'],'120.20');
    comprobar(estado($pdo,$pagos['presencial'])['importe']==='100.10');
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'09:45','importe'=>'0.01'],clave()); comprobar(estado($pdo,$id)['importe']==='120.20');
    rechaza(function()use($pdo,$id){$pdo->prepare('UPDATE pagos SET importe=1 WHERE id_pago=:id')->execute([':id'=>$id]);});
});
caso('P14','Citas sin cuenta financiera conservan reprogramación y cancelación',function()use($pdo,$ids,$base){
    $id=cvp_insertar_cita($pdo,$ids['ficha_paciente'],$ids['ficha_medico'],$ids['especialidad'],$base['fecha'],'10:00','10:15','programada');
    $pdo->prepare("UPDATE citas SET hora_inicio='10:15',hora_fin='10:30' WHERE id_cita=:id")->execute([':id'=>$id]);
    $pdo->prepare("UPDATE citas SET estado='cancelada' WHERE id_cita=:id")->execute([':id'=>$id]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM pagos WHERE id_cita=:id');$q->execute([':id'=>$id]);comprobar((int)$q->fetchColumn()===0);
});
caso('P15','Reprogramación conserva pago; cancelación solicita pero no devuelve',function()use($pdo,$ids,&$pagos){
    $p=estado($pdo,$pagos['presencial']);
    $pdo->prepare("UPDATE citas SET hora_inicio='10:30',hora_fin='10:45' WHERE id_cita=:id")->execute([':id'=>$p['id_cita']]);
    comprobar(estado($pdo,$pagos['presencial'])['estado']==='pagado');
    $pdo->prepare("UPDATE citas SET estado='cancelada' WHERE id_cita=:id")->execute([':id'=>$p['id_cita']]);
    comprobar(estado($pdo,$pagos['presencial'])['estado']==='reembolso_pendiente');
    rechaza(fn()=>cvp_reembolso($pdo,$ids['recepcionista'],$pagos['presencial'],'completar','ENSAYO-DEV-1',true));
    rechaza(fn()=>cvp_reembolso($pdo,$ids['administrador'],$pagos['presencial'],'completar','ENSAYO-DEV-1',false));
    cvp_reembolso($pdo,$ids['administrador'],$pagos['presencial'],'completar','ENSAYO-DEV-1',true);
    cvp_reembolso($pdo,$ids['administrador'],$pagos['presencial'],'completar','ENSAYO-DEV-1',true);
    comprobar(estado($pdo,$pagos['presencial'])['estado']==='reembolsado');
});
caso('P16','Reportes exactos separan cobros reales, devoluciones y simulaciones',function()use($pdo){
    $d=(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    $real=cvp_reporte($pdo,$d,$d,'real')['resumen']; $demo=cvp_reporte($pdo,$d,$d,'demo')['resumen'];
    comprobar($real['cobrado']==='100.10' && $real['reembolsado']==='100.10' && $real['neto']==='0.00');
    comprobar($demo['cobrado']==='100.10' && (int)$demo['exitosos']===1);
});
caso('P18','Pago tardío queda en revisión sin recuperar el turno reasignado',function()use($pdo,$ids,&$pagos){
    cvp_simular($pdo,$ids['administrador'],$pagos['vencido'],'exitoso',clave());
    $p=estado($pdo,$pagos['vencido']); comprobar($p['estado']==='reembolso_pendiente' && $p['id_cita']===null);
});
caso('P19','Pendiente no confirma cita; vencimiento forzado es solo administrador',function()use($pdo,$ids,$base,&$pagos){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='10:45'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave());
    cvp_simular($pdo,$ids['administrador'],$id,'pendiente',clave()); comprobar(estado($pdo,$id)['estado']==='procesando' && estado($pdo,$id)['id_cita']===null);
    cvp_simular($pdo,$ids['administrador'],$id,'vencido',clave()); comprobar(estado($pdo,$id)['estado']==='vencido'); $pagos['http_demo']=$id;
});
caso('P20','Cancelar pendiente anula deuda, conserva movimientos e impide cobrar',function()use($pdo,$ids,$base){
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'11:00'],clave()); $p=estado($pdo,$id);
    $pdo->prepare("UPDATE citas SET estado='cancelada' WHERE id_cita=:id")->execute([':id'=>$p['id_cita']]);
    comprobar(estado($pdo,$id)['estado']==='anulado'); rechaza(fn()=>cvp_cobrar($pdo,$ids['recepcionista'],$id,'efectivo','NO',true));
});
caso('P21','Referencias de cobro únicas; registro de movimientos no editable',function()use($pdo,$ids,$base){
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'11:15'],clave());
    rechaza(fn()=>cvp_cobrar($pdo,$ids['recepcionista'],$id,'qr_recepcion','ENSAYO-COBRO-1',true));
    comprobar(estado($pdo,$id)['estado']==='pendiente');
    rechaza(fn()=>$pdo->exec('UPDATE movimientos_pago SET importe=0'));
});
caso('P22','Sin tarifa, fechas pasadas o bloques fuera del horario no se reserva',function()use($pdo,$ids,$base){
    $e=(int)$pdo->query('SELECT MAX(id_especialidad) FROM especialidades')->fetchColumn();
    $pdo->prepare('INSERT INTO medico_especialidad VALUES (:m,:e)')->execute([':m'=>$ids['ficha_medico'],':e'=>$e]);
    $d=$base; $d['id_especialidad']=(string)$e; $d['hora_inicio']='11:30';
    rechaza(fn()=>cvp_reservar($pdo,$ids['paciente'],$d,clave()));
    foreach (['07:45','11:31','18:00'] as $h) rechaza(fn()=>cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>$h],clave()));
    $d=$base; $d['fecha']='2001-01-01'; $d['hora_inicio']='08:00';
    rechaza(fn()=>cvp_reservar($pdo,$ids['paciente'],$d,clave()));
});
caso('P23','Pago demo mantiene el importe pactado aunque cambie la tarifa antes de aprobar',function()use($pdo,$ids,$base){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='11:30'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave());
    cvp_guardar_tarifa($pdo,$ids['administrador'],$ids['ficha_medico'],$ids['especialidad'],'130.30');
    cvp_simular($pdo,$ids['administrador'],$id,'exitoso',clave());
    comprobar(estado($pdo,$id)['importe']==='120.20' && estado($pdo,$id)['estado']==='pagado');
    cvp_guardar_tarifa($pdo,$ids['administrador'],$ids['ficha_medico'],$ids['especialidad'],'120.20');
});
caso('P24','Perfil desactivado durante la espera: no se confirma cita y el pago se revisa',function()use($pdo,$ids,$base){
    $d=$base; $d['modalidad']='demo'; $d['hora_inicio']='11:45'; $id=cvp_reservar($pdo,$ids['paciente'],$d,clave());
    try {
        $pdo->prepare('UPDATE pacientes SET activo=FALSE WHERE id_paciente=:id')->execute([':id'=>$ids['ficha_paciente']]);
        cvp_simular($pdo,$ids['administrador'],$id,'exitoso',clave());
        comprobar(estado($pdo,$id)['id_cita']===null && estado($pdo,$id)['estado']==='reembolso_pendiente');
    } finally { $pdo->prepare('UPDATE pacientes SET activo=TRUE WHERE id_paciente=:id')->execute([':id'=>$ids['ficha_paciente']]); }
});
caso('P25','Reprogramación SQL a un bloqueo temporal se revierte sin perder la cita',function()use($pdo,$ids,$base){
    $id=cvp_reservar($pdo,$ids['paciente'],$base+['hora_inicio'=>'14:00'],clave()); $p=estado($pdo,$id);
    rechaza(function()use($pdo,$p){$pdo->prepare("UPDATE citas SET hora_inicio='09:15',hora_fin='09:30' WHERE id_cita=:id")->execute([':id'=>$p['id_cita']]);});
    $q=$pdo->prepare('SELECT hora_inicio FROM citas WHERE id_cita=:id'); $q->execute([':id'=>$p['id_cita']]);comprobar($q->fetchColumn()==='14:00:00');
});
caso('P26','Dos respuestas exitosas con claves distintas tampoco duplican el cobro',function()use($pdo,$ids,&$pagos){
    cvp_simular($pdo,$ids['administrador'],$pagos['demo'],'exitoso',clave());
    $q=$pdo->prepare("SELECT count(*) FROM movimientos_pago WHERE id_pago=:id AND tipo IN ('cobro','cobro_tardio')");
    $q->execute([':id'=>$pagos['demo']]); comprobar((int)$q->fetchColumn()===1);
});
caso('P27','Tarjeta requiere solicitud, referencia controlada y administrador; rechazo libera turno',function()use($pdo,$ids,$base){
    $d=$base+['hora_inicio'=>'15:15','metodo_online'=>'tarjeta']; $d['modalidad']='demo';
    $id=cvp_reservar($pdo,$ids['paciente'],$d,clave());
    rechaza(fn()=>cvp_simular($pdo,$ids['administrador'],$id,'exitoso',clave()));
    rechaza(fn()=>cvp_solicitar_tarjeta($pdo,$ids['paciente'],$id,'NO-ADMITIDA'));
    rechaza(fn()=>cvp_solicitar_tarjeta($pdo,$ids['paciente2'],$id,'CVS-TARJETA-SINTETICA-V1'));
    cvp_solicitar_tarjeta($pdo,$ids['paciente'],$id,'CVS-TARJETA-SINTETICA-V1');
    cvp_solicitar_tarjeta($pdo,$ids['paciente'],$id,'CVS-TARJETA-SINTETICA-V1');
    comprobar(estado($pdo,$id)['estado']==='procesando' && estado($pdo,$id)['id_cita']===null);
    rechaza(fn()=>cvp_simular($pdo,$ids['paciente'],$id,'exitoso',clave()));
    cvp_simular($pdo,$ids['administrador'],$id,'fallido',clave());
    comprobar(estado($pdo,$id)['estado']==='fallido' && estado($pdo,$id)['id_cita']===null);
    cvp_reservar($pdo,$ids['paciente2'],$base+['hora_inicio'=>'15:15'],clave());
});
caso('P28','QR y tarjeta quedan diferenciados e inmutables; reportes separados y filtrados',function()use($pdo,$ids,$base){
    foreach (['qr'=>'15:30','tarjeta'=>'15:45'] as $metodo=>$hora) {
        $d=$base+['hora_inicio'=>$hora,'metodo_online'=>$metodo];$d['modalidad']='demo';
        $id=cvp_reservar($pdo,$ids['paciente'],$d,clave());
        if ($metodo==='tarjeta') cvp_solicitar_tarjeta($pdo,$ids['paciente'],$id,'CVS-TARJETA-SINTETICA-V1');
        cvp_simular($pdo,$ids['administrador'],$id,'exitoso',clave());
        cvp_simular($pdo,$ids['administrador'],$id,'exitoso',clave());
        comprobar(estado($pdo,$id)['metodo_online']===$metodo && estado($pdo,$id)['estado']==='pagado');
        rechaza(function()use($pdo,$id){$pdo->prepare("UPDATE pagos SET metodo_online=CASE WHEN metodo_online='tarjeta' THEN 'qr' ELSE 'tarjeta' END WHERE id_pago=:id")->execute([':id'=>$id]);});
    }
    $hoy=(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    $datos=cvp_reporte($pdo,$hoy,$hoy,'demo',['id_medico'=>(string)$ids['ficha_medico'],'id_especialidad'=>(string)$ids['especialidad'],'estado'=>'pagado']);
    $metodos=array_column($datos['grupos']['metodo'],'cobrado','grupo');
    comprobar($metodos['qr']==='120.20' && $metodos['tarjeta']==='120.20');
    comprobar(cvp_reporte($pdo,$hoy,$hoy,'real',['estado'=>'fallido'])['resumen']['cobrado']==='0');
    rechaza(fn()=>cvp_reporte($pdo,$hoy,$hoy,'demo',['estado'=>'no_existe']));
    comprobar(cvp_fecha_visible('2026-11-04 20:09:52.472257+00')==='04/11/2026, 16:09' && cvp_bob('100.00')==='Bs 100,00');
});
echo json_encode(['pruebas'=>$resultados,'fixtures'=>['usuarios'=>$ids,'pagos'=>$pagos,'fecha'=>$fecha,'base'=>$base]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
exit(count(array_filter($resultados,fn($r)=>$r['estado']!=='OK')) ? 1 : 0);
