<?php
// RF12: consulta administrativa de citas, no historia clínica ni auditoría de cambios.
// Compatible con historial_citas.php?id_paciente=ID_VALIDO, sin ampliar permisos.
// Lee los estados actuales de RF06/RF09/RF10; conserva visibles los cinco estados,
// citas futuras y registros asociados inactivos. No modifica ninguna tabla.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
cvs_exigir_roles(['administrador', 'recepcionista']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Método no permitido. Este módulo solo admite consultas mediante GET.');
}

function cvs_rf12_texto(array $origen, string $campo, string $etiqueta, int $maximo, array &$errores): string {
    if (!array_key_exists($campo, $origen)) return '';
    $valor = $origen[$campo];
    if (!is_string($valor) || strlen($valor) > $maximo * 4 || str_contains($valor, "\0")) {
        $errores[] = $etiqueta . ': el parámetro debe ser texto válido de hasta ' . $maximo . ' caracteres.';
        return '';
    }
    $longitud = preg_match_all('/./us', $valor);
    if ($longitud === false || $longitud > $maximo) {
        $errores[] = $etiqueta . ': el texto no es UTF-8 válido o supera ' . $maximo . ' caracteres.';
        return '';
    }
    return trim($valor);
}

function cvs_rf12_entero(mixed $valor): int {
    // Validar antes de convertir: rechazar arrays, signos, decimales y desbordamientos.
    if (!is_string($valor) || preg_match('/\A[1-9][0-9]{0,9}\z/', $valor) !== 1) return 0;
    return filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0;
}

function cvs_rf12_fecha(string $valor): bool {
    if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $valor, $partes) !== 1) return false;
    return checkdate((int)$partes[2], (int)$partes[3], (int)$partes[1]);
}

function cvs_rf12_patron(string $valor): string {
    // Tratar %, _ y el escape como texto literal, no como comodines del usuario.
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valor) . '%';
}

function cvs_rf12_error(Throwable $ex, string $operacion): void {
    $codigo = (string)$ex->getCode();
    error_log('RF12: ' . $operacion
        . ($ex instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/', $codigo) === 1
            ? '; SQLSTATE ' . $codigo : ''));
}

function cvs_rf12_url(array $parametros): string {
    return 'historial_citas.php?' . http_build_query($parametros);
}

$estados = ['programada'=>'Programada', 'confirmada'=>'Confirmada', 'atendida'=>'Atendida', 'cancelada'=>'Cancelada', 'inasistencia'=>'Inasistencia'];
$errores = [];
$avisos = [];
$permitidos = ['buscar_paciente', 'id_paciente', 'codigo', 'estado', 'desde', 'hasta', 'pagina'];
foreach (array_keys($_GET) as $campo) {
    if (!in_array($campo, $permitidos, true)) {
        $errores[] = 'La dirección contiene parámetros no reconocidos. Utiliza los formularios de esta página.';
        break;
    }
}
$busquedaPaciente = cvs_rf12_texto($_GET, 'buscar_paciente', 'Búsqueda de paciente', 100, $errores);
$codigo = cvs_rf12_texto($_GET, 'codigo', 'Código de cita', 25, $errores);
$estado = cvs_rf12_texto($_GET, 'estado', 'Estado', 20, $errores);
$desde = cvs_rf12_texto($_GET, 'desde', 'Fecha desde', 10, $errores);
$hasta = cvs_rf12_texto($_GET, 'hasta', 'Fecha hasta', 10, $errores);
if ($estado === '') $estado = 'todos';
if ($estado !== 'todos' && !array_key_exists($estado, $estados)) {
    $errores[] = 'El estado debe ser uno de los valores disponibles en el selector.';
}
if ($desde !== '' && !cvs_rf12_fecha($desde)) $errores[] = 'La fecha desde no existe o no tiene formato AAAA-MM-DD.';
if ($hasta !== '' && !cvs_rf12_fecha($hasta)) $errores[] = 'La fecha hasta no existe o no tiene formato AAAA-MM-DD.';
if ($desde !== '' && $hasta !== '' && cvs_rf12_fecha($desde) && cvs_rf12_fecha($hasta) && $desde > $hasta) {
    $errores[] = 'La fecha desde no puede ser posterior a la fecha hasta.';
}
if (array_key_exists('buscar_paciente', $_GET) && preg_match_all('/./us', $busquedaPaciente) < 2) {
    $errores[] = 'Escribe al menos 2 caracteres del nombre, apellido o CI para buscar pacientes.';
}
$idPaciente = array_key_exists('id_paciente', $_GET) ? cvs_rf12_entero($_GET['id_paciente']) : 0;
if (array_key_exists('id_paciente', $_GET) && $idPaciente === 0) {
    $errores[] = 'El identificador del paciente debe ser un entero positivo válido.';
}
$pagina = cvs_rf12_entero($_GET['pagina'] ?? '1');
if ($pagina === 0) {
    $errores[] = 'La página solicitada no es válida. Debe ser un entero positivo dentro del rango admitido.';
    $pagina = 1;
}
$entradaValida = !$errores;
$conexion = cvs_conexion();

$paciente = null;
if ($idPaciente > 0) {
    try {
        // Proyección mínima. Los filtros inválidos no impiden corregir el formulario
        // de un paciente válido, pero sí bloquean la consulta de sus citas.
        $consulta = $conexion->prepare('SELECT id_paciente, nombres, apellidos, activo
            FROM pacientes WHERE id_paciente = :id');
        $consulta->execute([':id'=>$idPaciente]);
        $paciente = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$paciente) $errores[] = 'No se encontró el paciente solicitado. Puedes localizar otro mediante el buscador.';
    } catch (Throwable $ex) {
        cvs_rf12_error($ex, 'consultar_paciente');
        $errores[] = 'No fue posible consultar el paciente. Vuelve a intentarlo más tarde.';
    }
}

$coincidencias = [];
$masPacientes = false;
$busquedaRealizada = false;
if ($busquedaPaciente !== '' && $entradaValida) {
    try {
        $consulta = $conexion->prepare("SELECT id_paciente, nombres, apellidos, ci, activo
            FROM pacientes
            WHERE nombres ILIKE :nombres ESCAPE '!'
                OR apellidos ILIKE :apellidos ESCAPE '!'
                OR (nombres || ' ' || apellidos) ILIKE :completo ESCAPE '!'
                OR ci ILIKE :ci ESCAPE '!'
            ORDER BY apellidos, nombres, id_paciente LIMIT :limite");
        $patron = cvs_rf12_patron($busquedaPaciente);
        foreach ([':nombres', ':apellidos', ':completo', ':ci'] as $parametro) {
            $consulta->bindValue($parametro, $patron, PDO::PARAM_STR);
        }
        $consulta->bindValue(':limite', 21, PDO::PARAM_INT);
        $consulta->execute();
        $coincidencias = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $masPacientes = count($coincidencias) > 20;
        if ($masPacientes) array_pop($coincidencias);
        $busquedaRealizada = true;
    } catch (Throwable $ex) {
        cvs_rf12_error($ex, 'buscar_pacientes');
        $errores[] = 'No fue posible buscar pacientes. Vuelve a intentarlo más tarde.';
    }
}

$citas = [];
$totalPaciente = 0;
$totalFiltradas = 0;
$paginas = 1;
$tamano = 50;
$consultaDisponible = false;
$filtros = ['id_paciente'=>$idPaciente, 'codigo'=>$codigo, 'estado'=>$estado, 'desde'=>$desde, 'hasta'=>$hasta];
if ($paciente && $entradaValida) {
    try {
        $condiciones = [];
        $parametros = [':paciente'=>$idPaciente];
        if ($codigo !== '') {
            $condiciones[] = "c.codigo ILIKE :codigo ESCAPE '!'";
            $parametros[':codigo'] = cvs_rf12_patron($codigo);
        }
        if ($estado !== 'todos') {
            $condiciones[] = 'c.estado = :estado';
            $parametros[':estado'] = $estado;
        }
        if ($desde !== '') {
            $condiciones[] = 'c.fecha >= CAST(:desde AS DATE)';
            $parametros[':desde'] = $desde;
        }
        if ($hasta !== '') {
            $condiciones[] = 'c.fecha <= CAST(:hasta AS DATE)';
            $parametros[':hasta'] = $hasta;
        }
        $condicionFiltros = $condiciones ? implode(' AND ', $condiciones) : 'TRUE';
        // Conteos completos, sin LIMIT 100. Los filtros nunca cambian de paciente.
        $conteo = $conexion->prepare('SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE ' . $condicionFiltros . ') AS filtradas
            FROM citas c WHERE c.id_paciente = :paciente');
        foreach ($parametros as $clave => $valor) {
            $conteo->bindValue($clave, $valor, $clave === ':paciente' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $conteo->execute();
        $cantidades = $conteo->fetch(PDO::FETCH_ASSOC);
        $totalPaciente = (int)$cantidades['total'];
        $totalFiltradas = (int)$cantidades['filtradas'];
        $paginas = max(1, (int)ceil($totalFiltradas / $tamano));
        // Acotar a las páginas reales ANTES de calcular OFFSET, sin recortar el
        // historial completo ni ejecutar offsets exagerados enviados por URL.
        if ($pagina > $paginas) {
            $pagina = $paginas;
            $avisos[] = 'La página solicitada supera los resultados disponibles. Se muestra la última página disponible.';
        }
        if ($totalFiltradas > 0) {
            $consulta = $conexion->prepare('SELECT c.codigo, c.fecha, c.hora_inicio, c.hora_fin,
                c.estado, m.nombres AS medico_nombres, m.apellidos AS medico_apellidos,
                e.nombre AS especialidad
                FROM citas c JOIN medicos m ON m.id_medico = c.id_medico
                JOIN especialidades e ON e.id_especialidad = c.id_especialidad
                WHERE c.id_paciente = :paciente AND ' . $condicionFiltros . '
                ORDER BY c.fecha DESC, c.hora_inicio DESC, c.id_cita DESC
                LIMIT :limite OFFSET :offset');
            foreach ($parametros as $clave => $valor) {
                $consulta->bindValue($clave, $valor, $clave === ':paciente' ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $consulta->bindValue(':limite', $tamano, PDO::PARAM_INT);
            $consulta->bindValue(':offset', ($pagina - 1) * $tamano, PDO::PARAM_INT);
            $consulta->execute();
            $citas = $consulta->fetchAll(PDO::FETCH_ASSOC);
        }
        $consultaDisponible = true;
    } catch (Throwable $ex) {
        cvs_rf12_error($ex, 'consultar_historial');
        $errores[] = 'No fue posible consultar el historial. Vuelve a intentarlo más tarde.';
    }
}
$primera = $citas ? ($pagina - 1) * $tamano + 1 : 0;
$ultima = $citas ? $primera + count($citas) - 1 : 0;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial de citas | Clínica Vida Salud</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f2f7f8;color:#17333b;font-family:Arial,sans-serif}
header{background:#146d73;color:#fff;padding:24px max(18px,calc((100vw - 1180px)/2))}
header h1{margin:0;font-size:26px}header p{margin:8px 0 0;line-height:1.5}
main{max-width:1180px;margin:26px auto;padding:0 16px}
.panel{min-width:0;background:#fff;border-radius:14px;padding:24px;margin-bottom:22px;box-shadow:0 3px 18px #143b4215}
h2{font-size:21px;margin:0 0 18px}p{line-height:1.6}
label{display:block;font-weight:bold;font-size:14px;margin-bottom:6px}
input,select{width:100%;min-width:0;padding:11px;border:1px solid #c7d6d9;border-radius:7px;font:inherit;background:#fff}
button,.boton{display:inline-block;background:#146d73;color:#fff;border:0;border-radius:8px;padding:10px 15px;font-weight:bold;cursor:pointer;text-decoration:none;font-size:14px}
.boton.secundario{background:#e7f0f1;color:#155960}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,.tabla:focus-visible{outline:3px solid #db9c22;outline-offset:3px}
.acciones{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:20px}
.mensaje{border-radius:7px;padding:12px;margin:12px 0;background:#e7f0f1;line-height:1.6}
.mensaje.error{background:#ffe7e7}.mensaje.aviso{background:#fff3d8}
.filtros{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.filtros>div{flex:1;min-width:170px}
.pequeno{font-size:13px;color:#49626a;line-height:1.6}
.tabla{max-width:100%;min-width:0;overflow-x:auto;overscroll-behavior-x:contain}
table{width:100%;border-collapse:collapse}
th,td{padding:12px;text-align:left;border-bottom:1px solid #e4eeee;font-size:14px;line-height:1.5;vertical-align:top;word-break:normal}
th{background:#eaf4f4;white-space:nowrap}
.pacientes{min-width:660px}.pacientes td:first-child{min-width:220px;overflow-wrap:break-word}
.pacientes td:nth-child(2){min-width:160px;white-space:nowrap}.pacientes .boton{white-space:nowrap}
.citas{min-width:1080px;table-layout:auto}
.citas th:nth-child(1),.citas td:nth-child(1):not([colspan]){min-width:160px;white-space:nowrap}
.citas th:nth-child(2),.citas td:nth-child(2){min-width:124px;white-space:nowrap}
.citas th:nth-child(3),.citas td:nth-child(3),.citas th:nth-child(4),.citas td:nth-child(4){min-width:112px;white-space:nowrap}
.citas th:nth-child(5),.citas td:nth-child(5){min-width:210px;overflow-wrap:break-word}
.citas th:nth-child(6),.citas td:nth-child(6){min-width:190px;overflow-wrap:break-word}
.citas th:nth-child(7),.citas td:nth-child(7){min-width:128px;white-space:nowrap}
.etiqueta{display:inline-block;font-size:12px;border-radius:7px;background:#eaf2f3;padding:6px 8px;white-space:nowrap}
.inactivo,.cancelada{background:#ffe8e8}.paciente{overflow-wrap:anywhere}.paginacion{margin-top:20px;margin-bottom:0}
@media(max-width:700px){.panel{padding:16px}.filtros{display:block}.filtros>div{margin-bottom:12px}header h1{font-size:23px}.acciones{gap:8px}}
</style>
</head>
<body>
<header><h1>Clínica Vida Salud</h1><p>RF12 · Historial de citas médicas</p><p>Consulta administrativa · Administrador y recepcionista</p></header>
<main>
<nav class="acciones" aria-label="Navegación del módulo"><a class="boton secundario" href="pacientes.php">← Volver a pacientes</a><a class="boton secundario" href="index.php">Ir al inicio</a></nav>
<?php foreach ($errores as $error): ?><div class="mensaje error" role="alert"><?= cvs_escapar($error) ?></div><?php endforeach; ?>
<?php foreach ($avisos as $aviso): ?><div class="mensaje aviso" role="status"><?= cvs_escapar($aviso) ?></div><?php endforeach; ?>

<section class="panel" aria-labelledby="titulo-pacientes">
<h2 id="titulo-pacientes">Seleccionar paciente</h2>
<form method="get" action="historial_citas.php" class="filtros">
<div><label for="buscar_paciente">Nombre, apellido o CI</label><input id="buscar_paciente" name="buscar_paciente" minlength="2" maxlength="100" required value="<?= cvs_escapar($busquedaPaciente) ?>" aria-describedby="ayuda-pacientes" placeholder="Ej.: apellido o marcador ficticio"></div>
<button type="submit">Buscar pacientes</button><a class="boton secundario" href="historial_citas.php">Limpiar selección</a>
</form>
<p id="ayuda-pacientes" class="pequeno">Escribe al menos 2 caracteres. Se muestran hasta 20 coincidencias, incluidos pacientes inactivos. No se listan pacientes sin un criterio de búsqueda.</p>
<?php if ($busquedaRealizada): ?>
<?php if ($masPacientes): ?><div class="mensaje aviso" role="status">Hay más de 20 coincidencias. Precisa el nombre, apellido o CI para localizar al paciente.</div><?php endif; ?>
<?php if (!$coincidencias): ?><p role="status">No se encontraron pacientes con ese criterio.</p><?php else: ?>
<p class="pequeno"><?= cvs_escapar(count($coincidencias)) ?> coincidencias mostradas.</p>
<div class="tabla" tabindex="0" role="region" aria-label="Pacientes encontrados"><table class="pacientes"><thead><tr><th scope="col">Paciente</th><th scope="col">CI</th><th scope="col">Situación</th><th scope="col">Acción</th></tr></thead><tbody>
<?php foreach ($coincidencias as $opcion): ?>
<tr><td><?= cvs_escapar($opcion['nombres'] . ' ' . $opcion['apellidos']) ?></td><td><?= cvs_escapar($opcion['ci'] ?? 'Sin CI') ?></td><td><span class="etiqueta <?= cvs_activo($opcion['activo']) ? '' : 'inactivo' ?>"><?= cvs_activo($opcion['activo']) ? 'Activo' : 'Inactivo' ?></span></td><td><a class="boton" href="<?= cvs_escapar(cvs_rf12_url(['id_paciente'=>$opcion['id_paciente']])) ?>#historial">Ver historial</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php endif; ?>
</section>

<?php if ($paciente): ?>
<section class="panel" id="historial" aria-labelledby="titulo-historial">
<h2 id="titulo-historial" class="paciente">Historial de <?= cvs_escapar($paciente['nombres'] . ' ' . $paciente['apellidos']) ?></h2>
<p><span class="etiqueta <?= cvs_activo($paciente['activo']) ? '' : 'inactivo' ?>"><?= cvs_activo($paciente['activo']) ? 'Paciente activo' : 'Paciente inactivo · Historial conservado' ?></span> <span class="pequeno">ID de paciente: <?= cvs_escapar($paciente['id_paciente']) ?></span></p>
<form method="get" action="historial_citas.php#historial" class="filtros">
<input type="hidden" name="id_paciente" value="<?= cvs_escapar($paciente['id_paciente']) ?>">
<div><label for="codigo">Código de cita</label><input id="codigo" name="codigo" maxlength="25" value="<?= cvs_escapar($codigo) ?>" placeholder="Código completo o parcial"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option><?php foreach ($estados as $valor => $etiqueta): ?><option value="<?= cvs_escapar($valor) ?>" <?= $estado === $valor ? 'selected' : '' ?>><?= cvs_escapar($etiqueta) ?></option><?php endforeach; ?></select></div>
<div><label for="desde">Fecha desde</label><input type="date" id="desde" name="desde" value="<?= cvs_escapar($desde) ?>"></div>
<div><label for="hasta">Fecha hasta</label><input type="date" id="hasta" name="hasta" value="<?= cvs_escapar($hasta) ?>"></div>
<button type="submit">Filtrar citas</button><a class="boton secundario" href="<?= cvs_escapar(cvs_rf12_url(['id_paciente'=>$idPaciente])) ?>#historial">Quitar filtros</a>
</form>
<p class="pequeno">Incluye citas pasadas y futuras en cualquiera de los cinco estados. Las fechas desde y hasta son inclusivas. Orden: fecha, hora de inicio e ID de cita descendentes. No se muestran motivos ni datos clínicos.</p>
<?php if ($consultaDisponible): ?>
<p class="pequeno" role="status">Total del paciente: <?= cvs_escapar($totalPaciente) ?> citas · Coinciden con los filtros: <?= cvs_escapar($totalFiltradas) ?> · Mostrando <?= cvs_escapar($primera) ?>–<?= cvs_escapar($ultima) ?>.</p>
<div class="tabla" tabindex="0" role="region" aria-label="Historial de citas con desplazamiento horizontal"><table class="citas"><thead><tr><th scope="col">Código</th><th scope="col">Fecha</th><th scope="col">Hora inicio</th><th scope="col">Hora fin</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Estado</th></tr></thead><tbody>
<?php if (!$citas): ?><tr><td colspan="7"><?= $totalPaciente === 0 ? 'Este paciente todavía no tiene citas registradas.' : 'No hay citas que cumplan los filtros seleccionados. Puedes modificar los criterios o quitar los filtros.' ?></td></tr><?php endif; ?>
<?php foreach ($citas as $cita): ?>
<tr><td><?= cvs_escapar($cita['codigo']) ?></td><td><?= cvs_escapar($cita['fecha']) ?></td><td><?= cvs_escapar($cita['hora_inicio']) ?></td><td><?= cvs_escapar($cita['hora_fin']) ?></td><td><?= cvs_escapar($cita['medico_nombres'] . ' ' . $cita['medico_apellidos']) ?></td><td><?= cvs_escapar($cita['especialidad']) ?></td><td><span class="etiqueta <?= $cita['estado'] === 'cancelada' ? 'cancelada' : '' ?>"><?= cvs_escapar($estados[$cita['estado']] ?? $cita['estado']) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<nav class="acciones paginacion" aria-label="Páginas del historial">
<?php if ($pagina > 1): ?><a class="boton secundario" href="<?= cvs_escapar(cvs_rf12_url(array_replace($filtros, ['pagina'=>$pagina - 1]))) ?>#historial">← Anterior</a><?php endif; ?>
<span class="pequeno">Página <?= cvs_escapar($pagina) ?> de <?= cvs_escapar($paginas) ?> · Hasta 50 citas por página</span>
<?php if ($pagina < $paginas): ?><a class="boton secundario" href="<?= cvs_escapar(cvs_rf12_url(array_replace($filtros, ['pagina'=>$pagina + 1]))) ?>#historial">Siguiente →</a><?php endif; ?>
</nav>
<?php else: ?><p class="mensaje" role="status"><?= $entradaValida ? 'El historial no está disponible temporalmente.' : 'No se consultaron las citas porque hay parámetros inválidos. Corrige los filtros o utiliza «Quitar filtros».' ?></p><?php endif; ?>
</section>
<?php elseif (!array_key_exists('id_paciente', $_GET)): ?><p class="mensaje" role="status">Busca y selecciona un paciente para consultar su historial.</p><?php endif; ?>
</main>
</body>
</html>
