<?php
/**
 * RF13 — Capa de consultas de REPORTES (solo lectura).
 *
 * No escribe en la base, no altera permisos ni la conexión global.
 * Todas las funciones devuelven arreglos/valores preparados para la vista.
 * Nombres de parámetros GET:
 *   desde, hasta     - rango YYYY-MM-DD del período de citas.
 *   id_medico, id_especialidad - números positivos opcionales.
 *   estado           - uno de los cinco estados válidos.
 *   tipo             - sección del módulo (citas|medicos|especialidades|estados|pacientes|agenda).
 *   p, pp            - página de la tabla de citas y de pacientes.
 *   q                - búsqueda de pacientes (nombre o CI).
 *   ag_id_medico, ag_fecha - parámetros propios de la agenda diaria.
 *   b_medico, b_especialidad, b_estado - botón "limpiar" por campo (no existen; se usan los mismos filtros).
 */

declare(strict_types=1);

const CVSR_ESTADOS = ['programada', 'confirmada', 'atendida', 'cancelada', 'inasistencia'];
const CVSR_TIPOS = ['citas', 'medicos', 'especialidades', 'estados', 'pacientes', 'agenda'];
const CVSR_POR_PAGINA = 15;
const CVSR_LIMITE_DIAS = 366;

/** Días límites del rango de reportes: America/La_Paz. */
function cvsr_hoy(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('America/La_Paz'));
}

/**
 * Valida y normaliza los filtros del módulo.
 * Nunca acepta silenciosamente una fecha inválida: los errores se devuelven
 * para que la vista los muestre, y los límites de 366 días se ajustan con aviso.
 */
function cvsr_validar_filtros(array $consulta): array
{
    foreach ($consulta as $clave=>$valor) {
        if (!is_string($valor) || str_contains($valor, "\0")) $consulta[$clave] = '__invalido__';
    }
    $zona = new DateTimeZone('America/La_Paz');
    $hoy = new DateTimeImmutable('today', $zona);
    $errores = [];
    $avisos = [];

    // Tipo de reporte dentro de la lista permitida.
    $tipo = (string) ($consulta['tipo'] ?? 'citas');
    if (!in_array($tipo, CVSR_TIPOS, true)) {
        $tipo = 'citas';
        $avisos[] = 'El tipo de reporte solicitado no existe; se muestra el reporte de citas.';
    }

    // Fechas estrictas YYYY-MM-DD; vaco = valores por defecto (mes actual).
    $desde = cvsr_parsear_fecha((string) ($consulta['desde'] ?? ''), $hoy->format('Y-m-01'));
    $hasta = cvsr_parsear_fecha((string) ($consulta['hasta'] ?? ''), $hoy->format('Y-m-d'));
    if ($desde === null) {
        $errores[] = 'La fecha desde no es válida. Usa el formato AAAA-MM-DD.';
    }
    if ($hasta === null) {
        $errores[] = 'La fecha hasta no es válida. Usa el formato AAAA-MM-DD.';
    }

    if ($desde !== null && $hasta !== null) {
        $minDesde = $hoy->modify('-' . CVSR_LIMITE_DIAS . ' days');
        $maxHasta = $hoy->modify('+' . CVSR_LIMITE_DIAS . ' days');
        if ($desde > $hasta) {
            $errores[] = 'La fecha desde no puede ser posterior a la fecha hasta.';
        }
        if ($desde < $minDesde) {
            $desde = $minDesde;
            $avisos[] = 'El rango de reportes se limita a los últimos ' . CVSR_LIMITE_DIAS . ' días: la fecha desde se ajusta a ' . $desde->format('Y-m-d') . '.';
        }
        if ($hasta > $maxHasta) {
            $hasta = $maxHasta;
            $avisos[] = 'El rango de reportes se limita a los próximos ' . CVSR_LIMITE_DIAS . ' días: la fecha hasta se ajusta a ' . $hasta->format('Y-m-d') . '.';
        }
    }

    // Identificadores positivos opcionales.
    $medico = cvsr_entero_positivo($consulta['id_medico'] ?? null);
    $especialidad = cvsr_entero_positivo($consulta['id_especialidad'] ?? null);

    // Estado dentro de la lista de cinco.
    $estado = (string) ($consulta['estado'] ?? '');
    if ($estado !== '' && !in_array($estado, CVSR_ESTADOS, true)) {
        $errores[] = 'El estado indicado no es un estado válido del sistema.';
        $estado = '';
    }

    // Paginaciones acotadas.
    $paginaCitas = cvsr_pagina($consulta['p'] ?? null);
    $paginaPacientes = cvsr_pagina($consulta['pp'] ?? null);
    $agendaFecha = cvsr_parsear_fecha((string)($consulta['ag_fecha'] ?? ''), $hoy->format('Y-m-d'));
    if ($agendaFecha === null) $errores[] = 'La fecha de la agenda no es válida.';

    return [
        'tipo' => $tipo,
        'desde' => $desde ?? $hoy->modify('first day of this month'),
        'hasta' => $hasta ?? $hoy,
        'desde_str' => (string) ($consulta['desde'] ?? ''),
        'hasta_str' => (string) ($consulta['hasta'] ?? ''),
        'id_medico' => $medico,
        'id_especialidad' => $especialidad,
        'estado' => $estado,
        'pagina_citas' => $paginaCitas,
        'pagina_pacientes' => $paginaPacientes,
        'q' => cvsr_texto_busqueda($consulta['q'] ?? ''),
        'ag_id_medico' => cvsr_entero_positivo($consulta['ag_id_medico'] ?? null),
        'ag_fecha' => $agendaFecha ?? $hoy,
        'ag_fecha_str' => (string) ($consulta['ag_fecha'] ?? ''),
        'errores' => $errores,
        'avisos' => $avisos,
    ];
}

/** Fecha YYYY-MM-DD estricta: devuelve DateTimeImmutable (medianoche) o null. */
function cvsr_parsear_fecha(string $valor, string $defecto): ?DateTimeImmutable
{
    $limpio = trim($valor);
    $zona = new DateTimeZone('America/La_Paz');
    if ($limpio === '') {
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $defecto, $zona);

        return $f === false ? null : $f->setTime(0, 0);
    }
    if (!preg_match('/\A(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\z/', $limpio)) {
        return null;
    }
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $limpio, $zona);
    if ($f === false || $f->format('Y-m-d') !== $limpio) {
        return null; // Fecha inexistente, p. ej. 2026-02-30.
    }

    return $f->setTime(0, 0);
}

function cvsr_entero_positivo($valor): ?int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT);
    if ($n === false || $n <= 0) {
        return null;
    }

    return $n;
}

function cvsr_pagina($valor): int
{
    $n = cvsr_entero_positivo($valor);
    if ($n === null) {
        return 1;
    }

    return min($n, 10000);
}

/** Limpia la búsqueda: sin caracteres de control, con comodines escapados. */
function cvsr_texto_busqueda(string $valor): string
{
    $limpio = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $valor);
    $limpio = trim((string) $limpio);
    if ($limpio === '') {
        return '';
    }
    $limpio = mb_substr($limpio, 0, 80);

    return strtr($limpio, ['\\' => '\\\\', '%' => '\\%', '_' => '\_']);
}

function cvsr_porcentaje(int $parte, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round($parte * 100 / $total);
}

/**
 * Condición WHERE compartida del período y filtros de citas.
 * $nivelFiltros: '' = solo período; 'citas' = período + médico + especialidad + estado.
 */
function cvsr_condiciones(array $val, string $nivelFiltros): array
{
    $sql = 'c.fecha BETWEEN :desde AND :hasta';
    $parametros = [
        ':desde' => $val['desde']->format('Y-m-d'),
        ':hasta' => $val['hasta']->format('Y-m-d'),
    ];
    if ($nivelFiltros === 'citas') {
        if ($val['id_medico'] !== null) {
            $sql .= ' AND c.id_medico = :id_medico';
            $parametros[':id_medico'] = $val['id_medico'];
        }
        if ($val['id_especialidad'] !== null) {
            $sql .= ' AND c.id_especialidad = :id_especialidad';
            $parametros[':id_especialidad'] = $val['id_especialidad'];
        }
        if ($val['estado'] !== '') {
            $sql .= ' AND c.estado = :estado';
            $parametros[':estado'] = $val['estado'];
        }
    }

    return [$sql, $parametros];
}

/** Ejecuta un SELECT con parmetros nombrados y devuelve fetchAll. */
function cvsr_consultar(PDO $pdo, string $sql, array $parametros, array $enteros = []): array
{
    $stmt = $pdo->prepare($sql);
    foreach ($parametros as $clave => $valor) {
        $tipo = isset($enteros[$clave]) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($clave, $valor, $tipo);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}

/** Conteo de citas por estado para el perodo y filtros aplicados. */
function cvsr_resumen_estados(PDO $pdo, array $val): array
{
    [$cond, $parametros] = cvsr_condiciones($val, 'citas');
    $sql = "SELECT estado, COUNT(*) AS total FROM citas c WHERE {$cond} GROUP BY estado";
    $filas = cvsr_consultar($pdo, $sql, $parametros);
    $mapa = array_fill_keys(CVSR_ESTADOS, 0);
    foreach ($filas as $fila) {
        $mapa[$fila['estado']] = (int) $fila['total'];
    }
    $total = array_sum($mapa);

    return ['estados' => $mapa, 'total' => $total];
}

/** Listado paginado de citas con todos los filtros y su total real. */
function cvsr_listado_citas(PDO $pdo, array $val, int $desplazamiento, int $limite): array
{
    [$cond, $parametros] = cvsr_condiciones($val, 'citas');
    $sql = "SELECT c.id_cita, c.codigo, c.fecha::text AS fecha, c.hora_inicio, c.hora_fin, c.estado,
                   p.nombres || ' ' || p.apellidos AS paciente,
                   m.nombres || ' ' || m.apellidos AS medico,
                   e.nombre AS especialidad
            FROM citas c
            JOIN pacientes p ON p.id_paciente = c.id_paciente
            JOIN medicos m ON m.id_medico = c.id_medico
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            WHERE {$cond}
            ORDER BY c.fecha DESC, c.hora_inicio DESC, c.id_cita DESC
            LIMIT :limite OFFSET :desplazamiento";
    $stmt = $pdo->prepare($sql);
    foreach ($parametros as $clave => $valor) {
        $stmt->bindValue($clave, $valor, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $stmt->execute();

    $sqlTotal = "SELECT COUNT(*) FROM citas c WHERE {$cond}";
    $stmtTotal = $pdo->prepare($sqlTotal);
    foreach ($parametros as $clave => $valor) {
        $stmtTotal->bindValue($clave, $valor, PDO::PARAM_STR);
    }
    $stmtTotal->execute();

    return ['filas' => $stmt->fetchAll(), 'total' => (int) $stmtTotal->fetchColumn()];
}

/** Citas por mdico (incluye mdicos desactivados) con estados. */
function cvsr_por_medico(PDO $pdo, array $val): array
{
    [$cond, $parametros] = cvsr_condiciones($val, 'citas');
    $sql = "SELECT m.id_medico, m.nombres, m.apellidos, m.registro_profesional, m.activo AS med_activo,
                   COUNT(*) FILTER (WHERE TRUE) AS total,
                   COUNT(*) FILTER (WHERE c.estado = 'programada') AS programada,
                   COUNT(*) FILTER (WHERE c.estado = 'confirmada') AS confirmada,
                   COUNT(*) FILTER (WHERE c.estado = 'atendida') AS atendida,
                   COUNT(*) FILTER (WHERE c.estado = 'cancelada') AS cancelada,
                   COUNT(*) FILTER (WHERE c.estado = 'inasistencia') AS inasistencia
            FROM citas c
            JOIN medicos m ON m.id_medico = c.id_medico
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            WHERE {$cond} AND c.id_medico = COALESCE(:id_medico, c.id_medico)
            GROUP BY m.id_medico, m.nombres, m.apellidos, m.registro_profesional, m.activo
            ORDER BY total DESC, m.apellidos, m.nombres";

    return cvsr_consultar($pdo, $sql, $parametros + [
        ':id_medico' => $val['id_medico'],
    ], [':id_medico' => true, ':id_especialidad' => true]);
}

/** Citas por especialidad (incluye inactivas con citas histricas). */
function cvsr_por_especialidad(PDO $pdo, array $val): array
{
    [$cond, $parametros] = cvsr_condiciones($val, 'citas');
    $sql = "SELECT e.nombre AS especialidad, e.activo AS esp_activo,
                   COUNT(*) FILTER (WHERE TRUE) AS total,
                   COUNT(*) FILTER (WHERE c.estado = 'programada') AS programada,
                   COUNT(*) FILTER (WHERE c.estado = 'confirmada') AS confirmada,
                   COUNT(*) FILTER (WHERE c.estado = 'atendida') AS atendida,
                   COUNT(*) FILTER (WHERE c.estado = 'cancelada') AS cancelada,
                   COUNT(*) FILTER (WHERE c.estado = 'inasistencia') AS inasistencia
            FROM citas c
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            WHERE {$cond} AND c.id_especialidad = COALESCE(:id_especialidad, c.id_especialidad)
            GROUP BY e.nombre, e.activo
            ORDER BY total DESC, e.nombre";

    return cvsr_consultar($pdo, $sql, $parametros + [
        ':id_especialidad' => $val['id_especialidad'],
    ], [':id_especialidad' => true]);
}

/** Serie de citas por fecha en el perodo (para el grfico de actividad). */
function cvsr_por_fecha(PDO $pdo, array $val): array
{
    [$cond, $parametros] = cvsr_condiciones($val, 'citas');
    $sql = "SELECT c.fecha::text AS fecha, COUNT(*) AS total
            FROM citas c
            WHERE {$cond}
            GROUP BY c.fecha
            ORDER BY c.fecha";

    return cvsr_consultar($pdo, $sql, $parametros);
}

// ------------------------- PACIENTES -------------------------

/** Total, activos e inactivos de pacientes (sin filtros de perodo). */
function cvsr_pacientes_resumen(PDO $pdo): array
{
    $sql = "SELECT COUNT(*) FILTER (WHERE TRUE) AS total,
                   COUNT(*) FILTER (WHERE activo) AS activos,
                   COUNT(*) FILTER (WHERE NOT activo) AS inactivos
            FROM pacientes";
    $fila = $pdo->query($sql)->fetch();

    return ['total' => (int) $fila['total'], 'activos' => (int) $fila['activos'], 'inactivos' => (int) $fila['inactivos']];
}

/** Tabla administrativa de pacientes con bsqueda por nombre o CI. Solo lectura. */
function cvsr_pacientes(PDO $pdo, string $q, int $desplazamiento, int $limite): array
{
    if ($q === '') {
        $sql = 'SELECT id_paciente, ci, nombres, apellidos, activo FROM pacientes
                ORDER BY apellidos, nombres
                LIMIT :limite OFFSET :desplazamiento';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $stmt->execute();

        $stmtTotal = $pdo->query('SELECT COUNT(*) FROM pacientes');
    } else {
        $sql = "SELECT id_paciente, ci, nombres, apellidos, activo FROM pacientes
                WHERE nombres ILIKE :q1 OR apellidos ILIKE :q2 OR ci ILIKE :q3
                ORDER BY apellidos, nombres
                LIMIT :limite OFFSET :desplazamiento";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':q1', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':q2', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':q3', '%' . $q . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $stmt->execute();

        $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM pacientes WHERE nombres ILIKE :q1 OR apellidos ILIKE :q2 OR ci ILIKE :q3');
        $stmtTotal->bindValue(':q1', '%' . $q . '%', PDO::PARAM_STR);
        $stmtTotal->bindValue(':q2', '%' . $q . '%', PDO::PARAM_STR);
        $stmtTotal->bindValue(':q3', '%' . $q . '%', PDO::PARAM_STR);
        $stmtTotal->execute();
    }

    return ['filas' => $stmt->fetchAll(), 'total' => (int) $stmtTotal->fetchColumn()];
}

/** Mdicos y especialidades para los selects, incluidos los desactivados. */
function cvsr_listas_formulario(PDO $pdo): array
{
    $medicos = $pdo->query('SELECT id_medico, nombres, apellidos, activo FROM medicos ORDER BY activo DESC, apellidos, nombres')->fetchAll();
    $especialidades = $pdo->query('SELECT id_especialidad, nombre, activo FROM especialidades ORDER BY activo DESC, nombre')->fetchAll();

    return ['medicos' => $medicos, 'especialidades' => $especialidades];
}

// ------------------------- AGENDA DIARIA -------------------------

/**
 * Agenda de un médico para una fecha (histrica incluida), cronolgica,
 * con resumen de estados. No se ocult a los casos cancelados.
 */
function cvsr_agenda_dia(PDO $pdo, int $idMedico, string $fechaYmd): array
{
    $sql = "SELECT c.id_cita, c.codigo, c.hora_inicio, c.hora_fin, c.estado,
                   p.nombres || ' ' || p.apellidos AS paciente,
                   e.nombre AS especialidad
            FROM citas c
            JOIN pacientes p ON p.id_paciente = c.id_paciente
            JOIN especialidades e ON e.id_especialidad = c.id_especialidad
            WHERE c.id_medico = :id_medico AND c.fecha = :fecha
            ORDER BY c.hora_inicio, c.id_cita
            LIMIT 500";
    $filas = cvsr_consultar($pdo, $sql, [
        ':id_medico' => $idMedico,
        ':fecha' => $fechaYmd,
    ], [':id_medico' => true]);

    $mapa = array_fill_keys(CVSR_ESTADOS, 0);
    foreach ($filas as $fila) {
        $mapa[$fila['estado']]++;
    }

    return ['filas' => $filas, 'estados' => $mapa, 'total' => count($filas)];
}
