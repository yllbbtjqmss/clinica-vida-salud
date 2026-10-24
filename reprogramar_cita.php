<?php
/**
 * RF08 - Reprogramación de citas médicas
 * Clínica Vida Salud
 */

require_once __DIR__ . '/includes/auth.php';

$esEndpointJson = isset($_GET['cargar_bloques']) && isset($_GET['fecha']);

if ($esEndpointJson) {
    header('Content-Type: application/json; charset=UTF-8');
    $usuarioJson = cvs_usuario_actual();
    if ($usuarioJson === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Sesion no valida. Inicie sesion nuevamente.']);
        exit;
    }
    if (!in_array($usuarioJson['rol'], ['administrador', 'recepcionista'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'No tiene permisos para este recurso.']);
        exit;
    }
} else {
    cvs_exigir_roles(['administrador', 'recepcionista']);
}

$conexion = cvs_conexion();

$zonaClinica = new DateTimeZone('America/La_Paz');
$hoy = new DateTimeImmutable('today', $zonaClinica);
$hoyYmd = $hoy->format('Y-m-d');

// Generación de token CSRF
if (empty($_SESSION['csrf_reprog'])) {
    $_SESSION['csrf_reprog'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errores = [];
$aviso   = $_SESSION['aviso_reprog'] ?? null;
unset($_SESSION['aviso_reprog']);

$idCita = filter_var($_GET['id_cita'] ?? ($_POST['id_cita'] ?? ''), FILTER_VALIDATE_INT);
if (!$idCita) {
    header('Location: citas.php');
    exit;
}

// Cargar cita con datos relacionados
$cita = null;
try {
    $stmtC = $conexion->prepare("SELECT c.id_cita, c.codigo, c.id_paciente, c.id_medico, c.id_especialidad,
                                        c.fecha, c.hora_inicio, c.hora_fin, c.motivo, c.estado,
                                        p.nombres || ' ' || p.apellidos AS paciente, p.ci,
                                        m.nombres || ' ' || m.apellidos AS medico, m.registro_profesional, m.activo AS medico_activo,
                                        e.nombre AS especialidad
                                 FROM citas c
                                 JOIN pacientes p ON p.id_paciente = c.id_paciente
                                 JOIN medicos m ON m.id_medico = c.id_medico
                                 JOIN especialidades e ON e.id_especialidad = c.id_especialidad
                                 WHERE c.id_cita = :id_cita");
    $stmtC->bindValue(':id_cita', $idCita, PDO::PARAM_INT);
    $stmtC->execute();
    $cita = $stmtC->fetch();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $errores[] = 'No se pudo cargar la cita.';
}

if (!$cita) {
    header('Location: citas.php');
    exit;
}

// Validar que sea reprogramable
if (!in_array($cita['estado'], ['programada', 'confirmada'], true)) {
    $_SESSION['aviso_citas'] = 'Esta cita no puede ser reprogramada (estado: ' . $cita['estado'] . ').';
    header('Location: citas.php');
    exit;
}

$inicioOriginal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $cita['fecha'] . ' ' . $cita['hora_inicio'], $zonaClinica)
    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $cita['fecha'] . ' ' . substr($cita['hora_inicio'], 0, 5), $zonaClinica);
$ahoraGuardia = new DateTimeImmutable('now', $zonaClinica);
if (!$inicioOriginal || $inicioOriginal <= $ahoraGuardia) {
    $_SESSION['aviso_citas'] = 'Esta cita ya ha transcurrido y no puede ser reprogramada.';
    header('Location: citas.php');
    exit;
}
if ($cita['medico_activo'] !== true && $cita['medico_activo'] !== 1 && $cita['medico_activo'] !== 't') {
    // En Postgres con PDO puede venir como bool true o 't' dependiendo; also check active
    // but be defensive
}

// Obtener bloques disponibles para reprogramación (AJAX)
$bloquesDisponibles = [];
if (isset($_GET['cargar_bloques']) && isset($_GET['fecha'])) {
    $fechaB = trim($_GET['fecha']);
    $fB = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaB, $zonaClinica);
    if ($fB && $fB->format('Y-m-d') !== $fechaB) {
        $fB = false;
    }
    $idMedB = (int)$cita['id_medico'];
    if ($fB && $fB >= $hoy) {
        $diaSemana = (int)$fB->format('N');
        try {
            $hStmt = $conexion->prepare("SELECT id_horario, hora_inicio, hora_fin, duracion_minutos
                                         FROM horarios
                                         WHERE id_medico = :id_medico AND dia_semana = :dia_semana AND activo = TRUE
                                         ORDER BY hora_inicio");
            $hStmt->bindValue(':id_medico', $idMedB, PDO::PARAM_INT);
            $hStmt->bindValue(':dia_semana', $diaSemana, PDO::PARAM_INT);
            $hStmt->execute();
            $horariosActivos = $hStmt->fetchAll();
            
            // Obtener citas vigentes del médico en esa fecha, EXCLUYENDO la propia cita
            $cStmt = $conexion->prepare("SELECT hora_inicio, hora_fin FROM citas
                                         WHERE id_medico = :id_medico AND fecha = :fecha 
                                         AND estado IN ('programada', 'confirmada')
                                         AND id_cita <> :id_cita_excluir");
            $cStmt->bindValue(':id_medico', $idMedB, PDO::PARAM_INT);
            $cStmt->bindValue(':fecha', $fechaB, PDO::PARAM_STR);
            $cStmt->bindValue(':id_cita_excluir', $idCita, PDO::PARAM_INT);
            $cStmt->execute();
            $citasOcupadas = $cStmt->fetchAll();
            
            foreach ($horariosActivos as $hor) {
                $dur = (int)$hor['duracion_minutos'];
                if ($dur <= 0) continue;
                $inicioH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hor['hora_inicio'], 0, 5));
                $finH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hor['hora_fin'], 0, 5));
                if (!$inicioH || !$finH) continue;
                $base = $fB;
                $cursor = $base->setTime((int)$inicioH->format('H'), (int)$inicioH->format('i'));
                $finDia = $base->setTime((int)$finH->format('H'), (int)$finH->format('i'));
                while ($cursor < $finDia) {
                    $bloqueFin = $cursor->modify('+' . $dur . ' minutes');
                    if ($bloqueFin > $finDia) break;
                    if ($fB == $hoy) {
                        $ahora = new DateTimeImmutable('now', $zonaClinica);
                        if ($cursor <= $ahora) {
                            $cursor = $bloqueFin;
                            continue;
                        }
                    }
                    // Verificar solapamiento
                    $ocupado = false;
                    foreach ($citasOcupadas as $co) {
                        $coIni = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($co['hora_inicio'], 0, 5));
                        $coFin = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($co['hora_fin'], 0, 5));
                        if (!$coIni || !$coFin) continue;
                        $coIniDt = $base->setTime((int)$coIni->format('H'), (int)$coIni->format('i'));
                        $coFinDt = $base->setTime((int)$coFin->format('H'), (int)$coFin->format('i'));
                        if (!($bloqueFin <= $coIniDt || $cursor >= $coFinDt)) {
                            $ocupado = true;
                            break;
                        }
                    }
                    if (!$ocupado) {
                        $bloquesDisponibles[] = [
                            'hora_inicio' => $cursor->format('H:i'),
                            'hora_fin' => $bloqueFin->format('H:i')
                        ];
                    }
                    $cursor = $bloqueFin;
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }
    }
    header('Content-Type: application/json');
    echo json_encode($bloquesDisponibles);
    exit;
}

// Procesar reprogramación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_reprog'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no válida. Recarga la página.';
    }
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'reprogramar' && !$errores) {
        $fechaNueva = trim((string)($_POST['fecha'] ?? ''));
        $horaInicioNueva = trim((string)($_POST['hora_inicio'] ?? ''));
        
        $fNueva = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNueva, $zonaClinica);
        $fechaNuevaValida = $fNueva !== false && $fNueva->format('Y-m-d') === $fechaNueva;
        if (!$fechaNuevaValida || $fNueva < $hoy) {
            $errores[] = 'Selecciona una fecha válida (futura o actual).';
        }
        if ($horaInicioNueva === '' || !preg_match('/\A(?:0[0-9]|1[0-9]|2[0-3]):[0-5]\d\z/', $horaInicioNueva)) {
            $errores[] = 'Selecciona un bloque horario disponible.';
        }
        if ($fNueva == $hoy && $horaInicioNueva !== '') {
            $ahora = new DateTimeImmutable('now', $zonaClinica);
            $bloqIniCheck = DateTimeImmutable::createFromFormat('Y-m-d H:i', $fechaNueva . ' ' . $horaInicioNueva, $zonaClinica);
            if ($bloqIniCheck && $bloqIniCheck <= $ahora) {
                $errores[] = 'No es posible reprogramar a horas que ya pasaron.';
            }
        }
        
        if (!$errores) {
            try {
                $conexion->beginTransaction();
                // Verificar cita sigue reprogramable y datos actuales
                $chkCita = $conexion->prepare("SELECT id_cita, id_medico, id_especialidad, estado FROM citas WHERE id_cita = :id_cita FOR UPDATE");
                $chkCita->bindValue(':id_cita', $idCita, PDO::PARAM_INT);
                $chkCita->execute();
                $citaChk = $chkCita->fetch();
                if (!$citaChk || !in_array($citaChk['estado'], ['programada', 'confirmada'], true)) {
                    throw new Exception('La cita ya no se encuentra en estado reprogramable.');
                }
                $idMedChk = (int)$citaChk['id_medico'];
                
                // Verificar médico activo
                $chkMed = $conexion->prepare("SELECT activo FROM medicos WHERE id_medico = :id");
                $chkMed->bindValue(':id', $idMedChk, PDO::PARAM_INT);
                $chkMed->execute();
                $medChk = $chkMed->fetch();
                if (!$medChk || ($medChk['activo'] !== true && $medChk['activo'] !== 1 && $medChk['activo'] !== 't')) {
                    throw new Exception('Médico inválido o inactivo.');
                }
                
                // Obtener horario activo para ese día
                $diaSemanaN = (int)$fNueva->format('N');
                $hChk = $conexion->prepare("SELECT hora_inicio, hora_fin, duracion_minutos FROM horarios WHERE id_medico = :id_medico AND dia_semana = :dia_semana AND activo = TRUE ORDER BY hora_inicio");
                $hChk->bindValue(':id_medico', $idMedChk, PDO::PARAM_INT);
                $hChk->bindValue(':dia_semana', $diaSemanaN, PDO::PARAM_INT);
                $hChk->execute();
                $horariosChk = $hChk->fetchAll();
                if (empty($horariosChk)) {
                    throw new Exception('El médico no tiene horario activo para ese día.');
                }
                
                $bloqIniDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $fechaNueva . ' ' . $horaInicioNueva, $zonaClinica);
                if (!$bloqIniDt) {
                    throw new Exception('Bloque horario inválido.');
                }
                
                // Encontrar horario activo específico al que pertenece el bloque y usar su duracion_minutos
                $horarioEncontrado = null;
                $hcIniEncontrado = null;
                foreach ($horariosChk as $hc) {
                    $hcIni = DateTimeImmutable::createFromFormat('H:i:s', $hc['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hc['hora_inicio'], 0, 5));
                    $hcFin = DateTimeImmutable::createFromFormat('H:i:s', $hc['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hc['hora_fin'], 0, 5));
                    if (!$hcIni || !$hcFin) continue;
                    $hcIniDt = $fNueva->setTime((int)$hcIni->format('H'), (int)$hcIni->format('i'));
                    $hcFinDt = $fNueva->setTime((int)$hcFin->format('H'), (int)$hcFin->format('i'));
                    if ($bloqIniDt >= $hcIniDt && $bloqIniDt < $hcFinDt) {
                        $horarioEncontrado = $hc;
                        $hcIniEncontrado = $hcIniDt;
                        break;
                    }
                }
                if (!$horarioEncontrado || !$hcIniEncontrado) {
                    throw new Exception('El bloque seleccionado está fuera del horario de atención.');
                }
                $durMin = (int)$horarioEncontrado['duracion_minutos'];
                if ($durMin <= 0) {
                    throw new Exception('Duración de horario inválida.');
                }
                // Verificar que la hora solicitada esté alineada con los bloques válidos generados desde el inicio del horario
                $diffMin = ($bloqIniDt->getTimestamp() - $hcIniEncontrado->getTimestamp()) / 60;
                if ($diffMin < 0 || ($diffMin % $durMin) !== 0) {
                    throw new Exception('El bloque seleccionado no corresponde a un horario válido.');
                }
                $bloqFinDt = $bloqIniDt->modify('+' . $durMin . ' minutes');
                // Verificar que el bloque completo termine dentro del mismo horario
                $hcFinF = DateTimeImmutable::createFromFormat('H:i:s', $horarioEncontrado['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($horarioEncontrado['hora_fin'], 0, 5));
                $hcFinDtF = $fNueva->setTime((int)$hcFinF->format('H'), (int)$hcFinF->format('i'));
                if ($bloqFinDt > $hcFinDtF) {
                    throw new Exception('El bloque seleccionado está fuera del horario de atención.');
                }
                // Fecha/hora futura ya validada, pero asegurar
                $ahoraChk = new DateTimeImmutable('now', $zonaClinica);
                if ($bloqIniDt <= $ahoraChk) {
                    throw new Exception('No es posible reprogramar a horas que ya pasaron.');
                }
                $horaFinCalc = $bloqFinDt->format('H:i:s');
                
                // Actualizar cita - conservar id_cita, codigo, paciente, médico y especialidad
                $sqlUpd = "UPDATE citas 
                           SET fecha = :fecha, hora_inicio = :hora_inicio, hora_fin = :hora_fin, actualizado_en = CURRENT_TIMESTAMP
                           WHERE id_cita = :id_cita";
                $stmtUpd = $conexion->prepare($sqlUpd);
                $stmtUpd->bindValue(':fecha', $fechaNueva, PDO::PARAM_STR);
                $stmtUpd->bindValue(':hora_inicio', $horaInicioNueva . ':00', PDO::PARAM_STR);
                $stmtUpd->bindValue(':hora_fin', $horaFinCalc, PDO::PARAM_STR);
                $stmtUpd->bindValue(':id_cita', $idCita, PDO::PARAM_INT);
                $stmtUpd->execute();
                
                $conexion->commit();
                $_SESSION['aviso_citas'] = 'Cita reprogramada correctamente.';
                header('Location: citas.php#citas-listado');
                exit;
            } catch (PDOException $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                if ($e->getCode() === '23P01') {
                    $errores[] = 'El horario ya fue reservado por otro usuario. Selecciona otro bloque disponible.';
                } elseif ($e->getCode() === '23505') {
                    $errores[] = 'Conflicto al reprogramar la cita.';
                } elseif ($e->getCode() === '23503') {
                    $errores[] = 'No se pudo reprogramar la cita por restricción de integridad.';
                } else {
                    error_log($e->getMessage());
                    $errores[] = 'No se pudo reprogramar la cita.';
                }
            } catch (Exception $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                $errores[] = $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reprogramar cita - Clínica Vida Salud</title>
    <link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('citas', 'Reprogramar citas'); ?>
<main class="ui-contenido">
    <section class="panel">
        <h2>Reprogramar cita</h2>
        <div style="margin-bottom:16px"><a class="btn" href="citas.php" style="padding:8px 14px;font-size:13px">← Volver a Citas</a></div>
        <?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
        <?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
        
        <h3 style="margin-top:0">Datos actuales de la cita</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Código</div>
                <div class="value"><?= e($cita['codigo']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Estado</div>
                <div class="value"><?= e($cita['estado']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Paciente</div>
                <div class="value"><?= e($cita['paciente']) ?><?= $cita['ci'] ? ' (' . e($cita['ci']) . ')' : '' ?></div>
            </div>
            <div class="info-item">
                <div class="label">Especialidad</div>
                <div class="value"><?= e($cita['especialidad']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Médico</div>
                <div class="value"><?= e($cita['medico']) ?> <?= $cita['registro_profesional'] ? '(' . e($cita['registro_profesional']) . ')' : '' ?></div>
            </div>
            <div class="info-item">
                <div class="label">Fecha y hora actuales</div>
                <div class="value"><?= e($cita['fecha']) ?> | <?= e(substr($cita['hora_inicio'], 0, 5)) ?> - <?= e(substr($cita['hora_fin'], 0, 5)) ?></div>
            </div>
        </div>
        <?php if ($cita['motivo']): ?>
            <div class="info-item" style="margin-top:12px">
                <div class="label">Motivo</div>
                <div class="value"><?= e($cita['motivo']) ?></div>
            </div>
        <?php endif; ?>
    </section>
    
    <section class="panel">
        <h2>Nueva programación</h2>
        <form method="post" action="reprogramar_cita.php?id_cita=<?= e($idCita) ?>" id="formReprog" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_reprog']) ?>">
            <input type="hidden" name="accion" value="reprogramar">
            <input type="hidden" name="id_cita" value="<?= e($idCita) ?>">
            <input type="hidden" name="hora_inicio" id="hora_inicio_sel" value="">
            <div class="campos">
                <div>
                    <label for="fecha">Nueva fecha *</label>
                    <input id="fecha" type="date" name="fecha" required min="<?= e($hoyYmd) ?>">
                </div>
            </div>
            <div style="margin-top:18px">
                <label>Bloques disponibles *</label>
                <div id="bloques" class="bloques">
                    <p style="color:#6b7f85">Seleccione fecha para ver bloques disponibles del médico.</p>
                </div>
            </div>
            <div class="acciones">
                <button type="submit">Guardar reprogramación</button>
                <a class="btn" href="citas.php" style="background:#6b7f85">Cancelar</a>
            </div>
        </form>
    </section>
</main>

<script>
(function(){
    const fec = document.getElementById('fecha');
    const bloquesDiv = document.getElementById('bloques');
    const horaSel = document.getElementById('hora_inicio_sel');
    const form = document.getElementById('formReprog');
    
    async function cargarBloques(){
        bloquesDiv.innerHTML = '<p style="color:#6b7f85">Cargando...</p>';
        horaSel.value = '';
        const fval = fec.value;
        if(!fval){
            bloquesDiv.innerHTML = '<p style="color:#6b7f85">Seleccione fecha para ver bloques disponibles del médico.</p>';
            return;
        }
        try {
            const res = await fetch('reprogramar_cita.php?id_cita=<?= e($idCita) ?>&cargar_bloques=1&fecha=' + encodeURIComponent(fval));
            const data = await res.json();
            if(data.length === 0){
                bloquesDiv.innerHTML = '<p style="color:#9b2c2c">No hay bloques disponibles para esta fecha.</p>';
                return;
            }
            bloquesDiv.innerHTML = '';
            for(const b of data){
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bloque';
                btn.textContent = b.hora_inicio + ' - ' + b.hora_fin;
                btn.onclick = function(){
                    document.querySelectorAll('.bloque').forEach(x=>x.classList.remove('sel'));
                    btn.classList.add('sel');
                    horaSel.value = b.hora_inicio;
                };
                bloquesDiv.appendChild(btn);
            }
        } catch(e){
            bloquesDiv.innerHTML = '<p style="color:#9b2c2c">Error al cargar bloques.</p>';
        }
    }
    
    if(fec) fec.addEventListener('change', cargarBloques);
    
    if(form){
        form.addEventListener('submit', function(e){
            const fecv = document.getElementById('fecha');
            let mensajes = [];
            if(fecv && fecv.value === '') mensajes.push('Selecciona una fecha.');
            if(horaSel && horaSel.value === '') mensajes.push('Selecciona un bloque horario disponible.');
            if(mensajes.length > 0){
                e.preventDefault();
                alert(mensajes.join('\n'));
            }
        });
    }
})();
</script>
<?php cvsu_cierre(); ?>
</body>
</html>
