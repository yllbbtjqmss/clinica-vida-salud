<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario=cvs_exigir_roles(['administrador','recepcionista','paciente']);
require_once __DIR__ . '/includes/pagos_ui.php';
cvp_solo_metodos(['GET','POST']); $pdo=cvs_conexion(); cvp_requerir_modulo($pdo);
$id=cvp_id($_GET['id'] ?? null); $error=null; $p=null; $movimientos=[];
try {
    cvp_expirar($pdo); $p=cvp_pago($pdo,$id,(int)$usuario['id_usuario']);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $clave=cvp_validar_post('pago:'.$id); $accion=cvs_texto($_POST,'accion');
        if ($accion==='solicitar_tarjeta') {
            // No aceptar campos bancarios: el único instrumento admitido es una referencia fija sintética.
            if (array_diff(array_keys($_POST),['csrf','operacion','accion','referencia_prueba'])) throw new DomainException('La solicitud contiene campos no admitidos.');
            cvp_solicitar_tarjeta($pdo,(int)$usuario['id_usuario'],$id,cvs_texto($_POST,'referencia_prueba'));
        }
        elseif ($accion==='cobrar') cvp_cobrar($pdo,(int)$usuario['id_usuario'],$id,cvs_texto($_POST,'metodo'),cvs_texto($_POST,'referencia'),($_POST['verificado'] ?? null)==='1');
        elseif ($accion==='simular') cvp_simular($pdo,(int)$usuario['id_usuario'],$id,cvs_texto($_POST,'escenario'),$clave);
        elseif ($accion==='abandonar') cvp_abandonar($pdo,(int)$usuario['id_usuario'],$id);
        elseif ($accion==='reembolso') cvp_reembolso($pdo,(int)$usuario['id_usuario'],$id,cvs_texto($_POST,'revision'),cvs_texto($_POST,'referencia'),($_POST['verificado'] ?? null)==='1');
        else throw new DomainException('Acción no permitida.');
        cvp_redirigir_pago($id,$accion === 'solicitar_tarjeta' ? 'Tarjeta de prueba aceptada. Estamos procesando tu pago.' : 'Operación registrada correctamente.');
    }
} catch (Throwable $ex) {
    if ($ex instanceof CvpAccesoDenegado) http_response_code(403);
    $error=$ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'detalle_pago');
    if (!$p) http_response_code(404);
}
if ($p) {
    $p=cvp_pago($pdo,$id,(int)$usuario['id_usuario']);
    $q=$pdo->prepare('SELECT m.tipo,m.estado_anterior,m.estado_nuevo,m.importe,m.referencia,m.creado_en,u.nombre_usuario
        FROM movimientos_pago m LEFT JOIN usuarios u ON u.id_usuario=m.responsable WHERE m.id_pago=:id ORDER BY m.id_movimiento');
    $q->execute([':id'=>$id]); $movimientos=$q->fetchAll(PDO::FETCH_ASSOC);
}
cvp_inicio('pagos','Ficha y estado del pago'); cvp_mensaje($error);
$limite = $p && $p['estado_reserva'] === 'activa' ? min(strtotime($p['vence_en']),
    (new DateTimeImmutable($p['fecha'].' '.$p['hora_inicio'],new DateTimeZone('America/La_Paz')))->getTimestamp()) : null;
?>
<?php if ($p): ?>
<?php if ($p['modalidad']==='demo' && ($p['metodo_online'] ?? null) !== 'qr'): ?><p class="cvp-prueba" role="note"><?= cvs_escapar(CVP_DEMO_AVISO) ?></p><?php endif; ?>
<?php if ($p['estado']==='pagado'): ?><div class="mensaje" role="status"><strong>Pago aprobado.</strong> <?= $p['estado_cita'] === 'confirmada' ? 'Tu cita ha sido confirmada.' : 'Pago registrado correctamente.' ?> Tu comprobante está disponible.</div><?php endif; ?>
<?php if ($p['estado']==='procesando'): ?><p class="aviso" role="status">Estamos procesando tu pago. Pendiente de verificación.</p><?php endif; ?>
<?php if (($p['metodo_online'] ?? null) === 'tarjeta' && $p['estado'] === 'pendiente' && $p['estado_reserva'] === 'activa' && cvp_demo_habilitado()): ?>
<section class="panel cvp-checkout"><header class="cvp-checkout-cabecera"><span aria-hidden="true" class="cvp-icono">▣</span><div><h1>Tarjeta de crédito o débito</h1><span class="pequeno">🔒 Solicitud protegida por sesión</span></div></header>
<p id="cvp-tarjeta-ayuda">Utiliza el instrumento sintético precargado. Los campos son de solo lectura y no admiten tarjetas bancarias.</p>
<form method="post" action="pago.php?id=<?= $id ?>" id="cvp-tarjeta" autocomplete="off" aria-describedby="cvp-tarjeta-ayuda">
<?php cvp_ocultos(cvp_formulario('pago:'.$id)); ?><input type="hidden" name="accion" value="solicitar_tarjeta"><input type="hidden" name="referencia_prueba" value="CVS-TARJETA-SINTETICA-V1">
<div class="campo"><label for="cvp-pan">Número de tarjeta</label><input id="cvp-pan" value="0000 0000 0000 0000" readonly aria-readonly="true" autocomplete="off" data-sintetico="0000 0000 0000 0000"></div>
<div class="campo"><label for="cvp-titular">Nombre del titular</label><input id="cvp-titular" value="PACIENTE DE PRUEBA" readonly autocomplete="off" data-sintetico="PACIENTE DE PRUEBA"></div>
<div class="cvp-dos-columnas"><div class="campo"><label for="cvp-vencimiento">Vencimiento (MM/AA)</label><input id="cvp-vencimiento" value="12/99" readonly autocomplete="off" data-sintetico="12/99"></div><div class="campo"><label for="cvp-cvv">Código de seguridad (CVV)</label><input id="cvp-cvv" type="password" value="000" readonly autocomplete="off" data-sintetico="000"></div></div>
<p class="cvp-total">Importe total <strong><?= cvs_escapar(cvp_bob($p['importe'])) ?></strong></p>
<p id="cvp-tarjeta-error" role="alert"></p><button type="submit" class="cvp-pagar">Pagar <?= cvs_escapar(cvp_bob($p['importe'])) ?></button>
<p class="pequeno" id="cvp-procesamiento" aria-live="polite">La confirmación se consultará desde el servidor.</p></form></section>
<?php endif; ?>
<?php if (($p['metodo_online'] ?? null) === 'qr' && $p['estado_reserva'] === 'activa'): ?>
<section class="panel cvp-checkout cvp-qr-panel" aria-labelledby="cvp-qr-titulo">
<header><p class="cvp-marca">Clínica Vida Salud</p><h1 id="cvp-qr-titulo">Pago mediante QR</h1></header>
<p class="cvp-qr-instrucciones">Escanea el código QR desde tu app bancaria para completar el pago.</p>
<?php cvp_qr_visual(); ?>
<dl class="cvp-qr-datos">
<div><dt>Importe a pagar</dt><dd class="cvp-precio"><?= cvs_escapar(cvp_bob($p['importe'])) ?></dd></div>
<div><dt>Referencia de pago</dt><dd class="cvp-codigo"><?= cvs_escapar($p['referencia']) ?></dd></div>
<div><dt>Estado del pago</dt><dd><span class="pill"><?= $p['estado']==='pendiente' ? 'Pendiente de verificación' : cvs_escapar(cvp_estado($p['estado'])) ?></span></dd></div>
</dl>
<p class="cvp-qr-consulta"><?= cvs_escapar(cvp_fecha_visible($p['fecha'],false).' · '.substr($p['hora_inicio'],0,5).' – '.substr($p['hora_fin'],0,5)) ?><br><?= cvs_escapar($p['medico'].' · '.$p['especialidad']) ?></p>
<p class="cvp-qr-plazo">Tiempo restante: <strong data-cuenta-atras="<?= $limite ?>" data-servidor="<?= (int)$p['servidor_epoch'] ?>">Consulta el plazo del resumen</strong></p>
<div class="acciones"><a class="boton" href="pago.php?id=<?= $id ?>">Actualizar estado</a><a href="#cvp-resumen">Regresar al resumen</a></div></section>
<?php endif; ?>
<section class="panel" id="cvp-resumen"><h2><?= $p['codigo_cita'] ? 'Resumen de la cita' : 'Resumen de reserva' ?></h2><p class="cvp-codigo"><?= cvs_escapar($p['codigo_cita'] ?: $p['codigo_reserva']) ?></p>
<?php cvp_resumen($p); ?>
<div class="resumen"><span class="pill">Cita: <?= cvs_escapar($p['estado_cita'] ? cvp_estado($p['estado_cita']) : 'Todavía no confirmada') ?></span><span class="pill">Pago: <?= cvs_escapar(cvp_estado($p['estado'])) ?></span></div>
<?php if ($p['estado_reserva']==='activa'): ?><p class="aviso">Completa tu pago antes del <?= cvs_escapar(cvp_fecha_visible(date('c',$limite))) ?>.<?php if (($p['metodo_online'] ?? null) !== 'qr'): ?> Tiempo restante: <strong data-cuenta-atras="<?= $limite ?>" data-servidor="<?= (int)$p['servidor_epoch'] ?>">Consulta el plazo indicado</strong>.<?php endif; ?></p><?php endif; ?>
<?php if ($p['estado']==='vencido'): ?><p class="aviso">Reserva vencida. El horario ha sido liberado; consulta disponibilidad antes de reservar nuevamente.</p><?php endif; ?>
<?php if ($p['estado']==='fallido'): ?><p class="error">Pago rechazado. No se confirmó la cita y el horario fue liberado.</p><?php endif; ?>
<?php if ($p['modalidad']==='presencial' && $p['estado']==='pendiente'): ?><p class="aviso">Cita reservada con pago presencial pendiente. Realiza el pago en recepción; el horario ya está ocupado.</p><?php endif; ?>
<?php if ($p['estado']==='reembolso_pendiente'): ?><p class="aviso">Devolución pendiente de revisión. No se ha devuelto dinero automáticamente. Un pago tardío nunca recupera un horario liberado.</p><?php endif; ?>
<p>Método: <?= cvs_escapar(cvp_metodo($p)) ?> · Cobro: <?= cvs_escapar(cvp_fecha_visible($p['confirmado_en'])) ?>.</p><p class="pequeno">Referencia: <?= cvs_escapar($p['referencia_cobro'] ?: $p['referencia']) ?><?= $p['responsable_nombre'] ? ' · Registrado por '.cvs_escapar($p['responsable_nombre']) : '' ?></p>
<?php if ($p['modalidad']==='demo' && ($p['metodo_online'] ?? null) === 'qr'): ?><p class="pequeno cvp-nota-academica" role="note">Presentación académica · Confirmación sujeta a verificación administrativa.</p><?php endif; ?>
<div class="acciones"><a class="boton" href="ficha_reserva.php?id=<?= $id ?>">Ver / imprimir ficha</a><a class="boton secundario" href="ficha_reserva.php?id=<?= $id ?>&amp;descargar=1">Descargar ficha HTML</a><?php if ($p['confirmado_en']): ?><a class="boton secundario" href="ficha_reserva.php?id=<?= $id ?>&amp;tipo=comprobante">Comprobante de pago</a><?php endif; ?><a class="boton secundario" href="pagos.php">Volver al listado</a></div></section>
<?php if ($p['modalidad']==='presencial' && $p['estado']==='pendiente' && in_array($usuario['rol'],['administrador','recepcionista'],true)): ?>
<section class="panel"><h2>Registrar cobro presencial</h2><p>Importe acordado: <strong><?= cvs_escapar(cvp_bob($p['importe'])) ?></strong>. Se registra completo y no puede modificarse desde este formulario.</p>
<form method="post" action="pago.php?id=<?= $id ?>" class="cvp-envio"><?php cvp_ocultos(cvp_formulario('pago:'.$id)); ?><input type="hidden" name="accion" value="cobrar">
<div class="campos"><div class="campo"><label for="metodo">Método verificado</label><select id="metodo" name="metodo" required><?php foreach (CVP_METODOS as $v=>$t): ?><option value="<?= $v ?>" <?= cvs_texto($_POST,'metodo')===$v ? 'selected' : '' ?>><?= cvs_escapar($t) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="referencia">Referencia (obligatoria para QR, sin datos bancarios)</label><input id="referencia" name="referencia" maxlength="100" value="<?= cvs_escapar(cvs_texto($_POST,'referencia')) ?>"></div></div>
<label class="cvp-opcion"><input type="checkbox" name="verificado" value="1" required><span>He recibido y verificado presencialmente el importe completo. No basta una captura de pantalla del paciente.</span></label><div class="acciones"><button type="submit">Registrar cobro verificado</button></div></form></section>
<?php endif; ?>
<?php if ($p['modalidad']==='demo' && !$p['confirmado_en'] && $usuario['rol']==='administrador' && cvp_demo_habilitado()): ?>
<section class="panel"><h2>Verificación de pagos</h2><p>Gestión exclusiva de administración. Registra el resultado y conserva la responsabilidad de la revisión. Una respuesta tardía se deriva a devolución.</p>
<form method="post" action="pago.php?id=<?= $id ?>" class="cvp-envio"><?php cvp_ocultos(cvp_formulario('pago:'.$id)); ?><input type="hidden" name="accion" value="simular"><label for="escenario">Estado de la transacción</label><select name="escenario" id="escenario"><option value="pendiente">En procesamiento</option><option value="exitoso">Pago aprobado</option><option value="fallido">Pago rechazado</option><option value="vencido">Reserva vencida</option></select><div class="acciones"><button type="submit">Registrar resultado</button></div></form></section>
<?php endif; ?>
<?php if ($p['estado_reserva']==='activa' && !$p['confirmado_en']): ?>
<section class="panel"><h2>Seguimiento del pago</h2><p>La cita se confirmará una vez verificado el pago. Puedes consultar el resultado o liberar tu reserva.</p><div class="acciones"><a class="boton secundario" href="pago.php?id=<?= $id ?>">Actualizar estado</a></div>
<form method="post" action="pago.php?id=<?= $id ?>"><?php cvp_ocultos(cvp_formulario('pago:'.$id)); ?><input type="hidden" name="accion" value="abandonar"><div class="acciones"><button type="submit" class="boton rojizo">Abandonar reserva temporal</button></div></form></section>
<?php endif; ?>
<?php if ($p['estado']==='reembolso_pendiente' && $usuario['rol']==='administrador'): ?>
<section class="panel"><h2>Revisión de devolución</h2><p>Registra la devolución completa después de verificarla. La decisión quedará en el historial con tu usuario responsable.</p>
<form method="post" action="pago.php?id=<?= $id ?>" class="cvp-envio"><?php cvp_ocultos(cvp_formulario('pago:'.$id)); ?><input type="hidden" name="accion" value="reembolso"><div class="campos"><div class="campo"><label for="revision">Decisión</label><select id="revision" name="revision"><option value="completar">Registrar devolución verificada</option><option value="rechazar">Rechazar solicitud (sin devolver)</option></select></div><div class="campo"><label for="ref-devolucion">Referencia de devolución</label><input id="ref-devolucion" name="referencia" maxlength="100" value="<?= cvs_escapar(cvs_texto($_POST,'referencia')) ?>"></div></div><label class="cvp-opcion"><input type="checkbox" name="verificado" value="1"><span>Verifiqué la devolución completa (requerido para completarla).</span></label><div class="acciones"><button type="submit">Guardar revisión</button></div></form></section>
<?php endif; ?>
<section class="panel"><h2>Historial de pagos</h2><div class="tabla"><table><thead><tr><th>Fecha · La Paz</th><th>Movimiento</th><th>Estado</th><th>Importe</th><th>Referencia</th><?php if ($usuario['rol']!=='paciente'): ?><th>Responsable</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($movimientos as $m): ?><tr><td><?= cvs_escapar(cvp_fecha_visible($m['creado_en'])) ?></td><td><?= cvs_escapar(cvp_estado($m['tipo'])) ?></td><td><?= cvs_escapar(cvp_estado($m['estado_nuevo'])) ?></td><td><?= cvs_escapar(cvp_bob($m['importe'])) ?></td><td><?= cvs_escapar($m['referencia'] ?? '—') ?></td><?php if ($usuario['rol']!=='paciente'): ?><td><?= cvs_escapar($m['nombre_usuario'] ?? 'Sistema') ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<script src="assets/reservas.js" defer></script>
<script src="assets/pagos.js" defer></script>
<?php cvp_fin(); ?>
