<?php
// RF02 - Gestion de pacientes | Clinica Vida Salud
// RF01: acceso exclusivo para administrador y recepcionista.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
cvs_exigir_roles(['administrador', 'recepcionista']);
$conexion = cvs_conexion();

if (!isset($_SESSION['csrf_pacientes'])) {
    $_SESSION['csrf_pacientes'] = bin2hex(random_bytes(32));
}

function e(mixed $valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esActivo(mixed $valor): bool {
    return $valor === true || $valor === 1 || $valor === '1' || $valor === 't' || $valor === 'true';
}

function longitud(string $texto): int {
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}

function datosFormulario(array $origen): array {
    $campos = [
        'ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono',
        'correo', 'direccion', 'tipo_sangre', 'alergias', 'antecedentes',
        'contacto_emergencia', 'telefono_emergencia'
    ];
    $datos = [];
    foreach ($campos as $campo) {
        $datos[$campo] = trim((string)($origen[$campo] ?? ''));
    }
    return $datos;
}

function validarPaciente(array $datos): array {
    $errores = [];
    foreach (['nombres', 'apellidos', 'fecha_nacimiento', 'telefono'] as $campo) {
        if ($datos[$campo] === '') {
            $errores[] = 'Completa nombres, apellidos, fecha de nacimiento y telefono.';
            break;
        }
    }
    $maximos = [
        'ci'=>25, 'nombres'=>100, 'apellidos'=>100, 'sexo'=>25, 'telefono'=>30,
        'correo'=>150, 'direccion'=>255, 'tipo_sangre'=>5,
        'contacto_emergencia'=>150, 'telefono_emergencia'=>30
    ];
    foreach ($maximos as $campo => $maximo) {
        if (longitud($datos[$campo]) > $maximo) {
            $errores[] = "El campo $campo supera la longitud permitida ($maximo).";
        }
    }
    if ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electronico no es valido.';
    }
    if ($datos['sexo'] !== '' && !in_array($datos['sexo'], ['Femenino', 'Masculino', 'Otro', 'Prefiere no indicar'], true)) {
        $errores[] = 'Selecciona una opcion valida para sexo.';
    }
    if ($datos['tipo_sangre'] !== '' && !in_array($datos['tipo_sangre'], ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) {
        $errores[] = 'Selecciona un tipo de sangre valido.';
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
    if (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha_nacimiento'] || $fecha > new DateTimeImmutable('today')) {
        $errores[] = 'La fecha de nacimiento es invalida.';
    }
    return $errores;
}

function parametrosPaciente(array $datos): array {
    $campos = [
        'ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono',
        'correo', 'direccion', 'tipo_sangre', 'alergias', 'antecedentes',
        'contacto_emergencia', 'telefono_emergencia'
    ];
    $resultado = [];
    foreach ($campos as $campo) {
        $resultado[':' . $campo] = $datos[$campo] !== '' ? $datos[$campo] : null;
    }
    return $resultado;
}

function obtenerPaciente(PDO $conexion, int $id): ?array {
    $consulta = $conexion->prepare('SELECT * FROM pacientes WHERE id_paciente = :id');
    $consulta->execute([':id' => $id]);
    $paciente = $consulta->fetch();
    return $paciente ?: null;
}

$errores = [];
$aviso = $_SESSION['aviso_pacientes'] ?? '';
unset($_SESSION['aviso_pacientes']);
$accionVista = (string)($_GET['accion'] ?? 'nuevo');
$idVista = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$datos = datosFormulario([]);
$pacienteEditado = null;
$historial = [];
$busqueda = trim((string)($_GET['buscar'] ?? ''));
$estadoFiltro = (string)($_GET['estado'] ?? 'activos');
if (!in_array($estadoFiltro, ['activos', 'inactivos', 'todos'], true)) {
    $estadoFiltro = 'activos';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accionPost = (string)($_POST['accion'] ?? '');
    $idPost = filter_var($_POST['id_paciente'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    if (!hash_equals($_SESSION['csrf_pacientes'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no valida. Recarga la pagina.';
    } elseif (in_array($accionPost, ['crear', 'editar'], true)) {
        $datos = datosFormulario($_POST);
        $errores = validarPaciente($datos);
        if ($accionPost === 'editar' && $idPost === 0) {
            $errores[] = 'El paciente indicado no es valido.';
        }
        if (!$errores) {
            try {
                $parametros = parametrosPaciente($datos);
                if ($accionPost === 'crear') {
                    $sql = 'INSERT INTO pacientes (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion, tipo_sangre, alergias, antecedentes, contacto_emergencia, telefono_emergencia)
                            VALUES (:ci, :nombres, :apellidos, :fecha_nacimiento, :sexo, :telefono, :correo, :direccion, :tipo_sangre, :alergias, :antecedentes, :contacto_emergencia, :telefono_emergencia)';
                } else {
                    $sql = 'UPDATE pacientes SET ci=:ci, nombres=:nombres, apellidos=:apellidos,
                            fecha_nacimiento=:fecha_nacimiento, sexo=:sexo, telefono=:telefono,
                            correo=:correo, direccion=:direccion, tipo_sangre=:tipo_sangre,
                            alergias=:alergias, antecedentes=:antecedentes,
                            contacto_emergencia=:contacto_emergencia, telefono_emergencia=:telefono_emergencia
                            WHERE id_paciente=:id AND activo=TRUE';
                    $parametros[':id'] = $idPost;
                }
                $consulta = $conexion->prepare($sql);
                $consulta->execute($parametros);
                if ($accionPost === 'editar' && $consulta->rowCount() === 0) {
                    $errores[] = 'No se encontro un paciente activo para modificar.';
                } else {
                    $_SESSION['aviso_pacientes'] = $accionPost === 'crear' ? 'Paciente registrado correctamente.' : 'Paciente actualizado correctamente.';
                    header('Location: pacientes.php');
                    exit;
                }
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23505') {
                    $errores[] = 'Ese CI ya esta registrado en otro paciente.';
                } else {
                    error_log('RF02: guardar_paciente'
                        . (preg_match('/\A[A-Z0-9]{5}\z/', (string)$ex->getCode()) === 1
                            ? '; SQLSTATE ' . (string)$ex->getCode() : ''));
                    $errores[] = 'No se pudo guardar la informacion.';
                }
            }
        }
        if ($accionPost === 'editar') {
            $accionVista = 'editar';
            $idVista = $idPost;
        }
    } elseif (in_array($accionPost, ['desactivar', 'activar'], true) && $idPost > 0) {
        try {
            $activo = $accionPost === 'activar' ? 'TRUE' : 'FALSE';
            $consulta = $conexion->prepare("UPDATE pacientes SET activo = $activo WHERE id_paciente = :id");
            $consulta->execute([':id' => $idPost]);
            $_SESSION['aviso_pacientes'] = $consulta->rowCount() > 0
                ? ($accionPost === 'activar' ? 'Paciente activado.' : 'Paciente desactivado; su historial se conserva.')
                : 'No se encontraron cambios para ese paciente.';
            header('Location: pacientes.php?estado=todos');
            exit;
        } catch (PDOException $ex) {
            error_log('RF02: cambiar_estado_paciente'
                . (preg_match('/\A[A-Z0-9]{5}\z/', (string)$ex->getCode()) === 1
                    ? '; SQLSTATE ' . (string)$ex->getCode() : ''));
            $errores[] = 'No fue posible cambiar el estado del paciente.';
        }
    } else {
        $errores[] = 'Operacion no valida.';
    }
}

if ($accionVista === 'editar' && $idVista > 0) {
    try {
        $pacienteEditado = obtenerPaciente($conexion, $idVista);
        if (!$pacienteEditado || !esActivo($pacienteEditado['activo'])) {
            $errores[] = 'Solo es posible editar pacientes activos.';
            $accionVista = 'nuevo';
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($errores)) {
            $datos = datosFormulario($pacienteEditado);
        }
    } catch (PDOException $ex) {
        error_log('RF02: abrir_ficha_paciente'
            . (preg_match('/\A[A-Z0-9]{5}\z/', (string)$ex->getCode()) === 1
                ? '; SQLSTATE ' . (string)$ex->getCode() : ''));
        $errores[] = 'No se pudo abrir la ficha del paciente.';
        $accionVista = 'nuevo';
    }
}

if ($accionVista === 'historial' && $idVista > 0) {
    try {
        $pacienteEditado = obtenerPaciente($conexion, $idVista);
        if ($pacienteEditado) {
            $consulta = $conexion->prepare('SELECT c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
                m.nombres AS medico_nombres, m.apellidos AS medico_apellidos, e.nombre AS especialidad
                FROM citas c
                JOIN medicos m ON m.id_medico = c.id_medico
                JOIN especialidades e ON e.id_especialidad = c.id_especialidad
                WHERE c.id_paciente = :id ORDER BY c.fecha DESC, c.hora_inicio DESC LIMIT 100');
            $consulta->execute([':id'=>$idVista]);
            $historial = $consulta->fetchAll();
        } else {
            $errores[] = 'No se encontro ese paciente.';
        }
    } catch (PDOException $ex) {
        error_log('RF02: consultar_historial_paciente'
            . (preg_match('/\A[A-Z0-9]{5}\z/', (string)$ex->getCode()) === 1
                ? '; SQLSTATE ' . (string)$ex->getCode() : ''));
        $errores[] = 'No se pudo consultar el historial de citas.';
    }
}

try {
    $condiciones = [];
    $parametrosLista = [];
    if ($estadoFiltro === 'activos') $condiciones[] = 'activo = TRUE';
    if ($estadoFiltro === 'inactivos') $condiciones[] = 'activo = FALSE';
    if ($busqueda !== '') {
        $condiciones[] = '(ci ILIKE :buscar_ci OR nombres ILIKE :buscar_nombres OR apellidos ILIKE :buscar_apellidos)';
        $parametrosLista[':buscar_ci'] = '%' . $busqueda . '%';
        $parametrosLista[':buscar_nombres'] = '%' . $busqueda . '%';
        $parametrosLista[':buscar_apellidos'] = '%' . $busqueda . '%';
    }
    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta = $conexion->prepare("SELECT id_paciente, ci, nombres, apellidos, fecha_nacimiento, telefono, activo
        FROM pacientes $where ORDER BY id_paciente DESC LIMIT 100");
    $consulta->execute($parametrosLista);
    $lista = $consulta->fetchAll();
} catch (PDOException $ex) {
    error_log('RF02: listar_pacientes'
        . (preg_match('/\A[A-Z0-9]{5}\z/', (string)$ex->getCode()) === 1
            ? '; SQLSTATE ' . (string)$ex->getCode() : ''));
    $lista = [];
    $errores[] = 'No fue posible consultar el listado.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pacientes | Clinica Vida Salud</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f2f7f8;color:#17333b;font-family:Arial,sans-serif}
header{background:#146d73;color:white;padding:24px max(18px,calc((100vw - 1050px)/2))}header h1{margin:0;font-size:26px}header p{margin:6px 0 0}
main{max-width:1050px;margin:26px auto;padding:0 16px}.panel{background:#fff;border-radius:14px;padding:24px;margin-bottom:22px;box-shadow:0 3px 18px #143b4215}
h2{font-size:21px;margin:0 0 18px}.campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}
label{display:block;font-weight:bold;font-size:14px;margin-bottom:6px}input,select,textarea{width:100%;padding:11px;border:1px solid #c7d6d9;border-radius:7px;font:inherit;background:white}textarea{min-height:80px;resize:vertical}
button,.boton{display:inline-block;background:#146d73;color:white;border:0;border-radius:8px;padding:10px 15px;font-weight:bold;cursor:pointer;text-decoration:none;font-size:14px}
.boton.secundario{background:#e7f0f1;color:#155960}.boton.rojizo{background:#a53c47}.acciones{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.mensaje{border-radius:7px;background:#e1f6e9;padding:12px;margin:12px 0}.mensaje.error{background:#ffe7e7}.tabla{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:750px}th,td{padding:12px;text-align:left;border-bottom:1px solid #e4eeee;font-size:14px}th{background:#eaf4f4}.etiqueta{font-size:12px;border-radius:7px;background:#eaf2f3;padding:5px 7px;white-space:nowrap}.inactivo{background:#ffe8e8}
.filtros{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.filtros>div{flex:1;min-width:170px}.espacio{margin-top:18px}.pequeno{font-size:13px;color:#587079}.enlace{color:#146d73;text-decoration:none}
@media(max-width:700px){.campos{grid-template-columns:1fr}.panel{padding:16px}.filtros{display:block}.filtros>div{margin-bottom:10px}}
</style>
</head>
<body>
<header><h1>Clínica Vida Salud</h1><p>RF02 · Gestión de pacientes · Entorno local de desarrollo</p></header>
<main>
<p><a class="boton secundario" href="index.php">← Volver al inicio</a></p>
<?php if ($aviso !== ''): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
<?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($accionVista === 'historial' && $pacienteEditado): ?>
<section class="panel">
<h2>Historial de citas: <?= e($pacienteEditado['nombres'] . ' ' . $pacienteEditado['apellidos']) ?></h2>
<p class="pequeno">Incluye citas canceladas e historicas. Acceso exclusivo para administrador y recepcionista.</p>
<div class="tabla"><table><thead><tr><th>Codigo</th><th>Fecha</th><th>Horario</th><th>Medico</th><th>Especialidad</th><th>Estado</th></tr></thead><tbody>
<?php if (!$historial): ?><tr><td colspan="6">Este paciente aun no tiene citas.</td></tr><?php endif; ?>
<?php foreach ($historial as $cita): ?><tr><td><?= e($cita['codigo']) ?></td><td><?= e($cita['fecha']) ?></td><td><?= e(substr($cita['hora_inicio'], 0, 5) . ' - ' . substr($cita['hora_fin'], 0, 5)) ?></td><td><?= e($cita['medico_nombres'] . ' ' . $cita['medico_apellidos']) ?></td><td><?= e($cita['especialidad']) ?></td><td><?= e($cita['estado']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="espacio"><a class="boton secundario" href="pacientes.php">Volver al listado</a></p>
</section>
<?php else: ?>
<section class="panel">
<h2><?= $accionVista === 'editar' && $pacienteEditado ? 'Editar paciente #' . e($idVista) : 'Registrar nuevo paciente' ?></h2>
<form method="post" action="pacientes.php">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_pacientes']) ?>">
<input type="hidden" name="accion" value="<?= $accionVista === 'editar' && $pacienteEditado ? 'editar' : 'crear' ?>">
<?php if ($accionVista === 'editar' && $pacienteEditado): ?><input type="hidden" name="id_paciente" value="<?= e($idVista) ?>"><?php endif; ?>
<div class="campos">
<div><label for="nombres">Nombres *</label><input id="nombres" name="nombres" maxlength="100" required value="<?= e($datos['nombres']) ?>"></div>
<div><label for="apellidos">Apellidos *</label><input id="apellidos" name="apellidos" maxlength="100" required value="<?= e($datos['apellidos']) ?>"></div>
<div><label for="ci">CI (opcional)</label><input id="ci" name="ci" maxlength="25" value="<?= e($datos['ci']) ?>"></div>
<div><label for="fecha_nacimiento">Fecha de nacimiento *</label><input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="<?= date('Y-m-d') ?>" required value="<?= e($datos['fecha_nacimiento']) ?>"></div>
<div><label for="sexo">Sexo</label><select id="sexo" name="sexo"><option value="">No indicado</option><?php foreach (['Femenino','Masculino','Otro','Prefiere no indicar'] as $opcion): ?><option value="<?= e($opcion) ?>" <?= $datos['sexo'] === $opcion ? 'selected' : '' ?>><?= e($opcion) ?></option><?php endforeach; ?></select></div>
<div><label for="telefono">Telefono *</label><input id="telefono" name="telefono" maxlength="30" required value="<?= e($datos['telefono']) ?>"></div>
<div><label for="correo">Correo electronico</label><input id="correo" name="correo" type="email" maxlength="150" value="<?= e($datos['correo']) ?>"></div>
<div><label for="direccion">Direccion</label><input id="direccion" name="direccion" maxlength="255" value="<?= e($datos['direccion']) ?>"></div>
<div><label for="tipo_sangre">Tipo de sangre</label><select id="tipo_sangre" name="tipo_sangre"><option value="">No indicado</option><?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $tipo): ?><option value="<?= e($tipo) ?>" <?= $datos['tipo_sangre'] === $tipo ? 'selected' : '' ?>><?= e($tipo) ?></option><?php endforeach; ?></select></div>
<div><label for="contacto_emergencia">Contacto de emergencia</label><input id="contacto_emergencia" name="contacto_emergencia" maxlength="150" value="<?= e($datos['contacto_emergencia']) ?>"></div>
<div><label for="telefono_emergencia">Telefono de emergencia</label><input id="telefono_emergencia" name="telefono_emergencia" maxlength="30" value="<?= e($datos['telefono_emergencia']) ?>"></div>
<div><label for="alergias">Alergias</label><textarea id="alergias" name="alergias"><?= e($datos['alergias']) ?></textarea></div>
<div><label for="antecedentes">Antecedentes relevantes</label><textarea id="antecedentes" name="antecedentes"><?= e($datos['antecedentes']) ?></textarea></div>
</div>
<p class="espacio"><button type="submit"><?= $accionVista === 'editar' && $pacienteEditado ? 'Guardar cambios' : 'Registrar paciente' ?></button>
<?php if ($accionVista === 'editar'): ?><a class="boton secundario" href="pacientes.php">Cancelar edicion</a><?php endif; ?></p>
</form>
</section>
<section class="panel">
<h2>Pacientes registrados</h2>
<form method="get" action="pacientes.php" class="filtros">
<div><label for="buscar">Buscar por CI, nombres o apellidos</label><input id="buscar" name="buscar" value="<?= e($busqueda) ?>" placeholder="Ej.: Lopez"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="activos" <?= $estadoFiltro === 'activos' ? 'selected' : '' ?>>Activos</option><option value="inactivos" <?= $estadoFiltro === 'inactivos' ? 'selected' : '' ?>>Inactivos</option><option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option></select></div>
<button type="submit">Buscar</button> <a class="boton secundario" href="pacientes.php">Limpiar</a>
</form>
<p class="pequeno">Se muestran hasta 100 resultados. Desactivar no elimina las citas anteriores.</p>
<div class="tabla"><table><thead><tr><th>ID</th><th>CI</th><th>Paciente</th><th>Nacimiento</th><th>Telefono</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="7">No se encontraron pacientes.</td></tr><?php endif; ?>
<?php foreach ($lista as $paciente): ?>
<tr><td><?= e($paciente['id_paciente']) ?></td><td><?= e($paciente['ci'] ?? '—') ?></td><td><?= e($paciente['nombres'] . ' ' . $paciente['apellidos']) ?></td><td><?= e($paciente['fecha_nacimiento']) ?></td><td><?= e($paciente['telefono']) ?></td><td><span class="etiqueta <?= esActivo($paciente['activo']) ? '' : 'inactivo' ?>"><?= esActivo($paciente['activo']) ? 'Activo' : 'Inactivo' ?></span></td>
<td><div class="acciones">
<a class="boton secundario" href="pacientes.php?accion=historial&amp;id=<?= e($paciente['id_paciente']) ?>">Historial</a>
<?php if (esActivo($paciente['activo'])): ?>
<a class="boton secundario" href="pacientes.php?accion=editar&amp;id=<?= e($paciente['id_paciente']) ?>">Editar</a>
<form method="post" action="pacientes.php" onsubmit="return confirm('¿Desactivar este paciente? Se conservara su historial.');">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_pacientes']) ?>"><input type="hidden" name="accion" value="desactivar"><input type="hidden" name="id_paciente" value="<?= e($paciente['id_paciente']) ?>"><button type="submit" class="boton rojizo">Desactivar</button>
</form>
<?php else: ?>
<form method="post" action="pacientes.php"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_pacientes']) ?>"><input type="hidden" name="accion" value="activar"><input type="hidden" name="id_paciente" value="<?= e($paciente['id_paciente']) ?>"><button type="submit">Activar</button></form>
<?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
</main>
</body>
</html>
