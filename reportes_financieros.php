<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario=cvs_exigir_roles(['administrador']);
require_once __DIR__ . '/includes/pagos_ui.php';
require_once __DIR__ . '/includes/reportes_pagos.php';
cvp_solo_metodos(['GET']); $pdo=cvs_conexion(); cvp_requerir_modulo($pdo);
$hoy=new DateTimeImmutable('today',new DateTimeZone('America/La_Paz'));
$desde=cvs_texto($_GET,'desde') ?: $hoy->format('Y-m-01'); $hasta=cvs_texto($_GET,'hasta') ?: $hoy->format('Y-m-d');
$entorno=cvs_texto($_GET,'entorno') ?: 'real'; $error=null; $datos=null;
$filtros=['id_medico'=>cvs_texto($_GET,'id_medico'),'id_especialidad'=>cvs_texto($_GET,'id_especialidad'),'estado'=>cvs_texto($_GET,'estado')];
try { cvp_expirar($pdo); $datos=cvp_reporte($pdo,$desde,$hasta,$entorno,$filtros); }
catch (Throwable $ex) { $error=$ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'reportes_financieros'); }
$medicos=$pdo->query("SELECT id_medico,nombres || ' ' || apellidos AS nombre FROM medicos ORDER BY apellidos,nombres")->fetchAll(PDO::FETCH_ASSOC);
$especialidades=$pdo->query('SELECT id_especialidad,nombre FROM especialidades ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
cvp_inicio('reportes_financieros','Reportes económicos'); cvp_mensaje($error);
?>
<section class="panel"><h1>Reportes económicos</h1><p>Importes en BOB. Los cobros y devoluciones se contabilizan por fecha de movimiento en America/La_Paz, no por fecha de atención ni cantidad de citas. Las citas antiguas sin registro financiero no se valorizan retroactivamente.</p>
<div class="acciones"><a class="boton secundario" href="reportes.php">Reportes de citas</a><a class="boton secundario" href="pagos.php">Pagos y devoluciones</a></div>
<form method="get" action="reportes_financieros.php" class="filtros cvp-filtros"><div class="campo"><label for="desde">Desde</label><input id="desde" name="desde" type="date" required value="<?= cvs_escapar($desde) ?>"></div><div class="campo"><label for="hasta">Hasta</label><input id="hasta" name="hasta" type="date" required value="<?= cvs_escapar($hasta) ?>"></div><div class="campo"><label for="entorno">Libro separado</label><select id="entorno" name="entorno"><option value="real" <?= $entorno==='real' ? 'selected' : '' ?>>Presencial verificado</option><option value="demo" <?= $entorno==='demo' ? 'selected' : '' ?>>Pagos en línea · pruebas</option></select></div>
<?php foreach (['id_medico'=>['Médico',$medicos],'id_especialidad'=>['Especialidad',$especialidades]] as $campo=>[$titulo,$opciones]): ?><div class="campo"><label for="<?= $campo ?>"><?= $titulo ?></label><select name="<?= $campo ?>" id="<?= $campo ?>"><option value="">Todos</option><?php foreach ($opciones as $op): ?><option value="<?= (int)$op[$campo] ?>" <?= (string)$op[$campo] === $filtros[$campo] ? 'selected' : '' ?>><?= cvs_escapar($op['nombre']) ?></option><?php endforeach; ?></select></div><?php endforeach; ?>
<div class="campo"><label for="estado">Estado del pago</label><select name="estado" id="estado"><option value="">Todos</option><?php foreach (CVP_ESTADOS as $s): ?><option value="<?= $s ?>" <?= $s === $filtros['estado'] ? 'selected' : '' ?>><?= cvs_escapar(cvp_estado($s)) ?></option><?php endforeach; ?></select></div><button type="submit">Consultar</button><a href="reportes_financieros.php">Limpiar</a></form>
<?php if ($entorno==='demo'): ?><p class="cvp-prueba"><?= cvs_escapar(CVP_DEMO_AVISO) ?></p><?php endif; ?>
<?php if ($datos): $r=$datos['resumen']; ?>
<div class="cvp-indicadores"><?php foreach (['cobrado'=>'Cobrado bruto en el período','reembolsado'=>'Devuelto en el período','neto'=>'Neto de movimientos','pendiente'=>'Importe pendiente actual¹','importe_devoluciones'=>'Importe en revisión¹'] as $campo=>$titulo): ?><div class="panel"><h2><?= cvs_escapar($titulo) ?></h2><p class="cvp-precio"><?= cvs_escapar(cvp_bob($r[$campo])) ?></p></div><?php endforeach; ?></div>
<div class="resumen"><?php foreach (['exitosos'=>'Pagos con cobro en el período','fallidos'=>'Pagos fallidos actuales¹','devoluciones_pendientes'=>'Devoluciones pendientes¹','devoluciones_completadas'=>'Devoluciones completadas¹','operaciones'=>'Operaciones creadas¹'] as $campo=>$titulo): ?><span class="pill"><?= cvs_escapar($titulo) ?>: <?= (int)$r[$campo] ?></span><?php endforeach; ?></div>
<p class="pequeno">¹ Estado actual de las cuentas creadas dentro del rango seleccionado. Las anuladas, vencidas y fallidas no son deuda pendiente. Una devolución pendiente todavía forma parte del cobrado bruto; solo el movimiento de devolución reduce el neto.</p>
<?php endif; ?></section>
<?php if ($datos): foreach (['fecha'=>'Movimientos por fecha','especialidad'=>'Ingresos por especialidad','medico'=>'Ingresos por médico','metodo'=>'Ingresos por método'] as $tipo=>$titulo): ?>
<section class="panel"><h2><?= cvs_escapar($titulo) ?></h2><div class="tabla"><table><thead><tr><th>Agrupación</th><th>Cobrado</th><th>Devuelto</th><th>Neto</th></tr></thead><tbody>
<?php foreach ($datos['grupos'][$tipo] as $fila): ?><tr><td><?= cvs_escapar($tipo === 'fecha' ? cvp_fecha_visible($fila['grupo'],false) : (CVP_METODOS[$fila['grupo']] ?? CVP_ONLINE[$fila['grupo']] ?? ($fila['grupo'] === 'demostracion' ? 'En línea · histórico de prueba' : $fila['grupo']))) ?></td><td><?= cvs_escapar(cvp_bob($fila['cobrado'])) ?></td><td><?= cvs_escapar(cvp_bob($fila['devuelto'])) ?></td><td><?= cvs_escapar(cvp_bob($fila['neto'])) ?></td></tr><?php endforeach; ?>
<?php if (!$datos['grupos'][$tipo]): ?><tr><td colspan="4">Sin movimientos monetarios en este período.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endforeach; endif; cvp_fin(); ?>
