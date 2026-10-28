<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['medico']);
require_once __DIR__ . '/includes/personal.php';
require_once __DIR__ . '/includes/ui.php';
cvsp_solo_get(['fecha']);
$fecha = $_GET['fecha'] ?? cvsd_hoy()->format('Y-m-d');
$f = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
$error = (!$f || $f->format('Y-m-d') !== $fecha || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $fecha)) ? 'La fecha no existe. Selecciona una fecha válida.' : '';
if ($error) http_response_code(400);
$pdo = cvs_conexion(); $citas = []; $horarios = [];
try {
    $q = $pdo->prepare('SELECT id_medico, nombres, apellidos FROM medicos WHERE id_usuario=:usuario AND activo=TRUE');
    $q->execute([':usuario'=>$usuario['id_usuario']]); $medico = $q->fetch(PDO::FETCH_ASSOC);
    if ($medico && !$error) {
        // Identidad exclusivamente de sesión y FK, nunca un ID recibido del navegador.
        $q = $pdo->prepare("SELECT c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
            p.nombres || ' ' || p.apellidos AS persona, e.nombre AS especialidad
            FROM citas c JOIN medicos m ON m.id_medico=c.id_medico
            JOIN pacientes p ON p.id_paciente=c.id_paciente JOIN especialidades e ON e.id_especialidad=c.id_especialidad
            WHERE m.id_usuario=:usuario AND m.activo=TRUE AND c.fecha=:fecha ORDER BY c.hora_inicio, c.id_cita");
        $q->execute([':usuario'=>$usuario['id_usuario'], ':fecha'=>$fecha]); $citas = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT h.dia_semana, h.hora_inicio, h.hora_fin, h.duracion_minutos FROM horarios h JOIN medicos m USING (id_medico)
            WHERE m.id_usuario=:usuario AND m.activo=TRUE AND h.activo=TRUE ORDER BY h.dia_semana, h.hora_inicio');
        $q->execute([':usuario'=>$usuario['id_usuario']]); $horarios = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $ex) { cvs_error_base_datos($ex); }
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mi agenda | Clínica Vida Salud</title><link rel="stylesheet" href="assets/estilos.css"></head><body>
<?php cvsu_area('mi_agenda', 'Mi agenda médica'); ?><main class="ui-contenido">
<section class="panel"><h1>Mi agenda</h1>
<?php if (!$medico): ?><p class="aviso">Tu cuenta todavía no tiene un registro médico activo vinculado. Solicita al administrador que revise el vínculo.</p>
<?php else: ?><p><?= cvs_escapar($medico['nombres'] . ' ' . $medico['apellidos']) ?> · Consulta personal · Hora de referencia: America/La_Paz.</p>
<form method="get" action="mi_agenda.php" class="filtros"><div class="campo"><label for="fecha">Fecha</label><input type="date" id="fecha" name="fecha" required value="<?= cvs_escapar($fecha) ?>"></div><button type="submit">Consultar</button></form>
<?php if ($error): ?><div class="error" role="alert"><?= cvs_escapar($error) ?></div><?php else: ?>
<div class="resumen"><span class="pill">Total: <?= count($citas) ?></span><?php $conteos = array_count_values(array_column($citas, 'estado')); foreach (CVSD_ESTADOS as $estado): ?><span class="estado est-<?= $estado ?>"><?= cvs_escapar(ucfirst($estado)) ?>: <?= $conteos[$estado] ?? 0 ?></span><?php endforeach; ?></div>
<?php cvsp_tabla_citas($citas, 'Paciente'); endif; ?>
<?php endif; ?></section>
<?php if ($medico && !$error): ?><section class="panel"><h2>Mis horarios de atención</h2><p>Horarios semanales activos configurados por administración.</p>
<?php if (!$horarios): ?><p>No tienes horarios activos configurados.</p><?php endif; ?>
<?php foreach ($horarios as $h): ?><div class="info-item"><span class="label"><?= cvs_escapar(CVSD_DIAS_SEMANA[(int)$h['dia_semana']]) ?></span><span class="value"><?= cvs_escapar(substr($h['hora_inicio'], 0, 5) . ' – ' . substr($h['hora_fin'], 0, 5)) ?> · Turnos de <?= (int)$h['duracion_minutos'] ?> minutos</span></div><?php endforeach; ?></section><?php endif; ?>
</main><?php cvsu_cierre(); ?></body></html>
