<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pasarela_demo.php';

const CVP_ESTADOS = ['pendiente','procesando','pagado','fallido','vencido','anulado','reembolso_pendiente','reembolsado'];
const CVP_METODOS = ['efectivo'=>'Efectivo presencial', 'qr_recepcion'=>'QR presencial verificado'];
const CVP_DEMO_AVISO = 'Entorno de pruebas · Sin transacciones bancarias';
const CVP_ONLINE = ['tarjeta'=>'Tarjeta', 'qr'=>'QR en línea'];

function cvp_online_disponible(PDO $pdo): bool {
    return cvs_activo($pdo->query("SELECT to_regclass('public.solicitudes_pago_prueba') IS NOT NULL")->fetchColumn());
}

function cvp_fecha_visible(?string $valor, bool $hora = true): string {
    if (!$valor) return '—';
    try {
        return (new DateTimeImmutable($valor, new DateTimeZone('America/La_Paz')))
            ->setTimezone(new DateTimeZone('America/La_Paz'))->format($hora ? 'd/m/Y, H:i' : 'd/m/Y');
    } catch (Exception $ex) { return '—'; }
}

function cvp_metodo(array $p): string {
    if ($p['modalidad'] === 'demo') return isset(CVP_ONLINE[$p['metodo_online'] ?? ''])
        ? CVP_ONLINE[$p['metodo_online']] . ' de prueba' : 'En línea · histórico de prueba';
    return CVP_METODOS[$p['metodo'] ?? ''] ?? 'Presencial · por registrar';
}

final class CvpAccesoDenegado extends DomainException {}

function cvp_disponible(PDO $pdo): bool {
    return cvs_activo($pdo->query("SELECT to_regclass('public.pagos') IS NOT NULL
        AND to_regprocedure('public.cvs_expirar_reservas()') IS NOT NULL")->fetchColumn());
}

function cvp_demo_habilitado(): bool { return getenv('CVS_PAGOS_DEMO') !== '0'; }

function cvp_id(mixed $valor): int {
    if (!is_string($valor) && !is_int($valor)) return 0;
    return preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$valor)
        ? (filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>2147483647]]) ?: 0) : 0;
}

/** Nunca convertir importes monetarios a float. Máximo NUMERIC(12,2). */
function cvp_importe(mixed $valor): string {
    if (!is_string($valor) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/', $valor)) {
        throw new DomainException('Importe inválido: utiliza un número positivo con hasta dos decimales y punto decimal.');
    }
    [$entero,$decimal] = array_pad(explode('.', $valor), 2, '');
    $normalizado = $entero . '.' . str_pad($decimal, 2, '0');
    if ($normalizado === '0.00') throw new DomainException('La tarifa debe ser mayor que cero.');
    return $normalizado;
}

function cvp_bob(mixed $valor): string {
    $texto = (string)($valor ?? '0.00');
    if (!preg_match('/\A(-?)([0-9]+)(?:\.([0-9]{1,2}))?\z/', $texto, $m)) return 'Importe no disponible';
    return 'Bs ' . $m[1] . preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $m[2])
        . ',' . str_pad($m[3] ?? '', 2, '0');
}

function cvp_clave(mixed $clave): string {
    if (!is_string($clave) || !preg_match('/\A[a-f0-9]{64}\z/', $clave)) throw new DomainException('Identificador de operación inválido. Recarga el formulario.');
    return $clave;
}

function cvp_error(Throwable $ex, string $operacion): string {
    $codigo = $ex instanceof PDOException ? (string)$ex->getCode() : '';
    error_log('Reservas/pagos: ' . $operacion . (preg_match('/\A[A-Z0-9]{5}\z/', $codigo) ? '; SQLSTATE ' . $codigo : ''));
    return match ($codigo) {
        '23P01' => 'Otro usuario ocupó ese horario. Selecciona un bloque disponible.',
        '23505' => 'La referencia u operación ya está registrada. Consulta su estado antes de repetir.',
        '40P01', '40001', '55P03' => 'La operación coincidió con otro cambio. Actualiza el formulario y vuelve a intentarlo.',
        default => 'No fue posible completar la operación. No se confirmó ningún cambio; revisa su estado antes de reintentar.'
    };
}

/** Todas las escrituras de agenda toman el mismo candado ANTES de bloquear filas. */
function cvp_bloquear(PDO $pdo, ?int $actor = null): void {
    if (!$pdo->inTransaction()) throw new LogicException('Se requiere una transacción.');
    $pdo->query('SELECT pg_advisory_xact_lock(1129729107, 701)');
    if ($actor !== null) {
        $q = $pdo->prepare("SELECT set_config('cvs.actor_id', :actor, true)");
        $q->execute([':actor'=>(string)$actor]);
    }
    $pdo->query('SELECT cvs_expirar_reservas()');
}

function cvp_expirar(PDO $pdo): void {
    if ($pdo->inTransaction()) { cvp_bloquear($pdo); return; }
    $pdo->beginTransaction();
    try { cvp_bloquear($pdo); $pdo->commit(); }
    catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

function cvp_actor(PDO $pdo, int $id, array $roles, bool $bloquear = true): array {
    $q = $pdo->prepare('SELECT u.id_usuario, u.activo, r.nombre AS rol FROM usuarios u JOIN roles r USING (id_rol)
        WHERE u.id_usuario=:id' . ($bloquear ? ' FOR SHARE OF u, r' : ''));
    $q->execute([':id'=>$id]); $actor = $q->fetch(PDO::FETCH_ASSOC);
    if (!$actor || !cvs_activo($actor['activo']) || !in_array($actor['rol'], $roles, true)) {
        throw new CvpAccesoDenegado('Tu cuenta no tiene permiso para realizar esta operación.');
    }
    return $actor;
}

function cvp_paciente(PDO $pdo, array $actor, ?int $solicitado = null, bool $bloquear = false): array {
    if ($actor['rol'] === 'paciente') {
        $sql = 'id_usuario=:id'; $id = (int)$actor['id_usuario'];
    } else {
        $sql = 'id_paciente=:id'; $id = $solicitado ?? 0;
    }
    $q = $pdo->prepare('SELECT id_paciente, nombres, apellidos, telefono, correo, fecha_nacimiento FROM pacientes
        WHERE activo=TRUE AND ' . $sql . ($bloquear ? ' FOR SHARE' : ''));
    $q->execute([':id'=>$id]); $paciente = $q->fetch(PDO::FETCH_ASSOC);
    if (!$paciente) throw new DomainException('Se requiere una ficha de paciente activa y vinculada. Solicita revisión a administración.');
    return $paciente;
}

function cvp_tarifa(PDO $pdo, int $medico, int $especialidad, bool $bloquear = false): array {
    $q = $pdo->prepare("SELECT t.importe, t.moneda, m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad
        FROM tarifas_consulta t JOIN medicos m USING(id_medico) JOIN especialidades e USING(id_especialidad)
        WHERE t.id_medico=:medico AND t.id_especialidad=:especialidad AND m.activo AND e.activo"
        . ($bloquear ? ' FOR SHARE OF t, m, e' : ''));
    $q->execute([':medico'=>$medico, ':especialidad'=>$especialidad]); $tarifa = $q->fetch(PDO::FETCH_ASSOC);
    if (!$tarifa) throw new DomainException('La combinación médico/especialidad no tiene una tarifa activa configurada.');
    $tarifa['importe'] = cvp_importe($tarifa['importe']);
    return $tarifa;
}

function cvp_guardar_tarifa(PDO $pdo, int $actorId, int $medico, int $especialidad, string $importe): void {
    $importe = cvp_importe($importe);
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId); cvp_actor($pdo, $actorId, ['administrador']);
        $q = $pdo->prepare('SELECT 1 FROM medico_especialidad WHERE id_medico=:m AND id_especialidad=:e FOR SHARE');
        $q->execute([':m'=>$medico, ':e'=>$especialidad]);
        if (!$q->fetchColumn()) throw new DomainException('La asignación médico/especialidad no existe.');
        $q = $pdo->prepare('SELECT importe FROM tarifas_consulta WHERE id_medico=:m AND id_especialidad=:e FOR UPDATE');
        $q->execute([':m'=>$medico, ':e'=>$especialidad]); $anterior = $q->fetchColumn();
        if ($anterior !== $importe) {
            $q = $pdo->prepare('INSERT INTO tarifas_consulta(id_medico,id_especialidad,importe,actualizado_por)
                VALUES (:m,:e,:importe,:actor) ON CONFLICT(id_medico,id_especialidad) DO UPDATE
                SET importe=EXCLUDED.importe, actualizado_por=EXCLUDED.actualizado_por, actualizado_en=clock_timestamp()');
            $q->execute([':m'=>$medico, ':e'=>$especialidad, ':importe'=>$importe, ':actor'=>$actorId]);
            $q = $pdo->prepare('INSERT INTO historial_tarifas(id_medico,id_especialidad,importe_anterior,importe_nuevo,responsable)
                VALUES (:m,:e,:antes,:nuevo,:actor)');
            $q->execute([':m'=>$medico, ':e'=>$especialidad, ':antes'=>$anterior === false ? null : $anterior, ':nuevo'=>$importe, ':actor'=>$actorId]);
        }
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

function cvp_fecha(string $fecha): DateTimeImmutable {
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone('America/La_Paz'));
    if (!$f || !preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/', $fecha) || $f->format('Y-m-d') !== $fecha) {
        throw new DomainException('Selecciona una fecha válida (años 2000–2099).');
    }
    return $f;
}

/** Misma regla de bloques completos/alineados del núcleo, con ocupación común. */
function cvp_bloques(PDO $pdo, int $medico, string $fecha, int $excluirCita = 0): array {
    $f = cvp_fecha($fecha);
    $ahora = new DateTimeImmutable($pdo->query("SELECT to_char(timezone('America/La_Paz',clock_timestamp()), 'YYYY-MM-DD HH24:MI:SS')")->fetchColumn(), $f->getTimezone());
    if ($f < $ahora->setTime(0,0)) return [];
    $q = $pdo->prepare('SELECT hora_inicio,hora_fin,duracion_minutos FROM horarios WHERE id_medico=:m AND dia_semana=:dia AND activo ORDER BY hora_inicio'
        . ($pdo->inTransaction() ? ' FOR SHARE' : ''));
    $q->execute([':m'=>$medico, ':dia'=>(int)$f->format('N')]); $horarios = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT lower(b.intervalo) AS inicio, upper(b.intervalo) AS fin FROM bloqueos_agenda b
        LEFT JOIN reservas_temporales r USING(id_reserva)
        WHERE b.id_medico=:m AND b.activo AND (b.id_cita IS NULL OR b.id_cita<>:excluir)
        AND (r.id_reserva IS NULL OR (r.estado='activa' AND r.vence_en>clock_timestamp()))
        AND b.intervalo && tsrange(CAST(:fecha AS DATE), CAST(:fin AS DATE)+INTERVAL '1 day','[)')");
    $q->execute([':m'=>$medico, ':excluir'=>$excluirCita, ':fecha'=>$fecha, ':fin'=>$fecha]); $ocupados = $q->fetchAll(PDO::FETCH_ASSOC);
    $bloques = [];
    foreach ($horarios as $h) {
        $duracion = (int)$h['duracion_minutos'];
        if ($duracion <= 0) continue;
        $cursor = new DateTimeImmutable($fecha . ' ' . $h['hora_inicio'], $f->getTimezone());
        $limite = new DateTimeImmutable($fecha . ' ' . $h['hora_fin'], $f->getTimezone());
        while (($fin = $cursor->modify('+' . $duracion . ' minutes')) <= $limite) {
            $libre = $cursor > $ahora;
            foreach ($ocupados as $o) {
                if ($cursor < new DateTimeImmutable($o['fin'], $f->getTimezone()) && $fin > new DateTimeImmutable($o['inicio'], $f->getTimezone())) { $libre = false; break; }
            }
            if ($libre) $bloques[] = ['hora_inicio'=>$cursor->format('H:i'), 'hora_fin'=>$fin->format('H:i'), 'duracion'=>$duracion];
            $cursor = $fin;
        }
    }
    return $bloques;
}

function cvp_bloque(PDO $pdo, int $medico, string $fecha, string $hora): array {
    foreach (cvp_bloques($pdo, $medico, $fecha) as $b) if ($b['hora_inicio'] === $hora) return $b;
    throw new DomainException('El bloque ya no está disponible, está en el pasado o no corresponde al horario activo.');
}

function cvp_movimiento(PDO $pdo, int $pago, string $tipo, ?string $antes, string $despues, ?int $actor, string $clave, string $importe = '0.00', ?string $referencia = null): void {
    $q = $pdo->prepare('INSERT INTO movimientos_pago(id_pago,tipo,estado_anterior,estado_nuevo,responsable,clave_evento,importe,referencia)
        VALUES (:p,:tipo,:antes,:despues,:actor,:clave,:importe,:ref)');
    $q->execute([':p'=>$pago, ':tipo'=>$tipo, ':antes'=>$antes, ':despues'=>$despues, ':actor'=>$actor, ':clave'=>$clave, ':importe'=>$importe, ':ref'=>$referencia]);
}

function cvp_insertar_cita(PDO $pdo, int $paciente, int $medico, int $especialidad, string $fecha, string $inicio, string $fin, string $estado, ?int $id = null, ?string $motivo = null): int {
    $q = $pdo->prepare('INSERT INTO citas(' . ($id ? 'id_cita,' : '') . 'codigo,id_paciente,id_medico,id_especialidad,fecha,hora_inicio,hora_fin,estado,motivo) '
        . ($id ? 'OVERRIDING SYSTEM VALUE ' : '') . 'VALUES (' . ($id ? ':id,' : '') . ':codigo,:paciente,:medico,:especialidad,:fecha,:inicio,:fin,:estado,:motivo) RETURNING id_cita');
    $params = [':codigo'=>'CITA-' . strtoupper(bin2hex(random_bytes(10))), ':paciente'=>$paciente, ':medico'=>$medico,
        ':especialidad'=>$especialidad, ':fecha'=>$fecha, ':inicio'=>$inicio, ':fin'=>$fin, ':estado'=>$estado, ':motivo'=>$motivo];
    if ($id) $params[':id'] = $id;
    $q->execute($params); return (int)$q->fetchColumn();
}

function cvp_reservar(PDO $pdo, int $actorId, array $datos, string $clave, ?string $precioEsperado = null): int {
    cvp_clave($clave);
    $medico = cvp_id($datos['id_medico'] ?? null); $especialidad = cvp_id($datos['id_especialidad'] ?? null);
    $fecha = cvs_texto($datos, 'fecha'); $inicio = cvs_texto($datos, 'hora_inicio'); $modalidad = cvs_texto($datos, 'modalidad');
    $motivo=cvs_texto($datos,'motivo');
    $online = cvs_texto($datos,'metodo_online');
    $ampliado = cvp_online_disponible($pdo);
    if ($online !== '' && (!$ampliado || $modalidad !== 'demo' || !isset(CVP_ONLINE[$online]))) {
        throw new DomainException('Selecciona un método de pago en línea disponible.');
    }
    if (strlen($motivo)>255) throw new DomainException('El motivo supera la longitud permitida.');
    cvp_fecha($fecha);
    if (!$medico || !$especialidad || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $inicio)
        || !in_array($modalidad, ['presencial','demo'], true)) throw new DomainException('Completa la selección de consulta y modalidad.');
    if ($modalidad === 'demo' && !cvp_demo_habilitado()) throw new DomainException('Los pagos de demostración están deshabilitados.');
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId);
        $actor = cvp_actor($pdo, $actorId, ['administrador','recepcionista','paciente']);
        $paciente = cvp_paciente($pdo, $actor, cvp_id($datos['id_paciente'] ?? null), true);
        $huella = hash('sha256', json_encode([$actorId,$paciente['id_paciente'],$medico,$especialidad,$fecha,$inicio,$modalidad,$motivo], JSON_THROW_ON_ERROR));
        if ($online !== '') $huella = hash('sha256', $huella . ':' . $online);
        $q = $pdo->prepare('SELECT id_pago,creado_por,huella_solicitud FROM pagos WHERE idempotencia=:clave');
        $q->execute([':clave'=>$clave]); $existente = $q->fetch(PDO::FETCH_ASSOC);
        if ($existente) {
            if ((int)$existente['creado_por'] !== $actorId || !hash_equals($existente['huella_solicitud'], $huella)) throw new DomainException('El formulario ya se utilizó para otra reserva. Abre uno nuevo.');
            $pdo->commit(); return (int)$existente['id_pago'];
        }
        $tarifa = cvp_tarifa($pdo, $medico, $especialidad, true);
        if ($precioEsperado !== null && $tarifa['importe'] !== cvp_importe($precioEsperado)) throw new DomainException('La tarifa cambió después del resumen. Vuelve a consultar el precio antes de confirmar.');
        $bloque = cvp_bloque($pdo, $medico, $fecha, $inicio);
        $cita = null; $reserva = null;
        if ($modalidad === 'presencial') {
            $cita = cvp_insertar_cita($pdo, (int)$paciente['id_paciente'], $medico, $especialidad, $fecha, $inicio, $bloque['hora_fin'], 'programada', null, $motivo ?: null);
        } else {
            $q = $pdo->prepare("INSERT INTO reservas_temporales(codigo,id_paciente,creado_por,id_medico,id_especialidad,fecha,hora_inicio,hora_fin,vence_en)
                VALUES (:codigo,:paciente,:actor,:medico,:especialidad,:fecha,:inicio,:fin,clock_timestamp()+INTERVAL '10 minutes') RETURNING id_reserva");
            $q->execute([':codigo'=>'RES-' . strtoupper(bin2hex(random_bytes(10))), ':paciente'=>$paciente['id_paciente'], ':actor'=>$actorId,
                ':medico'=>$medico, ':especialidad'=>$especialidad, ':fecha'=>$fecha, ':inicio'=>$inicio, ':fin'=>$bloque['hora_fin']]);
            $reserva = (int)$q->fetchColumn();
        }
        $q = $pdo->prepare('INSERT INTO pagos(referencia,id_cita,id_reserva,id_paciente,creado_por,importe,modalidad,idempotencia,huella_solicitud'
            . ($ampliado ? ',metodo_online' : '') . ')
            VALUES (:ref,:cita,:reserva,:paciente,:actor,:importe,:modalidad,:clave,:huella' . ($ampliado ? ',:online' : '') . ') RETURNING id_pago');
        $params = [':ref'=>'PAGO-' . strtoupper(bin2hex(random_bytes(12))), ':cita'=>$cita, ':reserva'=>$reserva,
            ':paciente'=>$paciente['id_paciente'], ':actor'=>$actorId, ':importe'=>$tarifa['importe'], ':modalidad'=>$modalidad, ':clave'=>$clave, ':huella'=>$huella];
        if ($ampliado) $params[':online'] = $online ?: null;
        $q->execute($params);
        $id = (int)$q->fetchColumn(); cvp_movimiento($pdo, $id, 'creacion', null, 'pendiente', $actorId, 'creacion:' . $id);
        $pdo->commit(); return $id;
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

/** No devuelve datos de terceros: mismo control para ficha, comprobante y acciones. */
function cvp_pago(PDO $pdo, int $id, int $actorId, bool $bloquear = false): array {
    $actor = cvp_actor($pdo, $actorId, ['administrador','recepcionista','paciente'], $bloquear);
    $q = $pdo->prepare("SELECT p.*, c.codigo AS codigo_cita, c.estado AS estado_cita,
        COALESCE(c.fecha,r.fecha) AS fecha, COALESCE(c.hora_inicio,r.hora_inicio) AS hora_inicio,
        COALESCE(c.hora_fin,r.hora_fin) AS hora_fin, r.estado AS estado_reserva, r.vence_en,
        pa.nombres || ' ' || pa.apellidos AS paciente, m.nombres || ' ' || m.apellidos AS medico,
        e.nombre AS especialidad, r.codigo AS codigo_reserva,
        (SELECT nombre_usuario FROM usuarios WHERE id_usuario=p.responsable_cobro) AS responsable_nombre,
        EXTRACT(EPOCH FROM clock_timestamp())::bigint AS servidor_epoch
        FROM pagos p JOIN pacientes pa ON pa.id_paciente=p.id_paciente
        LEFT JOIN citas c ON c.id_cita=p.id_cita LEFT JOIN reservas_temporales r ON r.id_reserva=p.id_reserva
        JOIN medicos m ON m.id_medico=COALESCE(c.id_medico,r.id_medico)
        JOIN especialidades e ON e.id_especialidad=COALESCE(c.id_especialidad,r.id_especialidad)
        WHERE p.id_pago=:id" . ($actor['rol'] === 'paciente' ? ' AND pa.id_usuario=:usuario AND pa.activo=TRUE' : '')
        . ($bloquear ? ' FOR UPDATE OF p' : ''));
    $params = [':id'=>$id]; if ($actor['rol'] === 'paciente') $params[':usuario']=$actorId;
    $q->execute($params); $pago = $q->fetch(PDO::FETCH_ASSOC);
    if (!$pago) throw new DomainException('Operación no disponible para tu cuenta.');
    return $pago;
}

function cvp_cobrar(PDO $pdo, int $actorId, int $id, string $metodo, string $referencia, bool $verificado): void {
    if (!isset(CVP_METODOS[$metodo]) || !$verificado) throw new DomainException('Selecciona un método y confirma que el cobro fue verificado presencialmente.');
    $referencia = trim($referencia);
    if (!preg_match('/\A[A-Za-z0-9._\/-]{0,100}\z/', $referencia) || ($metodo === 'qr_recepcion' && $referencia === '')) {
        throw new DomainException('QR requiere referencia verificada. Utiliza hasta 100 letras, números, puntos, guiones o barras, sin datos bancarios.');
    }
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId); cvp_actor($pdo, $actorId, ['administrador','recepcionista']);
        $p = cvp_pago($pdo, $id, $actorId, true);
        if ($p['modalidad'] !== 'presencial') throw new DomainException('Esta operación no corresponde a un cobro presencial.');
        if ($p['confirmado_en'] !== null) { $pdo->commit(); return; } // Reenvío: nunca otro cobro.
        $q = $pdo->prepare('SELECT estado FROM citas WHERE id_cita=:id FOR UPDATE'); $q->execute([':id'=>$p['id_cita']]);
        if ($q->fetchColumn() === 'cancelada' || $p['estado'] !== 'pendiente') throw new DomainException('La cita cancelada o el estado actual no permiten cobrar.');
        $referencia = $referencia ?: 'CAJA-' . strtoupper(bin2hex(random_bytes(12)));
        $q = $pdo->prepare("UPDATE pagos SET estado='pagado',metodo=:metodo,referencia_cobro=:ref,responsable_cobro=:actor,
            confirmado_en=clock_timestamp(),actualizado_en=clock_timestamp() WHERE id_pago=:id");
        $q->execute([':metodo'=>$metodo, ':ref'=>$referencia, ':actor'=>$actorId, ':id'=>$id]);
        cvp_movimiento($pdo, $id, 'cobro', $p['estado'], 'pagado', $actorId, 'cobro:' . $id, $p['importe'], $referencia);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

/** Solo administración simula al proveedor; no hay endpoint público de aprobación. */
function cvp_solicitar_tarjeta(PDO $pdo, int $actorId, int $id, string $referenciaPrueba): void {
    if ($referenciaPrueba !== 'CVS-TARJETA-SINTETICA-V1' || !cvp_demo_habilitado()) {
        throw new DomainException('Instrumento de prueba no admitido.');
    }
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId);
        $p = cvp_pago($pdo, $id, $actorId, true);
        if (($p['metodo_online'] ?? null) !== 'tarjeta' || $p['modalidad'] !== 'demo') throw new DomainException('La operación no corresponde a tarjeta.');
        $q = $pdo->prepare('SELECT 1 FROM solicitudes_pago_prueba WHERE id_pago=:id'); $q->execute([':id'=>$id]);
        if ($q->fetchColumn()) { $pdo->commit(); return; }
        if ($p['estado_reserva'] !== 'activa' || $p['estado'] !== 'pendiente') throw new DomainException('La reserva ya no admite solicitudes de pago.');
        $q = $pdo->prepare('INSERT INTO solicitudes_pago_prueba(id_pago,solicitado_por) VALUES (:id,:actor)');
        $q->execute([':id'=>$id, ':actor'=>$actorId]);
        $q = $pdo->prepare("UPDATE pagos SET estado='procesando',actualizado_en=clock_timestamp() WHERE id_pago=:id"); $q->execute([':id'=>$id]);
        cvp_movimiento($pdo,$id,'pendiente','pendiente','procesando',$actorId,'solicitud_tarjeta:'.$id);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

function cvp_simular(PDO $pdo, int $actorId, int $id, string $escenario, string $clave): void {
    cvp_clave($clave);
    if (!cvp_demo_habilitado()) throw new DomainException('La simulación está deshabilitada.');
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId); cvp_actor($pdo, $actorId, ['administrador']);
        $p = cvp_pago($pdo, $id, $actorId, true);
        if ($p['modalidad'] !== 'demo') throw new DomainException('No se puede simular un cobro presencial.');
        if (($p['metodo_online'] ?? null) === 'tarjeta' && in_array($escenario,['exitoso','fallido'],true)) {
            $q=$pdo->prepare('SELECT 1 FROM solicitudes_pago_prueba WHERE id_pago=:id'); $q->execute([':id'=>$id]);
            if (!$q->fetchColumn()) throw new DomainException('Primero debe enviarse la solicitud con el instrumento de prueba.');
        }
        $q = $pdo->prepare('SELECT id_pago,resultado FROM intentos_pago WHERE idempotencia=:clave'); $q->execute([':clave'=>$clave]);
        $previo = $q->fetch(PDO::FETCH_ASSOC);
        if ($previo) {
            if ((int)$previo['id_pago'] !== $id || $previo['resultado'] !== $escenario) throw new DomainException('La acción ya fue utilizada para otra respuesta.');
            $pdo->commit(); return;
        }
        if ($p['confirmado_en'] !== null) { $pdo->commit(); return; }
        $resultado = (new CvsPasarelaDemostracion())->simular($escenario);
        $q = $pdo->prepare("INSERT INTO intentos_pago(id_pago,proveedor,referencia_externa,resultado,idempotencia,responsable)
            VALUES (:id,'demostracion',:ref,:resultado,:clave,:actor)");
        $q->execute([':id'=>$id, ':ref'=>$resultado['referencia'], ':resultado'=>$escenario, ':clave'=>$clave, ':actor'=>$actorId]);
        $q = $pdo->prepare("SELECT *, (estado='activa' AND vence_en>clock_timestamp()
            AND fecha+hora_inicio>timezone('America/La_Paz',clock_timestamp())) AS vigente
            FROM reservas_temporales WHERE id_reserva=:id FOR UPDATE");
        $q->execute([':id'=>$p['id_reserva']]); $r = $q->fetch(PDO::FETCH_ASSOC);
        $vigente = cvs_activo($r['vigente']); $nuevo = $p['estado']; $tipo = 'pendiente'; $importe = '0.00';
        if ($vigente && $escenario === 'exitoso') {
            // No recalcular el precio: sí revalidar que la oferta y la ficha aún sean utilizables.
            $q=$pdo->prepare("SELECT h.id_horario FROM horarios h JOIN medicos m USING(id_medico)
                JOIN medico_especialidad me USING(id_medico) JOIN especialidades e USING(id_especialidad)
                JOIN pacientes pa ON pa.id_paciente=:paciente
                WHERE m.id_medico=:medico AND e.id_especialidad=:especialidad AND m.activo AND e.activo AND pa.activo
                AND h.activo AND h.dia_semana=EXTRACT(ISODOW FROM CAST(:fecha AS DATE))
                AND h.hora_inicio<=CAST(:inicio AS TIME) AND h.hora_fin>=CAST(:fin AS TIME)
                AND EXTRACT(EPOCH FROM (CAST(:fin_duracion AS TIME)-CAST(:inicio_duracion AS TIME)))=h.duracion_minutos*60
                AND MOD(EXTRACT(EPOCH FROM (CAST(:alineacion AS TIME)-h.hora_inicio))::integer,h.duracion_minutos*60)=0
                FOR SHARE OF h,m,me,e,pa");
            $q->execute([':paciente'=>$p['id_paciente'],':medico'=>$r['id_medico'],':especialidad'=>$r['id_especialidad'],
                ':fecha'=>$r['fecha'],':inicio'=>$r['hora_inicio'],':fin'=>$r['hora_fin'],':fin_duracion'=>$r['hora_fin'],
                ':inicio_duracion'=>$r['hora_inicio'],':alineacion'=>$r['hora_inicio']]);
            $vigente=(bool)$q->fetchColumn();
            if (!$vigente) {
                $q=$pdo->prepare("UPDATE reservas_temporales SET estado='fallida',actualizado_en=clock_timestamp() WHERE id_reserva=:id");
                $q->execute([':id'=>$r['id_reserva']]);
            }
        }
        if ($escenario === 'exitoso') {
            $nuevo = 'reembolso_pendiente'; $tipo = 'cobro_tardio'; $importe = $p['importe'];
            if ($vigente) {
                // Reservar el ID permite soltar el bloqueo temporal antes del INSERT sin
                // exponer una ventana: FK diferida + candado + transacción completa.
                $idCita = (int)$pdo->query("SELECT nextval(pg_get_serial_sequence('citas','id_cita'))")->fetchColumn();
                $q = $pdo->prepare("UPDATE reservas_temporales SET estado='convertida',id_cita=:cita,actualizado_en=clock_timestamp()
                    WHERE id_reserva=:id AND estado='activa' AND vence_en>clock_timestamp()
                    AND fecha+hora_inicio>timezone('America/La_Paz',clock_timestamp())");
                $q->execute([':cita'=>$idCita, ':id'=>$r['id_reserva']]);
                if ($q->rowCount()===1) {
                    cvp_insertar_cita($pdo, (int)$p['id_paciente'], (int)$r['id_medico'], (int)$r['id_especialidad'], $r['fecha'], $r['hora_inicio'], $r['hora_fin'], 'confirmada', $idCita);
                    $q = $pdo->prepare('UPDATE pagos SET id_cita=:cita WHERE id_pago=:id'); $q->execute([':cita'=>$idCita, ':id'=>$id]);
                    $nuevo = 'pagado'; $tipo = 'cobro';
                } else {
                    $pdo->query('SELECT cvs_expirar_reservas()');
                }
            }
            $q = $pdo->prepare("UPDATE pagos SET estado=:estado,metodo='demostracion',referencia_cobro=:ref,
                responsable_cobro=:actor,confirmado_en=clock_timestamp(),actualizado_en=clock_timestamp() WHERE id_pago=:id");
            $q->execute([':estado'=>$nuevo, ':ref'=>$resultado['referencia'], ':actor'=>$actorId, ':id'=>$id]);
        } elseif ($vigente && $escenario === 'vencido') {
            $q = $pdo->prepare("UPDATE reservas_temporales SET vence_en=clock_timestamp()-INTERVAL '1 second' WHERE id_reserva=:id");
            $q->execute([':id'=>$r['id_reserva']]); $pdo->query('SELECT cvs_expirar_reservas()');
            $pdo->commit(); return;
        } elseif ($vigente && $escenario === 'fallido') {
            $nuevo = 'fallido'; $tipo = 'fallo';
            $q = $pdo->prepare("UPDATE reservas_temporales SET estado='fallida',actualizado_en=clock_timestamp() WHERE id_reserva=:id");
            $q->execute([':id'=>$r['id_reserva']]);
            $q = $pdo->prepare('UPDATE pagos SET estado=:estado,actualizado_en=clock_timestamp() WHERE id_pago=:id'); $q->execute([':estado'=>$nuevo, ':id'=>$id]);
        } elseif ($vigente && $escenario === 'pendiente') {
            $nuevo = 'procesando';
            $q = $pdo->prepare("UPDATE pagos SET estado='procesando',actualizado_en=clock_timestamp() WHERE id_pago=:id"); $q->execute([':id'=>$id]);
        }
        cvp_movimiento($pdo, $id, $tipo, $p['estado'], $nuevo, $actorId, 'demo:' . $clave, $importe, $resultado['referencia']);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

function cvp_abandonar(PDO $pdo, int $actorId, int $id): void {
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId); $p = cvp_pago($pdo, $id, $actorId, true);
        if ($p['modalidad'] !== 'demo' || $p['estado_reserva'] !== 'activa' || $p['confirmado_en'] !== null) throw new DomainException('Esta reserva no se puede abandonar.');
        $q = $pdo->prepare("UPDATE reservas_temporales SET estado='cancelada',actualizado_en=clock_timestamp() WHERE id_reserva=:id"); $q->execute([':id'=>$p['id_reserva']]);
        $q = $pdo->prepare("UPDATE pagos SET estado='anulado',actualizado_en=clock_timestamp() WHERE id_pago=:id"); $q->execute([':id'=>$id]);
        cvp_movimiento($pdo, $id, 'cancelacion', $p['estado'], 'anulado', $actorId, 'abandono:' . $id);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}

function cvp_reembolso(PDO $pdo, int $actorId, int $id, string $accion, string $referencia, bool $verificado): void {
    if (!in_array($accion, ['completar','rechazar'], true)) throw new DomainException('Revisión inválida.');
    if ($accion === 'completar' && (!$verificado || !preg_match('/\A[A-Za-z0-9._\/-]{1,100}\z/', $referencia))) {
        throw new DomainException('Confirma la verificación de la devolución y su referencia (sin datos bancarios).');
    }
    $pdo->beginTransaction();
    try {
        cvp_bloquear($pdo, $actorId); cvp_actor($pdo, $actorId, ['administrador']); $p = cvp_pago($pdo, $id, $actorId, true);
        if ($p['estado'] === 'reembolsado' && $accion === 'completar') { $pdo->commit(); return; }
        if ($p['estado'] !== 'reembolso_pendiente') throw new DomainException('No hay una devolución pendiente para revisar.');
        $nuevo = $accion === 'completar' ? 'reembolsado' : 'pagado';
        $q = $pdo->prepare('UPDATE pagos SET estado=:estado,actualizado_en=clock_timestamp() WHERE id_pago=:id'); $q->execute([':estado'=>$nuevo, ':id'=>$id]);
        cvp_movimiento($pdo, $id, $accion === 'completar' ? 'reembolso' : 'rechazo_reembolso', $p['estado'], $nuevo, $actorId,
            'revision:' . $id . ':' . $nuevo, $accion === 'completar' ? $p['importe'] : '0.00', $referencia ?: null);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
}
