<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['paciente']);
require_once __DIR__ . '/includes/pagos_ui.php';
cvp_solo_metodos(['GET','POST']);
$pdo = cvs_conexion(); cvp_requerir_modulo($pdo);
$error = null; $resumen = null; $claveReserva = null; $paciente = null;
$datos = ['id_especialidad'=>cvs_texto($_GET,'id_especialidad'), 'id_medico'=>cvs_texto($_GET,'id_medico'),
    'fecha'=>cvs_texto($_GET,'fecha') ?: (new DateTimeImmutable('today',new DateTimeZone('America/La_Paz')))->format('Y-m-d'), 'hora_inicio'=>cvs_texto($_GET,'hora_inicio')];
try {
    $paciente = cvp_paciente($pdo, $usuario);
    cvp_expirar($pdo);
    if (isset($_GET['consulta'])) {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_GET['consulta'] === 'medicos') {
            $q = $pdo->prepare("SELECT m.id_medico,m.nombres,m.apellidos,t.importe FROM medicos m
                JOIN medico_especialidad me USING(id_medico) JOIN especialidades e USING(id_especialidad)
                LEFT JOIN tarifas_consulta t ON t.id_medico=me.id_medico AND t.id_especialidad=me.id_especialidad
                WHERE m.activo AND e.activo AND me.id_especialidad=:id ORDER BY m.apellidos,m.nombres");
            $q->execute([':id'=>cvp_id($_GET['id_especialidad'] ?? null)]); $salida = $q->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($_GET['consulta'] === 'bloques') {
            $tarifa = cvp_tarifa($pdo, cvp_id($_GET['id_medico'] ?? null), cvp_id($_GET['id_especialidad'] ?? null));
            $salida = ['tarifa'=>$tarifa['importe'], 'bloques'=>cvp_bloques($pdo,cvp_id($_GET['id_medico'] ?? null),cvs_texto($_GET,'fecha'))];
        } else { throw new DomainException('Consulta inválida.'); }
        echo json_encode($salida, JSON_THROW_ON_ERROR); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = cvs_texto($_POST,'accion');
        if ($accion === 'confirmar') {
            $claveReserva = cvp_validar_post('reserva');
            $cotizacion = $_SESSION['cvp_cotizaciones'][$claveReserva] ?? null;
            if (!is_array($cotizacion)) throw new DomainException('El resumen venció. Vuelve a seleccionar la consulta.');
            $datos = $cotizacion['datos'];
            $datos['modalidad'] = cvs_texto($_POST,'modalidad');
            $datos['metodo_online'] = $datos['modalidad'] === 'demo' ? cvs_texto($_POST,'metodo_online') : '';
            if ($datos['modalidad'] === 'demo' && (!cvp_online_disponible($pdo) || !isset(CVP_ONLINE[$datos['metodo_online']]))) throw new DomainException('Selecciona Tarjeta o QR. Si no están disponibles, solicita la actualización del módulo.');
            $id = cvp_reservar($pdo,(int)$usuario['id_usuario'],$datos,$claveReserva,$cotizacion['importe']);
            cvp_redirigir_pago($id,$datos['modalidad'] === 'demo' ? 'Reserva creada. Completa el pago en línea antes de que venza el plazo.' : 'Cita registrada. El pago se realizará en recepción.');
        } elseif ($accion === 'resumir') {
            cvp_validar_post('seleccion');
            foreach (array_keys($datos) as $campo) $datos[$campo] = cvs_texto($_POST,$campo);
            $tarifa = cvp_tarifa($pdo,cvp_id($datos['id_medico']),cvp_id($datos['id_especialidad']));
            $bloque = cvp_bloque($pdo,cvp_id($datos['id_medico']),$datos['fecha'],$datos['hora_inicio']);
            $resumen = array_merge($tarifa,$bloque,['paciente'=>$paciente['nombres'].' '.$paciente['apellidos'],'fecha'=>$datos['fecha']]);
            $claveReserva = cvp_formulario('reserva');
            $_SESSION['cvp_cotizaciones'][$claveReserva] = ['datos'=>$datos,'importe'=>$tarifa['importe']];
            while (count($_SESSION['cvp_cotizaciones']) > 10) array_shift($_SESSION['cvp_cotizaciones']);
        } else { throw new DomainException('Acción no válida.'); }
    }
} catch (Throwable $ex) {
    $error = $ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'reserva_paciente');
    if (isset($_GET['consulta'])) { http_response_code(400); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['error'=>$error]); exit; }
}
$especialidades = $pdo->query('SELECT id_especialidad,nombre FROM especialidades WHERE activo ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
cvp_inicio('reservar_cita','Reservar cita'); cvp_mensaje($error);
?>
<section class="panel">
    <h1>Reservar cita</h1>
    <p>Elige tu consulta, revisa el importe y decide cómo pagar. La reserva se registra exclusivamente a nombre de tu ficha vinculada.</p>
    <ol class="cvp-pasos"><li>Especialidad y médico</li><li>Fecha y bloque</li><li>Resumen y pago</li></ol>
<?php if (!$paciente): ?>
    <p class="aviso">Necesitas una ficha de paciente activa vinculada. Solicítala a administración.</p>
<?php elseif ($resumen): ?>
    <h2>Confirmar reserva</h2>
    <?php cvp_resumen($resumen); ?>
    <p class="pequeno">Datos de contacto de tu ficha: <?= cvs_escapar($paciente['telefono']) ?> · <?= cvs_escapar($paciente['correo'] ?: 'Sin correo registrado') ?>.</p>
    <p>Este resumen todavía no bloquea el horario. Disponibilidad y tarifa se validarán nuevamente al confirmar.</p>
    <form method="post" action="reservar_cita.php" class="cvp-envio">
        <?php cvp_ocultos($claveReserva); ?>
        <input type="hidden" name="accion" value="confirmar">
        <fieldset><legend>¿Cómo deseas pagar?</legend>
            <?php if (cvp_demo_habilitado() && cvp_online_disponible($pdo)): ?>
            <label class="cvp-opcion"><input type="radio" name="modalidad" value="demo" required <?= cvs_texto($_POST,'modalidad') === 'demo' ? 'checked' : '' ?>><span><strong>Pago en línea</strong><br>Completa tu operación en un plazo de hasta 10 minutos.</span></label>
            <fieldset class="cvp-submetodos" id="cvp-metodos-online"><legend>Método de pago en línea</legend>
                <label class="cvp-opcion"><input type="radio" name="metodo_online" value="tarjeta" <?= cvs_texto($_POST,'metodo_online') !== 'qr' ? 'checked' : '' ?>><span>▣ Tarjeta de crédito o débito</span></label>
                <label class="cvp-opcion"><input type="radio" name="metodo_online" value="qr" <?= cvs_texto($_POST,'metodo_online') === 'qr' ? 'checked' : '' ?>><span>▦ Código QR</span></label>
                <small class="pequeno cvp-nota-academica">Presentación académica · Confirmación sujeta a verificación administrativa.</small>
            </fieldset>
            <?php endif; ?>
            <label class="cvp-opcion"><input type="radio" name="modalidad" value="presencial" required <?= cvs_texto($_POST,'modalidad') !== 'demo' ? 'checked' : '' ?>><span><strong>Pago presencial</strong><br>Tu cita queda registrada. Paga en recepción el día de la consulta.</span></label>
        </fieldset>
        <div class="acciones"><button type="submit">Confirmar reserva</button><a class="boton secundario" href="reservar_cita.php?<?= cvs_escapar(http_build_query($datos)) ?>">Volver a la selección</a></div>
    </form>
<?php else: ?>
    <form method="post" action="reservar_cita.php" id="cvp-seleccion" data-medico="<?= cvs_escapar($datos['id_medico']) ?>" data-hora="<?= cvs_escapar($datos['hora_inicio']) ?>">
        <?php cvp_ocultos(cvp_formulario('seleccion')); ?>
        <input type="hidden" name="accion" value="resumir">
        <div class="campos">
            <div class="campo"><label for="cvp-especialidad">Especialidad</label><select id="cvp-especialidad" name="id_especialidad" required><option value="">Selecciona...</option><?php foreach ($especialidades as $e): ?><option value="<?= (int)$e['id_especialidad'] ?>" <?= (string)$e['id_especialidad'] === $datos['id_especialidad'] ? 'selected' : '' ?>><?= cvs_escapar($e['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="campo"><label for="cvp-medico">Médico</label><select id="cvp-medico" name="id_medico" required><option value="">Selecciona primero una especialidad</option></select></div>
            <div class="campo"><label for="cvp-fecha">Fecha · America/La_Paz</label><input id="cvp-fecha" name="fecha" type="date" required value="<?= cvs_escapar($datos['fecha']) ?>" min="<?= cvs_escapar((new DateTimeImmutable('today',new DateTimeZone('America/La_Paz')))->format('Y-m-d')) ?>"></div>
        </div>
        <p id="cvp-precio" class="cvp-precio" aria-live="polite">Selecciona una consulta para ver su tarifa.</p>
        <fieldset><legend>Horarios disponibles</legend><div id="cvp-bloques" class="cvp-bloques" aria-live="polite">Selecciona médico y fecha.</div></fieldset>
        <p id="cvp-error" role="alert"></p>
        <div class="acciones"><button type="submit">Revisar resumen</button><a class="boton secundario" href="mis_citas.php">Mis citas y perfil</a></div>
        <noscript>Activa JavaScript para consultar médicos y disponibilidad. La confirmación se valida siempre en el servidor.</noscript>
    </form>
<?php endif; ?>
</section>
<script src="assets/reservas.js" defer></script>
<script src="assets/pagos.js" defer></script>
<?php cvp_fin(); ?>
