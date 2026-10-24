<?php
/**
 * Datos de solo lectura para el panel administrativo.
 * No escribe en la base, no altera el esquema y no expone datos
 * a roles sin permiso: index.php valida el rol antes de llamar a estas funciones.
 */

declare(strict_types=1);

const CVSD_MESES = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];
const CVSD_DIAS_SEMANA = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const CVSD_ESTADOS = ['programada', 'confirmada', 'atendida', 'cancelada', 'inasistencia'];

/** Fecha Y-m-d y hora de Bolivia: America/La_Paz. */
function cvsd_hoy(): DateTimeImmutable
{
    $zona = new DateTimeZone('America/La_Paz');

    return new DateTimeImmutable('today', $zona);
}

/** Hora actual de Bolivia. */
function cvsd_ahora(DateTimeZone $zona = null): DateTimeImmutable
{
    return new DateTimeImmutable('now', $zona ?? new DateTimeZone('America/La_Paz'));
}

function cvsd_formato_fecha_larga(DateTimeImmutable $fecha): string
{
    $diaSemana = CVSD_DIAS_SEMANA[(int) $fecha->format('N')];
    $dia = $fecha->format('j');
    $mes = CVSD_MESES[(int) $fecha->format('n')];
    $anio = $fecha->format('Y');

    return $diaSemana . ', ' . $dia . ' de ' . $mes . ' de ' . $anio;
}

function cvsd_formato_hora(string $valor): string
{
    // Devuelve 'H:i' a partir de 'H:i:s' u 'H:i'.
    $partes = substr($valor, 0, 5);

    return $partes === '' ? '' : $partes;
}

/** Cantidad real de pacientes activos. */
function cvsd_pacientes_activos(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM pacientes WHERE activo = TRUE')->fetchColumn();
}

/** Filas de citas con paciente, especialidad y médico para una fecha exacta. */
function cvsd_citas_del_dia(PDO $pdo, string $fechaYmd): array
{
    $sql = 'SELECT c.id_cita, c.hora_inicio, c.hora_fin, c.estado,
                   p.nombres AS pac_nombres, p.apellidos AS pac_apellidos,
                   e.nombre AS especialidad,
                   m.nombres AS med_nombres, m.apellidos AS med_apellidos
            FROM citas c
            JOIN pacientes p ON p.id_paciente = c.id_paciente
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            JOIN medicos m ON m.id_medico = c.id_medico
            WHERE c.fecha = :fecha
            ORDER BY c.hora_inicio, c.id_cita';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':fecha', $fechaYmd, PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetchAll();
}

/** Cantidad de citas registradas por fecha dentro de un rango [incluido]. */
function cvsd_conteo_por_fecha(PDO $pdo, string $desdeYmd, string $hastaYmd): array
{
    $sql = 'SELECT c.fecha::text AS fecha, COUNT(*) AS total
            FROM citas c
            WHERE c.fecha BETWEEN :desde AND :hasta
            GROUP BY c.fecha
            ORDER BY c.fecha';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':desde', $desdeYmd, PDO::PARAM_STR);
    $stmt->bindValue(':hasta', $hastaYmd, PDO::PARAM_STR);
    $stmt->execute();

    $mapa = [];
    foreach ($stmt->fetchAll() as $fila) {
        $mapa[$fila['fecha']] = (int) $fila['total'];
    }

    return $mapa;
}

/**
 * Últimas citas registradas según citas.creado_en (dato fiable, sin inferencias).
 * $limite entre 1 y 20.
 */
function cvsd_actividad_reciente(PDO $pdo, int $limite = 8): array
{
    $limite = max(1, min(20, $limite));
    $sql = 'SELECT c.creado_en, c.fecha, c.hora_inicio, c.estado,
                   p.nombres AS pac_nombres, p.apellidos AS pac_apellidos,
                   e.nombre AS especialidad
            FROM citas c
            JOIN pacientes p ON p.id_paciente = c.id_paciente
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            ORDER BY c.creado_en DESC, c.id_cita DESC
            LIMIT ' . $limite;

    return $pdo->query($sql)->fetchAll();
}

/** Médicos activos con sus especialidades asignadas. */
function cvsd_medicos_activos(PDO $pdo): array
{
    $sql = "SELECT m.id_medico, m.nombres, m.apellidos,
                   COALESCE(string_agg(DISTINCT e.nombre, ', ' ORDER BY e.nombre), '') AS especialidades
            FROM medicos m
            LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
            LEFT JOIN especialidades e ON e.id_especialidad = me.id_especialidad
            WHERE m.activo = TRUE
            GROUP BY m.id_medico
            ORDER BY m.apellidos, m.nombres";

    return $pdo->query($sql)->fetchAll();
}

/**
 * Bloques libres por médico para la fecha indicada, replicando los criterios
 * de RF06/RF07 (citas.php): horarios activos del día de la semana, bloques
 * alineados desde la hora_inicio de cada horario con su duracion_minutos,
 * bloque completo dentro del horario y, si la fecha es hoy, solo bloques
 * posteriores a la hora actual. Ocupación: citas programadas o confirmadas.
 */
function cvsd_disponibilidad_del_dia(PDO $pdo, DateTimeImmutable $fecha): array
{
    $fechaYmd = $fecha->format('Y-m-d');
    $diaSemana = (int) $fecha->format('N');
    $esHoy = $fechaYmd === cvsd_hoy()->format('Y-m-d');
    $ahora = $esHoy ? cvsd_ahora($fecha->getTimezone()) : null;

    $medicos = cvsd_medicos_activos($pdo);
    if ($medicos === []) {
        return [];
    }

    // Marcadores de posición IN (:m0, :m1, ...)
    $marcadores = [];
    $parametros = [];
    foreach ($medicos as $i => $medico) {
        $clave = ':m' . $i;
        $marcadores[] = $clave;
        $parametros[$clave] = (int) $medico['id_medico'];
    }
    $listaIn = implode(', ', $marcadores);

    $stmtH = $pdo->prepare('SELECT id_medico, hora_inicio, hora_fin, duracion_minutos
                            FROM horarios
                            WHERE id_medico IN (' . $listaIn . ') AND dia_semana = :dia AND activo = TRUE
                            ORDER BY id_medico, hora_inicio');
    $stmtH->bindValue(':dia', $diaSemana, PDO::PARAM_INT);
    foreach ($parametros as $clave => $valor) {
        $stmtH->bindValue($clave, $valor, PDO::PARAM_INT);
    }
    $stmtH->execute();
    $horariosPorMedico = [];
    foreach ($stmtH->fetchAll() as $h) {
        $horariosPorMedico[(int) $h['id_medico']][] = $h;
    }

    $stmtC = $pdo->prepare('SELECT id_medico, hora_inicio, hora_fin
                            FROM citas
                            WHERE id_medico IN (' . $listaIn . ') AND fecha = :fecha AND estado IN (\'programada\', \'confirmada\')');
    $stmtC->bindValue(':fecha', $fechaYmd, PDO::PARAM_STR);
    foreach ($parametros as $clave => $valor) {
        $stmtC->bindValue($clave, $valor, PDO::PARAM_INT);
    }
    $stmtC->execute();
    $ocupadasPorMedico = [];
    foreach ($stmtC->fetchAll() as $c) {
        $ocupadasPorMedico[(int) $c['id_medico']][] = $c;
    }

    $salida = [];
    foreach ($medicos as $medico) {
        $idMedico = (int) $medico['id_medico'];
        $horarios = $horariosPorMedico[$idMedico] ?? [];
        $ocupadas = $ocupadasPorMedico[$idMedico] ?? [];

        $rangosTexto = [];
        $bloquesLibres = [];
        foreach ($horarios as $hor) {
            $dur = (int) $hor['duracion_minutos'];
            if ($dur <= 0) {
                continue;
            }
            $inicioH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_inicio'])
                ?: DateTimeImmutable::createFromFormat('H:i', substr((string) $hor['hora_inicio'], 0, 5));
            $finH = DateTimeImmutable::createFromFormat('H:i:s', $hor['hora_fin'])
                ?: DateTimeImmutable::createFromFormat('H:i', substr((string) $hor['hora_fin'], 0, 5));
            if (!$inicioH || !$finH) {
                continue;
            }
            $base = $fecha;
            $cursor = $base->setTime((int) $inicioH->format('H'), (int) $inicioH->format('i'));
            $finDia = $base->setTime((int) $finH->format('H'), (int) $finH->format('i'));
            while ($cursor < $finDia) {
                $bloqueFin = $cursor->modify('+' . $dur . ' minutes');
                if ($bloqueFin > $finDia) {
                    break;
                }
                $libre = true;
                if ($ahora !== null && $cursor <= $ahora) {
                    $libre = false; // Bloque ya transcurrido, igual que en RF06.
                }
                if ($libre) {
                    foreach ($ocupadas as $co) {
                        $coIni = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_inicio'])
                            ?: DateTimeImmutable::createFromFormat('H:i', substr((string) $co['hora_inicio'], 0, 5));
                        $coFin = DateTimeImmutable::createFromFormat('H:i:s', $co['hora_fin'])
                            ?: DateTimeImmutable::createFromFormat('H:i', substr((string) $co['hora_fin'], 0, 5));
                        if (!$coIni || !$coFin) {
                            continue;
                        }
                        $coIniDt = $base->setTime((int) $coIni->format('H'), (int) $coIni->format('i'));
                        $coFinDt = $base->setTime((int) $coFin->format('H'), (int) $coFin->format('i'));
                        if (!($bloqueFin <= $coIniDt || $cursor >= $coFinDt)) {
                            $libre = false;
                            break;
                        }
                    }
                }
                if ($libre) {
                    $bloquesLibres[] = $cursor->format('H:i');
                }
                $cursor = $bloqueFin;
            }
            $rangosTexto[] = cvsd_formato_hora((string) $hor['hora_inicio']) . ' - ' . cvsd_formato_hora((string) $hor['hora_fin']);
        }

        $estado = 'sin_horario';
        $proximo = '';
        if ($rangosTexto !== []) {
            if ($bloquesLibres !== []) {
                $estado = 'con_turno';
                $proximo = $bloquesLibres[0];
            } else {
                $estado = 'completo';
            }
        }

        $salida[] = [
            'id_medico' => $idMedico,
            // Cadenas garantizadas para que la vista no reciba NULL.
            'nombres' => trim((string) ($medico['nombres'] ?? '')),
            'apellidos' => trim((string) ($medico['apellidos'] ?? '')),
            'nombre' => trim((string) ($medico['nombres'] ?? '')) . ' ' . trim((string) ($medico['apellidos'] ?? '')),
            'especialidades' => (string) ($medico['especialidades'] ?? ''),
            'horario' => implode('  ', $rangosTexto),
            'estado' => $estado,
            'proximo' => $proximo,
        ];
    }

    return $salida;
}

/** Valida el parámetro de mes 'YYYY-MM' y devuelve el primer día del mes.
 * Devuelve el mes actual de Bolivia si el valor no es válido.
 */
function cvsd_mes_desde_url(string $valor, DateTimeImmutable $hoy): DateTimeImmutable
{
    $limpio = trim($valor);
    $f = DateTimeImmutable::createFromFormat('!Y-m', $limpio, $hoy->getTimezone());
    if ($f && $f->format('Y-m') === $limpio && (int) $f->format('Y') >= 2000 && (int) $f->format('Y') <= 2100) {
        return $f->setDate((int) $f->format('Y'), (int) $f->format('n'), 1);
    }

    return $hoy->setDate((int) $hoy->format('Y'), (int) $hoy->format('n'), 1);
}

/** Valida el parámetro de día 'YYYY-MM-DD' (crea la fecha a medianoche).
 * Devuelve el día de hoy en Bolivia si el valor no es válido.
 */
function cvsd_dia_desde_url(string $valor, DateTimeImmutable $hoy): DateTimeImmutable
{
    $limpio = trim($valor);
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $limpio, $hoy->getTimezone());
    if ($f && $f->format('Y-m-d') === $limpio) {
        return $f->setTime(0, 0);
    }

    return $hoy;
}

/** Convierte 'YYYY-MM-DD' a texto legible corto, p. ej. '24 de Octubre de 2026'. */
function cvsd_fecha_corta(string $ymd): string
{
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$f) {
        return $ymd;
    }

    return (int) $f->format('j') . ' ' . CVSD_MESES[(int) $f->format('n')] . ' ' . $f->format('Y');
}
