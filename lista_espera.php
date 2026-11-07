<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard.php';
$usuario = cvs_exigir_roles(['administrador', 'recepcionista']);

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método no permitido.');
}

$conexion = cvs_conexion();
$estados = ['activa'=>'Activa', 'retirada'=>'Retirada', 'asignada'=>'Asignada'];
$zonaClinica = new DateTimeZone('America/La_Paz');
$hoy = new DateTimeImmutable('today', $zonaClinica);

function cvs_le_id(mixed $valor): int
{
    if (!is_string($valor) || preg_match('/\A[1-9][0-9]{0,9}\z/', $valor) !== 1) return 0;
    return filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0;
}

function cvs_le_fecha(string $valor, DateTimeZone $zona): ?DateTimeImmutable
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, $zona);
    return $fecha && $fecha->format('Y-m-d') === $valor ? $fecha : null;
}

function cvs_le_hora(string $valor): bool
{
    return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $valor) === 1;
}

function cvs_le_registrar_error(Throwable $ex, string $operacion): void
{
    $codigo = (string)$ex->getCode();
    error_log('Lista de espera: ' . $operacion
        . ($ex instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/', $codigo) === 1
            ? '; SQLSTATE ' . $codigo : ''));
}

function cvs_le_redirigir(string $tipo, string $mensaje): never
{
    $_SESSION['lista_espera_flash'] = ['tipo'=>$tipo, 'mensaje'=>$mensaje];
    header('Location: lista_espera.php', true, 303);
    exit;
}

function cvs_le_responder_json(array $datos, int $estado = 200): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['cargar_bloques'] ?? null) === '1') {
    $idMedicoBloques = cvs_le_id($_GET['id_medico'] ?? null);
    $idEspecialidadBloques = cvs_le_id($_GET['id_especialidad'] ?? null);
    $fechaTextoBloques = cvs_texto($_GET, 'fecha');
    $fechaBloques = cvs_le_fecha($fechaTextoBloques, $zonaClinica);
    if ($idMedicoBloques === 0 || $idEspecialidadBloques === 0
        || !$fechaBloques || $fechaBloques < $hoy) {
        cvs_le_responder_json(['error'=>'Selecciona una especialidad, un médico y una fecha válida.'], 422);
    }

    try {
        $compatible = $conexion->prepare('SELECT 1
            FROM medicos m
            JOIN medico_especialidad me ON me.id_medico = m.id_medico
            JOIN especialidades e ON e.id_especialidad = me.id_especialidad
            WHERE m.id_medico = :medico AND e.id_especialidad = :especialidad
                AND m.activo = TRUE AND e.activo = TRUE');
        $compatible->execute([':medico'=>$idMedicoBloques, ':especialidad'=>$idEspecialidadBloques]);
        if (!$compatible->fetchColumn()) {
            cvs_le_responder_json(['error'=>'El médico no atiende la especialidad seleccionada.'], 422);
        }

        $bloques = cvsd_bloques_medico($conexion, $idMedicoBloques, $fechaBloques);
        $ahora = new DateTimeImmutable('now', $zonaClinica);
        foreach ($bloques as &$bloque) {
            $inicioBloque = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                $fechaTextoBloques . ' ' . $bloque['inicio'],
                $zonaClinica
            );
            $bloque['seleccionable'] = $bloque['estado'] === 'ocupado'
                && $inicioBloque !== false && $inicioBloque > $ahora;
            $bloque['inicio_unix'] = $inicioBloque !== false ? $inicioBloque->getTimestamp() : null;
        }
        unset($bloque);
        cvs_le_responder_json(['bloques'=>$bloques]);
    } catch (PDOException $ex) {
        cvs_le_registrar_error($ex, 'consultar_bloques');
        cvs_le_responder_json(['error'=>'No fue posible consultar la agenda médica.'], 500);
    }
}

$errores = [];
$flash = $_SESSION['lista_espera_flash'] ?? null;
unset($_SESSION['lista_espera_flash']);
$datos = [
    'id_paciente'=>'',
    'id_especialidad'=>'',
    'id_medico'=>'',
    'fecha_desde'=>$hoy->format('Y-m-d'),
    'fecha_hasta'=>$hoy->format('Y-m-d'),
    'hora_desde'=>'',
    'hora_hasta'=>'',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
        $errores[] = 'Solicitud no válida. Recarga la página e inténtalo nuevamente.';
    } else {
        $accion = cvs_texto($_POST, 'accion');
        if ($accion === 'asignar_turno') {
            $idSolicitud = cvs_le_id($_POST['id_lista_espera'] ?? null);
            $idCitaLiberada = cvs_le_id($_POST['id_cita_liberada'] ?? null);
            if ($idSolicitud === 0 || $idCitaLiberada === 0
                || ($_POST['confirmacion'] ?? null) !== '1') {
                $errores[] = 'Confirma la asignación e indica una sugerencia válida.';
            } else {
                try {
                    $conexion->beginTransaction();

                    $permiso = $conexion->prepare('SELECT u.activo, r.nombre AS rol
                        FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
                        WHERE u.id_usuario = :usuario FOR SHARE OF u, r');
                    $permiso->execute([':usuario'=>$usuario['id_usuario']]);
                    $identidad = $permiso->fetch(PDO::FETCH_ASSOC);
                    if (!$identidad || !cvs_activo($identidad['activo'])
                        || !in_array($identidad['rol'], ['administrador', 'recepcionista'], true)) {
                        $conexion->rollBack();
                        cvs_denegar_acceso();
                    }

                    $bloquearSolicitud = $conexion->prepare('SELECT id_lista_espera, id_paciente,
                            id_especialidad, id_medico, fecha_desde, fecha_hasta, hora_desde,
                            hora_hasta, estado
                        FROM lista_espera WHERE id_lista_espera = :id FOR UPDATE');
                    $bloquearSolicitud->execute([':id'=>$idSolicitud]);
                    $solicitud = $bloquearSolicitud->fetch(PDO::FETCH_ASSOC);
                    if (!$solicitud || $solicitud['estado'] !== 'activa') {
                        throw new DomainException('La solicitud ya no está activa. Actualiza las sugerencias.');
                    }

                    $bloquearCita = $conexion->prepare("SELECT c.id_cita, c.id_medico, c.id_especialidad,
                            c.fecha, c.hora_inicio, c.hora_fin, c.estado,
                            (c.fecha + c.hora_inicio > timezone('America/La_Paz', clock_timestamp())) AS futura
                        FROM citas c WHERE c.id_cita = :id FOR UPDATE OF c");
                    $bloquearCita->execute([':id'=>$idCitaLiberada]);
                    $citaLiberada = $bloquearCita->fetch(PDO::FETCH_ASSOC);
                    if (!$citaLiberada || $citaLiberada['estado'] !== 'cancelada'
                        || !cvs_activo($citaLiberada['futura'])) {
                        throw new DomainException('El turno cancelado ya no está disponible.');
                    }

                    $fechaTurno = (string)$citaLiberada['fecha'];
                    $horaInicioTexto = substr((string)$citaLiberada['hora_inicio'], 0, 8);
                    $horaFinTexto = substr((string)$citaLiberada['hora_fin'], 0, 8);
                    $inicioTurno = DateTimeImmutable::createFromFormat(
                        '!Y-m-d H:i:s', $fechaTurno . ' ' . $horaInicioTexto, $zonaClinica
                    );
                    $finTurno = DateTimeImmutable::createFromFormat(
                        '!Y-m-d H:i:s', $fechaTurno . ' ' . $horaFinTexto, $zonaClinica
                    );
                    if (!$inicioTurno || !$finTurno || $finTurno <= $inicioTurno
                        || $inicioTurno <= new DateTimeImmutable('now', $zonaClinica)
                        || (int)$solicitud['id_especialidad'] !== (int)$citaLiberada['id_especialidad']
                        || ($solicitud['id_medico'] !== null
                            && (int)$solicitud['id_medico'] !== (int)$citaLiberada['id_medico'])
                        || $fechaTurno < (string)$solicitud['fecha_desde']
                        || $fechaTurno > (string)$solicitud['fecha_hasta']
                        || ($solicitud['hora_desde'] !== null
                            && (substr((string)$solicitud['hora_desde'], 0, 5) > substr($horaInicioTexto, 0, 5)
                                || substr((string)$solicitud['hora_hasta'], 0, 5) < substr($horaFinTexto, 0, 5)))) {
                        throw new DomainException('La sugerencia ya no coincide con las preferencias activas.');
                    }

                    $bloquearPaciente = $conexion->prepare('SELECT id_paciente
                        FROM pacientes WHERE id_paciente = :id AND activo = TRUE FOR UPDATE');
                    $bloquearPaciente->execute([':id'=>(int)$solicitud['id_paciente']]);
                    if (!$bloquearPaciente->fetchColumn()) {
                        throw new DomainException('El paciente de la solicitud ya no está activo.');
                    }

                    $validarMedico = $conexion->prepare('SELECT 1
                        FROM medicos m
                        JOIN medico_especialidad me ON me.id_medico = m.id_medico
                        JOIN especialidades e ON e.id_especialidad = me.id_especialidad
                        WHERE m.id_medico = :medico AND e.id_especialidad = :especialidad
                            AND m.activo = TRUE AND e.activo = TRUE
                        FOR SHARE OF m, me, e');
                    $validarMedico->execute([
                        ':medico'=>(int)$citaLiberada['id_medico'],
                        ':especialidad'=>(int)$citaLiberada['id_especialidad'],
                    ]);
                    if (!$validarMedico->fetchColumn()) {
                        throw new DomainException('El médico o la especialidad ya no están activos o vinculados.');
                    }

                    $bloquearHorarios = $conexion->prepare('SELECT hora_inicio, hora_fin, duracion_minutos
                        FROM horarios
                        WHERE id_medico = :medico AND dia_semana = :dia AND activo = TRUE
                        FOR SHARE');
                    $bloquearHorarios->execute([
                        ':medico'=>(int)$citaLiberada['id_medico'],
                        ':dia'=>(int)$inicioTurno->format('N'),
                    ]);
                    $horarios = $bloquearHorarios->fetchAll(PDO::FETCH_ASSOC);
                    $horarioValido = false;
                    foreach ($horarios as $horario) {
                        $horaHorarioInicio = substr((string)$horario['hora_inicio'], 0, 8);
                        $horaHorarioFin = substr((string)$horario['hora_fin'], 0, 8);
                        $inicioHorario = DateTimeImmutable::createFromFormat(
                            '!Y-m-d H:i:s', $fechaTurno . ' ' . $horaHorarioInicio, $zonaClinica
                        );
                        $finHorario = DateTimeImmutable::createFromFormat(
                            '!Y-m-d H:i:s', $fechaTurno . ' ' . $horaHorarioFin, $zonaClinica
                        );
                        $duracion = (int)$horario['duracion_minutos'];
                        if (!$inicioHorario || !$finHorario || $duracion < 1
                            || $inicioTurno < $inicioHorario || $finTurno > $finHorario) {
                            continue;
                        }
                        $segundosDesdeInicio = $inicioTurno->getTimestamp() - $inicioHorario->getTimestamp();
                        if ($segundosDesdeInicio >= 0 && $segundosDesdeInicio % ($duracion * 60) === 0
                            && $inicioTurno->modify('+' . $duracion . ' minutes')->format('H:i:s') === $horaFinTexto) {
                            $horarioValido = true;
                            break;
                        }
                    }
                    if (!$horarioValido) {
                        throw new DomainException('El turno cancelado ya no corresponde a un horario activo del médico.');
                    }

                    $conflictoMedico = $conexion->prepare("SELECT id_cita FROM citas
                        WHERE id_medico = :medico AND fecha = :fecha
                            AND estado IN ('programada', 'confirmada')
                            AND hora_inicio < CAST(:hora_fin AS TIME)
                            AND hora_fin > CAST(:hora_inicio AS TIME)
                        FOR UPDATE");
                    $conflictoMedico->execute([
                        ':medico'=>(int)$citaLiberada['id_medico'],
                        ':fecha'=>$fechaTurno,
                        ':hora_inicio'=>$horaInicioTexto,
                        ':hora_fin'=>$horaFinTexto,
                    ]);
                    if ($conflictoMedico->fetchColumn()) {
                        throw new DomainException('El turno ya fue reservado. Actualiza las sugerencias.');
                    }

                    $conflictoPaciente = $conexion->prepare("SELECT id_cita FROM citas
                        WHERE id_paciente = :paciente AND fecha = :fecha
                            AND estado IN ('programada', 'confirmada')
                            AND hora_inicio < CAST(:hora_fin AS TIME)
                            AND hora_fin > CAST(:hora_inicio AS TIME)
                        FOR UPDATE");
                    $conflictoPaciente->execute([
                        ':paciente'=>(int)$solicitud['id_paciente'],
                        ':fecha'=>$fechaTurno,
                        ':hora_inicio'=>$horaInicioTexto,
                        ':hora_fin'=>$horaFinTexto,
                    ]);
                    if ($conflictoPaciente->fetchColumn()) {
                        throw new DomainException('El paciente ya tiene otra cita en ese horario.');
                    }

                    $codigo = 'CITA-' . strtoupper(bin2hex(random_bytes(4))) . '-'
                        . (new DateTimeImmutable('now', $zonaClinica))->format('Ymd');
                    $crearCita = $conexion->prepare("INSERT INTO citas
                        (codigo, id_paciente, id_medico, id_especialidad, fecha, hora_inicio,
                            hora_fin, motivo, estado, creado_en, actualizado_en)
                        VALUES (:codigo, :paciente, :medico, :especialidad, :fecha,
                            CAST(:hora_inicio AS TIME), CAST(:hora_fin AS TIME), NULL, 'programada',
                            timezone('America/La_Paz', clock_timestamp()),
                            timezone('America/La_Paz', clock_timestamp()))
                        RETURNING id_cita");
                    $crearCita->execute([
                        ':codigo'=>$codigo,
                        ':paciente'=>(int)$solicitud['id_paciente'],
                        ':medico'=>(int)$citaLiberada['id_medico'],
                        ':especialidad'=>(int)$citaLiberada['id_especialidad'],
                        ':fecha'=>$fechaTurno,
                        ':hora_inicio'=>$horaInicioTexto,
                        ':hora_fin'=>$horaFinTexto,
                    ]);
                    $idCitaAsignada = (int)$crearCita->fetchColumn();

                    $marcarAsignada = $conexion->prepare("UPDATE lista_espera
                        SET estado = 'asignada', id_cita_asignada = :cita,
                            actualizado_en = timezone('America/La_Paz', clock_timestamp())
                        WHERE id_lista_espera = :solicitud AND estado = 'activa'");
                    $marcarAsignada->execute([
                        ':cita'=>$idCitaAsignada,
                        ':solicitud'=>$idSolicitud,
                    ]);
                    if ($marcarAsignada->rowCount() !== 1) {
                        throw new DomainException('La solicitud ya no está activa. No se asignó el turno.');
                    }

                    $conexion->commit();
                    cvs_le_redirigir('exito', 'Turno asignado correctamente. Código de cita: ' . $codigo . '.');
                } catch (DomainException $ex) {
                    if ($conexion->inTransaction()) $conexion->rollBack();
                    cvs_le_redirigir('advertencia', $ex->getMessage());
                } catch (PDOException $ex) {
                    if ($conexion->inTransaction()) $conexion->rollBack();
                    if (in_array((string)$ex->getCode(), ['23P01', '23505', '40001', '40P01'], true)) {
                        cvs_le_redirigir('advertencia', 'El turno o la solicitud cambiaron durante la asignación. Actualiza las sugerencias.');
                    }
                    cvs_le_registrar_error($ex, 'asignar_turno');
                    cvs_le_redirigir('error', 'No fue posible asignar el turno. No se guardó ningún cambio.');
                } catch (Throwable $ex) {
                    if ($conexion->inTransaction()) $conexion->rollBack();
                    cvs_le_registrar_error($ex, 'asignar_turno');
                    cvs_le_redirigir('error', 'No fue posible asignar el turno. No se guardó ningún cambio.');
                }
            }
        } elseif ($accion === 'registrar') {
            foreach (['id_paciente', 'id_especialidad', 'id_medico', 'fecha_desde', 'hora_desde', 'hora_hasta'] as $campo) {
                $datos[$campo] = cvs_texto($_POST, $campo);
            }
            $datos['fecha_hasta'] = $datos['fecha_desde'];
            $idPaciente = cvs_le_id($datos['id_paciente']);
            $idEspecialidad = cvs_le_id($datos['id_especialidad']);
            $idMedico = cvs_le_id($datos['id_medico']);
            $fechaDesde = cvs_le_fecha($datos['fecha_desde'], $zonaClinica);

            if ($idPaciente === 0) $errores[] = 'Selecciona un paciente válido.';
            if ($idEspecialidad === 0) $errores[] = 'Selecciona una especialidad válida.';
            if ($idMedico === 0) $errores[] = 'Selecciona un médico válido.';
            if (!$fechaDesde || $fechaDesde < $hoy) {
                $errores[] = 'La fecha deseada debe ser válida, futura o actual.';
            }
            if (!cvs_le_hora($datos['hora_desde']) || !cvs_le_hora($datos['hora_hasta'])
                || $datos['hora_hasta'] <= $datos['hora_desde']) {
                $errores[] = 'Selecciona un bloque ocupado válido de la agenda.';
            }

            if (!$errores) {
                try {
                    $conexion->beginTransaction();
                    $validar = $conexion->prepare('SELECT 1
                        FROM pacientes p
                        JOIN especialidades e ON e.id_especialidad = :especialidad
                        JOIN medicos m ON m.id_medico = :medico
                        JOIN medico_especialidad me ON me.id_medico = m.id_medico
                            AND me.id_especialidad = e.id_especialidad
                        WHERE p.id_paciente = :paciente AND p.activo = TRUE
                            AND e.activo = TRUE AND m.activo = TRUE
                        FOR SHARE OF p, e, m');
                    $validar->execute([
                        ':paciente'=>$idPaciente,
                        ':especialidad'=>$idEspecialidad,
                        ':medico'=>$idMedico,
                    ]);
                    if (!$validar->fetchColumn()) {
                        throw new DomainException('El paciente, la especialidad o el médico no están activos o no son compatibles.');
                    }

                    $bloquearHorarios = $conexion->prepare('SELECT id_horario
                        FROM horarios
                        WHERE id_medico = :medico AND dia_semana = :dia AND activo = TRUE
                        FOR SHARE');
                    $bloquearHorarios->execute([
                        ':medico'=>$idMedico,
                        ':dia'=>(int)$fechaDesde->format('N'),
                    ]);
                    if (!$bloquearHorarios->fetchAll(PDO::FETCH_COLUMN)) {
                        throw new DomainException('El médico ya no tiene un horario activo para la fecha seleccionada.');
                    }

                    $bloqueReal = false;
                    foreach (cvsd_bloques_medico($conexion, $idMedico, $fechaDesde) as $bloque) {
                        if ($bloque['inicio'] === $datos['hora_desde']
                            && $bloque['fin'] === $datos['hora_hasta']
                            && $bloque['estado'] === 'ocupado') {
                            $bloqueReal = true;
                            break;
                        }
                    }
                    $inicioSeleccionado = DateTimeImmutable::createFromFormat(
                        '!Y-m-d H:i',
                        $datos['fecha_desde'] . ' ' . $datos['hora_desde'],
                        $zonaClinica
                    );
                    if (!$bloqueReal || !$inicioSeleccionado
                        || $inicioSeleccionado <= new DateTimeImmutable('now', $zonaClinica)) {
                        throw new DomainException('El bloque seleccionado ya no está ocupado o dejó de estar disponible para lista de espera.');
                    }

                    $ocupacion = $conexion->prepare("SELECT id_cita
                        FROM citas
                        WHERE id_medico = :medico AND fecha = :fecha
                            AND estado IN ('programada', 'confirmada')
                            AND hora_inicio < CAST(:hora_hasta AS TIME)
                            AND hora_fin > CAST(:hora_desde AS TIME)
                        FOR SHARE");
                    $ocupacion->execute([
                        ':medico'=>$idMedico,
                        ':fecha'=>$datos['fecha_desde'],
                        ':hora_desde'=>$datos['hora_desde'],
                        ':hora_hasta'=>$datos['hora_hasta'],
                    ]);
                    if (!$ocupacion->fetchColumn()) {
                        throw new DomainException('El turno fue liberado antes de registrar la solicitud. Actualiza la agenda.');
                    }

                    $insertar = $conexion->prepare('INSERT INTO lista_espera
                        (id_paciente, id_especialidad, id_medico, fecha_desde, fecha_hasta, hora_desde, hora_hasta)
                        VALUES (:paciente, :especialidad, :medico, :fecha_desde, :fecha_hasta, :hora_desde, :hora_hasta)');
                    $insertar->bindValue(':paciente', $idPaciente, PDO::PARAM_INT);
                    $insertar->bindValue(':especialidad', $idEspecialidad, PDO::PARAM_INT);
                    $insertar->bindValue(':medico', $idMedico, PDO::PARAM_INT);
                    $insertar->bindValue(':fecha_desde', $datos['fecha_desde'], PDO::PARAM_STR);
                    $insertar->bindValue(':fecha_hasta', $datos['fecha_hasta'], PDO::PARAM_STR);
                    $insertar->bindValue(':hora_desde', $datos['hora_desde'] !== '' ? $datos['hora_desde'] : null,
                        $datos['hora_desde'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $insertar->bindValue(':hora_hasta', $datos['hora_hasta'] !== '' ? $datos['hora_hasta'] : null,
                        $datos['hora_hasta'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $insertar->execute();
                    $conexion->commit();
                    cvs_le_redirigir('exito', 'Solicitud registrada en la lista de espera.');
                } catch (DomainException $ex) {
                    if ($conexion->inTransaction()) $conexion->rollBack();
                    $errores[] = $ex->getMessage();
                } catch (PDOException $ex) {
                    if ($conexion->inTransaction()) $conexion->rollBack();
                    if ($ex->getCode() === '23505') {
                        $errores[] = 'Ya existe una solicitud activa del paciente con las mismas preferencias.';
                    } else {
                        cvs_le_registrar_error($ex, 'registrar');
                        $errores[] = 'No fue posible registrar la solicitud. Verifica que la migración esté aplicada.';
                    }
                }
            }
        } elseif ($accion === 'retirar') {
            $idSolicitud = cvs_le_id($_POST['id_lista_espera'] ?? null);
            if ($idSolicitud === 0) {
                $errores[] = 'La solicitud indicada no es válida.';
            } else {
                try {
                    $retirar = $conexion->prepare("UPDATE lista_espera
                        SET estado = 'retirada', actualizado_en = timezone('America/La_Paz', clock_timestamp())
                        WHERE id_lista_espera = :id AND estado = 'activa'");
                    $retirar->execute([':id'=>$idSolicitud]);
                    cvs_le_redirigir($retirar->rowCount() === 1 ? 'exito' : 'advertencia',
                        $retirar->rowCount() === 1
                            ? 'Solicitud retirada de la lista de espera.'
                            : 'La solicitud ya no está activa o no existe.');
                } catch (PDOException $ex) {
                    cvs_le_registrar_error($ex, 'retirar');
                    $errores[] = 'No fue posible retirar la solicitud.';
                }
            }
        } else {
            $errores[] = 'La acción solicitada no es válida.';
        }
    }
}

$pacientes = [];
$especialidades = [];
$medicos = [];
try {
    $pacientes = $conexion->query("SELECT id_paciente, nombres, apellidos, ci
        FROM pacientes WHERE activo = TRUE ORDER BY apellidos, nombres, id_paciente")->fetchAll(PDO::FETCH_ASSOC);
    $especialidades = $conexion->query("SELECT id_especialidad, nombre
        FROM especialidades WHERE activo = TRUE ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $medicos = $conexion->query("SELECT m.id_medico, m.nombres, m.apellidos,
            string_agg(DISTINCT e.nombre, ', ' ORDER BY e.nombre) AS especialidades,
            string_agg(DISTINCT e.id_especialidad::text, ',' ORDER BY e.id_especialidad::text) AS especialidad_ids
        FROM medicos m
        JOIN medico_especialidad me ON me.id_medico = m.id_medico
        JOIN especialidades e ON e.id_especialidad = me.id_especialidad AND e.activo = TRUE
        WHERE m.activo = TRUE
        GROUP BY m.id_medico ORDER BY m.apellidos, m.nombres")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    cvs_le_registrar_error($ex, 'cargar_opciones');
    $errores[] = 'No fue posible cargar pacientes, especialidades y médicos.';
}

$estadoFiltro = cvs_texto($_GET, 'estado');
if ($estadoFiltro === '') $estadoFiltro = 'activa';
if ($estadoFiltro !== 'todos' && !array_key_exists($estadoFiltro, $estados)) {
    $errores[] = 'El filtro de estado no es válido.';
    $estadoFiltro = 'activa';
}
$busqueda = cvs_texto($_GET, 'buscar');
$longitudBusqueda = preg_match_all('/./us', $busqueda);
if ($longitudBusqueda === false || $longitudBusqueda > 100 || str_contains($busqueda, "\0")) {
    $errores[] = 'La búsqueda debe contener hasta 100 caracteres válidos.';
    $busqueda = '';
}
$pagina = cvs_le_id($_GET['pagina'] ?? '1');
if ($pagina === 0 || $pagina > 10000) $pagina = 1;
$tamano = 50;
$lista = [];
$haySiguiente = false;
$listadoDisponible = true;

try {
    $condiciones = [];
    $parametros = [];
    if ($estadoFiltro !== 'todos') {
        $condiciones[] = 'le.estado = :estado';
        $parametros[':estado'] = $estadoFiltro;
    }
    if ($busqueda !== '') {
        $condiciones[] = "(p.nombres || ' ' || p.apellidos ILIKE :buscar_nombre ESCAPE '!'
            OR COALESCE(p.ci, '') ILIKE :buscar_ci ESCAPE '!')";
        $patronBusqueda = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busqueda) . '%';
        $parametros[':buscar_nombre'] = $patronBusqueda;
        $parametros[':buscar_ci'] = $patronBusqueda;
    }
    $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta = $conexion->prepare("SELECT le.id_lista_espera, le.fecha_desde, le.fecha_hasta,
            le.hora_desde, le.hora_hasta, le.estado, le.creado_en,
            p.nombres || ' ' || p.apellidos AS paciente, p.ci,
            e.nombre AS especialidad,
            CASE WHEN m.id_medico IS NULL THEN NULL ELSE m.nombres || ' ' || m.apellidos END AS medico,
            c.codigo AS cita_asignada
        FROM lista_espera le
        JOIN pacientes p ON p.id_paciente = le.id_paciente
        JOIN especialidades e ON e.id_especialidad = le.id_especialidad
        LEFT JOIN medicos m ON m.id_medico = le.id_medico
        LEFT JOIN citas c ON c.id_cita = le.id_cita_asignada"
        . $where . " ORDER BY CASE le.estado WHEN 'activa' THEN 0 WHEN 'asignada' THEN 1 ELSE 2 END,
            le.creado_en, le.id_lista_espera LIMIT :limite OFFSET :offset");
    foreach ($parametros as $clave => $valor) $consulta->bindValue($clave, $valor, PDO::PARAM_STR);
    $consulta->bindValue(':limite', $tamano + 1, PDO::PARAM_INT);
    $consulta->bindValue(':offset', ($pagina - 1) * $tamano, PDO::PARAM_INT);
    $consulta->execute();
    $lista = $consulta->fetchAll(PDO::FETCH_ASSOC);
    $haySiguiente = count($lista) > $tamano;
    if ($haySiguiente) array_pop($lista);
} catch (PDOException $ex) {
    cvs_le_registrar_error($ex, 'listar');
    $listadoDisponible = false;
    $errores[] = 'La lista de espera no está disponible. Verifica que la migración esté aplicada.';
}

$csrf = cvs_csrf_token();
$filtros = ['buscar'=>$busqueda, 'estado'=>$estadoFiltro, 'pagina'=>$pagina];
$sugerencias = [];
try {
    $consultaSugerencias = $conexion->query("WITH vacantes AS (
        SELECT DISTINCT ON (c.id_medico, c.fecha, c.hora_inicio, c.hora_fin)
            c.id_cita, c.id_medico, c.id_especialidad, c.fecha, c.hora_inicio, c.hora_fin
        FROM citas c
        WHERE c.estado = 'cancelada'
            AND c.fecha + c.hora_inicio > timezone('America/La_Paz', clock_timestamp())
            AND EXISTS (
                SELECT 1 FROM horarios h
                WHERE h.id_medico = c.id_medico
                    AND h.dia_semana = EXTRACT(ISODOW FROM c.fecha)::SMALLINT
                    AND h.activo = TRUE
                    AND c.hora_inicio >= h.hora_inicio
                    AND c.hora_fin <= h.hora_fin
                    AND c.hora_fin = c.hora_inicio + h.duracion_minutos * INTERVAL '1 minute'
                    AND MOD(EXTRACT(EPOCH FROM (c.hora_inicio - h.hora_inicio)),
                        h.duracion_minutos * 60) = 0
            )
            AND NOT EXISTS (
                SELECT 1 FROM citas ocupada
                WHERE ocupada.id_medico = c.id_medico AND ocupada.fecha = c.fecha
                    AND ocupada.estado IN ('programada', 'confirmada')
                    AND ocupada.hora_inicio < c.hora_fin AND ocupada.hora_fin > c.hora_inicio
            )
        ORDER BY c.id_medico, c.fecha, c.hora_inicio, c.hora_fin, c.id_cita
    )
    SELECT le.id_lista_espera, v.id_cita AS id_cita_liberada, v.fecha,
        v.hora_inicio, v.hora_fin, le.creado_en,
        p.nombres || ' ' || p.apellidos AS paciente,
        e.nombre AS especialidad, m.nombres || ' ' || m.apellidos AS medico
    FROM lista_espera le
    JOIN vacantes v ON v.id_especialidad = le.id_especialidad
        AND v.fecha BETWEEN le.fecha_desde AND le.fecha_hasta
        AND (le.id_medico IS NULL OR le.id_medico = v.id_medico)
        AND (le.hora_desde IS NULL OR
            (le.hora_desde <= v.hora_inicio AND le.hora_hasta >= v.hora_fin))
    JOIN pacientes p ON p.id_paciente = le.id_paciente AND p.activo = TRUE
    JOIN medicos m ON m.id_medico = v.id_medico AND m.activo = TRUE
    JOIN especialidades e ON e.id_especialidad = v.id_especialidad AND e.activo = TRUE
    WHERE le.estado = 'activa'
        AND EXISTS (SELECT 1 FROM medico_especialidad me
            WHERE me.id_medico = v.id_medico AND me.id_especialidad = v.id_especialidad)
        AND NOT EXISTS (
            SELECT 1 FROM citas ocupada
            WHERE ocupada.id_paciente = le.id_paciente AND ocupada.fecha = v.fecha
                AND ocupada.estado IN ('programada', 'confirmada')
                AND ocupada.hora_inicio < v.hora_fin AND ocupada.hora_fin > v.hora_inicio
        )
    ORDER BY le.creado_en, le.id_lista_espera, v.fecha, v.hora_inicio, v.id_cita
    LIMIT 100");
    $sugerencias = $consultaSugerencias->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    cvs_le_registrar_error($ex, 'consultar_sugerencias');
    $errores[] = 'No fue posible consultar las sugerencias de turnos liberados.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista de espera | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('lista_espera', 'Lista de espera'); ?>
<main class="ui-contenido">
<p><a class="boton secundario" href="index.php">← Volver al inicio</a></p>
<?php if (is_array($flash) && is_string($flash['mensaje'] ?? null)): ?>
<div class="mensaje <?= ($flash['tipo'] ?? '') === 'advertencia' ? 'advertencia' : '' ?>" role="status"><?= cvs_escapar($flash['mensaje']) ?></div>
<?php endif; ?>
<?php foreach ($errores as $error): ?><div class="error" role="alert"><?= cvs_escapar($error) ?></div><?php endforeach; ?>

<section class="panel" aria-labelledby="titulo-sugerencias">
<h2 id="titulo-sugerencias">Turnos liberados: sugerencias priorizadas</h2>
<p>Se muestran turnos futuros liberados por cancelación que siguen disponibles y coinciden con las solicitudes activas. El orden prioriza la solicitud más antigua.</p>
<?php if (!$sugerencias): ?><p role="status">No hay sugerencias de asignación disponibles.</p><?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-label="Sugerencias de turnos liberados"><table><thead><tr><th>Paciente</th><th>Especialidad</th><th>Médico</th><th>Turno liberado</th><th>Solicitud registrada</th><th>Confirmación</th></tr></thead><tbody>
<?php foreach ($sugerencias as $sugerencia): ?>
<?php $idConfirmacion = 'confirmar-asignacion-' . (int)$sugerencia['id_lista_espera'] . '-' . (int)$sugerencia['id_cita_liberada']; ?>
<tr>
<td><?= cvs_escapar($sugerencia['paciente']) ?></td>
<td><?= cvs_escapar($sugerencia['especialidad']) ?></td>
<td><?= cvs_escapar($sugerencia['medico']) ?></td>
<td><?= cvs_escapar($sugerencia['fecha'] . ' · ' . substr($sugerencia['hora_inicio'], 0, 5) . ' a ' . substr($sugerencia['hora_fin'], 0, 5)) ?></td>
<td><?= cvs_escapar(substr($sugerencia['creado_en'], 0, 16)) ?></td>
<td>
<form method="post" action="lista_espera.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar($csrf) ?>">
<input type="hidden" name="accion" value="asignar_turno">
<input type="hidden" name="id_lista_espera" value="<?= (int)$sugerencia['id_lista_espera'] ?>">
<input type="hidden" name="id_cita_liberada" value="<?= (int)$sugerencia['id_cita_liberada'] ?>">
<label for="<?= cvs_escapar($idConfirmacion) ?>"><input id="<?= cvs_escapar($idConfirmacion) ?>" type="checkbox" name="confirmacion" value="1" required> Confirmo la asignación</label>
<button type="submit">Asignar turno</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (count($sugerencias) === 100): ?><p class="pequeno">Se muestran las 100 sugerencias más antiguas que coinciden.</p><?php endif; ?>
<?php endif; ?>
</section>

<section class="panel" aria-labelledby="titulo-registro">
<h2 id="titulo-registro">Registrar solicitud de espera</h2>
<p>Selecciona el paciente y un turno ocupado de la agenda médica.</p>
<form method="post" action="lista_espera.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar($csrf) ?>">
<input type="hidden" name="accion" value="registrar">
<input id="hora-desde" type="hidden" name="hora_desde" value="<?= cvs_escapar($datos['hora_desde']) ?>">
<input id="hora-hasta" type="hidden" name="hora_hasta" value="<?= cvs_escapar($datos['hora_hasta']) ?>">
<div class="campos">
<div class="campo"><label for="id-paciente">Paciente *</label><select id="id-paciente" name="id_paciente" required><option value="">Seleccionar paciente</option><?php foreach ($pacientes as $paciente): ?><option value="<?= cvs_escapar($paciente['id_paciente']) ?>" <?= $datos['id_paciente'] === (string)$paciente['id_paciente'] ? 'selected' : '' ?>><?= cvs_escapar($paciente['apellidos'] . ', ' . $paciente['nombres'] . ($paciente['ci'] ? ' · CI ' . $paciente['ci'] : '')) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="id-especialidad">Especialidad *</label><select id="id-especialidad" name="id_especialidad" required><option value="">Seleccionar especialidad</option><?php foreach ($especialidades as $especialidad): ?><option value="<?= cvs_escapar($especialidad['id_especialidad']) ?>" <?= $datos['id_especialidad'] === (string)$especialidad['id_especialidad'] ? 'selected' : '' ?>><?= cvs_escapar($especialidad['nombre']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="id-medico">Médico *</label><select id="id-medico" name="id_medico" required><option value="">Seleccionar médico</option><?php foreach ($medicos as $medico): ?><option value="<?= cvs_escapar($medico['id_medico']) ?>" data-especialidades="<?= cvs_escapar($medico['especialidad_ids']) ?>" data-nombre="<?= cvs_escapar($medico['nombres'] . ' ' . $medico['apellidos']) ?>" <?= $datos['id_medico'] === (string)$medico['id_medico'] ? 'selected' : '' ?>><?= cvs_escapar($medico['apellidos'] . ', ' . $medico['nombres'] . ' · ' . $medico['especialidades']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="fecha-desde">Fecha deseada *</label><input id="fecha-desde" type="date" name="fecha_desde" min="<?= cvs_escapar($hoy->format('Y-m-d')) ?>" value="<?= cvs_escapar($datos['fecha_desde']) ?>" required></div>
</div>
<div class="campo">
<label>Bloques de la agenda</label>
<div id="bloques-espera" class="bloques" aria-live="polite"><p>Selecciona especialidad, médico y fecha para consultar la agenda.</p></div>
<p id="seleccion-bloque" class="ayuda">Los bloques libres son informativos. Selecciona un bloque ocupado para solicitar ese turno.</p>
</div>
<div id="resumen-bloque" class="resumen" hidden aria-live="polite">
<strong>Turno solicitado</strong>
<span id="resumen-medico" class="pill"></span>
<span id="resumen-fecha" class="pill"></span>
<span id="resumen-horario" class="pill"></span>
</div>
<div class="acciones"><button type="submit">Agregar a lista de espera</button></div>
</form>
</section>

<section class="panel" aria-labelledby="titulo-listado">
<h2 id="titulo-listado">Solicitudes registradas</h2>
<form method="get" action="lista_espera.php" class="filtros">
<div><label for="buscar">Paciente o CI</label><input id="buscar" name="buscar" maxlength="100" value="<?= cvs_escapar($busqueda) ?>"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option><?php foreach ($estados as $valor => $etiqueta): ?><option value="<?= cvs_escapar($valor) ?>" <?= $estadoFiltro === $valor ? 'selected' : '' ?>><?= cvs_escapar($etiqueta) ?></option><?php endforeach; ?></select></div>
<button type="submit">Buscar</button><a class="boton secundario" href="lista_espera.php">Limpiar</a>
</form>
<div class="tabla" tabindex="0" role="region" aria-label="Lista de espera con desplazamiento horizontal"><table><thead><tr><th>Paciente</th><th>Especialidad</th><th>Médico</th><th>Fecha deseada</th><th>Horario</th><th>Estado</th><th>Registro</th><th>Cita asignada</th><th>Acción</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="9"><?= $listadoDisponible ? 'No se encontraron solicitudes.' : 'Listado temporalmente no disponible.' ?></td></tr><?php endif; ?>
<?php foreach ($lista as $solicitud): ?>
<?php
$claseEstado = match ($solicitud['estado']) {'activa'=>'estado-activo', 'retirada'=>'estado-inactivo', default=>'resultado'};
$fechaSolicitud = $solicitud['fecha_desde'] === $solicitud['fecha_hasta']
    ? $solicitud['fecha_desde']
    : $solicitud['fecha_desde'] . ' a ' . $solicitud['fecha_hasta'] . ' (registro anterior)';
?>
<tr>
<td><?= cvs_escapar($solicitud['paciente']) ?><br><span class="pequeno"><?= cvs_escapar($solicitud['ci'] ? 'CI ' . $solicitud['ci'] : 'Sin CI') ?></span></td>
<td><?= cvs_escapar($solicitud['especialidad']) ?></td>
<td><?= cvs_escapar($solicitud['medico'] ?? 'Cualquier médico compatible') ?></td>
<td><?= cvs_escapar($fechaSolicitud) ?></td>
<td><?= $solicitud['hora_desde'] !== null && $solicitud['hora_hasta'] !== null ? cvs_escapar(substr($solicitud['hora_desde'], 0, 5) . ' a ' . substr($solicitud['hora_hasta'], 0, 5)) : 'Cualquier horario' ?></td>
<td><span class="<?= cvs_escapar($claseEstado) ?>"><?= cvs_escapar($estados[$solicitud['estado']] ?? $solicitud['estado']) ?></span></td>
<td><?= cvs_escapar(substr($solicitud['creado_en'], 0, 16)) ?></td>
<td><?= cvs_escapar($solicitud['cita_asignada'] ?? '—') ?></td>
<td><?php if ($solicitud['estado'] === 'activa'): ?><form method="post" action="lista_espera.php"><input type="hidden" name="csrf" value="<?= cvs_escapar($csrf) ?>"><input type="hidden" name="accion" value="retirar"><input type="hidden" name="id_lista_espera" value="<?= cvs_escapar($solicitud['id_lista_espera']) ?>"><button type="submit" class="rojizo mini">Retirar</button></form><?php else: ?><span class="pequeno">Sin acciones</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($listadoDisponible): ?><nav class="acciones paginacion" aria-label="Páginas de lista de espera"><?php if ($pagina > 1): ?><a class="boton secundario" href="<?= cvs_escapar('lista_espera.php?' . http_build_query(array_replace($filtros, ['pagina'=>$pagina - 1]))) ?>">← Anterior</a><?php endif; ?><span class="pequeno">Página <?= cvs_escapar($pagina) ?> · Hasta 50 solicitudes</span><?php if ($haySiguiente): ?><a class="boton secundario" href="<?= cvs_escapar('lista_espera.php?' . http_build_query(array_replace($filtros, ['pagina'=>$pagina + 1]))) ?>">Siguiente →</a><?php endif; ?></nav><?php endif; ?>
</section>
</main>
<?php cvsu_cierre(); ?>
<script>
(function () {
    const especialidad = document.getElementById('id-especialidad');
    const medico = document.getElementById('id-medico');
    const fechaDesde = document.getElementById('fecha-desde');
    const horaDesde = document.getElementById('hora-desde');
    const horaHasta = document.getElementById('hora-hasta');
    const contenedor = document.getElementById('bloques-espera');
    const seleccion = document.getElementById('seleccion-bloque');
    const resumen = document.getElementById('resumen-bloque');
    const resumenMedico = document.getElementById('resumen-medico');
    const resumenFecha = document.getElementById('resumen-fecha');
    const resumenHorario = document.getElementById('resumen-horario');
    let controlador = null;

    function mensaje(texto) {
        contenedor.replaceChildren();
        const parrafo = document.createElement('p');
        parrafo.textContent = texto;
        contenedor.appendChild(parrafo);
    }

    function actualizarMedicos() {
        const idEspecialidad = especialidad.value;
        Array.from(medico.options).forEach(function (opcion) {
            if (opcion.value === '') return;
            const permitidas = (opcion.dataset.especialidades || '').split(',');
            opcion.disabled = idEspecialidad !== '' && !permitidas.includes(idEspecialidad);
        });
        if (medico.selectedOptions[0] && medico.selectedOptions[0].disabled) medico.value = '';
    }

    function limpiarSeleccion() {
        horaDesde.value = '';
        horaHasta.value = '';
        contenedor.querySelectorAll('button[aria-pressed="true"]').forEach(function (anterior) {
            anterior.classList.remove('sel');
            anterior.setAttribute('aria-pressed', 'false');
        });
        resumen.hidden = true;
        seleccion.textContent = 'Los bloques libres son informativos. Selecciona un bloque ocupado para solicitar ese turno.';
    }

    function mostrarResumen(inicio, fin) {
        const opcionMedico = medico.selectedOptions[0];
        resumenMedico.textContent = 'Médico: ' + (opcionMedico.dataset.nombre || opcionMedico.textContent);
        resumenFecha.textContent = 'Fecha: ' + fechaDesde.value;
        resumenHorario.textContent = 'Horario: ' + inicio + ' - ' + fin;
        resumen.hidden = false;
    }

    async function cargarBloques() {
        if (controlador) controlador.abort();
        contenedor.replaceChildren();
        seleccion.textContent = 'Los bloques libres son informativos. Selecciona un bloque ocupado para solicitar ese turno.';
        if (!especialidad.value || !medico.value || !fechaDesde.value) {
            mensaje('Selecciona especialidad, médico y fecha para consultar la agenda.');
            return;
        }

        controlador = new AbortController();
        mensaje('Consultando agenda...');
        const parametros = new URLSearchParams({
            cargar_bloques: '1',
            id_especialidad: especialidad.value,
            id_medico: medico.value,
            fecha: fechaDesde.value
        });

        try {
            const respuesta = await fetch('lista_espera.php?' + parametros.toString(), {
                headers: {'Accept': 'application/json'},
                signal: controlador.signal
            });
            const datos = await respuesta.json();
            if (!respuesta.ok) throw new Error(datos.error || 'No fue posible consultar la agenda.');
            if (!Array.isArray(datos.bloques) || datos.bloques.length === 0) {
                mensaje('El médico no tiene bloques en horarios activos para esa fecha.');
                return;
            }

            contenedor.replaceChildren();
            let seleccionRestaurada = false;
            datos.bloques.forEach(function (bloque) {
                const etiqueta = bloque.estado === 'libre' ? 'LIBRE'
                    : bloque.estado === 'ocupado' ? 'OCUPADO' : 'NO DISPONIBLE';
                const clase = bloque.estado === 'libre' ? 'dash-turno-libre'
                    : bloque.estado === 'ocupado' ? 'dash-turno-completo' : 'dash-turno-sin-horario';
                const texto = bloque.inicio + ' - ' + bloque.fin + ' · ' + etiqueta;

                if (bloque.estado === 'ocupado' && bloque.seleccionable === true) {
                    const boton = document.createElement('button');
                    boton.type = 'button';
                    boton.className = 'bloque dash-med-estado ' + clase;
                    boton.textContent = texto;
                    boton.setAttribute('aria-pressed', 'false');
                    if (horaDesde.value === bloque.inicio && horaHasta.value === bloque.fin) {
                        boton.classList.add('sel');
                        boton.setAttribute('aria-pressed', 'true');
                        mostrarResumen(bloque.inicio, bloque.fin);
                        seleccionRestaurada = true;
                    }
                    boton.addEventListener('click', function () {
                        if (!Number.isFinite(bloque.inicio_unix)
                            || Date.now() >= bloque.inicio_unix * 1000) {
                            limpiarSeleccion();
                            cargarBloques();
                            return;
                        }
                        contenedor.querySelectorAll('button[aria-pressed="true"]').forEach(function (anterior) {
                            anterior.classList.remove('sel');
                            anterior.setAttribute('aria-pressed', 'false');
                        });
                        boton.classList.add('sel');
                        boton.setAttribute('aria-pressed', 'true');
                        horaDesde.value = bloque.inicio;
                        horaHasta.value = bloque.fin;
                        seleccion.textContent = 'Bloque ocupado seleccionado: ' + texto + '.';
                        mostrarResumen(bloque.inicio, bloque.fin);
                    });
                    contenedor.appendChild(boton);
                } else {
                    const estado = document.createElement('span');
                    estado.className = 'dash-med-estado ' + clase;
                    estado.textContent = texto;
                    contenedor.appendChild(estado);
                }
            });
            if (horaDesde.value !== '' && !seleccionRestaurada) limpiarSeleccion();
        } catch (error) {
            if (error.name !== 'AbortError') mensaje(error.message || 'No fue posible consultar la agenda.');
        }
    }

    especialidad.addEventListener('change', function () {
        limpiarSeleccion();
        actualizarMedicos();
        cargarBloques();
    });
    medico.addEventListener('change', function () {
        limpiarSeleccion();
        cargarBloques();
    });
    fechaDesde.addEventListener('change', function () {
        limpiarSeleccion();
        cargarBloques();
    });

    actualizarMedicos();
    if (!medico.value) limpiarSeleccion();
    cargarBloques();
})();
</script>
</body>
</html>
