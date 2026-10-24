<?php
// RF10 administrativo: registra resultado final, no llegada ni hora real de atención.
// Contrato provisional para integrar con RF06/RF08/RF11:
// - programada/confirmada -> atendida/inasistencia solo después del fin programado.
// - DATE + TIME se interpreta en America/La_Paz; se admiten días anteriores.
// - cancelada/atendida/inasistencia son finales: no rectificar ni revivir desde aquí.
// - Solo se actualizan estado y actualizado_en; se conserva la cita y sus motivos.
// - Los estados finales no ocupan intervalo según la exclusión actual de citas.
// - No crea reservas, no reprograma ni implementa una vista para médicos.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['administrador', 'recepcionista']);

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método no permitido.');
}
$conexion = cvs_conexion();
$estados = ['programada'=>'Programada', 'confirmada'=>'Confirmada', 'atendida'=>'Atendida', 'cancelada'=>'Cancelada', 'inasistencia'=>'Inasistencia'];
$resultados = ['atendida'=>'Atendida', 'inasistencia'=>'Inasistencia'];

function cvs_rf10_id(mixed $valor): int {
    if (!is_string($valor) || preg_match('/\A[1-9][0-9]{0,9}\z/', $valor) !== 1) return 0;
    return filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0;
}

function cvs_rf10_fecha(string $valor): bool {
    if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $valor, $partes) !== 1) return false;
    return checkdate((int)$partes[2], (int)$partes[3], (int)$partes[1]);
}

function cvs_rf10_error(Throwable $ex, string $operacion): void {
    $codigo = (string)$ex->getCode();
    error_log('RF10: ' . $operacion
        . ($ex instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/', $codigo) === 1
            ? '; SQLSTATE ' . $codigo : ''));
}

function cvs_rf10_redirigir(string $tipo, string $mensaje, array $parametros = []): never {
    // Flash sin datos personales ni información clínica.
    $_SESSION['rf10_flash'] = ['tipo'=>$tipo, 'mensaje'=>$mensaje];
    $destino = 'asistencia_citas.php';
    if ($parametros) $destino .= '?' . http_build_query($parametros);
    header('Location: ' . $destino, true, 303);
    exit;
}

function cvs_rf10_impedimento(array $cita): ?string {
    if ($cita['estado'] === 'cancelada') return 'Cita cancelada: no admite resultados de asistencia.';
    if (in_array($cita['estado'], ['atendida', 'inasistencia'], true)) {
        return 'Resultado final ya registrado. No se permiten rectificaciones.';
    }
    if (!in_array($cita['estado'], ['programada', 'confirmada'], true)) {
        return 'El estado actual no permite registrar un resultado.';
    }
    if (!cvs_activo($cita['registrable'])) return 'El horario de la cita aún no ha finalizado.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
        cvs_rf10_redirigir('error', 'Solicitud no válida. Recarga la página y utiliza el formulario de confirmación.');
    }
    $id = cvs_rf10_id($_POST['id_cita'] ?? null);
    $resultado = $_POST['resultado'] ?? null;
    if ($id === 0 || ($_POST['accion'] ?? null) !== 'registrar_resultado'
        || !is_string($resultado) || !array_key_exists($resultado, $resultados)) {
        cvs_rf10_redirigir('error', 'El identificador o la acción solicitada no es válido.');
    }
    if (($_POST['confirmacion'] ?? null) !== '1') {
        cvs_rf10_redirigir('error', 'Debes confirmar expresamente el resultado elegido.', ['confirmar'=>$id, 'resultado'=>$resultado]);
    }

    try {
        $conexion->beginTransaction();
        // Mismo orden de bloqueo que RF09. Evaluar la hora después de la espera.
        $bloquear = $conexion->prepare('SELECT id_cita FROM citas WHERE id_cita = :id FOR UPDATE');
        $bloquear->execute([':id'=>$id]);
        $citaBloqueada = $bloquear->fetch(PDO::FETCH_ASSOC);

        // Revalidar usuario, activo y rol dentro de la transacción.
        // Los bloqueos compartidos mantienen el permiso estable hasta finalizar.
        $permiso = $conexion->prepare('SELECT u.activo, r.nombre AS rol
            FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
            WHERE u.id_usuario = :usuario FOR SHARE OF u, r');
        $permiso->execute([':usuario'=>$usuario['id_usuario']]);
        $identidad = $permiso->fetch(PDO::FETCH_ASSOC);
        if (!$identidad || !cvs_activo($identidad['activo'])
            || !in_array($identidad['rol'], ['administrador', 'recepcionista'], true)) {
            $conexion->rollBack();
            cvs_denegar_acceso();
        }
        if (!$citaBloqueada) {
            $conexion->rollBack();
            cvs_rf10_redirigir('advertencia', 'La cita solicitada no está disponible.');
        }

        // No usar estado ni fechas del formulario. clock_timestamp() avanza incluso
        // si hubo espera por otra transacción, a diferencia de CURRENT_TIMESTAMP.
        $verificar = $conexion->prepare("SELECT estado,
            (estado IN ('programada', 'confirmada')
                AND fecha + hora_fin <= timezone('America/La_Paz', clock_timestamp())) AS registrable
            FROM citas WHERE id_cita = :id");
        $verificar->execute([':id'=>$id]);
        $actual = $verificar->fetch(PDO::FETCH_ASSOC);
        if (!$actual) throw new RuntimeException('RF10: fila no disponible.');
        $impedimento = cvs_rf10_impedimento($actual);
        if ($impedimento !== null) {
            $conexion->rollBack();
            cvs_rf10_redirigir('advertencia', $impedimento);
        }

        // Exclusivamente estas dos columnas. Condiciones repetidas al escribir.
        $guardar = $conexion->prepare("UPDATE citas
            SET estado = :resultado,
                actualizado_en = timezone('America/La_Paz', clock_timestamp())
            WHERE id_cita = :id AND estado IN ('programada', 'confirmada')
                AND fecha + hora_fin <= timezone('America/La_Paz', clock_timestamp())");
        $guardar->execute([':resultado'=>$resultado, ':id'=>$id]);
        if ($guardar->rowCount() !== 1) {
            $conexion->rollBack();
            cvs_rf10_redirigir('advertencia', 'La cita ya no cumple las condiciones para registrar el resultado. Actualiza el listado.');
        }
        $conexion->commit();
        // Volver sin filtros para no ocultar el nuevo estado por el filtro anterior.
        cvs_rf10_redirigir('exito', 'Resultado registrado: ' . $resultados[$resultado] . '. Se conservaron los demás datos de la cita.');
    } catch (Throwable $ex) {
        if ($conexion->inTransaction()) {
            try {
                $conexion->rollBack();
            } catch (Throwable $rollbackEx) {
                cvs_rf10_error($rollbackEx, 'rollback_resultado');
            }
        }
        cvs_rf10_error($ex, 'registrar_resultado');
        cvs_rf10_redirigir('error', 'No fue posible guardar el resultado. Consulta el estado actual antes de volver a intentarlo.');
    }
}

$errores = [];
$flash = $_SESSION['rf10_flash'] ?? null;
unset($_SESSION['rf10_flash']);
$busqueda = cvs_texto($_GET, 'buscar');
$longitudBusqueda = preg_match_all('/./us', $busqueda);
if ($longitudBusqueda === false || $longitudBusqueda > 25 || str_contains($busqueda, "\0")) {
    $errores[] = 'Busca un código válido de hasta 25 caracteres.';
    $busqueda = '';
}
$estado = cvs_texto($_GET, 'estado');
if ($estado === '') $estado = 'todos';
if ($estado !== 'todos' && !array_key_exists($estado, $estados)) {
    $errores[] = 'El filtro de estado no es válido. Se muestran todos los estados.';
    $estado = 'todos';
}
$fecha = cvs_texto($_GET, 'fecha');
if ($fecha !== '' && !cvs_rf10_fecha($fecha)) {
    $errores[] = 'El filtro de fecha no es válido. Utiliza una fecha existente con formato AAAA-MM-DD.';
    $fecha = '';
}
$pagina = cvs_rf10_id($_GET['pagina'] ?? '1');
if ($pagina === 0) $pagina = 1;
$tamano = 50;
$offset = ($pagina - 1) * $tamano;
$filtros = ['buscar'=>$busqueda, 'estado'=>$estado, 'fecha'=>$fecha, 'pagina'=>$pagina];
$urlListado = 'asistencia_citas.php?' . http_build_query($filtros);

// Datos administrativos mínimos; no consultar motivos, teléfonos ni antecedentes.
$seleccion = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
    p.nombres || ' ' || p.apellidos AS paciente,
    m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad,
    (c.estado IN ('programada', 'confirmada')
        AND c.fecha + c.hora_fin <= timezone('America/La_Paz', clock_timestamp())) AS registrable
    FROM citas c JOIN pacientes p ON p.id_paciente = c.id_paciente
    JOIN medicos m ON m.id_medico = c.id_medico
    JOIN especialidades e ON e.id_especialidad = c.id_especialidad";

$confirmar = null;
$resultadoElegido = cvs_texto($_GET, 'resultado');
if (array_key_exists('confirmar', $_GET)) {
    $idConfirmar = cvs_rf10_id($_GET['confirmar']);
    if ($idConfirmar === 0 || !array_key_exists($resultadoElegido, $resultados)) {
        $errores[] = 'La cita o el resultado solicitado para confirmar no es válido.';
    } else {
        try {
            $consulta = $conexion->prepare($seleccion . ' WHERE c.id_cita = :id');
            $consulta->execute([':id'=>$idConfirmar]);
            $confirmar = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$confirmar) {
                $errores[] = 'La cita solicitada no está disponible.';
            } else {
                $impedimento = cvs_rf10_impedimento($confirmar);
                if ($impedimento !== null) {
                    $errores[] = $impedimento;
                    $confirmar = null;
                }
            }
        } catch (Throwable $ex) {
            cvs_rf10_error($ex, 'consultar_confirmacion');
            $errores[] = 'No fue posible consultar la cita para confirmar su resultado.';
        }
    }
}

$lista = [];
$haySiguiente = false;
$listadoDisponible = true;
try {
    $condiciones = [];
    $parametros = [];
    if ($busqueda !== '') {
        $condiciones[] = "c.codigo ILIKE :codigo ESCAPE '!'";
        $parametros[':codigo'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busqueda) . '%';
    }
    if ($estado !== 'todos') {
        $condiciones[] = 'c.estado = :estado';
        $parametros[':estado'] = $estado;
    }
    if ($fecha !== '') {
        $condiciones[] = 'c.fecha = CAST(:fecha AS DATE)';
        $parametros[':fecha'] = $fecha;
    }
    $where = $condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta = $conexion->prepare($seleccion . $where
        . ' ORDER BY c.fecha DESC, c.hora_inicio DESC, c.id_cita DESC LIMIT :limite OFFSET :offset');
    foreach ($parametros as $clave => $valor) $consulta->bindValue($clave, $valor, PDO::PARAM_STR);
    $consulta->bindValue(':limite', $tamano + 1, PDO::PARAM_INT);
    $consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
    $consulta->execute();
    $lista = $consulta->fetchAll(PDO::FETCH_ASSOC);
    $haySiguiente = count($lista) > $tamano;
    if ($haySiguiente) array_pop($lista);
} catch (Throwable $ex) {
    cvs_rf10_error($ex, 'listar_citas');
    $listadoDisponible = false;
    $errores[] = 'No fue posible consultar el listado de citas. Vuelve a intentarlo más tarde.';
}
$csrf = cvs_csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Control de asistencia | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('asistencia', 'Control de asistencia'); ?>
<main class="ui-contenido">
<p><a class="boton secundario" href="index.php">← Volver al inicio</a></p>
<?php if (is_array($flash) && is_string($flash['mensaje'] ?? null)): ?>
<div class="mensaje <?= cvs_escapar(in_array($flash['tipo'] ?? '', ['error', 'advertencia'], true) ? $flash['tipo'] : '') ?>" role="status"><?= cvs_escapar($flash['mensaje']) ?></div>
<?php endif; ?>
<?php foreach ($errores as $error): ?><div class="mensaje error" role="alert"><?= cvs_escapar($error) ?></div><?php endforeach; ?>

<?php if ($confirmar): ?>
<section class="panel" aria-labelledby="titulo-confirmacion">
<h2 id="titulo-confirmacion">Confirmar resultado de la cita</h2>
<p>Revisa la cita y el resultado elegido antes de guardar. Este módulo no permite rectificar un resultado final.</p>
<p class="resultado">Resultado elegido: <?= cvs_escapar($resultados[$resultadoElegido]) ?></p>
<dl class="detalle">
<div><dt>Código</dt><dd><?= cvs_escapar($confirmar['codigo']) ?></dd></div>
<div><dt>Paciente</dt><dd><?= cvs_escapar($confirmar['paciente']) ?></dd></div>
<div><dt>Médico</dt><dd><?= cvs_escapar($confirmar['medico']) ?></dd></div>
<div><dt>Especialidad</dt><dd><?= cvs_escapar($confirmar['especialidad']) ?></dd></div>
<div><dt>Fecha y horario · America/La_Paz</dt><dd><?= cvs_escapar($confirmar['fecha']) ?> · <?= cvs_escapar($confirmar['hora_inicio']) ?> a <?= cvs_escapar($confirmar['hora_fin']) ?></dd></div>
<div><dt>Estado actual</dt><dd><?= cvs_escapar($estados[$confirmar['estado']] ?? $confirmar['estado']) ?></dd></div>
</dl>
<form method="post" action="asistencia_citas.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar($csrf) ?>">
<input type="hidden" name="accion" value="registrar_resultado">
<input type="hidden" name="id_cita" value="<?= cvs_escapar($confirmar['id_cita']) ?>">
<input type="hidden" name="resultado" value="<?= cvs_escapar($resultadoElegido) ?>">
<div class="campo"><label class="confirmacion"><input type="checkbox" name="confirmacion" value="1" required><span>Confirmo que deseo registrar esta cita como <?= cvs_escapar($resultados[$resultadoElegido]) ?>.</span></label></div>
<div class="acciones"><button type="submit">Guardar resultado: <?= cvs_escapar($resultados[$resultadoElegido]) ?></button><a class="boton secundario" href="<?= cvs_escapar($urlListado) ?>">Volver sin guardar</a></div>
</form>
<p class="pequeno">El servidor volverá a comprobar permisos, estado y finalización del horario al guardar.</p>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="titulo-listado">
<h2 id="titulo-listado">Citas y resultados de asistencia</h2>
<form method="get" action="asistencia_citas.php" class="filtros">
<div><label for="buscar">Buscar por código</label><input id="buscar" name="buscar" maxlength="25" value="<?= cvs_escapar($busqueda) ?>" placeholder="Código completo o parcial"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos los estados</option><?php foreach ($estados as $valor => $etiqueta): ?><option value="<?= cvs_escapar($valor) ?>" <?= $estado === $valor ? 'selected' : '' ?>><?= cvs_escapar($etiqueta) ?></option><?php endforeach; ?></select></div>
<div><label for="fecha">Fecha de la cita</label><input type="date" id="fecha" name="fecha" value="<?= cvs_escapar($fecha) ?>"></div>
<button type="submit">Buscar</button><a class="boton secundario" href="asistencia_citas.php">Limpiar</a>
</form>
<p class="pequeno">Solo citas programadas o confirmadas cuyo horario haya finalizado, incluidos días anteriores. Referencia temporal: America/La_Paz, calculada en PostgreSQL. No registra llegada ni hora real de atención y no modifica citas con estado final.</p>
<div class="tabla" tabindex="0" role="region" aria-label="Listado de citas con desplazamiento horizontal"><table><thead><tr><th scope="col">Código</th><th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Fecha</th><th scope="col">Horario</th><th scope="col">Estado</th><th scope="col">Registrar resultado</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="8"><?= $listadoDisponible ? 'No se encontraron citas con los filtros seleccionados.' : 'Listado temporalmente no disponible.' ?></td></tr><?php endif; ?>
<?php foreach ($lista as $cita): ?>
<tr>
<td><?= cvs_escapar($cita['codigo']) ?></td><td><?= cvs_escapar($cita['paciente']) ?></td><td><?= cvs_escapar($cita['medico']) ?></td><td><?= cvs_escapar($cita['especialidad']) ?></td><td><?= cvs_escapar($cita['fecha']) ?></td><td><?= cvs_escapar($cita['hora_inicio']) ?><br>a <?= cvs_escapar($cita['hora_fin']) ?></td>
<td><span class="etiqueta <?= $cita['estado'] === 'cancelada' ? 'cancelada' : '' ?>"><?= cvs_escapar($estados[$cita['estado']] ?? $cita['estado']) ?></span></td>
<td><?php if (cvs_activo($cita['registrable'])): ?>
<p class="disponible">Horario finalizado</p>
<div class="acciones">
<?php foreach ($resultados as $valor => $etiqueta): ?>
<a class="boton <?= $valor === 'inasistencia' ? 'advertencia' : '' ?>" href="<?= cvs_escapar($urlListado . '&' . http_build_query(['confirmar'=>$cita['id_cita'], 'resultado'=>$valor])) ?>"><?= cvs_escapar($etiqueta) ?></a>
<?php endforeach; ?>
</div>
<?php else: ?><span class="pequeno"><?= cvs_escapar(cvs_rf10_impedimento($cita)) ?></span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($listadoDisponible): ?>
<nav class="acciones paginacion" aria-label="Páginas de citas">
<?php if ($pagina > 1): ?><a class="boton secundario" href="<?= cvs_escapar('asistencia_citas.php?' . http_build_query(array_replace($filtros, ['pagina'=>$pagina - 1]))) ?>">← Anterior</a><?php endif; ?>
<span class="pequeno">Página <?= cvs_escapar($pagina) ?> · <?= cvs_escapar(count($lista)) ?> citas mostradas · Hasta 50 por página</span>
<?php if ($haySiguiente): ?><a class="boton secundario" href="<?= cvs_escapar('asistencia_citas.php?' . http_build_query(array_replace($filtros, ['pagina'=>$pagina + 1]))) ?>">Siguiente →</a><?php endif; ?>
</nav>
<?php endif; ?>
</section>
</main>
<?php cvsu_cierre(); ?>
</body>
</html>
