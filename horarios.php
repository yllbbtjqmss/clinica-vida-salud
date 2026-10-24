<?php
/**
 * RF05 - Gestión de horarios de atención
 * Clínica Vida Salud
 */

session_start();
require 'C:/xampp/config_clinica/database.php';

// Generación de token CSRF
if (empty($_SESSION['csrf_horarios'])) {
    $_SESSION['csrf_horarios'] = bin2hex(random_bytes(32));
}

// Helper para escapar salida
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errores = [];
$aviso   = $_SESSION['aviso_horarios'] ?? null;
unset($_SESSION['aviso_horarios']);

// Días de la semana (según esquema: 1-7)
$diasSemana = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado',
    7 => 'Domingo'
];

// Cargar médicos activos
try {
    $medStmt = $conexion->query("SELECT id_medico, registro_profesional, nombres, apellidos FROM medicos WHERE activo = TRUE ORDER BY apellidos, nombres");
    $medicosLista = $medStmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $medicosLista = [];
    $errores[] = 'No se pudieron cargar los médicos activos.';
}

// Datos formulario
$datos = [
    'id_horario'      => '',
    'id_medico'       => '',
    'dia_semana'      => '',
    'hora_inicio'     => '',
    'hora_fin'        => '',
    'duracion_minutos'=> '15',
    'activo'          => '1'
];

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_horarios'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no válida. Recarga la página.';
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'toggle' && !$errores) {
        $id_h = filter_var($_POST['id_horario'] ?? null, FILTER_VALIDATE_INT);
        if (!$id_h) {
            $errores[] = 'Identificador de horario inválido.';
        } else {
            try {
                $stmtT = $conexion->prepare("UPDATE horarios SET activo = NOT activo WHERE id_horario = :id");
                $stmtT->bindValue(':id', $id_h, PDO::PARAM_INT);
                $stmtT->execute();
                $_SESSION['aviso_horarios'] = 'Estado del horario actualizado correctamente.';
                header('Location: horarios.php#horarios-listado');
                exit;
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errores[] = 'No se pudo cambiar el estado del horario.';
            }
        }
    }

    if ($accion === 'guardar' && !$errores) {
        $datos['id_horario']       = trim((string)($_POST['id_horario'] ?? ''));
        $datos['id_medico']        = trim((string)($_POST['id_medico'] ?? ''));
        $datos['dia_semana']       = trim((string)($_POST['dia_semana'] ?? ''));
        $datos['hora_inicio']      = trim((string)($_POST['hora_inicio'] ?? ''));
        $datos['hora_fin']         = trim((string)($_POST['hora_fin'] ?? ''));
        $datos['duracion_minutos'] = trim((string)($_POST['duracion_minutos'] ?? '15'));
        $datos['activo']           = (isset($_POST['activo']) && $_POST['activo'] === '1') ? '1' : '0';

        // Validaciones
        $idMedVal = filter_var($datos['id_medico'], FILTER_VALIDATE_INT);
        if (!$idMedVal) {
            $errores[] = 'Selecciona un médico activo.';
        }
        $diaVal = filter_var($datos['dia_semana'], FILTER_VALIDATE_INT);
        if (!$diaVal || $diaVal < 1 || $diaVal > 7) {
            $errores[] = 'Selecciona un día de la semana válido (1-7).';
        }
        if ($datos['hora_inicio'] === '' || $datos['hora_fin'] === '') {
            $errores[] = 'Indica hora de inicio y hora de fin.';
        }
        $durVal = filter_var($datos['duracion_minutos'], FILTER_VALIDATE_INT);
        if (!$durVal || $durVal <= 0) {
            $errores[] = 'La duración por cita debe ser mayor a 0.';
        }
        if ($datos['hora_inicio'] !== '' && $datos['hora_fin'] !== '') {
            if (strtotime($datos['hora_fin']) <= strtotime($datos['hora_inicio'])) {
                $errores[] = 'La hora final debe ser mayor a la hora inicial.';
            }
        }

        if (!$errores) {
            $esNuevo = ($datos['id_horario'] === '');
            try {
                $conexion->beginTransaction();
                if ($esNuevo) {
                    $sqlIns = "INSERT INTO horarios (id_medico, dia_semana, hora_inicio, hora_fin, duracion_minutos, activo)
                               VALUES (:id_medico, :dia_semana, :hora_inicio, :hora_fin, :duracion_minutos, :activo)";
                    $stmtIns = $conexion->prepare($sqlIns);
                    $stmtIns->bindValue(':id_medico', $idMedVal, PDO::PARAM_INT);
                    $stmtIns->bindValue(':dia_semana', $diaVal, PDO::PARAM_INT);
                    $stmtIns->bindValue(':hora_inicio', $datos['hora_inicio'], PDO::PARAM_STR);
                    $stmtIns->bindValue(':hora_fin', $datos['hora_fin'], PDO::PARAM_STR);
                    $stmtIns->bindValue(':duracion_minutos', $durVal, PDO::PARAM_INT);
                    $stmtIns->bindValue(':activo', ($datos['activo'] === '1'), PDO::PARAM_BOOL);
                    $stmtIns->execute();
                    $conexion->commit();
                    $_SESSION['aviso_horarios'] = 'Horario registrado correctamente.';
                    header('Location: horarios.php#horarios-listado');
                    exit;
                } else {
                    $idH = filter_var($datos['id_horario'], FILTER_VALIDATE_INT);
                    if (!$idH) {
                        throw new Exception('Horario inválido');
                    }
                    $sqlUpd = "UPDATE horarios SET id_medico=:id_medico, dia_semana=:dia_semana, hora_inicio=:hora_inicio, hora_fin=:hora_fin, duracion_minutos=:duracion_minutos, activo=:activo WHERE id_horario=:id_horario";
                    $stmtUpd = $conexion->prepare($sqlUpd);
                    $stmtUpd->bindValue(':id_medico', $idMedVal, PDO::PARAM_INT);
                    $stmtUpd->bindValue(':dia_semana', $diaVal, PDO::PARAM_INT);
                    $stmtUpd->bindValue(':hora_inicio', $datos['hora_inicio'], PDO::PARAM_STR);
                    $stmtUpd->bindValue(':hora_fin', $datos['hora_fin'], PDO::PARAM_STR);
                    $stmtUpd->bindValue(':duracion_minutos', $durVal, PDO::PARAM_INT);
                    $stmtUpd->bindValue(':activo', ($datos['activo'] === '1'), PDO::PARAM_BOOL);
                    $stmtUpd->bindValue(':id_horario', $idH, PDO::PARAM_INT);
                    $stmtUpd->execute();
                    $conexion->commit();
                    $_SESSION['aviso_horarios'] = 'Horario actualizado correctamente.';
                    header('Location: horarios.php#horarios-listado');
                    exit;
                }
            } catch (PDOException $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                // 23P01 exclusion violation, 23514 check
                if ($e->getCode() === '23P01') {
                    $errores[] = 'Existe un solapamiento con otro horario activo del mismo médico en ese día.';
                } elseif ($e->getCode() === '23514') {
                    $errores[] = 'Los datos no cumplen las restricciones: verifique horas, día o duración.';
                } elseif ($e->getCode() === '23503') {
                    $errores[] = 'No se puede modificar el horario por restricciones de integridad con citas existentes.';
                } else {
                    error_log($e->getMessage());
                    $errores[] = 'No se pudo guardar el horario.';
                }
            } catch (Exception $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                $errores[] = 'No se pudo guardar el horario.';
            }
        }
    }
}

// Editar
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {
    $idEdit = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
    if ($idEdit) {
        try {
            $stmtEd = $conexion->prepare("SELECT id_horario, id_medico, dia_semana, hora_inicio, hora_fin, duracion_minutos, activo FROM horarios WHERE id_horario = :id");
            $stmtEd->bindValue(':id', $idEdit, PDO::PARAM_INT);
            $stmtEd->execute();
            $hEd = $stmtEd->fetch();
            if ($hEd) {
                $datos['id_horario']       = $hEd['id_horario'];
                $datos['id_medico']        = $hEd['id_medico'];
                $datos['dia_semana']       = $hEd['dia_semana'];
                $datos['hora_inicio']      = substr($hEd['hora_inicio'], 0, 5);
                $datos['hora_fin']         = substr($hEd['hora_fin'], 0, 5);
                $datos['duracion_minutos'] = $hEd['duracion_minutos'];
                $datos['activo']           = $hEd['activo'] ? '1' : '0';
            } else {
                $errores[] = 'Horario no encontrado.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errores[] = 'No se pudo cargar el horario para editar.';
        }
    } else {
        $errores[] = 'Identificador de horario inválido.';
    }
}

// Filtros listado
$fMed = filter_var($_GET['f_medico'] ?? '', FILTER_VALIDATE_INT);
$fDia = filter_var($_GET['f_dia'] ?? '', FILTER_VALIDATE_INT);
$lista = [];
try {
    $sqlList = "SELECT h.id_horario, h.id_medico, h.dia_semana, h.hora_inicio, h.hora_fin, h.duracion_minutos, h.activo,
                       m.registro_profesional, m.nombres, m.apellidos
                FROM horarios h
                JOIN medicos m ON m.id_medico = h.id_medico
                WHERE 1=1";
    $params = [];
    if ($fMed) {
        $sqlList .= " AND h.id_medico = :f_medico";
        $params[':f_medico'] = $fMed;
    }
    if ($fDia && $fDia >=1 && $fDia <=7) {
        $sqlList .= " AND h.dia_semana = :f_dia";
        $params[':f_dia'] = $fDia;
    }
    $sqlList .= " ORDER BY m.apellidos, m.nombres, h.dia_semana, h.hora_inicio";
    $stmtList = $conexion->prepare($sqlList);
    foreach ($params as $k => $v) {
        $stmtList->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmtList->execute();
    $lista = $stmtList->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $lista = [];
    $errores[] = 'No se pudo cargar el listado de horarios.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horarios | Clínica Vida Salud</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f7f8;color:#19333c;font-family:Arial,sans-serif}
        header{background:#146d73;color:white;padding:22px max(20px,calc((100vw - 1100px)/2))}
        header h1{margin:0;font-size:25px}header p{margin:7px 0 0}
        nav{margin-top:12px}nav a{color:#e6fffd;text-decoration:none;margin-right:14px;font-weight:600}
        nav a:hover{text-decoration:underline}
        main{max-width:1100px;margin:28px auto;padding:0 16px}
        .panel{background:white;border-radius:13px;padding:25px;margin-bottom:24px;box-shadow:0 3px 20px #0a30301a}
        h2{margin-top:0;font-size:21px}
        .campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        label{display:block;font-weight:600;font-size:14px;margin-bottom:7px}
        input,select{width:100%;border:1px solid #cbd6d9;border-radius:7px;padding:11px;font-size:15px;background:white}
        .acciones{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px;align-items:center}
        button, .btn{background:#146d73;color:white;border:0;border-radius:8px;padding:12px 22px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block}
        .btn-sec{background:#4a6f73}
        .mensaje{border-radius:7px;padding:12px 15px;margin-bottom:15px;background:#e2f7e9}
        .error{background:#fce6e6}
        .filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
        .filtros > div{min-width:220px}
        .tabla{overflow-x:auto}
        table{border-collapse:collapse;width:100%;min-width:900px}
        td,th{text-align:left;border-bottom:1px solid #e5ebee;padding:12px;font-size:14px;vertical-align:top}
        th{background:#edf5f5}
        .estado-activo{color:#1f7c3f;font-weight:600}
        .estado-inactivo{color:#9b2c2c;font-weight:600}
        .mini{padding:8px 14px;font-size:13px}
        #horarios-listado{scroll-margin-top: 20px;}
        @media(max-width:700px){.campos{grid-template-columns:1fr}.panel{padding:17px}}
    </style>
</head>
<body>
<header>
    <h1>Clínica Vida Salud</h1>
    <p>Gestión de horarios de atención · Versión local de desarrollo</p>
    <nav>
        <a href="index.php">Inicio</a>
        <a href="pacientes.php">Pacientes</a>
        <a href="medicos.php">Médicos</a>
        <a href="horarios.php">Horarios</a>
    </nav>
</header>
<main>
    <section class="panel">
        <h2><?= $datos['id_horario'] === '' ? 'Registrar nuevo horario' : 'Editar horario' ?></h2>
        <?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
        <?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post" action="horarios.php" id="formHorario" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_horarios']) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_horario" value="<?= e($datos['id_horario']) ?>">
            <div class="campos">
                <div>
                    <label for="id_medico">Médico activo *</label>
                    <select id="id_medico" name="id_medico" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($medicosLista as $m): ?>
                            <option value="<?= e($m['id_medico']) ?>" <?= ((string)$datos['id_medico'] === (string)$m['id_medico']) ? 'selected' : '' ?>>
                                <?= e($m['apellidos'] . ', ' . $m['nombres']) ?> (<?= e($m['registro_profesional']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="dia_semana">Día de la semana *</label>
                    <select id="dia_semana" name="dia_semana" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($diasSemana as $num => $nom): ?>
                            <option value="<?= e($num) ?>" <?= ((string)$datos['dia_semana'] === (string)$num) ? 'selected' : '' ?>><?= e($nom) ?> (<?= e($num) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="hora_inicio">Hora inicio *</label>
                    <input id="hora_inicio" type="time" name="hora_inicio" required value="<?= e($datos['hora_inicio']) ?>">
                </div>
                <div>
                    <label for="hora_fin">Hora fin *</label>
                    <input id="hora_fin" type="time" name="hora_fin" required value="<?= e($datos['hora_fin']) ?>">
                </div>
                <div>
                    <label for="duracion_minutos">Duración por cita (minutos) *</label>
                    <input id="duracion_minutos" type="number" min="1" step="1" name="duracion_minutos" required value="<?= e($datos['duracion_minutos']) ?>">
                </div>
                <div>
                    <label for="activo">Estado</label>
                    <select id="activo" name="activo">
                        <option value="1" <?= $datos['activo'] === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $datos['activo'] === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>
            <div class="acciones">
                <button type="submit">Guardar horario</button>
                <?php if ($datos['id_horario'] !== ''): ?>
                    <a class="btn btn-sec" href="horarios.php#horarios-listado">Cancelar edición</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" id="horarios-listado">
        <h2>Horarios registrados</h2>
        <form method="get" action="horarios.php#horarios-listado" class="filtros" style="margin-bottom:18px">
            <div>
                <label for="f_medico">Filtrar por médico</label>
                <select id="f_medico" name="f_medico">
                    <option value="">Todos</option>
                    <?php foreach ($medicosLista as $m): ?>
                        <option value="<?= e($m['id_medico']) ?>" <?= ($fMed == $m['id_medico']) ? 'selected' : '' ?>>
                            <?= e($m['apellidos'] . ', ' . $m['nombres']) ?> (<?= e($m['registro_profesional']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="f_dia">Filtrar por día</label>
                <select id="f_dia" name="f_dia">
                    <option value="">Todos</option>
                    <?php foreach ($diasSemana as $num => $nom): ?>
                        <option value="<?= e($num) ?>" <?= ($fDia == $num) ? 'selected' : '' ?>><?= e($nom) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;align-items:end">
                <button type="submit" class="mini">Filtrar</button>
                <?php if ($fMed || $fDia): ?><a class="btn btn-sec mini" href="horarios.php#horarios-listado">Limpiar</a><?php endif; ?>
            </div>
        </form>
        <?php if (($fMed || $fDia) && empty($lista)): ?>
            <div class="mensaje error">No se encontraron horarios con los filtros seleccionados.</div>
        <?php endif; ?>
        <div class="tabla">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Médico</th>
                        <th>Registro</th>
                        <th>Día</th>
                        <th>Hora inicio</th>
                        <th>Hora fin</th>
                        <th>Duración (min)</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($lista)): ?>
                    <tr><td colspan="9"><?= ($fMed || $fDia) ? 'Sin coincidencias.' : 'No hay horarios para mostrar.' ?></td></tr>
                <?php else: ?>
                    <?php foreach ($lista as $h): ?>
                        <tr>
                            <td><?= e($h['id_horario']) ?></td>
                            <td><?= e($h['apellidos'] . ', ' . $h['nombres']) ?></td>
                            <td><?= e($h['registro_profesional']) ?></td>
                            <td><?= isset($diasSemana[(int)$h['dia_semana']]) ? e($diasSemana[(int)$h['dia_semana']]) : e($h['dia_semana']) ?></td>
                            <td><?= e(substr($h['hora_inicio'], 0, 5)) ?></td>
                            <td><?= e(substr($h['hora_fin'], 0, 5)) ?></td>
                            <td><?= e($h['duracion_minutos']) ?></td>
                            <td>
                                <?php if ($h['activo']): ?>
                                    <span class="estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a class="btn mini" href="horarios.php?editar=<?= e($h['id_horario']) ?>#horarios-listado">Editar</a>
                                    <form method="post" action="horarios.php#horarios-listado" style="margin:0" onsubmit="return confirm('¿Confirmas cambiar el estado de este horario?');">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_horarios']) ?>">
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="id_horario" value="<?= e($h['id_horario']) ?>">
                                        <button type="submit" class="btn btn-sec mini"><?= $h['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p style="color:#6b7f85;margin-top:14px;font-size:13px">* La base de datos impide solapamientos de horarios activos por médico y día (restricción EXCLUDE). Las citas usan restricciones adicionales.</p>
    </section>
</main>

<script>
(function(){
    const form = document.getElementById('formHorario');
    if(!form) return;
    form.addEventListener('submit', function(e){
        const med = document.getElementById('id_medico');
        const dia = document.getElementById('dia_semana');
        const hi = document.getElementById('hora_inicio');
        const hf = document.getElementById('hora_fin');
        const dur = document.getElementById('duracion_minutos');
        let mensajes = [];
        if(med && med.value === '') mensajes.push('Selecciona un médico activo.');
        if(dia && (dia.value === '' || dia.value < 1 || dia.value > 7)) mensajes.push('Selecciona un día válido.');
        if(hi && hi.value === '') mensajes.push('Indica hora de inicio.');
        if(hf && hf.value === '') mensajes.push('Indica hora de fin.');
        if(hi && hf && hi.value && hf.value && hf.value <= hi.value) mensajes.push('La hora final debe ser mayor a la hora inicial.');
        if(dur && (dur.value === '' || parseInt(dur.value) <= 0)) mensajes.push('La duración debe ser mayor a 0.');
        if(mensajes.length > 0){
            e.preventDefault();
            alert(mensajes.join('\n'));
        }
    });
})();
</script>
</body>
</html>
