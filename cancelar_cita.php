<?php
// RF09 independiente. Contrato de integración con RF06 y los módulos de citas:
// - Solo administrador/recepcionista; programada o confirmada y aún no iniciada.
// - DATE + TIME se interpreta provisionalmente en America/La_Paz.
// - Cancelar conserva la cita: solo cambia estado, motivo_cancelacion y actualizado_en.
// - cancelada no ocupa intervalo según ex_citas_medico_sin_superposicion.
// - Los demás módulos deberán validar transiciones y no revivir citas canceladas.
// - No crea reservas, modifica horarios, notifica ni implementa reprogramación.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['administrador', 'recepcionista']);

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método no permitido.');
}
$conexion = cvs_conexion();

function cvs_rf09_id(mixed $valor): int {
    if (!is_string($valor)) return 0;
    if (preg_match('/\A[1-9][0-9]{0,9}\z/', $valor) !== 1) return 0;
    return filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0;
}

function cvs_rf09_error(Throwable $ex, string $operacion): void {
    $codigo = (string)$ex->getCode();
    error_log('RF09: ' . $operacion
        . ($ex instanceof PDOException && preg_match('/\A[A-Z0-9]{5}\z/', $codigo) === 1
            ? '; SQLSTATE ' . $codigo : ''));
}

function cvs_rf09_redirigir(string $tipo, string $mensaje, array $parametros = []): never {
    // No conservar motivos ni información clínica en flash, URL o registros.
    $_SESSION['rf09_flash'] = ['tipo'=>$tipo, 'mensaje'=>$mensaje];
    $destino = 'cancelar_cita.php';
    if ($parametros) $destino .= '?' . http_build_query($parametros);
    header('Location: ' . $destino, true, 303);
    exit;
}

function cvs_rf09_impedimento(array $cita): ?string {
    if ($cita['estado'] === 'cancelada') return 'Esta cita ya está cancelada. Se conserva su cancelación original.';
    if (!in_array($cita['estado'], ['programada', 'confirmada'], true)) {
        return 'El estado de esta cita no permite cancelarla.';
    }
    if (!cvs_activo($cita['cancelable'])) return 'La cita ya comenzó y no puede cancelarse.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
        cvs_rf09_redirigir('error', 'Solicitud no válida. Recarga la página y utiliza el formulario de confirmación.');
    }
    $id = cvs_rf09_id($_POST['id_cita'] ?? null);
    if ($id === 0 || ($_POST['accion'] ?? null) !== 'cancelar') {
        cvs_rf09_redirigir('error', 'La solicitud de cancelación no es válida.');
    }
    if (($_POST['confirmacion'] ?? null) !== '1') {
        cvs_rf09_redirigir('error', 'Debes confirmar expresamente la cancelación.', ['confirmar'=>$id]);
    }
    $motivo = $_POST['motivo_cancelacion'] ?? '';
    $longitud = is_string($motivo) ? preg_match_all('/./us', $motivo) : false;
    if ($longitud === false || $longitud > 500 || str_contains($motivo, "\0")) {
        cvs_rf09_redirigir('error', 'El motivo debe ser texto UTF-8 válido, sin caracteres nulos y de hasta 500 caracteres. Vuelve a introducirlo.', ['confirmar'=>$id]);
    }
    $motivo = trim($motivo);

    try {
        $conexion->beginTransaction();
        // Primero bloquear la fila. No calcular la hora antes de una posible espera.
        $bloquear = $conexion->prepare('SELECT id_cita, estado FROM citas WHERE id_cita = :id FOR UPDATE');
        $bloquear->execute([':id'=>$id]);
        $citaBloqueada = $bloquear->fetch(PDO::FETCH_ASSOC);

        // Revalidación dentro de la transacción, después de adquirir el bloqueo.
        // Los bloqueos compartidos impiden cambiar rol/activo hasta finalizar.
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
            cvs_rf09_redirigir('advertencia', 'La cita solicitada no está disponible.');
        }

        // clock_timestamp(), no CURRENT_TIMESTAMP: el reloj debe avanzar incluso
        // si la transacción esperó. La comparación es estricta: al inicio ya no vale.
        $verificar = $conexion->prepare("SELECT estado,
            (estado IN ('programada', 'confirmada')
                AND fecha + hora_inicio > timezone('America/La_Paz', clock_timestamp())) AS cancelable
            FROM citas WHERE id_cita = :id");
        $verificar->execute([':id'=>$id]);
        $actual = $verificar->fetch(PDO::FETCH_ASSOC);
        if (!$actual) throw new RuntimeException('RF09: fila no disponible.');
        $impedimento = cvs_rf09_impedimento($actual);
        if ($impedimento !== null) {
            $conexion->rollBack();
            cvs_rf09_redirigir('advertencia', $impedimento);
        }

        // No usar estado del formulario. Repetir estado y hora al efectuar el UPDATE.
        $cancelar = $conexion->prepare("UPDATE citas
            SET estado = 'cancelada', motivo_cancelacion = :motivo,
                actualizado_en = timezone('America/La_Paz', clock_timestamp())
            WHERE id_cita = :id AND estado IN ('programada', 'confirmada')
                AND fecha + hora_inicio > timezone('America/La_Paz', clock_timestamp())");
        $cancelar->execute([':motivo'=>$motivo !== '' ? $motivo : null, ':id'=>$id]);
        if ($cancelar->rowCount() !== 1) {
            $conexion->rollBack();
            cvs_rf09_redirigir('advertencia', 'La cita ya no cumple las condiciones de cancelación. Actualiza el listado.');
        }
        $conexion->commit();
        // Quitar filtros tras cancelar para no ocultarla por su nuevo estado.
        cvs_rf09_redirigir('exito', 'Cita cancelada correctamente. Se conservaron sus datos y su historial.');
    } catch (Throwable $ex) {
        if ($conexion->inTransaction()) {
            try {
                $conexion->rollBack();
            } catch (Throwable $rollbackEx) {
                cvs_rf09_error($rollbackEx, 'rollback_cancelacion');
            }
        }
        cvs_rf09_error($ex, 'cancelar_cita');
        cvs_rf09_redirigir('error', 'No fue posible completar la cancelación. Consulta el estado actual antes de volver a intentarlo.');
    }
}

$estados = ['programada'=>'Programada', 'confirmada'=>'Confirmada', 'atendida'=>'Atendida', 'cancelada'=>'Cancelada', 'inasistencia'=>'Inasistencia'];
$errores = [];
$flash = $_SESSION['rf09_flash'] ?? null;
unset($_SESSION['rf09_flash']);
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
$pagina = cvs_rf09_id($_GET['pagina'] ?? '1');
if ($pagina === 0) $pagina = 1;
$tamano = 50;
$offset = ($pagina - 1) * $tamano;
$urlListado = 'cancelar_cita.php?' . http_build_query(['buscar'=>$busqueda, 'estado'=>$estado, 'pagina'=>$pagina]);

// Solo datos administrativos para identificar citas; no se consultan antecedentes,
// teléfonos, correos ni el motivo clínico original.
$seleccion = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
    p.nombres || ' ' || p.apellidos AS paciente,
    m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad,
    (c.estado IN ('programada', 'confirmada')
        AND c.fecha + c.hora_inicio > timezone('America/La_Paz', clock_timestamp())) AS cancelable
    FROM citas c JOIN pacientes p ON p.id_paciente = c.id_paciente
    JOIN medicos m ON m.id_medico = c.id_medico
    JOIN especialidades e ON e.id_especialidad = c.id_especialidad";

$confirmar = null;
if (array_key_exists('confirmar', $_GET)) {
    $idConfirmar = cvs_rf09_id($_GET['confirmar']);
    if ($idConfirmar === 0) {
        $errores[] = 'El identificador de la cita no es válido.';
    } else {
        try {
            $consulta = $conexion->prepare($seleccion . ' WHERE c.id_cita = :id');
            $consulta->execute([':id'=>$idConfirmar]);
            $confirmar = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$confirmar) {
                $errores[] = 'La cita solicitada no está disponible.';
            } else {
                $impedimento = cvs_rf09_impedimento($confirmar);
                if ($impedimento !== null) {
                    $errores[] = $impedimento;
                    $confirmar = null;
                }
            }
        } catch (Throwable $ex) {
            cvs_rf09_error($ex, 'consultar_confirmacion');
            $errores[] = 'No fue posible consultar la cita para confirmar su cancelación.';
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
    cvs_rf09_error($ex, 'listar_citas');
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
<title>Cancelación de citas | Clínica Vida Salud</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f2f7f8;color:#17333b;font-family:Arial,sans-serif}header{background:#146d73;color:#fff;padding:24px max(18px,calc((100vw - 1180px)/2))}header h1{margin:0;font-size:26px}header p{margin:8px 0 0;line-height:1.5}main{max-width:1180px;margin:26px auto;padding:0 16px}.panel{background:#fff;border-radius:14px;padding:24px;margin-bottom:22px;box-shadow:0 3px 18px #143b4215}h2{font-size:21px;margin:0 0 18px}p{line-height:1.6}label{display:block;font-weight:bold;font-size:14px;margin-bottom:6px}input,select,textarea{width:100%;padding:11px;border:1px solid #c7d6d9;border-radius:7px;font:inherit;background:#fff}textarea{min-height:100px;resize:vertical}.campo{margin:18px 0}.confirmacion{display:flex;align-items:flex-start;gap:10px;line-height:1.6}.confirmacion input{width:auto;margin-top:6px;flex-shrink:0}button,.boton{display:inline-block;background:#146d73;color:#fff;border:0;border-radius:8px;padding:10px 15px;font-weight:bold;cursor:pointer;text-decoration:none;font-size:14px}.boton.secundario{background:#e7f0f1;color:#155960}.boton.rojizo{background:#a53c47}a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid #db9c22;outline-offset:3px}.acciones{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.mensaje{border-radius:7px;background:#e1f6e9;padding:12px;margin:12px 0;line-height:1.6}.mensaje.error{background:#ffe7e7}.mensaje.advertencia{background:#fff3d8}.filtros{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.filtros>div{flex:1;min-width:170px}.tabla{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1000px}th,td{padding:12px;text-align:left;border-bottom:1px solid #e4eeee;font-size:14px;vertical-align:top;overflow-wrap:anywhere}th{background:#eaf4f4}.etiqueta{display:inline-block;font-size:12px;border-radius:7px;background:#eaf2f3;padding:6px 8px}.cancelada{background:#ffe8e8}.disponible{color:#146d73;font-weight:bold}.pequeno{font-size:13px;color:#49626a;line-height:1.6}.detalle{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.detalle div{padding:12px;background:#f2f7f8;border-radius:7px;overflow-wrap:anywhere}.detalle dt{font-size:13px;font-weight:bold;margin-bottom:5px}.detalle dd{margin:0;line-height:1.5}.paginacion{margin-top:20px}@media(max-width:700px){.panel{padding:16px}.filtros{display:block}.filtros>div{margin-bottom:12px}.detalle{grid-template-columns:1fr}header h1{font-size:23px}.acciones{gap:8px}}
/* Anchos y saltos de línea específicos del listado de citas. */
.tabla{max-width:100%;min-width:0;overflow-x:auto;overscroll-behavior-x:contain}
.tabla table{min-width:1360px;table-layout:auto}
.tabla th,.tabla td{line-height:1.5;overflow-wrap:normal;word-break:normal}
.tabla th{white-space:nowrap}
.tabla th:nth-child(1),.tabla td:nth-child(1):not([colspan]){min-width:160px;white-space:nowrap}
.tabla th:nth-child(2),.tabla th:nth-child(3),.tabla td:nth-child(2),.tabla td:nth-child(3){min-width:190px}
.tabla th:nth-child(4),.tabla td:nth-child(4){min-width:180px}
.tabla td:nth-child(2),.tabla td:nth-child(3),.tabla td:nth-child(4){white-space:normal;overflow-wrap:break-word}
.tabla th:nth-child(5),.tabla td:nth-child(5){min-width:124px;white-space:nowrap}
.tabla th:nth-child(6),.tabla td:nth-child(6){min-width:112px;white-space:nowrap}
.tabla th:nth-child(7),.tabla td:nth-child(7){min-width:128px;white-space:nowrap}
.tabla th:nth-child(8),.tabla td:nth-child(8){min-width:240px}
.tabla .etiqueta,.tabla .boton{white-space:nowrap}
.tabla .disponible{margin:0 0 10px}
</style>
</head>
<body>
<header><h1>Clínica Vida Salud</h1><p>RF09 · Cancelación de citas médicas</p><p>Acceso exclusivo para administrador y recepcionista</p></header>
<main>
<p><a class="boton secundario" href="index.php">← Volver al inicio</a></p>
<?php if (is_array($flash) && is_string($flash['mensaje'] ?? null)): ?>
<div class="mensaje <?= cvs_escapar(in_array($flash['tipo'] ?? '', ['error', 'advertencia'], true) ? $flash['tipo'] : '') ?>" role="status"><?= cvs_escapar($flash['mensaje']) ?></div>
<?php endif; ?>
<?php foreach ($errores as $error): ?><div class="mensaje error" role="alert"><?= cvs_escapar($error) ?></div><?php endforeach; ?>

<?php if ($confirmar): ?>
<section class="panel" aria-labelledby="titulo-confirmacion">
<h2 id="titulo-confirmacion">Confirmar cancelación</h2>
<p>Verifica que esta sea la cita correcta. Se conservará en el historial y su intervalo dejará de estar ocupado por esta reserva.</p>
<dl class="detalle">
<div><dt>Código</dt><dd><?= cvs_escapar($confirmar['codigo']) ?></dd></div>
<div><dt>Paciente</dt><dd><?= cvs_escapar($confirmar['paciente']) ?></dd></div>
<div><dt>Médico</dt><dd><?= cvs_escapar($confirmar['medico']) ?></dd></div>
<div><dt>Especialidad</dt><dd><?= cvs_escapar($confirmar['especialidad']) ?></dd></div>
<div><dt>Fecha y horario · America/La_Paz</dt><dd><?= cvs_escapar($confirmar['fecha']) ?> · <?= cvs_escapar($confirmar['hora_inicio']) ?> a <?= cvs_escapar($confirmar['hora_fin']) ?></dd></div>
<div><dt>Estado actual</dt><dd><?= cvs_escapar($estados[$confirmar['estado']] ?? $confirmar['estado']) ?></dd></div>
</dl>
<form method="post" action="cancelar_cita.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar($csrf) ?>">
<input type="hidden" name="accion" value="cancelar">
<input type="hidden" name="id_cita" value="<?= cvs_escapar($confirmar['id_cita']) ?>">
<div class="campo"><label for="motivo_cancelacion">Motivo de cancelación (opcional)</label><textarea id="motivo_cancelacion" name="motivo_cancelacion" maxlength="500" aria-describedby="ayuda-motivo"></textarea><p id="ayuda-motivo" class="pequeno">Máximo 500 caracteres. No incluyas información clínica innecesaria.</p></div>
<div class="campo"><label class="confirmacion"><input type="checkbox" name="confirmacion" value="1" required><span>Confirmo que deseo cancelar esta cita. Este módulo no permite reactivarla.</span></label></div>
<div class="acciones"><button type="submit" class="boton rojizo">Confirmar cancelación</button><a class="boton secundario" href="<?= cvs_escapar($urlListado) ?>">Volver sin cancelar</a></div>
</form>
<p class="pequeno">La disponibilidad para cancelar se comprobará nuevamente al confirmar, aunque la página permanezca abierta.</p>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="titulo-listado">
<h2 id="titulo-listado">Citas registradas</h2>
<form method="get" action="cancelar_cita.php" class="filtros">
<div><label for="buscar">Buscar por código</label><input id="buscar" name="buscar" maxlength="25" value="<?= cvs_escapar($busqueda) ?>" placeholder="Código completo o parte del código"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos los estados</option><?php foreach ($estados as $valor => $etiqueta): ?><option value="<?= cvs_escapar($valor) ?>" <?= $estado === $valor ? 'selected' : '' ?>><?= cvs_escapar($etiqueta) ?></option><?php endforeach; ?></select></div>
<button type="submit">Buscar</button><a class="boton secundario" href="cancelar_cita.php">Limpiar</a>
</form>
<p class="pequeno">Solo pueden cancelarse citas programadas o confirmadas que aún no hayan comenzado. Hora de referencia: America/La_Paz, calculada en PostgreSQL. Las canceladas se conservan y pueden consultarse en «Todos los estados» o «Cancelada».</p>
<div class="tabla"><table><thead><tr><th scope="col">Código</th><th scope="col">Paciente</th><th scope="col">Médico</th><th scope="col">Especialidad</th><th scope="col">Fecha</th><th scope="col">Horario</th><th scope="col">Estado</th><th scope="col">Cancelación</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="8"><?= $listadoDisponible ? 'No se encontraron citas con los filtros seleccionados. Si aún no hay reservas, aparecerán aquí cuando se registren mediante el módulo correspondiente.' : 'Listado temporalmente no disponible.' ?></td></tr><?php endif; ?>
<?php foreach ($lista as $cita): ?>
<tr>
<td><?= cvs_escapar($cita['codigo']) ?></td><td><?= cvs_escapar($cita['paciente']) ?></td><td><?= cvs_escapar($cita['medico']) ?></td><td><?= cvs_escapar($cita['especialidad']) ?></td><td><?= cvs_escapar($cita['fecha']) ?></td><td><?= cvs_escapar($cita['hora_inicio']) ?><br>a <?= cvs_escapar($cita['hora_fin']) ?></td>
<td><span class="etiqueta <?= $cita['estado'] === 'cancelada' ? 'cancelada' : '' ?>"><?= cvs_escapar($estados[$cita['estado']] ?? $cita['estado']) ?></span></td>
<td><?php if (cvs_activo($cita['cancelable'])): ?>
<p class="disponible">Puede cancelarse</p>
<a class="boton rojizo" href="<?= cvs_escapar($urlListado . '&confirmar=' . rawurlencode((string)$cita['id_cita'])) ?>">Cancelar cita</a>
<?php else: ?><span class="pequeno"><?= cvs_escapar(cvs_rf09_impedimento($cita)) ?></span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($listadoDisponible): ?>
<nav class="acciones paginacion" aria-label="Páginas de citas">
<?php if ($pagina > 1): ?><a class="boton secundario" href="<?= cvs_escapar('cancelar_cita.php?' . http_build_query(['buscar'=>$busqueda, 'estado'=>$estado, 'pagina'=>$pagina - 1])) ?>">← Anterior</a><?php endif; ?>
<span class="pequeno">Página <?= cvs_escapar($pagina) ?> · <?= cvs_escapar(count($lista)) ?> citas mostradas · Hasta 50 por página</span>
<?php if ($haySiguiente): ?><a class="boton secundario" href="<?= cvs_escapar('cancelar_cita.php?' . http_build_query(['buscar'=>$busqueda, 'estado'=>$estado, 'pagina'=>$pagina + 1])) ?>">Siguiente →</a><?php endif; ?>
</nav>
<?php endif; ?>
</section>
</main>
</body>
</html>
