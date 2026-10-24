<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
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
<style>
*{box-sizing:border-box}body{margin:0;background:#f2f7f8;color:#17333b;font-family:Arial,sans-serif}header{background:#146d73;color:#fff;padding:26px max(20px,calc((100vw - 980px)/2))}header h1{font-size:26px;margin:0}header p{margin:8px 0 0;color:#e0f0f0}main{max-width:980px;margin:42px auto;padding:0 20px}.panel{background:#fff;border:1px solid #e1eeee;border-radius:18px;padding:34px;box-shadow:0 7px 25px #143b4210;margin-bottom:24px}h2{font-size:29px;margin:0 0 16px}p{line-height:1.7;color:#49646c}.etiqueta{display:inline-block;background:#e7f0f1;color:#155960;border-radius:20px;padding:7px 12px;font-size:13px;font-weight:bold}.acciones{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}.boton{display:inline-block;background:#146d73;color:#fff;border:0;border-radius:8px;padding:12px 18px;font:inherit;font-weight:bold;text-decoration:none;cursor:pointer}.boton.secundario{background:#e7f0f1;color:#155960}.boton:focus-visible{outline:3px solid #db9c22;outline-offset:3px}.nota{font-size:14px}.usuario{overflow-wrap:anywhere}.modulos{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:18px}.modulo{display:flex;flex-direction:column;gap:7px;background:#f7fbfb;border:1px solid #dfeaea;border-radius:12px;padding:16px;text-decoration:none;transition:background .2s ease,border-color .2s ease}.modulo:hover{background:#eef5f5;border-color:#146d73}.modulo:focus-visible{outline:3px solid #db9c22;outline-offset:2px}.modulo-titulo{color:#146d73;font-weight:bold}.modulo-desc{font-size:13px;color:#49646c;line-height:1.6}@media(max-width:650px){main{margin:24px auto}.panel{padding:23px}h2{font-size:25px}header h1{font-size:23px}}
</style>
</head>
<body>
<header><h1>Clínica Vida Salud</h1><p>Sistema de gestión de citas médicas</p></header>
<main>
<section class="panel">
<?php if ($usuario !== null): ?>
<span class="etiqueta"><?= cvs_escapar($nombresRoles[$usuario['rol']]) ?></span>
<h2 class="usuario">Bienvenido, <?= cvs_escapar($usuario['nombre_usuario']) ?></h2>
<p>Tu sesión está iniciada. Por seguridad, caduca después de 30 minutos de inactividad.</p>
<div class="acciones"><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>"><button class="boton secundario" type="submit">Cerrar sesión</button></form></div>
<?php else: ?>
<span class="etiqueta">Bienvenido</span>
<h2>Una atención mejor organizada.</h2>
<p>Este es el menú principal del sistema de Clínica Vida Salud. Inicia sesión para acceder a los módulos autorizados para tu rol.</p>
<div class="acciones"><a class="boton" href="login.php">Iniciar sesión</a></div>
<?php endif; ?>
</section>
<?php if ($usuario !== null && count($modulos) > 0): ?>
<section class="panel">
<h2>Módulos del sistema</h2>
<p>Los módulos que ves corresponden únicamente a los permisos de tu rol.</p>
<div class="modulos">
<?php foreach ($modulos as [$enlace, $titulo, $descripcion]): ?>
<a class="modulo" href="<?= cvs_escapar($enlace) ?>">
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
</body>
</html>
