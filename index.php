<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';
$usuario = cvs_usuario_actual();
$nombresRoles = ['administrador'=>'Administrador', 'recepcionista'=>'Recepcionista', 'medico'=>'Médico', 'paciente'=>'Paciente'];

$menus = [
    'administrador' => [
        ['pacientes.php', 'Pacientes', 'Registrar y administrar los pacientes activos de la clínica.'],
        ['medicos.php', 'Médicos', 'Gestionar médicos, sus datos y especialidades asignadas.'],
        ['especialidades.php', 'Especialidades', 'Mantener el catálogo de especialidades médicas.'],
        ['horarios.php', 'Horarios médicos', 'Definir los horarios de atención de cada médico.'],
        ['citas.php', 'Registrar citas', 'Programar nuevas citas para los pacientes.'],
        ['citas.php', 'Reprogramar citas', 'Selecciona la cita en el listado para asignar su nueva fecha.'],
        ['cancelar_cita.php', 'Cancelar citas', 'Cancelar citas vigentes de forma segura.'],
        ['asistencia_citas.php', 'Control de asistencia', 'Registrar asistencias e inasistencias de las citas.'],
        ['historial_citas.php', 'Historial de citas', 'Consultar el historial completo por paciente.'],
        ['agenda_medica.php', 'Agenda médica', 'Ver la agenda de cada médico por fecha.']
    ],
    'recepcionista' => [
        ['pacientes.php', 'Pacientes', 'Registrar y administrar los pacientes activos de la clínica.'],
        ['citas.php', 'Registrar citas', 'Programar nuevas citas para los pacientes.'],
        ['citas.php', 'Reprogramar citas', 'Selecciona la cita en el listado para asignar su nueva fecha.'],
        ['cancelar_cita.php', 'Cancelar citas', 'Cancelar citas vigentes de forma segura.'],
        ['asistencia_citas.php', 'Control de asistencia', 'Registrar asistencias e inasistencias de las citas.'],
        ['historial_citas.php', 'Historial de citas', 'Consultar el historial completo por paciente.'],
        ['agenda_medica.php', 'Agenda médica', 'Ver la agenda de cada médico por fecha.']
    ]
];
$modulos = $menus[$usuario['rol']] ?? [];

$avisoPersonal = null;
if ($usuario !== null && count($modulos) === 0) {
    $avisoPersonal = match ($usuario['rol']) {
        'medico' => 'Las funciones personales del rol de médico todavía no están integradas al sistema. No se otorga acceso a las agendas de otros médicos.',
        'paciente' => 'La consulta personal de tus citas estará disponible cuando se habilite la pantalla correspondiente.',
        default => 'Tu rol aún no tiene módulos disponibles en esta versión del sistema.'
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
<?php cvsu_area('index', 'Menú principal'); ?>
<main class="ui-contenido">
<?php if ($usuario !== null): ?>
<section class="ui-hero">
<div class="ui-hero-texto">
<span class="etiqueta"><?= cvs_escapar($nombresRoles[$usuario['rol']]) ?></span>
<h1 class="usuario ui-hero-nombre">Bienvenido de nuevo, <?= cvs_escapar($usuario['nombre_usuario']) ?></h1>
<p class="ui-hero-sub">Juntos por una comunidad más saludable. Por seguridad, tu sesión caduca después de 30 minutos de inactividad.</p>
</div>
<p class="ui-hero-frase">«Atención humana, tecnología que cuida.»</p>
</section>
<?php else: ?>
<section class="ui-hero">
<div class="ui-hero-texto">
<span class="etiqueta">Bienvenido</span>
<h1 class="ui-hero-nombre">Una atención mejor organizada.</h1>
<p class="ui-hero-sub">Inicia sesión para acceder a los módulos autorizados para tu rol.</p>
<span class="acciones"><a class="boton" href="login.php">Iniciar sesión</a></span>
</div>
<p class="ui-hero-frase">«Una comunidad más saludable.»</p>
</section>
<?php endif; ?>
<?php if ($usuario !== null && count($modulos) > 0): ?>
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
<?php elseif ($usuario !== null): ?>
<section class="panel">
<h2>Funciones en preparación</h2>
<p class="nota"><?= cvs_escapar($avisoPersonal) ?></p>
</section>
<?php endif; ?>
</main>
<?php cvsu_cierre(); ?>
</body>
</html>
