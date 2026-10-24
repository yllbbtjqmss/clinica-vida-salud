<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/dashboard.php';
$usuario = cvs_usuario_actual();
$nombresRoles = ['administrador'=>'Administrador', 'recepcionista'=>'Recepcionista', 'medico'=>'Médico', 'paciente'=>'Paciente'];

$menus = [
    'recepcionista' => [
        ['pacientes.php', 'Pacientes', 'Registrar y administrar los pacientes activos de la clínica.'],
        ['citas.php', 'Registrar citas', 'Programar nuevas citas para los pacientes.'],
        ['seleccionar_reprogramacion.php', 'Reprogramar citas', 'Selecciona la cita en el listado para asignar su nueva fecha.'],
        ['cancelar_cita.php', 'Cancelar citas', 'Cancelar citas vigentes de forma segura.'],
        ['asistencia_citas.php', 'Control de asistencia', 'Registrar asistencias e inasistencias de las citas.'],
        ['historial_citas.php', 'Historial de citas', 'Consultar el historial completo por paciente.']
    ]
];
$modulos = $usuario !== null ? ($menus[$usuario['rol']] ?? []) : [];

$avisoPersonal = null;
if ($usuario !== null && ($usuario['rol'] === 'medico' || $usuario['rol'] === 'paciente')) {
    $avisoPersonal = match ($usuario['rol']) {
        'medico' => 'Las funciones personales del rol de médico todavía no están integradas al sistema. No se otorga acceso a las agendas de otros médicos.',
        'paciente' => 'La consulta personal de tus citas estará disponible cuando se habilite la pantalla correspondiente.',
        default => 'Tu rol aún no tiene módulos disponibles en esta versión del sistema.'
    };
}

// ================= Panel administrativo (administrador y recepcionista) =================
$esPanel = $usuario !== null && ($usuario['rol'] === 'administrador' || $usuario['rol'] === 'recepcionista');

$panelError = false;
$totalPacientes = 0;
$hoy = null;
$citasHoy = [];
$mesInicio = null;
$diaSel = null;
$conteoMes = [];
$citasDia = [];
$maxMes = 0;
$actividad = [];
$disponibilidad = [];

if ($esPanel) {
    $hoy = cvsd_hoy();
    $mesInicio = cvsd_mes_desde_url((string) ($_GET['mes'] ?? ''), $hoy);
    $diaSel = cvsd_dia_desde_url((string) ($_GET['dia'] ?? ''), $hoy);

    // Al seleccionar un dia, el calendario se sita en el mes de ese da.
    if (isset($_GET['dia']) && trim((string) $_GET['dia']) !== '') {
        $mesInicio = $diaSel->setDate((int) $diaSel->format('Y'), (int) $diaSel->format('n'), 1);
    }

    try {
        $pdo = cvs_conexion();
        $totalPacientes = cvsd_pacientes_activos($pdo);
    $citasHoy = cvsd_citas_del_dia($pdo, $hoy->format('Y-m-d'));

    $finMes = DateTimeImmutable::createFromFormat('!Y-m-d', $mesInicio->format('Y-m-t'), $hoy->getTimezone());
        $conteoMes = cvsd_conteo_por_fecha($pdo, $mesInicio->format('Y-m-d'), $finMes->format('Y-m-d'));
        $maxMes = $conteoMes === [] ? 0 : max($conteoMes);

        $citasDia = cvsd_citas_del_dia($pdo, $diaSel->format('Y-m-d'));

        $actividad = cvsd_actividad_reciente($pdo, 8);
        $disponibilidad = cvsd_disponibilidad_del_dia($pdo, $hoy);
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $panelError = true;
        $citasHoy = [];
        $conteoMes = [];
        $citasDia = [];
        $actividad = [];
        $disponibilidad = [];
    }

    $saludo = match (true) {
        (int) cvsd_ahora()->format('G') < 12 => 'Buenos días,',
        (int) cvsd_ahora()->format('G') < 19 => 'Buenas tardes,',
        default => 'Buenas noches,'
    };
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inicio | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php cvsu_area('index', $esPanel ? 'Panel administrativo' : 'Menú principal'); ?>
<main class="ui-contenido">

<?php if ($esPanel): ?>
<section class="dash-banner">
<div class="dash-banner-texto">
<span class="dash-banner-rol"><?= cvs_escapar($nombresRoles[$usuario['rol']]) ?></span>
<h1><?= cvs_escapar($saludo) ?> <?= cvs_escapar($usuario['nombre_usuario']) ?></h1>
<p>Panorama del sistema con el resumen operativo de <?= cvs_escapar(cvsd_formato_fecha_larga($hoy)) ?>.</p>
</div>
<img class="dash-banner-imagen" src="assets/img/banner-medico.png" alt="" aria-hidden="true" loading="lazy">
</section>

<?php if ($panelError): ?>
<div class="dash-fallo" role="alert">No se pudieron consultar los datos del panel. Reintenta en unos minutos; los módulos normales siguen funcionando.</div>
<?php endif; ?>

<section class="dash-kpis" aria-label="Indicadores generales">
<div class="dash-kpi dash-kpi-turquesa">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<div>
<p class="dash-kpi-titulo">Pacientes activos</p>
<p class="dash-kpi-num"><?= (int) $totalPacientes ?></p>
<a class="dash-kpi-link" href="pacientes.php">Gestionar pacientes →</a>
</div>
</div>
<div class="dash-kpi">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('citas') ?></span>
<div>
<p class="dash-kpi-titulo">Citas de hoy</p>
<p class="dash-kpi-num"><?= count($citasHoy) ?></p>
<a class="dash-kpi-link" href="agenda_medica.php?fecha=<?= $hoy->format('Y-m-d') ?>">Ver agenda médica →</a>
</div>
</div>
</section>

<div class="dash-grid">
<div class="dash-col">

<!-- CALENDARIO INTERACTIVO -->
<section class="dash-panel" aria-labelledby="titulo-calendario">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('agenda') ?></span>
<h2 id="titulo-calendario">Calendario de citas</h2>
</div>
<div class="dash-cal-barra">
<p class="dash-cal-titulo"><?= cvs_escapar(CVSD_MESES[(int) $mesInicio->format('n')] . ' ' . $mesInicio->format('Y')) ?></p>
<div class="dash-cal-botones">
<?php
$mesAnterior = $mesInicio->modify('-1 month');
$mesSiguiente = $mesInicio->modify('+1 month');
$hoyMes = $hoy->setDate((int) $hoy->format('Y'), (int) $hoy->format('n'), 1);
?>
<a href="?mes=<?= $mesAnterior->format('Y-m') ?>" title="<?= cvs_escapar(CVSD_MESES[(int) $mesAnterior->format('n')] . ' ' . $mesAnterior->format('Y')) ?>" aria-label="Mes anterior"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5.5 8.5 12l6.5 6.5"/></svg></a>
<?php if ($mesInicio->format('Y-m') !== $hoyMes->format('Y-m')): ?>
<a href="?mes=<?= $hoyMes->format('Y-m') ?>" title="Volver al mes actual" aria-label="Mes actual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.3"/><path d="M12 8v4l2.6 1.7"/></svg></a>
<?php endif; ?>
<a href="?mes=<?= $mesSiguiente->format('Y-m') ?>" title="<?= cvs_escapar(CVSD_MESES[(int) $mesSiguiente->format('n')] . ' ' . $mesSiguiente->format('Y')) ?>" aria-label="Mes siguiente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5.5 6.5 6.5L9 20"/></svg></a>
</div>
</div>
<div class="dash-cal-dias" aria-hidden="true"><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span></div>
<div class="dash-cal-grid">
<?php
$primerLunes = $mesInicio->modify('-' . ((int) $mesInicio->format('N') - 1) . ' days');
$hoyYmd = $hoy->format('Y-m-d');
$selYmd = $diaSel->format('Y-m-d');
$cursor = $primerLunes;
for ($i = 0; $i < 42; $i++) {
    $enMes = (int) $cursor->format('n') === (int) $mesInicio->format('n') && (int) $cursor->format('Y') === (int) $mesInicio->format('Y');
    if ($i >= 28 && !$enMes && ($i % 7) === 0) {
        break;
    }
    if (!$enMes) {
        echo '<span class="dash-dia n0" aria-hidden="true"></span>';
        $cursor = $cursor->modify('+1 day');
        continue;
    }
    $fechaYmd = $cursor->format('Y-m-d');
    $cantidad = $conteoMes[$fechaYmd] ?? 0;
    $nivel = 0;
    if ($cantidad > 0 && $maxMes > 0) {
        $nivel = max(1, (int) ceil($cantidad / $maxMes * 3));
        if ($nivel > 3) {
            $nivel = 3;
        }
    }
    $clases = 'dash-dia n' . $nivel;
    if ($fechaYmd === $hoyYmd) {
        $clases .= ' hoy';
    }
    if ($fechaYmd === $selYmd) {
        $clases .= ' sel';
    }
    echo '<a class="' . $clases . '" href="?mes=' . $mesInicio->format('Y-m') . '&amp;dia=' . $fechaYmd . '" title="' . $fechaYmd . ': ' . $cantidad . ($cantidad === 1 ? ' cita' : ' citas') . '">';
    echo '<span class="dash-dia-num">' . (int) $cursor->format('j') . '</span>';
    if ($cantidad > 0) {
        echo '<span class="dash-dia-n">' . $cantidad . '</span>';
    } else {
        echo '<span class="dash-dia-n"> </span>';
    }
    echo '</a>';
    $cursor = $cursor->modify('+1 day');
}
?>
</div>
<ul class="dash-cal-leyenda">
<li><i class="l0"></i>Sin citas</li>
<li><i class="l1"></i>Pocas</li>
<li><i class="l2"></i>Media</li>
<li><i class="l3"></i>Muchas</li>
</ul>
<p class="dash-descripcion">La intensidad compara la cantidad real de citas de cada día del mes.</p>
<hr class="dash-cal-separador">
<p class="dash-cal-elegido-titulo"><?= cvs_escapar(cvsd_formato_fecha_larga($diaSel)) ?></p>
<p class="dash-cal-elegido-sub"><?= count($citasDia) === 1 ? '1 cita registrada' : count($citasDia) . ' citas registradas' ?> para esta fecha.</p>
<?php if ($citasDia === []): ?>
<div class="dash-hoy-vacio">
<span aria-hidden="true"><?= cvsu_icono('agenda') ?></span>
No hay citas registradas para este día.
</div>
<?php else: ?>
<ul class="dash-mini">
<?php foreach (array_slice($citasDia, 0, 8) as $cd): ?>
<li>
<span class="dash-hora"><?= cvs_escapar(cvsd_formato_hora($cd['hora_inicio'])) ?></span>
<span class="dash-mini-datos"><strong><?= cvs_escapar($cd['pac_nombres'] . ' ' . $cd['pac_apellidos']) ?></strong><span class="dash-sub"><?= cvs_escapar($cd['especialidad'] . ' · ' . $cd['med_nombres'] . ' ' . $cd['med_apellidos']) ?></span></span>
<span class="estado est-<?= cvs_escapar($cd['estado']) ?>"><?= cvs_escapar($cd['estado']) ?></span>
</li>
<?php endforeach; ?>
</ul>
<?php if (count($citasDia) > 8): ?>
<p class="dash-mas">Se muestran las primeras 8 de <?= count($citasDia) ?> citas.</p>
<?php endif; ?>
<p class="dash-mas"><a href="agenda_medica.php?fecha=<?= $diaSel->format('Y-m-d') ?>">Abrir el día en la agenda médica →</a></p>
<?php endif; ?>
</section>

<!-- DISPONIBILIDAD MÉDICA -->
<section class="dash-panel" aria-labelledby="titulo-disp">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('medicos') ?></span>
<h2 id="titulo-disp">Disponibilidad médica</h2>
</div>
<p class="dash-descripcion">Fecha consultada: <?= cvs_escapar(cvsd_formato_fecha_larga($hoy)) ?>. Se distingue tener horario de estar disponible: los turnos se calculan con los horarios activos y las citas programadas o confirmadas. No implica que el médico esté físicamente presente.</p>
<?php if ($disponibilidad === []): ?>
<div class="dash-hoy-vacio">
<span aria-hidden="true"><?= cvsu_icono('medicos') ?></span>
No hay médicos activos registrados todavía.
</div>
<?php else: ?>
<?php foreach ($disponibilidad as $dm): ?>
<div class="dash-med">
<span class="dash-med-avatar" aria-hidden="true">
<?php
// Normaliza NULL, '' y espacios para construir iniciales y nombres sin datos inventados.
$nombrePila = trim((string) ($dm['nombres'] ?? ''));
$apellidoPila = trim((string) ($dm['apellidos'] ?? ''));

$inicialA = '';
$inicialB = '';
if ($nombrePila !== '') {
    $inicialA = mb_strtoupper(mb_substr($nombrePila, 0, 1));
    if ($apellidoPila !== '') {
        $inicialB = mb_strtoupper(mb_substr($apellidoPila, 0, 1));
    }
} elseif ($apellidoPila !== '') {
    $inicialA = mb_strtoupper(mb_substr($apellidoPila, 0, 1));
} else {
    $inicialA = '?';
}
$iniciales = $inicialA . $inicialB;

if ($apellidoPila !== '' && $nombrePila !== '') {
    $nombreMed = $apellidoPila . ', ' . $nombrePila;
} elseif ($apellidoPila !== '') {
    $nombreMed = $apellidoPila;
} elseif ($nombrePila !== '') {
    $nombreMed = $nombrePila;
} else {
    $nombreMed = 'Nombre no registrado';
}
?>
<?= cvs_escapar($iniciales) ?>
</span>
<div>
<p class="dash-med-nombre"><?= cvs_escapar($nombreMed) ?></p>
<p class="dash-med-esp"><?= $dm['especialidades'] !== '' ? cvs_escapar($dm['especialidades']) : 'Sin especialidades asignadas' ?></p>
<?php if ($dm['horario'] !== ''): ?>
<p class="dash-med-horario">Horario: <?= cvs_escapar(str_replace('  ', ' y ', $dm['horario'])) ?></p>
<?php endif; ?>
<?php if ($dm['estado'] === 'con_turno'): ?>
<span class="dash-med-estado dash-turno-libre"><i></i>Próximo turno libre: <?= cvs_escapar($dm['proximo']) ?></span>
<?php elseif ($dm['estado'] === 'completo'): ?>
<span class="dash-med-estado dash-turno-completo"><i></i>No quedan turnos libres para la fecha</span>
<?php else: ?>
<span class="dash-med-estado dash-turno-sin-horario"><i></i>Sin horario activo para la fecha</span>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</section>

</div>
<div class="dash-col">

<!-- CITAS DE HOY -->
<section class="dash-panel" aria-labelledby="titulo-hoy">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('citas') ?></span>
<h2 id="titulo-hoy">Citas de hoy</h2>
</div>
<?php if ($citasHoy === []): ?>
<div class="dash-hoy-vacio">
<span aria-hidden="true"><?= cvsu_icono('citas') ?></span>
Por ahora no hay citas registradas para hoy. Tan pronto se registre una, aparecerá aquí.
</div>
<?php else: ?>
<?php foreach ($citasHoy as $ch): ?>
<div class="dash-fila">
<span class="dash-hora"><?= cvs_escapar(cvsd_formato_hora($ch['hora_inicio'])) ?></span>
<div>
<p class="dash-nombre"><?= cvs_escapar($ch['pac_nombres'] . ' ' . $ch['pac_apellidos']) ?></p>
<p class="dash-detalles"><?= cvs_escapar($ch['especialidad']) ?> · <?= cvs_escapar($ch['med_nombres'] . ' ' . $ch['med_apellidos']) ?></p>
</div>
<span class="estado est-<?= cvs_escapar($ch['estado']) ?>"><?= cvs_escapar($ch['estado']) ?></span>
</div>
<?php endforeach; ?>
<p class="dash-mas"><a href="agenda_medica.php?fecha=<?= $hoy->format('Y-m-d') ?>">Ver agenda de hoy →</a></p>
<?php endif; ?>
</section>

<!-- ACTIVIDAD RECIENTE -->
<section class="dash-panel" aria-labelledby="titulo-actividad">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('historial') ?></span>
<h2 id="titulo-actividad">Actividad reciente</h2>
</div>
<p class="dash-descripcion">Últimas citas registradas, según su fecha y hora de creación en el sistema.</p>
<?php if ($actividad === []): ?>
<div class="dash-hoy-vacio">
<span aria-hidden="true"><?= cvsu_icono('historial') ?></span>
Todavía no se han registrado citas en el sistema.
</div>
<?php else: ?>
<ul class="dash-timeline">
<?php foreach ($actividad as $act): ?>
<li class="dash-evento">
<span class="dash-evento-icono dash-ic-<?= cvs_escapar($act['estado']) ?>" aria-hidden="true">
<?= trim(cvsu_icono('citas')) ?>
</span>
<div>
<p class="dash-evento-titulo">Nueva cita registrada</p>
<p class="dash-evento-sub"><?= cvs_escapar($act['pac_nombres'] . ' ' . $act['pac_apellidos']) ?> · <?= cvs_escapar($act['especialidad']) ?><br>cita del <?= cvs_escapar(cvsd_fecha_corta($act['fecha'])) ?> <?= cvs_escapar(cvsd_formato_hora($act['hora_inicio'])) ?> · estado: <?= cvs_escapar($act['estado']) ?> · registrada el <?= cvs_escapar(cvsd_fecha_corta(substr($act['creado_en'], 0, 10))) ?> <?= cvs_escapar(cvsd_formato_hora(substr($act['creado_en'], 11, 5))) ?></p>
</div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="dash-mas"><a href="historial_citas.php">Ver historial completo →</a></p>
</section>

</div>
</div>

<?php else: ?>
<section class="ui-hero">
<div class="ui-hero-texto">
<span class="etiqueta"><?= $usuario !== null ? cvs_escapar($nombresRoles[$usuario['rol']]) : 'Bienvenido' ?></span>
<?php if ($usuario !== null): ?>
<h1 class="usuario ui-hero-nombre">Bienvenido de nuevo, <?= cvs_escapar($usuario['nombre_usuario']) ?></h1>
<p class="ui-hero-sub">Juntos por una comunidad más saludable. Por seguridad, tu sesión caduca después de 30 minutos de inactividad.</p>
<?php else: ?>
<h1 class="ui-hero-nombre">Una atención mejor organizada.</h1>
<p class="ui-hero-sub">Inicia sesión para acceder a los módulos autorizados para tu rol.</p>
<span class="acciones"><a class="boton" href="login.php">Iniciar sesión</a></span>
<?php endif; ?>
</div>
<p class="ui-hero-frase">«Atención humana, tecnología que cuida.»</p>
</section>
<?php
// Módulos o avisos del rol (recepcionista, médico, paciente).
if ($usuario !== null && count($modulos) > 0):
?>
<section class="panel">
<h2>Módulos del sistema</h2>
<p>Los módulos que ves corresponden únicamente a los permisos de tu rol.</p>
<div class="modulos">
<?php foreach ($modulos as [$enlace, $titulo, $descripcion]): ?>
<a class="modulo" href="<?= cvs_escapar($enlace) ?>">
<span class="modulo-icono"><?= cvsu_icono_archivo($enlace) ?></span>
<span class="modulo-titulo"><?= cvs_escapar($titulo) ?></span>
<span class="modulo-desc"><?= cvs_escapar($descripcion) ?></span>
</a>
<?php endforeach; ?>
</div>
</section>
<?php elseif ($avisoPersonal !== null): ?>
<section class="panel">
<h2>Funciones en preparación</h2>
<p class="nota"><?= cvs_escapar($avisoPersonal) ?></p>
</section>
<?php endif; ?>
<?php endif; ?>

</main>
<?php cvsu_cierre(); ?>
</body>
</html>
