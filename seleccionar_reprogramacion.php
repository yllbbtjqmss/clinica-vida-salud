<?php
/**
 * RF08 (paso 1): vista de selección de citas a reprogramar.
 * Solo lectura: no modifica registros; el cambio de fecha y hora se
 * sigue realizando exclusivamente en reprogramar_cita.php con sus
 * validaciones existentes.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard.php';

// RF01: administrador y recepcionista; médico y paciente reciben 403;
// sin sesión se redirige al login.
$usuario = cvs_exigir_roles(['administrador', 'recepcionista']);

$conexion = null;
$errores = [];
$filas = [];
$totalReprogramables = 0;
$paginaActual = 1;
$paginasTotales = 1;
$porPagina = 15;

// Paginación segura: entero >= 1, sin confiar en otra cosa.
$pagina = filter_var($_GET['p'] ?? '1', FILTER_VALIDATE_INT);
if ($pagina === false || $pagina <= 0) {
    $pagina = 1;
}

// Zona horaria de Bolivia: America/La_Paz.
$zonaClinica = new DateTimeZone('America/La_Paz');
$ahora = new DateTimeImmutable('now', $zonaClinica);
$hoyYmd = $ahora->format('Y-m-d');
$ahoraHora = $ahora->format('H:i:s');

try {
    $conexion = cvs_conexion();

    // Solo citas vigentes cuya fecha y hora de inicio no hayan transcurrido.
    $sqlTotal = "SELECT COUNT(*)
                 FROM citas
                 WHERE estado IN ('programada', 'confirmada')
                   AND (fecha > :hoy OR (fecha = :hoy2 AND hora_inicio > :hora))";
    $stmtTotal = $conexion->prepare($sqlTotal);
    $stmtTotal->bindValue(':hoy', $hoyYmd, PDO::PARAM_STR);
    $stmtTotal->bindValue(':hoy2', $hoyYmd, PDO::PARAM_STR);
    $stmtTotal->bindValue(':hora', $ahoraHora, PDO::PARAM_STR);
    $stmtTotal->execute();
    $totalReprogramables = (int) $stmtTotal->fetchColumn();

    $paginasTotales = max(1, (int) ceil($totalReprogramables / $porPagina));
    if ($pagina > $paginasTotales) {
        $pagina = $paginasTotales;
    }
    $paginaActual = $pagina;
    $desplazamiento = ($paginaActual - 1) * $porPagina;

    $sqlListado = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
                          p.nombres AS pac_nombres, p.apellidos AS pac_apellidos,
                          e.nombre AS especialidad,
                          m.nombres AS med_nombres, m.apellidos AS med_apellidos
                   FROM citas c
                   JOIN pacientes p ON p.id_paciente = c.id_paciente
                   JOIN especialidades e ON e.id_especialidad = c.id_especialidad
                   JOIN medicos m ON m.id_medico = c.id_medico
                   WHERE c.estado IN ('programada', 'confirmada')
                     AND (c.fecha > :hoy OR (c.fecha = :hoy2 AND c.hora_inicio > :hora))
                   ORDER BY c.fecha, c.hora_inicio
                   LIMIT :limite OFFSET :desplazamiento";
    $stmtListado = $conexion->prepare($sqlListado);
    $stmtListado->bindValue(':hoy', $hoyYmd, PDO::PARAM_STR);
    $stmtListado->bindValue(':hoy2', $hoyYmd, PDO::PARAM_STR);
    $stmtListado->bindValue(':hora', $ahoraHora, PDO::PARAM_STR);
    $stmtListado->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmtListado->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $stmtListado->execute();
    $filas = $stmtListado->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $errores[] = 'No se pudieron cargar las citas disponibles para reprogramar.';
    $filas = [];
    $totalReprogramables = 0;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reprogramar citas | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('seleccionar_reprogramacion', 'Reprogramar citas'); ?>
<main class="ui-contenido">
    <section class="panel">
        <div class="dash-cabecera">
            <span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('reprogramar') ?></span>
            <h1>Reprogramar citas</h1>
        </div>
        <p>Selecciona una cita vigente para cambiar su fecha u horario.</p>
        <?php foreach ($errores as $error): ?>
            <div class="error"><?= cvs_escapar($error) ?></div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Citas vigentes</h2>
        <p class="descripcion">
            Solo se muestran citas programadas o confirmadas cuya fecha y hora no hayan transcurrido.
            Fecha de referencia: <?= cvs_escapar(cvsd_formato_fecha_larga($ahora->setTime(0, 0))) ?>;
            hora de corte: <?= cvs_escapar(cvsd_formato_hora($ahoraHora)) ?>.
        </p>
        <?php if ($filas === []): ?>
            <div class="dash-hoy-vacio">
                <span aria-hidden="true"><?= cvsu_icono('reprogramar') ?></span>
                No hay citas disponibles para reprogramar.
            </div>
        <?php else: ?>
            <div class="tabla">
                <table class="citas">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Especialidad</th>
                            <th>Médico</th>
                            <th>Fecha actual</th>
                            <th>Hora actual</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filas as $fila): ?>
                        <tr>
                            <td><?= cvs_escapar($fila['codigo']) ?></td>
                            <td><?= cvs_escapar($fila['pac_nombres'] . ' ' . $fila['pac_apellidos']) ?></td>
                            <td><?= cvs_escapar($fila['especialidad']) ?></td>
                            <td><?= cvs_escapar($fila['med_nombres'] . ' ' . $fila['med_apellidos']) ?></td>
                            <td><?= cvs_escapar(cvsd_fecha_corta($fila['fecha'])) ?></td>
                            <td><?= cvs_escapar(cvsd_formato_hora($fila['hora_inicio'])) ?> - <?= cvs_escapar(cvsd_formato_hora($fila['hora_fin'])) ?></td>
                            <td><span class="estado est-<?= cvs_escapar($fila['estado']) ?>"><?= cvs_escapar($fila['estado']) ?></span></td>
                            <td><a class="boton pequeno" href="reprogramar_cita.php?id_cita=<?= (int) $fila['id_cita'] ?>">Reprogramar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($paginasTotales > 1): ?>
            <div class="paginacion">
                <span>Página <?= (int) $paginaActual ?> de <?= (int) $paginasTotales ?> · <?= (int) $totalReprogramables ?> citas reprogramables</span>
                <?php if ($paginaActual > 1): ?>
                    <a href="?p=<?= (int) ($paginaActual - 1) ?>">← Anterior</a>
                <?php endif; ?>
                <?php if ($paginaActual < $paginasTotales): ?>
                    <a href="?p=<?= (int) ($paginaActual + 1) ?>">Siguiente →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<?php cvsu_cierre(); ?>
</body>
</html>
