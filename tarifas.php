<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['administrador','recepcionista']);
require_once __DIR__ . '/includes/pagos_ui.php';
cvp_solo_metodos(['GET','POST']);
$pdo = cvs_conexion(); cvp_requerir_modulo($pdo); $error = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        cvp_validar_post('tarifa');
        cvp_guardar_tarifa($pdo,(int)$usuario['id_usuario'],cvp_id($_POST['id_medico'] ?? null),cvp_id($_POST['id_especialidad'] ?? null),cvs_texto($_POST,'importe'));
        $_SESSION['cvp_aviso'] = 'Tarifa actualizada para futuras reservas. Los importes acordados no cambian.';
        header('Location: tarifas.php',true,303); exit;
    }
} catch (Throwable $ex) { if ($ex instanceof CvpAccesoDenegado) http_response_code(403); $error = $ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'tarifas'); }
$lista = $pdo->query("SELECT me.id_medico,me.id_especialidad,m.nombres || ' ' || m.apellidos AS medico,e.nombre AS especialidad,
    t.importe,m.activo AND e.activo AS activo FROM medico_especialidad me JOIN medicos m USING(id_medico)
    JOIN especialidades e USING(id_especialidad) LEFT JOIN tarifas_consulta t USING(id_medico,id_especialidad) ORDER BY e.nombre,m.apellidos")->fetchAll(PDO::FETCH_ASSOC);
$historial = $pdo->query("SELECT h.importe_anterior,h.importe_nuevo,h.creado_en,u.nombre_usuario,m.nombres || ' ' || m.apellidos AS medico,e.nombre AS especialidad
    FROM historial_tarifas h JOIN usuarios u ON u.id_usuario=h.responsable JOIN medicos m USING(id_medico)
    JOIN especialidades e USING(id_especialidad) ORDER BY h.id_historial DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$claveTarifas=cvp_formulario('tarifa');
cvp_inicio('tarifas','Tarifas de consultas'); cvp_mensaje($error);
?>
<section class="panel"><h1>Tarifas de consultas</h1><p>Importes en BOB por médico y especialidad. No se cargan precios de ejemplo. Las reservas conservan el importe acordado aunque cambie esta tabla.</p>
<?php if ($usuario['rol'] !== 'administrador'): ?><p class="aviso">Recepción puede consultar tarifas, pero no modificarlas.</p><?php endif; ?>
<div class="tabla"><table><thead><tr><th>Médico</th><th>Especialidad</th><th>Tarifa actual</th><th>Estado</th><?php if ($usuario['rol'] === 'administrador'): ?><th>Configurar</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($lista as $fila): ?><tr><td><?= cvs_escapar($fila['medico']) ?></td><td><?= cvs_escapar($fila['especialidad']) ?></td><td><?= $fila['importe'] === null ? 'Sin tarifa' : cvs_escapar(cvp_bob($fila['importe'])) ?></td><td><?= cvs_activo($fila['activo']) ? 'Oferta activa' : 'Oferta inactiva' ?></td>
<?php if ($usuario['rol'] === 'administrador'): ?><td><form method="post" action="tarifas.php" class="cvp-tarifa">
<?php cvp_ocultos($claveTarifas); ?><input type="hidden" name="id_medico" value="<?= (int)$fila['id_medico'] ?>"><input type="hidden" name="id_especialidad" value="<?= (int)$fila['id_especialidad'] ?>">
<label>Nuevo importe (punto decimal)<input type="text" name="importe" inputmode="decimal" maxlength="13" required value="<?= cvs_escapar($error && cvp_id($_POST['id_medico'] ?? null) === (int)$fila['id_medico'] && cvp_id($_POST['id_especialidad'] ?? null) === (int)$fila['id_especialidad'] ? cvs_texto($_POST,'importe') : ($fila['importe'] ?? '')) ?>"></label><button type="submit">Guardar</button></form></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$lista): ?><tr><td colspan="5">Configura primero médicos y sus especialidades.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Últimos cambios de tarifa</h2><div class="tabla"><table><thead><tr><th>Fecha</th><th>Consulta</th><th>Antes</th><th>Después</th><th>Responsable</th></tr></thead><tbody>
<?php foreach ($historial as $h): ?><tr><td><?= cvs_escapar(cvp_fecha_visible($h['creado_en'])) ?></td><td><?= cvs_escapar($h['medico'].' · '.$h['especialidad']) ?></td><td><?= $h['importe_anterior'] === null ? 'Sin tarifa' : cvs_escapar(cvp_bob($h['importe_anterior'])) ?></td><td><?= cvs_escapar(cvp_bob($h['importe_nuevo'])) ?></td><td><?= cvs_escapar($h['nombre_usuario']) ?></td></tr><?php endforeach; ?>
<?php if (!$historial): ?><tr><td colspan="5">No hay cambios registrados.</td></tr><?php endif; ?></tbody></table></div></section>
<?php cvp_fin(); ?>
