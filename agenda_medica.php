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
$fechaSel = trim($_GET['fecha'] ?? date('Y-m-d'));
$fSel = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaSel);
if (!$fSel) {
    $fechaSel = date('Y-m-d');
    $fSel = new DateTimeImmutable($fechaSel);
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
if (!$medicoActual && !empty($medicosLista)) {
    // Seleccionar primer médico por defecto si no hay uno válido
    $idMedicoSel = (int)$medicosLista[0]['id_medico'];
    $medicoActual = $medicosLista[0];
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
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f7f8;color:#19333c;font-family:Arial,sans-serif}
        header{background:#146d73;color:white;padding:22px max(20px,calc((100vw - 1100px)/2))}
        header h1{margin:0;font-size:25px}header p{margin:7px 0 0}
        nav{margin-top:12px}nav a{color:#e6fffd;text-decoration:none;margin-right:14px;font-weight:600}
        nav a:hover{text-decoration:underline}
        main{max-width:1200px;margin:28px auto;padding:0 16px}
        .panel{background:white;border-radius:13px;padding:25px;margin-bottom:24px;box-shadow:0 3px 20px #0a30301a}
        h2{margin-top:0;font-size:21px}
        .campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:end}
        label{display:block;font-weight:600;font-size:14px;margin-bottom:7px}
        input,select{width:100%;border:1px solid #cbd6d9;border-radius:7px;padding:11px;font-size:15px;background:white;font-family:Arial,sans-serif}
        .acciones{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px;align-items:center}
        button, .btn{background:#146d73;color:white;border:0;border-radius:8px;padding:12px 22px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block}
        .mensaje{border-radius:7px;padding:12px 15px;margin-bottom:15px;background:#e2f7e9}
        .error{background:#fce6e6}
        .resumen{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
        .pill{padding:8px 12px;border-radius:999px;background:#edf5f5;border:1px solid #c9d9dc;font-size:13px;font-weight:600}
        .tabla{overflow-x:auto}
        table{border-collapse:collapse;width:100%;min-width:950px}
        td,th{text-align:left;border-bottom:1px solid #e5ebee;padding:12px;font-size:14px;vertical-align:top}
        th{background:#edf5f5}
        .estado{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:0.3px}
        .est-programada{background:#e6f4ff;color:#0b4f8a}
        .est-confirmada{background:#e8f7ef;color:#1f7a43}
        .est-atendida{background:#f1f5f9;color:#334155}
        .est-cancelada{background:#fdecec;color:#a61e1e}
        .est-inasistencia{background:#fff3cd;color:#7a5c00}
        .timeline{display:grid;grid-template-columns:90px 1fr;gap:10px}
        .hora-col{color:#6b7f85;font-weight:600}
        .cita-item{border:1px solid #e1ecec;border-radius:10px;padding:12px 14px;background:#fbfefe;margin-bottom:10px;box-shadow:0 1px 6px #0a303005}
        .cita-head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
        .cita-title{font-weight:700}
        .cita-meta{display:flex;flex-wrap:wrap;gap:12px;color:#35595c;font-size:13px;margin-top:6px}
        @media(max-width:700px){.campos{grid-template-columns:1fr}.panel{padding:17px}}
    </style>
</head>
<body>
<header>
    <h1>Clínica Vida Salud</h1>
    <p>Agenda médica - Versión local de desarrollo</p>
    <nav>
        <a href="index.php">Inicio</a>
        <a href="pacientes.php">Pacientes</a>
        <a href="medicos.php">Médicos</a>
        <a href="horarios.php">Horarios</a>
        <a href="citas.php">Citas</a>
        <a href="agenda_medica.php">Agenda</a>
    </nav>
</header>
<main>
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
</body>
</html>
