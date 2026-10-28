<?php
/**
 * RF11 - Agenda médica
 * Clínica Vida Salud
 */

require_once __DIR__ . '/includes/auth.php';
cvs_exigir_roles(['administrador', 'recepcionista']);
$conexion = cvs_conexion();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errores = [];
$aviso   = $_SESSION['aviso_agenda'] ?? null;
unset($_SESSION['aviso_agenda']);

// Cargar médicos activos
$medicosLista = [];
try {
    $mStmt = $conexion->query("SELECT id_medico, registro_profesional, nombres, apellidos FROM medicos WHERE activo = TRUE ORDER BY apellidos, nombres");
    $medicosLista = $mStmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $errores[] = 'No se pudieron cargar los médicos.';
}

$idMedicoSel = filter_var($_GET['id_medico'] ?? '', FILTER_VALIDATE_INT);
$fechaSel = array_key_exists('fecha', $_GET) ? cvs_texto($_GET, 'fecha') : (new DateTimeImmutable('today', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
$fSel = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $fechaSel)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $fechaSel) : false;
if (!$fSel || $fSel->format('Y-m-d') !== $fechaSel || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $fechaSel)) {
    $errores[] = 'La fecha no existe o no tiene formato AAAA-MM-DD.';
    $fSel = false;
    http_response_code(400);
}

// Validar médico seleccionado
$medicoActual = null;
if ($idMedicoSel) {
    foreach ($medicosLista as $m) {
        if ((int)$m['id_medico'] === $idMedicoSel) {
            $medicoActual = $m;
            break;
        }
    }
}
if (!$medicoActual && !array_key_exists('id_medico', $_GET) && !empty($medicosLista)) {
    // Seleccionar primer médico por defecto si no hay uno válido
    $idMedicoSel = (int)$medicosLista[0]['id_medico'];
    $medicoActual = $medicosLista[0];
}
if (array_key_exists('id_medico', $_GET) && !$medicoActual) {
    $errores[] = 'Selecciona un médico activo válido.';
    $idMedicoSel = false;
    http_response_code(400);
}

// Obtener citas del médico para la fecha
$citasAgenda = [];
$resumen = [
    'total' => 0,
    'programada' => 0,
    'confirmada' => 0,
    'atendida' => 0,
    'cancelada' => 0,
    'inasistencia' => 0
];
if ($idMedicoSel && $fSel) {
    try {
        $sqlA = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado, c.motivo,
                        p.nombres || ' ' || p.apellidos AS paciente,
                        e.nombre AS especialidad
                 FROM citas c
                 JOIN pacientes p ON p.id_paciente = c.id_paciente
                 JOIN especialidades e ON e.id_especialidad = c.id_especialidad
                 WHERE c.id_medico = :id_medico AND c.fecha = :fecha
                 ORDER BY c.hora_inicio ASC, c.hora_fin ASC";
        $stmtA = $conexion->prepare($sqlA);
        $stmtA->bindValue(':id_medico', $idMedicoSel, PDO::PARAM_INT);
        $stmtA->bindValue(':fecha', $fechaSel, PDO::PARAM_STR);
        $stmtA->execute();
        $citasAgenda = $stmtA->fetchAll();
        $resumen['total'] = count($citasAgenda);
        foreach ($citasAgenda as $ca) {
            $est = $ca['estado'];
            if (isset($resumen[$est])) {
                $resumen[$est]++;
            }
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $errores[] = 'No se pudo cargar la agenda del médico.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agenda médica - Clínica Vida Salud</title>
    <link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('agenda', 'Agenda médica'); ?>
<main class="ui-contenido">
    <section class="panel">
        <h2>Consulta de agenda por médico</h2>
        <?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
        <?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
        
        <form method="get" action="agenda_medica.php" id="filtroAgenda">
            <div class="campos">
                <div>
                    <label for="id_medico">Médico *</label>
                    <select id="id_medico" name="id_medico" required>
                        <?php foreach ($medicosLista as $m): ?>
                            <option value="<?= e($m['id_medico']) ?>" <?= ($idMedicoSel == $m['id_medico']) ? 'selected' : '' ?>>
                                <?= e($m['apellidos'] . ', ' . $m['nombres']) ?><?= $m['registro_profesional'] ? ' (' . e($m['registro_profesional']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fecha">Fecha *</label>
                    <input id="fecha" type="date" name="fecha" value="<?= e($fechaSel) ?>" required>
                </div>
            </div>
            <div class="acciones">
                <button type="submit">Ver agenda</button>
                <a class="btn" href="citas.php">Ir a Citas</a>
            </div>
        </form>
        
        <?php if ($medicoActual): ?>
            <div style="margin-top:18px">
                <h3 style="margin:0">Agenda de <?= e($medicoActual['apellidos'] . ', ' . $medicoActual['nombres']) ?> - <?= e($fechaSel) ?></h3>
                <div class="resumen">
                    <span class="pill">Total: <?= e($resumen['total']) ?></span>
                    <span class="pill">Programadas: <?= e($resumen['programada']) ?></span>
                    <span class="pill">Confirmadas: <?= e($resumen['confirmada']) ?></span>
                    <span class="pill">Atendidas: <?= e($resumen['atendida']) ?></span>
                    <span class="pill">Canceladas: <?= e($resumen['cancelada']) ?></span>
                    <span class="pill">Inasistencias: <?= e($resumen['inasistencia']) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </section>
    
    <section class="panel">
        <h2>Citas del día</h2>
        <?php if (empty($citasAgenda)): ?>
            <p style="color:#6b7f85">No hay citas para mostrar en esta agenda.</p>
        <?php else: ?>
            <div class="tabla" style="margin-bottom:18px">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Hora inicio</th>
                            <th>Hora fin</th>
                            <th>Especialidad</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($citasAgenda as $c): ?>
                            <tr>
                                <td><?= e($c['codigo']) ?></td>
                                <td><?= e($c['paciente']) ?></td>
                                <td><?= e(substr($c['hora_inicio'], 0, 5)) ?></td>
                                <td><?= e(substr($c['hora_fin'], 0, 5)) ?></td>
                                <td><?= e($c['especialidad']) ?></td>
                                <td>
                                    <?php
                                    $est = $c['estado'];
                                    $cls = 'est-programada';
                                    if ($est === 'confirmada') $cls = 'est-confirmada';
                                    elseif ($est === 'atendida') $cls = 'est-atendida';
                                    elseif ($est === 'cancelada') $cls = 'est-cancelada';
                                    elseif ($est === 'inasistencia') $cls = 'est-inasistencia';
                                    ?>
                                    <span class="estado <?= e($cls) ?>"><?= e($est) ?></span>
                                </td>
                                <td>
                                    <?php if (in_array($c['estado'], ['programada', 'confirmada'], true)): ?>
                                        <a class="btn" style="padding:8px 14px;font-size:13px" href="reprogramar_cita.php?id_cita=<?= e($c['id_cita']) ?>">Reprogramar</a>
                                    <?php else: ?>
                                        <span style="color:#6b7f85">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <h3 style="margin-top:0">Línea de tiempo</h3>
            <?php foreach ($citasAgenda as $c): ?>
                <div class="timeline">
                    <div class="hora-col"><?= e(substr($c['hora_inicio'], 0, 5)) ?> - <?= e(substr($c['hora_fin'], 0, 5)) ?></div>
                    <div class="cita-item">
                        <div class="cita-head">
                            <div class="cita-title"><?= e($c['paciente']) ?> <span style="color:#6b7f85;font-weight:400">| <?= e($c['codigo']) ?></span></div>
                            <div>
                                <?php
                                $est = $c['estado'];
                                $cls = 'est-programada';
                                if ($est === 'confirmada') $cls = 'est-confirmada';
                                elseif ($est === 'atendida') $cls = 'est-atendida';
                                elseif ($est === 'cancelada') $cls = 'est-cancelada';
                                elseif ($est === 'inasistencia') $cls = 'est-inasistencia';
                                ?>
                                <span class="estado <?= e($cls) ?>"><?= e($est) ?></span>
                            </div>
                        </div>
                        <div class="cita-meta">
                            <span>Especialidad: <?= e($c['especialidad']) ?></span>
                            <?php if (!empty($c['motivo'])): ?><span>Motivo: <?= e($c['motivo']) ?></span><?php endif; ?>
                            <?php if (in_array($c['estado'], ['programada', 'confirmada'], true)): ?>
                                <a href="reprogramar_cita.php?id_cita=<?= e($c['id_cita']) ?>" style="color:#146d73;font-weight:600">Reprogramar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
<?php cvsu_cierre(); ?>
</body>
</html>
