<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['administrador','recepcionista','paciente']);
require_once __DIR__ . '/includes/pagos_ui.php';
cvp_solo_metodos(['GET']); $pdo=cvs_conexion(); cvp_requerir_modulo($pdo);
$error=null; $lista=[]; $mas=false;
$estado=cvs_texto($_GET,'estado'); $metodo=cvs_texto($_GET,'metodo');
$desde=cvs_texto($_GET,'desde'); $hasta=cvs_texto($_GET,'hasta');
$pagina=min(cvp_id($_GET['pagina'] ?? '1') ?: 1,100000);
$online=cvp_online_disponible($pdo);
try {
    cvp_expirar($pdo);
    $condiciones=[]; $params=[];
    if ($usuario['rol']==='paciente') { $condiciones[]='pa.id_usuario=:usuario AND pa.activo=TRUE'; $params[':usuario']=$usuario['id_usuario']; }
    if ($estado!=='') {
        if (!in_array($estado,CVP_ESTADOS,true)) throw new DomainException('Estado de pago inválido.');
        $condiciones[]='p.estado=:estado'; $params[':estado']=$estado;
    }
    if ($metodo!=='') {
        if (!in_array($metodo,['efectivo','qr_recepcion','demostracion','sin_cobro','tarjeta','qr'],true)) throw new DomainException('Método inválido.');
        if ($metodo==='sin_cobro') $condiciones[]='p.metodo IS NULL';
        elseif (isset(CVP_ONLINE[$metodo])) {
            if (!$online) throw new DomainException('Actualiza el módulo para filtrar métodos en línea.');
            $condiciones[]='p.metodo_online=:metodo'; $params[':metodo']=$metodo;
        }
        else { $condiciones[]='p.metodo=:metodo'; $params[':metodo']=$metodo; }
    }
    if ($desde!=='') { cvp_fecha($desde); $condiciones[]="(p.creado_en AT TIME ZONE 'America/La_Paz')::date>=CAST(:desde AS DATE)"; $params[':desde']=$desde; }
    if ($hasta!=='') { cvp_fecha($hasta); $condiciones[]="(p.creado_en AT TIME ZONE 'America/La_Paz')::date<=CAST(:hasta AS DATE)"; $params[':hasta']=$hasta; }
    if ($desde && $hasta && $desde>$hasta) throw new DomainException('El rango de fechas está invertido.');
    $q=$pdo->prepare("SELECT p.id_pago,p.referencia,p.importe,p.modalidad,p.estado,p.metodo," . ($online ? 'p.metodo_online,' : '') . "
        pa.nombres || ' ' || pa.apellidos AS paciente, COALESCE(c.codigo,r.codigo) AS codigo,
        COALESCE(c.fecha,r.fecha) AS fecha, COALESCE(c.estado,r.estado) AS estado_agenda
        FROM pagos p JOIN pacientes pa ON pa.id_paciente=p.id_paciente LEFT JOIN citas c USING(id_cita)
        LEFT JOIN reservas_temporales r ON r.id_reserva=p.id_reserva"
        . ($condiciones ? ' WHERE '.implode(' AND ',$condiciones) : '') . ' ORDER BY p.id_pago DESC LIMIT 51 OFFSET :offset');
    foreach ($params as $k=>$v) $q->bindValue($k,$v);
    $q->bindValue(':offset',($pagina-1)*50,PDO::PARAM_INT); $q->execute(); $lista=$q->fetchAll(PDO::FETCH_ASSOC);
    $mas=count($lista)>50; if ($mas) array_pop($lista);
} catch (Throwable $ex) { $error=$ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'listar_pagos'); }
cvp_inicio('pagos',$usuario['rol']==='paciente' ? 'Mis reservas y pagos' : 'Administración de pagos'); cvp_mensaje($error);
?>
<section class="panel"><h1><?= $usuario['rol']==='paciente' ? 'Mis reservas y pagos' : 'Administración de pagos' ?></h1>
<p>Consulta tus operaciones y sus documentos. El estado del pago es independiente del estado de atención.</p>
<div class="acciones"><?php if ($usuario['rol']==='paciente'): ?><a class="boton" href="reservar_cita.php">Reservar cita</a><a class="boton secundario" href="mis_citas.php">Mis citas y perfil</a><?php else: ?><a class="boton secundario" href="tarifas.php">Consultar tarifas</a><?php if ($usuario['rol']==='administrador'): ?><a class="boton secundario" href="reportes_financieros.php">Reportes económicos</a><?php endif; ?><?php endif; ?></div>
<form method="get" action="pagos.php" class="filtros cvp-filtros">
<div class="campo"><label for="estado">Estado de pago</label><select id="estado" name="estado"><option value="">Todos</option><?php foreach (CVP_ESTADOS as $s): ?><option value="<?= $s ?>" <?= $s===$estado ? 'selected' : '' ?>><?= cvs_escapar(cvp_estado($s)) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="metodo">Método</label><select id="metodo" name="metodo"><option value="">Todos</option><?php foreach (CVP_METODOS+CVP_ONLINE+['demostracion'=>'En línea histórico','sin_cobro'=>'Sin cobro registrado'] as $v=>$t): ?><option value="<?= $v ?>" <?= $v===$metodo ? 'selected' : '' ?>><?= cvs_escapar($t) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="desde">Creación desde</label><input id="desde" type="date" name="desde" value="<?= cvs_escapar($desde) ?>"></div>
<div class="campo"><label for="hasta">Hasta</label><input id="hasta" type="date" name="hasta" value="<?= cvs_escapar($hasta) ?>"></div><button type="submit">Filtrar</button><a href="pagos.php">Limpiar</a></form>
<div class="tabla"><table><thead><tr><th>Reserva/cita</th><th>Paciente</th><th>Fecha de cita</th><th>Agenda</th><th>Importe</th><th>Pago</th><th>Modalidad</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($lista as $p): ?><tr><td><?= cvs_escapar($p['codigo']) ?></td><td><?= cvs_escapar($p['paciente']) ?></td><td><?= cvs_escapar(cvp_fecha_visible($p['fecha'],false)) ?></td><td><?= cvs_escapar(cvp_estado($p['estado_agenda'])) ?></td><td><?= cvs_escapar(cvp_bob($p['importe'])) ?></td><td><?= cvs_escapar(cvp_estado($p['estado'])) ?></td><td><?= cvs_escapar(cvp_metodo($p)) ?></td><td><a class="boton secundario" href="pago.php?id=<?= (int)$p['id_pago'] ?>">Ver ficha / pago</a></td></tr><?php endforeach; ?>
<?php if (!$lista): ?><tr><td colspan="8">Sin operaciones para estos filtros.</td></tr><?php endif; ?></tbody></table></div>
<nav class="acciones" aria-label="Páginas de pagos"><?php $filtros=['estado'=>$estado,'metodo'=>$metodo,'desde'=>$desde,'hasta'=>$hasta]; ?>
<?php if ($pagina>1): ?><a href="?<?= cvs_escapar(http_build_query($filtros+['pagina'=>$pagina-1])) ?>">Anterior</a><?php endif; ?><span>Página <?= $pagina ?></span><?php if ($mas): ?><a href="?<?= cvs_escapar(http_build_query($filtros+['pagina'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?></nav></section>
<?php cvp_fin(); ?>
