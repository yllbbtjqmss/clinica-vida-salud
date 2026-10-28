<?php
/**
 * RF13 — Reportes administrativos (solo lectura).
 *
 * Acceso exclusivo del administrador. Todos los datos provienen de la base
 * de PostgreSQL mediante consultas preparadas; no se modifican registros.
 * Los filtros viajan por GET: desde, hasta, id_medico, id_especialidad,
 * estado, tipo, p, pp, q, ag_id_medico y ag_fecha.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dashboard.php';
require_once __DIR__ . '/includes/reportes.php';

$usuario = cvs_exigir_roles(['administrador']);

require_once __DIR__ . '/includes/ui.php';

/** Etiquetas de los cinco estados validos (aparecen siempre, incluso en cero). */
$etiquetasEstado = array(
    'programada' => 'Programadas',
    'confirmada' => 'Confirmadas',
    'atendida' => 'Atendidas',
    'cancelada' => 'Canceladas',
    'inasistencia' => 'Inasistencias',
);

$titulosSeccion = array(
    'citas' => 'Reporte de citas por fecha',
    'medicos' => 'Reporte por médico',
    'especialidades' => 'Reporte por especialidad',
    'estados' => 'Reporte por estado',
    'pacientes' => 'Pacientes registrados',
    'agenda' => 'Agenda diaria por médico',
);

function cvsr_etiqueta_estado(string $estado): string
{
    static $mapa = array(
        'programada' => 'Programada',
        'confirmada' => 'Confirmada',
        'atendida' => 'Atendida',
        'cancelada' => 'Cancelada',
        'inasistencia' => 'Inasistencia',
    );

    return $mapa[$estado] ?? $estado;
}

function cvsr_fecha_corta(string $ymd): string
{
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

    return $f === false ? $ymd : $f->format('d/m/Y');
}

function cvsr_hora_corta($hora): string
{
    $texto = (string) $hora;

    return $texto === '' ? '—' : mb_substr($texto, 0, 5);
}

/** Enlace canónico dentro del módulo, solo con valores validados. */
function cvsr_url(array $cambios = []): string
{
    global $val;
    $tipo = $cambios['tipo'] ?? $val['tipo'];
    $params = array('tipo' => $tipo);
    if ($tipo === 'agenda') {
        if ($val['ag_id_medico'] !== null) {
            $params['ag_id_medico'] = $val['ag_id_medico'];
        }
        if ($val['ag_fecha'] !== null) {
            $params['ag_fecha'] = $val['ag_fecha']->format('Y-m-d');
        }
    } elseif ($tipo !== 'pacientes') {
        $params['desde'] = $val['desde']->format('Y-m-d');
        $params['hasta'] = $val['hasta']->format('Y-m-d');
        if ($val['id_medico'] !== null) {
            $params['id_medico'] = $val['id_medico'];
        }
        if ($val['id_especialidad'] !== null) {
            $params['id_especialidad'] = $val['id_especialidad'];
        }
        if ($val['estado'] !== '') {
            $params['estado'] = $val['estado'];
        }
        if (isset($cambios['p'])) {
            $params['p'] = $cambios['p'];
        }
    }
    if ($tipo === 'pacientes') {
        if ($val['q'] !== '') {
            $params['q'] = $val['q'];
        }
        if (isset($cambios['pp'])) {
            $params['pp'] = $cambios['pp'];
        }
    }

    return 'reportes.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/* ---------------------------------------------------------------
 * Carga de datos (lectura exclusiva)
 * --------------------------------------------------------------- */
$val = cvsr_validar_filtros($_GET);
$tipo = $val['tipo'];

$pdo = null;
$falloBD = false;
try {
    $pdo = cvs_conexion();
} catch (Throwable $ex) {
    $falloBD = true;
}

$datos = array();
$resumenEstados = null;
$paginacionCitas = null;
$paginacionPacientes = null;
$resumenPacientes = null;
$falloConsulta = false;

if (!$falloBD) {
    try {
        $datos['listas'] = cvsr_listas_formulario($pdo);
        if ($val['errores'] === array()) {
            if (in_array($tipo, array('citas', 'medicos', 'especialidades', 'estados'), true)) {
                $datos['actividad'] = cvsr_por_fecha($pdo, $val);
            }
            switch ($tipo) {
                case 'citas':
                    $resumenEstados = cvsr_resumen_estados($pdo, $val);
                    $lote = cvsr_listado_citas($pdo, $val, ($val['pagina_citas'] - 1) * CVSR_POR_PAGINA, CVSR_POR_PAGINA);
                    $paginas = max(1, (int) ceil($lote['total'] / CVSR_POR_PAGINA));
                    if ($val['pagina_citas'] > $paginas) {
                        $val['pagina_citas'] = $paginas;
                        $val['avisos'][] = 'La página solicitada supera los resultados disponibles; se muestra la última página.';
                        $lote = cvsr_listado_citas($pdo, $val, ($paginas - 1) * CVSR_POR_PAGINA, CVSR_POR_PAGINA);
                    }
                    $lote['paginas'] = $paginas;
                    $lote['desde'] = ($val['pagina_citas'] - 1) * CVSR_POR_PAGINA + 1;
                    $paginacionCitas = $lote;
                    break;
                case 'medicos':
                    $resumenEstados = cvsr_resumen_estados($pdo, $val);
                    $datos['medicos'] = cvsr_por_medico($pdo, $val);
                    break;
                case 'especialidades':
                    $resumenEstados = cvsr_resumen_estados($pdo, $val);
                    $datos['especialidades'] = cvsr_por_especialidad($pdo, $val);
                    break;
                case 'estados':
                    $resumenEstados = cvsr_resumen_estados($pdo, $val);
                    break;
                case 'pacientes':
                    $resumenPacientes = cvsr_pacientes_resumen($pdo);
                    $lote = cvsr_pacientes($pdo, $val['q'], ($val['pagina_pacientes'] - 1) * CVSR_POR_PAGINA, CVSR_POR_PAGINA);
                    $paginas = max(1, (int) ceil($lote['total'] / CVSR_POR_PAGINA));
                    if ($val['pagina_pacientes'] > $paginas) {
                        $val['pagina_pacientes'] = $paginas;
                        $val['avisos'][] = 'La página solicitada supera los resultados disponibles; se muestra la última página.';
                        $lote = cvsr_pacientes($pdo, $val['q'], ($paginas - 1) * CVSR_POR_PAGINA, CVSR_POR_PAGINA);
                    }
                    $lote['paginas'] = $paginas;
                    $lote['desde'] = ($val['pagina_pacientes'] - 1) * CVSR_POR_PAGINA + 1;
                    $paginacionPacientes = $lote;
                    break;
                case 'agenda':
                    if ($val['ag_id_medico'] !== null && $val['ag_fecha'] !== null) {
                        $datos['agenda'] = cvsr_agenda_dia($pdo, $val['ag_id_medico'], $val['ag_fecha']->format('Y-m-d'));
                    }
                    break;
            }
        }
    } catch (Throwable $ex) {
        $falloConsulta = true;
    }
}

/** Texto de filtros aplicados, usado en la interfaz y al imprimir. */
$resumenFiltros = 'Período: ' . cvsr_fecha_corta($val['desde']->format('Y-m-d')) . ' – ' . cvsr_fecha_corta($val['hasta']->format('Y-m-d'));
if ($tipo !== 'pacientes' && $tipo !== 'agenda') {
    if ($val['id_medico'] !== null) {
        foreach ($datos['listas']['medicos'] as $m) {
            if ((int) $m['id_medico'] === $val['id_medico']) {
                $resumenFiltros .= ' · Médico: ' . trim($m['nombres'] . ' ' . $m['apellidos']);
                break;
            }
        }
    }
    if ($val['id_especialidad'] !== null) {
        foreach ($datos['listas']['especialidades'] as $e) {
            if ((int) $e['id_especialidad'] === $val['id_especialidad']) {
                $resumenFiltros .= ' · Especialidad: ' . $e['nombre'];
                break;
            }
        }
    }
    if ($val['estado'] !== '') {
        $resumenFiltros .= ' · Estado: ' . cvsr_etiqueta_estado($val['estado']);
    }
}
if ($tipo === 'pacientes' && $val['q'] !== '') {
    $resumenFiltros .= ' · Búsqueda: “' . $val['q'] . '”';
}

$ahoraGenerado = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reportes administrativos | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('reportes', 'Reportes administrativos'); ?>
<main class="ui-contenido">

<div class="rep-intro">
<div>
<h1>Reportes administrativos</h1>
<p class="rep-descripcion">Consulta las estadísticas y el seguimiento de las citas médicas de Clínica Vida Salud.</p>
</div>
</div>

<nav class="rep-tabs" aria-label="Secciones del módulo de reportes">
<?php foreach (CVSR_TIPOS as $seccion): ?>
<a class="rep-tab<?= $seccion === $tipo ? ' activo' : '' ?>" <?= $seccion === $tipo ? 'aria-current="page"' : '' ?> href="<?= cvs_escapar(cvsr_url(array('tipo' => $seccion))) ?>"><?= cvs_escapar($titulosSeccion[$seccion]) ?></a>
<?php endforeach; ?>
</nav>

<div class="rep-meta" role="note">
<span>Generado el: <?= cvs_escapar($ahoraGenerado->format('d/m/Y H:i')) ?> (hora de La Paz) · Usuario: <?= cvs_escapar($usuario['nombre_usuario'] ?? '') ?> (Administrador)</span>
<span><?= cvs_escapar($resumenFiltros) ?></span>
</div>

<?php foreach ($val['errores'] as $errorVal): ?>
<div class="error" role="alert"><?= cvs_escapar($errorVal) ?></div>
<?php endforeach; ?>
<?php foreach ($val['avisos'] as $avisoVal): ?>
<div class="aviso" role="status"><?= cvs_escapar($avisoVal) ?></div>
<?php endforeach; ?>
<?php if ($falloBD): ?>
<div class="error" role="alert">No se pudo conectar con la base de datos. Reintenta en unos minutos; los demás módulos siguen funcionando.</div>
<?php elseif ($falloConsulta): ?>
<div class="error" role="alert">No fue posible consultar los reportes. Vuelve a intentarlo más tarde.</div>
<?php endif; ?>

<nav class="acciones" aria-label="Acciones del módulo">
<button type="button" class="boton secundario" onclick="window.print()">Imprimir reporte</button>
<a class="boton secundario" href="index.php">← Volver al inicio</a>
</nav>

<?php if (in_array($tipo, array('citas', 'medicos', 'especialidades', 'estados'), true) && $resumenEstados !== null): ?>
<!-- TARJETAS KPI DEL PERIOD FILTRADO -->
<section class="dash-kpis" aria-label="Totales del período filtrado">
<div class="dash-kpi dash-kpi-turquesa">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('reportes') ?></span>
<div>
<p class="dash-kpi-titulo">Total de citas</p>
<p class="dash-kpi-num"><?= (int) $resumenEstados['total'] ?></p>
<span class="dash-sub">En el rango y filtros aplicados</span>
</div>
</div>
<?php foreach (CVSR_ESTADOS as $estadoClave): ?>
<div class="dash-kpi">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('citas') ?></span>
<div>
<p class="dash-kpi-titulo"><?= cvs_escapar($etiquetasEstado[$estadoClave]) ?></p>
<p class="dash-kpi-num"><?= (int) $resumenEstados['estados'][$estadoClave] ?></p>
<span class="dash-sub"><?= cvsr_porcentaje($resumenEstados['estados'][$estadoClave], max($resumenEstados['total'], 1)) ?>% del total</span>
</div>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<!-- FILTROS COMUNES DEL PERIOD -->
<?php if (in_array($tipo, array('citas', 'medicos', 'especialidades', 'estados'), true)): ?>
<form class="filtros" method="get" action="reportes.php">
<input type="hidden" name="tipo" value="<?= cvs_escapar($tipo) ?>">
<div class="campo"><label for="desde">Fecha desde</label><input type="date" id="desde" name="desde" value="<?= cvs_escapar($val['desde']->format('Y-m-d')) ?>"></div>
<div class="campo"><label for="hasta">Fecha hasta</label><input type="date" id="hasta" name="hasta" value="<?= cvs_escapar($val['hasta']->format('Y-m-d')) ?>"></div>
<div class="campo"><label for="id_medico">Médico (opcional)</label>
<select id="id_medico" name="id_medico">
<option value="">Todos los médicos</option>
<?php foreach ($datos['listas']['medicos'] as $op): ?>
<option value="<?= (int) $op['id_medico'] ?>" <?= $val['id_medico'] === (int) $op['id_medico'] ? 'selected' : '' ?>><?= cvs_escapar(trim($op['nombres'] . ' ' . $op['apellidos']) . ($op['activo'] ? '' : ' (inactivo)')) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="campo"><label for="id_especialidad">Especialidad (opcional)</label>
<select id="id_especialidad" name="id_especialidad">
<option value="">Todas las especialidades</option>
<?php foreach ($datos['listas']['especialidades'] as $op): ?>
<option value="<?= (int) $op['id_especialidad'] ?>" <?= $val['id_especialidad'] === (int) $op['id_especialidad'] ? 'selected' : '' ?>><?= cvs_escapar($op['nombre'] . ($op['activo'] ? '' : ' (inactiva)')) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="campo"><label for="estado_filtro">Estado (opcional)</label>
<select id="estado_filtro" name="estado">
<option value="">Todos los estados</option>
<?php foreach (CVSR_ESTADOS as $estadoClave): ?>
<option value="<?= cvs_escapar($estadoClave) ?>" <?= $val['estado'] === $estadoClave ? 'selected' : '' ?>><?= cvs_escapar(cvsr_etiqueta_estado($estadoClave)) ?></option>
<?php endforeach; ?>
</select>
</div>
<button type="submit" class="boton">Aplicar filtros</button>
<a class="boton secundario" href="reportes.php?tipo=<?= cvs_escapar($tipo) ?>">Limpiar</a>
</form>
<p class="pequeno">Las fechas deben tener el formato AAAA-MM-DD. El rango de reportes está limitado a 366 días alrededor del día actual para proteger el rendimiento.</p>
<?php endif; ?>

<!-- GRAFICO DE ACTIVIDAD DEL PERIOD -->
<?php if (in_array($tipo, array('citas', 'medicos', 'especialidades', 'estados'), true) && $val['errores'] === array()): ?>
<?php $actividad = $datos['actividad'] ?? array();
$actTotal = array_sum(array_map('intval', array_column($actividad, 'total')));
$actMax = 0;
foreach ($actividad as $fila) { $actMax = max($actMax, (int) $fila['total']); }
?>
<section class="dash-panel" aria-labelledby="rep-titulo-actividad">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('reportes') ?></span>
<h2 id="rep-titulo-actividad">Actividad: citas por fecha</h2>
</div>
<p class="dash-sub">Cantidad de citas registradas por fecha dentro del período filtrado (<?= (int) $actTotal ?> en total).</p>
<?php if ($actividad === array()): ?>
<p class="vacio">No hay citas registradas en el período seleccionado. Ajusta las fechas o quita filtros.</p>
<?php else: ?>
<?php $salto = max(1, (int) ceil(count($actividad) / 16)); ?>
<div class="rep-actividad" role="img" aria-label="Gráfico de barras de citas por fecha. <?= cvs_escapar($resumenFiltros) ?>">
<?php foreach ($actividad as $i => $fila): $dia = (int) mb_substr($fila['fecha'], 8, 2); ?>
<div class="rep-ctd" title="<?= cvs_escapar(cvsr_fecha_corta($fila['fecha']) . ': ' . (int) $fila['total']) ?>">
<span class="rep-ctd-altura" style="height:<?= max(3, (int) round((int) $fila['total'] * 100 / max($actMax, 1))) ?>%"></span>
<span class="rep-ctd-etiqueta"<?= ($i % $salto) !== 0 ? ' aria-hidden="true"' : '' ?>><?= $dia ?></span>
</div>
<?php endforeach; ?>
</div>
<details class="rep-detalle">
<summary>Ver valores numéricos de la actividad</summary>
<p class="pequeno"><?php
$partes = array();
foreach ($actividad as $fila) {
    $partes[] = cvsr_fecha_corta($fila['fecha']) . ': ' . (int) $fila['total'];
}
echo cvs_escapar(implode(' · ', $partes));
?></p>
</details>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * A) REPORTE DE CITAS POR FECHA
 * --------------------------------------------------------------- */
if ($tipo === 'citas' && $val['errores'] === array()):
$rows = $paginacionCitas['filas'];
?>
<section class="dash-panel" aria-labelledby="rep-titulo-tabla">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('agenda') ?></span>
<h2 id="rep-titulo-tabla">Citas del período</h2>
</div>
<p class="dash-sub">Total de citas encontradas: <strong><?= (int) $paginacionCitas['total'] ?></strong>.<?php if ($paginacionCitas['total'] > 0): ?> Mostrando <?= (int) $paginacionCitas['desde'] ?>–<?= (int) ($paginacionCitas['desde'] - 1 + count($rows)) ?> de <?= (int) $paginacionCitas['total'] ?>.<?php endif; ?></p>
<?php if ($rows === array()): ?>
<p class="vacio">No hay citas que coincidan con los filtros aplicados.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-label="Listado de citas del período">
<table>
<thead><tr>
<th scope="col">Código</th><th scope="col">Fecha</th><th scope="col">Inicio</th><th scope="col">Fin</th>
<th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Estado</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $fila): ?>
<tr>
<td><?= cvs_escapar($fila['codigo']) ?></td>
<td><?= cvs_escapar(cvsr_fecha_corta($fila['fecha'])) ?></td>
<td><?= cvs_escapar(cvsr_hora_corta($fila['hora_inicio'])) ?></td>
<td><?= cvs_escapar(cvsr_hora_corta($fila['hora_fin'])) ?></td>
<td><?= cvs_escapar($fila['paciente']) ?></td>
<td><?= cvs_escapar($fila['medico']) ?></td>
<td><?= cvs_escapar($fila['especialidad']) ?></td>
<td><span class="pill"><?= cvs_escapar(cvsr_etiqueta_estado($fila['estado'])) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<nav class="paginacion" aria-label="Páginas del reporte de citas">
<?php if ($val['pagina_citas'] > 1): ?><a href="<?= cvs_escapar(cvsr_url(array('tipo' => 'citas', 'p' => $val['pagina_citas'] - 1))) ?>">« Anterior</a><?php endif; ?>
<span class="mini">Página <?= (int) $val['pagina_citas'] ?> de <?= (int) $paginacionCitas['paginas'] ?></span>
<?php if ($val['pagina_citas'] < $paginacionCitas['paginas']): ?><a href="<?= cvs_escapar(cvsr_url(array('tipo' => 'citas', 'p' => $val['pagina_citas'] + 1))) ?>">Siguiente »</a><?php endif; ?>
</nav>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * B) REPORTE POR MDICO
 * --------------------------------------------------------------- */
if ($tipo === 'medicos' && $val['errores'] === array()):
$rows = $datos['medicos'];
?>
<section class="dash-panel" aria-labelledby="rep-titulo-medicos">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('medicos') ?></span>
<h2 id="rep-titulo-medicos">Citas por médico</h2>
</div>
<p class="dash-sub">Conteo por médico en el período filtrado. Los médicos desactivados se conservan con su historial.</p>
<?php if ($rows === array()): ?>
<p class="vacio">No hay citas de médicos en el período seleccionado.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-label="Citas agrupadas por médico">
<table>
<thead><tr>
<th scope="col">Médico</th><th scope="col">Registro profesional</th><th scope="col">Total</th>
<th scope="col">Programadas</th><th scope="col">Confirmadas</th><th scope="col">Atendidas</th><th scope="col">Canceladas</th><th scope="col">Inasistencias</th><th scope="col">Detalle</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $fila): ?>
<tr>
<td><?= cvs_escapar(trim($fila['nombres'] . ' ' . $fila['apellidos'])) ?><?= $fila['med_activo'] ? '' : ' <span class="etiqueta inactivo">Inactivo</span>' ?></td>
<td><?= cvs_escapar($fila['registro_profesional'] !== null && $fila['registro_profesional'] !== '' ? $fila['registro_profesional'] : '—') ?></td>
<td><strong><?= (int) $fila['total'] ?></strong></td>
<td><?= (int) $fila['programada'] ?></td>
<td><?= (int) $fila['confirmada'] ?></td>
<td><?= (int) $fila['atendida'] ?></td>
<td><?= (int) $fila['cancelada'] ?></td>
<td><?= (int) $fila['inasistencia'] ?></td>
<td><a class="boton pequeno" href="<?= cvs_escapar(cvsr_url(array('tipo' => 'citas', 'p' => 1)) . '&id_medico=' . (int) $fila['id_medico']) ?>">Detalle</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * C) REPORTE POR ESPECIALIDAD
 * --------------------------------------------------------------- */
if ($tipo === 'especialidades' && $val['errores'] === array()):
$rows = $datos['especialidades'];
$totalEsp = max(array_sum(array_map('intval', array_column($rows, 'total'))), 1);
$espMax = 0;
foreach ($rows as $fila) { $espMax = max($espMax, (int) $fila['total']); }
?>
<section class="dash-panel" aria-labelledby="rep-titulo-especialidades">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('especialidades') ?></span>
<h2 id="rep-titulo-especialidades">Distribución por especialidad</h2>
</div>
<p class="dash-sub">Las especialidades inactivas con citas históricas se conservan en el reporte.</p>
<?php if ($rows === array()): ?>
<p class="vacio">No hay citas por especialidad en el período seleccionado.</p>
<?php else: ?>
<div class="rep-horizontal" role="img" aria-label="Distribución de citas por especialidad; <?= cvs_escapar($resumenFiltros) ?>">
<?php foreach ($rows as $fila): ?>
<div class="rep-h-fila">
<span class="rep-h-nombre"><?= cvs_escapar($fila['especialidad']) ?></span>
<span class="rep-h-track"><span class="rep-h-bar" style="width:<?= max(2, (int) round((int) $fila['total'] * 100 / max($espMax, 1))) ?>%"></span></span>
<span class="rep-h-num"><?= (int) $fila['total'] ?> · <?= cvsr_porcentaje((int) $fila['total'], $totalEsp) ?>%</span>
</div>
<?php endforeach; ?>
</div>
<div class="tabla" tabindex="0" role="region" aria-label="Tabla de citas por especialidad">
<table>
<thead><tr>
<th scope="col">Especialidad</th><th scope="col">Total</th><th scope="col">Programadas</th><th scope="col">Confirmadas</th>
<th scope="col">Atendidas</th><th scope="col">Canceladas</th><th scope="col">Inasistencias</th><th scope="col">% del total</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $fila): ?>
<tr>
<td><?= cvs_escapar($fila['especialidad']) ?><?= $fila['esp_activo'] ? '' : ' <span class="etiqueta inactivo">Inactiva</span>' ?></td>
<td><strong><?= (int) $fila['total'] ?></strong></td>
<td><?= (int) $fila['programada'] ?></td>
<td><?= (int) $fila['confirmada'] ?></td>
<td><?= (int) $fila['atendida'] ?></td>
<td><?= (int) $fila['cancelada'] ?></td>
<td><?= (int) $fila['inasistencia'] ?></td>
<td><?= cvsr_porcentaje((int) $fila['total'], $totalEsp) ?>%</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * D) REPORTE POR ESTADO
 * --------------------------------------------------------------- */
if ($tipo === 'estados' && $val['errores'] === array() && $resumenEstados !== null):
$estMapa = $resumenEstados['estados'];
$estTotal = $resumenEstados['total'];
$estMax = max(max($estMapa), 1);
?>
<section class="dash-panel" aria-labelledby="rep-titulo-estados">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('citas') ?></span>
<h2 id="rep-titulo-estados">Distribución por estado</h2>
</div>
<p class="dash-sub">Los cinco estados del sistema aparecen siempre, incluso con conteo cero.</p>
<?php if ($estTotal === 0): ?>
<p class="vacio">No hay citas en el período seleccionado. El gráfico se actualizará cuando existan registros.</p>
<?php else: ?>
<div class="rep-barras" role="img" aria-label="Gráfico de barras de citas por estado. <?= cvs_escapar($resumenFiltros) ?>">
<?php foreach (CVSR_ESTADOS as $estadoClave): ?>
<div class="rep-barra">
<span class="rep-barra-valor"><?= (int) $estMapa[$estadoClave] ?> · <?= cvsr_porcentaje($estMapa[$estadoClave], $estTotal) ?>%</span>
<span class="rep-barra-altura" style="height:<?= max(3, (int) round($estMapa[$estadoClave] * 100 / $estMax)) ?>%"></span>
<span class="rep-barra-etiqueta"><?= cvs_escapar($etiquetasEstado[$estadoClave]) ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<div class="tabla" tabindex="0" role="region" aria-label="Tabla de citas por estado">
<table class="estados-reporte">
<thead><tr><th scope="col">Estado</th><th scope="col">Cantidad</th><th scope="col">% de las citas del período</th></tr></thead>
<tbody>
<?php foreach (CVSR_ESTADOS as $estadoClave): ?>
<tr>
<td><span class="pill"><?= cvs_escapar($etiquetasEstado[$estadoClave]) ?></span></td>
<td><strong><?= (int) $estMapa[$estadoClave] ?></strong></td>
<td><?= cvsr_porcentaje($estMapa[$estadoClave], max($estTotal, 1)) ?>%</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * E) PACIENTES REGISTRADOS
 * --------------------------------------------------------------- */
if ($tipo === 'pacientes' && $falloBD === false && $falloConsulta === false):
?>
<section class="dash-kpis" aria-label="Indicadores de pacientes">
<div class="dash-kpi dash-kpi-turquesa">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<div>
<p class="dash-kpi-titulo">Pacientes registrados</p>
<p class="dash-kpi-num"><?= (int) $resumenPacientes['total'] ?></p>
<span class="dash-sub">Total del sistema</span>
</div>
</div>
<div class="dash-kpi">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<div>
<p class="dash-kpi-titulo">Activos</p>
<p class="dash-kpi-num"><?= (int) $resumenPacientes['activos'] ?></p>
</div>
</div>
<div class="dash-kpi">
<span class="dash-kpi-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<div>
<p class="dash-kpi-titulo">Inactivos</p>
<p class="dash-kpi-num"><?= (int) $resumenPacientes['inactivos'] ?></p>
</div>
</div>
</section>

<section class="dash-panel" aria-labelledby="rep-titulo-pacientes">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<h2 id="rep-titulo-pacientes">Registro administrativo de pacientes</h2>
</div>
<p class="dash-sub">Datos administrativos básicos; sin información clínica. Busca por nombre, apellido o CI.</p>
<form class="busqueda" method="get" action="reportes.php">
<input type="hidden" name="tipo" value="pacientes">
<div><label for="q">Buscar paciente</label><input id="q" name="q" maxlength="80" value="<?= cvs_escapar($val['q']) ?>" placeholder="Nombre, apellido o CI"></div>
<button type="submit" class="boton">Buscar</button>
<a class="boton secundario" href="reportes.php?tipo=pacientes">Limpiar</a>
</form>
</section>

<section class="dash-panel" aria-labelledby="rep-titulo-tabla-pacientes">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('pacientes') ?></span>
<h2 id="rep-titulo-tabla-pacientes">Listado de pacientes</h2>
</div>
<p class="dash-sub">Total de coincidencias: <strong><?= (int) $paginacionPacientes['total'] ?></strong>.<?php if ($paginacionPacientes['total'] > 0): ?> Mostrando <?= (int) $paginacionPacientes['desde'] ?>–<?= (int) ($paginacionPacientes['desde'] - 1 + count($paginacionPacientes['filas'])) ?> de <?= (int) $paginacionPacientes['total'] ?>.<?php endif; ?></p>
<?php if ($paginacionPacientes['filas'] === array()): ?>
<p class="vacio">No se encontraron pacientes con ese criterio.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-label="Listado de pacientes">
<table>
<thead><tr><th scope="col">Identificador</th><th scope="col">Nombre y apellido</th><th scope="col">CI</th><th scope="col">Estado</th></tr></thead>
<tbody>
<?php foreach ($paginacionPacientes['filas'] as $fila): ?>
<tr>
<td><?= (int) $fila['id_paciente'] ?></td>
<td><?= cvs_escapar($fila['nombres'] . ' ' . $fila['apellidos']) ?></td>
<td><?= cvs_escapar(($fila['ci'] ?? '') !== '' ? $fila['ci'] : 'Sin CI') ?></td>
<td><span class="etiqueta <?= $fila['activo'] ? '' : 'inactivo' ?>"><?= $fila['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<nav class="paginacion" aria-label="Páginas del listado de pacientes">
<?php if ($val['pagina_pacientes'] > 1): ?><a href="<?= cvs_escapar(cvsr_url(array('tipo' => 'pacientes', 'pp' => $val['pagina_pacientes'] - 1))) ?>">« Anterior</a><?php endif; ?>
<span class="mini">Página <?= (int) $val['pagina_pacientes'] ?> de <?= (int) $paginacionPacientes['paginas'] ?></span>
<?php if ($val['pagina_pacientes'] < $paginacionPacientes['paginas']): ?><a href="<?= cvs_escapar(cvsr_url(array('tipo' => 'pacientes', 'pp' => $val['pagina_pacientes'] + 1))) ?>">Siguiente »</a><?php endif; ?>
</nav>
<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------
 * F) AGENDA DIARIA POR MDICO
 * --------------------------------------------------------------- */
if ($tipo === 'agenda' && $falloBD === false && $falloConsulta === false && !$val['errores']):
?>
<section class="dash-panel" aria-labelledby="rep-titulo-agenda">
<div class="dash-cabecera">
<span class="dash-cabecera-icono" aria-hidden="true"><?= cvsu_icono('agenda') ?></span>
<h2 id="rep-titulo-agenda">Agenda diaria por médico</h2>
</div>
<form class="filtros" method="get" action="reportes.php">
<input type="hidden" name="tipo" value="agenda">
<div class="campo"><label for="ag_id_medico">Médico</label>
<select id="ag_id_medico" name="ag_id_medico" required>
<option value="">Selecciona un médico</option>
<?php foreach ($datos['listas']['medicos'] as $op): ?>
<option value="<?= (int) $op['id_medico'] ?>" <?= $val['ag_id_medico'] === (int) $op['id_medico'] ? 'selected' : '' ?>><?= cvs_escapar(trim($op['nombres'] . ' ' . $op['apellidos']) . ($op['activo'] ? '' : ' (inactivo)')) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="campo"><label for="ag_fecha">Fecha</label><input type="date" id="ag_fecha" name="ag_fecha" value="<?= cvs_escapar($val['ag_fecha']->format('Y-m-d')) ?>" required></div>
<button type="submit" class="boton">Consultar agenda</button>
</form>
<p class="pequeno">Puedes consultar cualquier fecha, incluidos registros históricos. Las citas canceladas se mantienen en el listado.</p>

<?php if ($val['ag_id_medico'] === null): ?>
<p class="vacio">Selecciona un médico y una fecha para consultar su agenda.</p>
<?php else:
$filaAgenda = $datos['agenda'];
$nombreMedicoA = '';
foreach ($datos['listas']['medicos'] as $op) {
    if ((int) $op['id_medico'] === $val['ag_id_medico']) {
        $nombreMedicoA = trim($op['nombres'] . ' ' . $op['apellidos']) . ($op['activo'] ? '' : ' (inactivo)');
        break;
    }
}
?>
<div class="resumen" role="list" aria-label="Resumen de estados de la agenda">
<span class="pill">Médico: <?= cvs_escapar($nombreMedicoA) ?></span>
<span class="pill">Fecha: <?= cvs_escapar(cvsd_formato_fecha_larga($val['ag_fecha'])) ?></span>
<span class="pill">Total: <?= (int) $filaAgenda['total'] ?></span>
<?php foreach (CVSR_ESTADOS as $estadoClave): ?>
<span class="pill"><?= cvs_escapar($etiquetasEstado[$estadoClave]) ?>: <?= (int) $filaAgenda['estados'][$estadoClave] ?></span>
<?php endforeach; ?>
</div>
<?php if ($filaAgenda['filas'] === array()): ?>
<p class="vacio">El médico no tiene citas registradas para esta fecha.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-label="Agenda diaria del médico">
<table>
<thead><tr><th scope="col">Código</th><th scope="col">Hora</th><th scope="col">Paciente</th><th scope="col">Especialidad</th><th scope="col">Estado</th></tr></thead>
<tbody>
<?php foreach ($filaAgenda['filas'] as $fila): ?>
<tr>
<td><?= cvs_escapar($fila['codigo']) ?></td>
<td><?= cvs_escapar(cvsr_hora_corta($fila['hora_inicio']) . ' – ' . cvsr_hora_corta($fila['hora_fin'])) ?></td>
<td><?= cvs_escapar($fila['paciente']) ?></td>
<td><?= cvs_escapar($fila['especialidad']) ?></td>
<td><span class="pill"><?= cvs_escapar(cvsr_etiqueta_estado($fila['estado'])) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>

</main>
<?php cvsu_cierre(); ?>
</body>
</html>
