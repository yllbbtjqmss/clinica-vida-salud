<?php
/**
 * RF06 - Registro de citas médicas
 * Clínica Vida Salud
 */

require_once __DIR__ . '/includes/auth.php';

$esEndpointJson = (isset($_GET['cargar_medicos']) && isset($_GET['id_especialidad']))
    || (isset($_GET['cargar_bloques']) && isset($_GET['id_medico']) && isset($_GET['fecha']));

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
if (empty($_SESSION['csrf_citas'])) {
    $_SESSION['csrf_citas'] = bin2hex(random_bytes(32));
}

// Helper para escapar salida
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errores = [];
$aviso   = $_SESSION['aviso_citas'] ?? null;
unset($_SESSION['aviso_citas']);

// Cargar pacientes activos
try {
    $pacStmt = $conexion->query("SELECT id_paciente, ci, nombres, apellidos FROM pacientes WHERE activo = TRUE ORDER BY apellidos, nombres");
    $pacientesLista = $pacStmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $pacientesLista = [];
    $errores[] = 'No se pudieron cargar los pacientes activos.';
}

// Cargar especialidades activas
try {
    $espStmt = $conexion->query("SELECT id_especialidad, nombre FROM especialidades WHERE activo = TRUE ORDER BY nombre");
    $especialidadesLista = $espStmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $especialidadesLista = [];
    $errores[] = 'No se pudieron cargar las especialidades activas.';
}

// Datos formulario
$datos = [
    'id_paciente'     => '',
    'id_especialidad' => '',
    'id_medico'       => '',
    'fecha'           => $hoyYmd,
    'hora_inicio'     => '',
    'motivo'          => ''
];

// Filtrar médicos por especialidad (AJAX-like via POST/GET - se hace por formulario normal)
$medicosPorEsp = [];
if (isset($_GET['cargar_medicos']) && isset($_GET['id_especialidad'])) {
    $idEsp = filter_var($_GET['id_especialidad'], FILTER_VALIDATE_INT);
    if ($idEsp) {
        try {
            $mStmt = $conexion->prepare("SELECT m.id_medico, m.registro_profesional, m.nombres, m.apellidos
                                         FROM medicos m
                                         JOIN medico_especialidad me ON me.id_medico = m.id_medico
                                         WHERE m.activo = TRUE AND me.id_especialidad = :id_especialidad
                                         ORDER BY m.apellidos, m.nombres");
            $mStmt->bindValue(':id_especialidad', $idEsp, PDO::PARAM_INT);
            $mStmt->execute();
            $medicosPorEsp = $mStmt->fetchAll();
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }
    }
    header('Content-Type: application/json');
    echo json_encode($medicosPorEsp);
    exit;
}

// Obtener bloques disponibles
$bloquesDisponibles = [];
if (isset($_GET['cargar_bloques']) && isset($_GET['id_medico']) && isset($_GET['fecha'])) {
    $idMedB = filter_var($_GET['id_medico'], FILTER_VALIDATE_INT);
    $fechaB = trim($_GET['fecha']);
    $fB = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaB, $zonaClinica);
    if ($fB && $fB->format('Y-m-d') !== $fechaB) {
        $fB = false;
    }
    if ($idMedB && $fB && $fB >= $hoy) {
        $diaSemana = (int)$fB->format('N'); // 1 lunes .. 7 domingo
        try {
            // Buscar horario activo del médico para ese día
            $hStmt = $conexion->prepare("SELECT id_horario, hora_inicio, hora_fin, duracion_minutos
                                         FROM horarios
                                         WHERE id_medico = :id_medico AND dia_semana = :dia_semana AND activo = TRUE
                                         ORDER BY hora_inicio");
            $hStmt->bindValue(':id_medico', $idMedB, PDO::PARAM_INT);
            $hStmt->bindValue(':dia_semana', $diaSemana, PDO::PARAM_INT);
            $hStmt->execute();
            $horariosActivos = $hStmt->fetchAll();
            
            // Obtener citas vigentes del médico en esa fecha
            $cStmt = $conexion->prepare("SELECT hora_inicio, hora_fin FROM citas
                                         WHERE id_medico = :id_medico AND fecha = :fecha AND estado IN ('programada', 'confirmada')");
            $cStmt->bindValue(':id_medico', $idMedB, PDO::PARAM_INT);
            $cStmt->bindValue(':fecha', $fechaB, PDO::PARAM_STR);
            $cStmt->execute();
            $citasOcupadas = $cStmt->fetchAll();
            
            // Generar bloques
            foreach ($horariosActivos as $hor) {
                $dur = (int)$hor['duracion_minutos'];
                if ($dur <= 0) continue;
                $inicioH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hor['hora_inicio'],0,5));
                $finH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hor['hora_fin'],0,5));
                if (!$inicioH || !$finH) continue;
                // Base para fecha
                $base = $fB;
                $cursor = $base->setTime((int)$inicioH->format('H'), (int)$inicioH->format('i'));
                $finDia = $base->setTime((int)$finH->format('H'), (int)$finH->format('i'));
                while ($cursor < $finDia) {
                    $bloqueFin = $cursor->modify('+' . $dur . ' minutes');
                    if ($bloqueFin > $finDia) break;
                    // No permitir horas que ya pasaron si es fecha actual
                    if ($fB == $hoy) {
                        $ahora = new DateTimeImmutable('now', $zonaClinica);
                        $bloqueInicioDt = $cursor;
                        if ($bloqueInicioDt <= $ahora) {
                            $cursor = $bloqueFin;
                            continue;
                        }
                    }
                    // Verificar solapamiento con citas ocupadas
                    $ocupado = false;
                    foreach ($citasOcupadas as $co) {
                        $coIni = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($co['hora_inicio'],0,5));
                        $coFin = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($co['hora_fin'],0,5));
                        if (!$coIni || !$coFin) continue;
                        $coIniDt = $base->setTime((int)$coIni->format('H'), (int)$coIni->format('i'));
                        $coFinDt = $base->setTime((int)$coFin->format('H'), (int)$coFin->format('i'));
                        // solapamiento si no (bloqueFin <= coIniDt o cursor >= coFinDt)
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

// Procesar registro de cita
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_citas'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no válida. Recarga la página.';
    }

    $accion = $_POST['accion'] ?? '';
    if ($accion === 'registrar' && !$errores) {
        $datos['id_paciente']     = trim((string)($_POST['id_paciente'] ?? ''));
        $datos['id_especialidad'] = trim((string)($_POST['id_especialidad'] ?? ''));
        $datos['id_medico']       = trim((string)($_POST['id_medico'] ?? ''));
        $datos['fecha']           = trim((string)($_POST['fecha'] ?? ''));
        $datos['hora_inicio']     = trim((string)($_POST['hora_inicio'] ?? ''));
        $datos['motivo']          = trim((string)($_POST['motivo'] ?? ''));

        $idPac = filter_var($datos['id_paciente'], FILTER_VALIDATE_INT);
        $idEsp = filter_var($datos['id_especialidad'], FILTER_VALIDATE_INT);
        $idMed = filter_var($datos['id_medico'], FILTER_VALIDATE_INT);
        $fCita = DateTimeImmutable::createFromFormat('!Y-m-d', $datos['fecha'], $zonaClinica);
        $fechaCitaValida = $fCita !== false && $fCita->format('Y-m-d') === $datos['fecha'];

        if (!$idPac) $errores[] = 'Selecciona un paciente válido.';
        if (!$idEsp) $errores[] = 'Selecciona una especialidad.';
        if (!$idMed) $errores[] = 'Selecciona un médico.';
        if (!$fechaCitaValida || $fCita < $hoy) $errores[] = 'Selecciona una fecha válida (futura o actual).';
        if ($datos['hora_inicio'] === '' || !preg_match('/\A(?:0[0-9]|1[0-9]|2[0-3]):[0-5]\d\z/', $datos['hora_inicio'])) $errores[] = 'Selecciona un bloque horario disponible.';

        if ($datos['motivo'] !== '' && strlen($datos['motivo']) > 255) {
            $errores[] = 'El motivo supera la longitud permitida.';
        }

        if (!$errores && $fCita == $hoy && $datos['hora_inicio'] !== '') {
            $ahora = new DateTimeImmutable('now', $zonaClinica);
            $bloqIni = DateTimeImmutable::createFromFormat('Y-m-d H:i', $datos['fecha'] . ' ' . $datos['hora_inicio'], $zonaClinica);
            if ($bloqIni && $bloqIni <= $ahora) {
                $errores[] = 'No es posible reservar horas que ya pasaron.';
            }
        }

        if (!$errores) {
            try {
                $conexion->beginTransaction();
                // Verificaciones servidor
                $chkPac = $conexion->prepare("SELECT 1 FROM pacientes WHERE id_paciente = :id AND activo = TRUE");
                $chkPac->bindValue(':id', $idPac, PDO::PARAM_INT);
                $chkPac->execute();
                if ($chkPac->rowCount() == 0) throw new Exception('Paciente inválido o inactivo');

                $chkMed = $conexion->prepare("SELECT 1 FROM medicos WHERE id_medico = :id AND activo = TRUE");
                $chkMed->bindValue(':id', $idMed, PDO::PARAM_INT);
                $chkMed->execute();
                if ($chkMed->rowCount() == 0) throw new Exception('Médico inválido o inactivo');

                $chkMe = $conexion->prepare("SELECT 1 FROM medico_especialidad WHERE id_medico = :id_medico AND id_especialidad = :id_especialidad");
                $chkMe->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                $chkMe->bindValue(':id_especialidad', $idEsp, PDO::PARAM_INT);
                $chkMe->execute();
                if ($chkMe->rowCount() == 0) throw new Exception('El médico no está asignado a esa especialidad');

                // Obtener horario activo para ese día
                $diaSemana = (int)$fCita->format('N');
                $hChk = $conexion->prepare("SELECT hora_inicio, hora_fin, duracion_minutos FROM horarios WHERE id_medico = :id_medico AND dia_semana = :dia_semana AND activo = TRUE ORDER BY hora_inicio");
                $hChk->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                $hChk->bindValue(':dia_semana', $diaSemana, PDO::PARAM_INT);
                $hChk->execute();
                $horariosChk = $hChk->fetchAll();
                if (empty($horariosChk)) throw new Exception('El médico no tiene horario activo para ese día');

                $bloqIniDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $datos['fecha'] . ' ' . $datos['hora_inicio'], $zonaClinica);
                if (!$bloqIniDt) throw new Exception('Bloque horario inválido');

                $horarioEncontrado = null;
                $hcIniEncontrado = null;
                foreach ($horariosChk as $hc) {
                    $hcIni = DateTimeImmutable::createFromFormat('H:i:s', $hc['hora_inicio']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hc['hora_inicio'],0,5));
                    $hcFin = DateTimeImmutable::createFromFormat('H:i:s', $hc['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($hc['hora_fin'],0,5));
                    if (!$hcIni || !$hcFin) continue;
                    $hcIniDt = $fCita->setTime((int)$hcIni->format('H'), (int)$hcIni->format('i'));
                    $hcFinDt = $fCita->setTime((int)$hcFin->format('H'), (int)$hcFin->format('i'));
                    if ($bloqIniDt >= $hcIniDt && $bloqIniDt < $hcFinDt) {
                        $horarioEncontrado = $hc;
                        $hcIniEncontrado = $hcIniDt;
                        break;
                    }
                }
                if (!$horarioEncontrado || !$hcIniEncontrado) throw new Exception('El bloque seleccionado está fuera del horario de atención');
                $durMin = (int)$horarioEncontrado['duracion_minutos'];
                if ($durMin <= 0) throw new Exception('Duración de horario inválida');
                $diffMin = ($bloqIniDt->getTimestamp() - $hcIniEncontrado->getTimestamp()) / 60;
                if ($diffMin < 0 || ($diffMin % $durMin) !== 0) throw new Exception('El bloque seleccionado no corresponde a un horario válido');
                $bloqFinDt = $bloqIniDt->modify('+' . $durMin . ' minutes');

                $hcFinF = DateTimeImmutable::createFromFormat('H:i:s', $horarioEncontrado['hora_fin']) ?: DateTimeImmutable::createFromFormat('H:i', substr($horarioEncontrado['hora_fin'],0,5));
                $hcFinDtF = $fCita->setTime((int)$hcFinF->format('H'), (int)$hcFinF->format('i'));
                if ($bloqFinDt > $hcFinDtF) throw new Exception('El bloque seleccionado está fuera del horario de atención');
                $horaFinCalc = $bloqFinDt->format('H:i:s');

                // Generar código único
                $codigo = 'CITA-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $hoy->format('Ymd');

                // Insertar (restricción EXCLUDE puede lanzar 23P01)
                $sqlInsC = "INSERT INTO citas (codigo, id_paciente, id_medico, id_especialidad, fecha, hora_inicio, hora_fin, motivo, estado, creado_en, actualizado_en)
                            VALUES (:codigo, :id_paciente, :id_medico, :id_especialidad, :fecha, :hora_inicio, :hora_fin, :motivo, 'programada', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                $stmtInsC = $conexion->prepare($sqlInsC);
                $stmtInsC->bindValue(':codigo', $codigo, PDO::PARAM_STR);
                $stmtInsC->bindValue(':id_paciente', $idPac, PDO::PARAM_INT);
                $stmtInsC->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                $stmtInsC->bindValue(':id_especialidad', $idEsp, PDO::PARAM_INT);
                $stmtInsC->bindValue(':fecha', $datos['fecha'], PDO::PARAM_STR);
                $stmtInsC->bindValue(':hora_inicio', $datos['hora_inicio'] . ':00', PDO::PARAM_STR);
                $stmtInsC->bindValue(':hora_fin', $horaFinCalc, PDO::PARAM_STR);
                if ($datos['motivo'] === '') {
                    $stmtInsC->bindValue(':motivo', null, PDO::PARAM_NULL);
                } else {
                    $stmtInsC->bindValue(':motivo', $datos['motivo'], PDO::PARAM_STR);
                }
                $stmtInsC->execute();
                $conexion->commit();
                $_SESSION['aviso_citas'] = 'Cita registrada correctamente. Código: ' . $codigo;
                header('Location: citas.php#citas-listado');
                exit;
            } catch (PDOException $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                if ($e->getCode() === '23P01') {
                    $errores[] = 'El horario ya fue reservado por otro usuario. Selecciona otro bloque disponible.';
                } elseif ($e->getCode() === '23505') {
                    $errores[] = 'Error al generar el código de cita. Intente nuevamente.';
                } elseif ($e->getCode() === '23503') {
                    $errores[] = 'No se pudo registrar la cita por restricción de integridad.';
                } else {
                    error_log($e->getMessage());
                    $errores[] = 'No se pudo registrar la cita.';
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

// Listado de citas
$listaCitas = [];
try {
    $sqlLC = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.motivo, c.estado,
                     p.nombres || ' ' || p.apellidos AS paciente, p.ci,
                     m.nombres || ' ' || m.apellidos AS medico, m.registro_profesional,
                     e.nombre AS especialidad
              FROM citas c
              JOIN pacientes p ON p.id_paciente = c.id_paciente
              JOIN medicos m ON m.id_medico = c.id_medico
              JOIN especialidades e ON e.id_especialidad = c.id_especialidad
              ORDER BY c.fecha DESC, c.hora_inicio DESC
              LIMIT 200";
    $stmtLC = $conexion->query($sqlLC);
    $listaCitas = $stmtLC->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $listaCitas = [];
    $errores[] = 'No se pudo cargar el listado de citas.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Citas | Clínica Vida Salud</title>
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
        input,select,textarea{width:100%;border:1px solid #cbd6d9;border-radius:7px;padding:11px;font-size:15px;background:white;font-family:Arial,sans-serif}
        textarea{resize:vertical;min-height:80px}
        .acciones{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px;align-items:center}
        button, .btn{background:#146d73;color:white;border:0;border-radius:8px;padding:12px 22px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block}
        .mensaje{border-radius:7px;padding:12px 15px;margin-bottom:15px;background:#e2f7e9}
        .error{background:#fce6e6}
        .bloques{display:flex;flex-wrap:wrap;gap:10px}
        .bloque{border:1px solid #7f9a9d;border-radius:7px;padding:10px 16px;cursor:pointer;background:#ffffff;color:#19333c;font-weight:600;font-family:Arial,sans-serif;font-size:15px;line-height:1.2;transition:background 0.2s ease,color 0.2s ease,border-color 0.2s ease,box-shadow 0.2s ease}
        .bloque:hover{background:#edf5f5;border-color:#146d73}
        .bloque:focus-visible{outline:2px solid #146d73;outline-offset:2px}
        .bloque.sel{background:#146d73;border-color:#146d73;color:#ffffff;box-shadow:0 2px 8px rgba(20,109,115,0.25)}
        .tabla{overflow-x:auto}
        table{border-collapse:collapse;width:100%;min-width:900px}
        td,th{text-align:left;border-bottom:1px solid #e5ebee;padding:12px;font-size:14px;vertical-align:top}
        th{background:#edf5f5}
        #citas-listado{scroll-margin-top: 20px;}
        @media(max-width:700px){.campos{grid-template-columns:1fr}.panel{padding:17px}}
    </style>
</head>
<body>
<header>
    <h1>Clínica Vida Salud</h1>
    <p>Registro de citas médicas · Versión local de desarrollo</p>
    <nav>
        <a href="index.php">Inicio</a>
        <a href="pacientes.php">Pacientes</a>
        <a href="medicos.php">Médicos</a>
        <a href="horarios.php">Horarios</a>
        <a href="citas.php">Citas</a>
    </nav>
</header>
<main>
    <section class="panel">
        <h2>Registrar nueva cita</h2>
        <?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
        <?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post" action="citas.php" id="formCita" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_citas']) ?>">
            <input type="hidden" name="accion" value="registrar">
            <input type="hidden" name="hora_inicio" id="hora_inicio_sel" value="<?= e($datos['hora_inicio']) ?>">
            <div class="campos">
                <div>
                    <label for="id_paciente">Paciente activo *</label>
                    <select id="id_paciente" name="id_paciente" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($pacientesLista as $p): ?>
                            <option value="<?= e($p['id_paciente']) ?>" <?= ((string)$datos['id_paciente'] === (string)$p['id_paciente']) ? 'selected' : '' ?>>
                                <?= e($p['apellidos'] . ', ' . $p['nombres']) ?> <?= $p['ci'] ? '(' . e($p['ci']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="id_especialidad">Especialidad *</label>
                    <select id="id_especialidad" name="id_especialidad" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($especialidadesLista as $esp): ?>
                            <option value="<?= e($esp['id_especialidad']) ?>" <?= ((string)$datos['id_especialidad'] === (string)$esp['id_especialidad']) ? 'selected' : '' ?>>
                                <?= e($esp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="id_medico">Médico *</label>
                    <select id="id_medico" name="id_medico" required>
                        <option value="">Primero seleccione especialidad</option>
                    </select>
                </div>
                <div>
                    <label for="fecha">Fecha *</label>
                    <input id="fecha" type="date" name="fecha" required min="<?= e($hoyYmd) ?>" value="<?= e($datos['fecha']) ?>">
                </div>
            </div>
            <div style="margin-top:18px">
                <label>Bloques disponibles *</label>
                <div id="bloques" class="bloques">
                    <p style="color:#6b7f85">Seleccione médico y fecha para ver bloques disponibles.</p>
                </div>
            </div>
            <div style="margin-top:18px">
                <label for="motivo">Motivo (opcional)</label>
                <textarea id="motivo" name="motivo" maxlength="255"><?= e($datos['motivo']) ?></textarea>
            </div>
            <div class="acciones">
                <button type="submit">Registrar cita</button>
            </div>
        </form>
    </section>

    <section class="panel" id="citas-listado">
        <h2>Citas registradas</h2>
        <div class="tabla">
            <table>
                <thead>
                    <tr>
                        <th>CA3digo</th>
                        <th>Paciente</th>
                        <th>CI</th>
                        <th>MAcdico</th>
                        <th>Registro</th>
                        <th>Especialidad</th>
                        <th>Fecha</th>
                        <th>Hora inicio</th>
                        <th>Hora fin</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($listaCitas)): ?>
                    <tr><td colspan="12">No hay citas para mostrar.</td></tr>
                <?php else: ?>
                    <?php foreach ($listaCitas as $c): ?>
                        <tr>
                            <td><?= e($c['codigo']) ?></td>
                            <td><?= e($c['paciente']) ?></td>
                            <td><?= e($c['ci'] ?? '�?"') ?></td>
                            <td><?= e($c['medico']) ?></td>
                            <td><?= e($c['registro_profesional']) ?></td>
                            <td><?= e($c['especialidad']) ?></td>
                            <td><?= e($c['fecha']) ?></td>
                            <td><?= e(substr($c['hora_inicio'], 0, 5)) ?></td>
                            <td><?= e(substr($c['hora_fin'], 0, 5)) ?></td>
                            <td><?= e($c['estado']) ?></td>
                            <td><?= $c['motivo'] !== null ? e($c['motivo']) : '�?"' ?></td>
                            <td>
                                <?php if (in_array($c['estado'], ['programada', 'confirmada'], true)): ?>
                                    <a class="btn" style="padding:8px 14px;font-size:13px" href="reprogramar_cita.php?id_cita=<?= e($c['id_cita']) ?>">Reprogramar</a>
                                <?php else: ?>
                                    <span style="color:#6b7f85">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p style="color:#6b7f85;margin-top:14px;font-size:13px">* Se verifica disponibilidad en servidor y se aplica restricción EXCLUDE para prevenir solapamientos.</p>
    </section>
</main>

<script>
(function(){
    const esp = document.getElementById('id_especialidad');
    const med = document.getElementById('id_medico');
    const fec = document.getElementById('fecha');
    const bloquesDiv = document.getElementById('bloques');
    const horaSel = document.getElementById('hora_inicio_sel');
    const form = document.getElementById('formCita');
    
    async function cargarMedicos(){
        med.innerHTML = '<option value="">Cargando...</option>';
        const val = esp.value;
        if(!val){
            med.innerHTML = '<option value="">Primero seleccione especialidad</option>';
            cargarBloques();
            return;
        }
        try {
            const res = await fetch('citas.php?cargar_medicos=1&id_especialidad=' + encodeURIComponent(val));
            const data = await res.json();
            med.innerHTML = '<option value="">Seleccione...</option>';
            for(const m of data){
                const opt = document.createElement('option');
                opt.value = m.id_medico;
                opt.textContent = m.apellidos + ', ' + m.nombres + ' (' + m.registro_profesional + ')';
                med.appendChild(opt);
            }
        } catch(e){
            med.innerHTML = '<option value="">Error al cargar médicos</option>';
        }
        cargarBloques();
    }
    
    async function cargarBloques(){
        bloquesDiv.innerHTML = '<p style="color:#6b7f85">Cargando...</p>';
        horaSel.value = '';
        const mval = med.value;
        const fval = fec.value;
        if(!mval || !fval){
            bloquesDiv.innerHTML = '<p style="color:#6b7f85">Seleccione médico y fecha para ver bloques disponibles.</p>';
            return;
        }
        try {
            const res = await fetch('citas.php?cargar_bloques=1&id_medico=' + encodeURIComponent(mval) + '&fecha=' + encodeURIComponent(fval));
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
    
    if(esp) esp.addEventListener('change', cargarMedicos);
    if(med) med.addEventListener('change', cargarBloques);
    if(fec) fec.addEventListener('change', cargarBloques);
    
    if(form){
        form.addEventListener('submit', function(e){
            const pac = document.getElementById('id_paciente');
            const espv = document.getElementById('id_especialidad');
            const medv = document.getElementById('id_medico');
            const fecv = document.getElementById('fecha');
            let mensajes = [];
            if(pac && pac.value === '') mensajes.push('Selecciona un paciente.');
            if(espv && espv.value === '') mensajes.push('Selecciona una especialidad.');
            if(medv && medv.value === '') mensajes.push('Selecciona un médico.');
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
</body>
</html>
