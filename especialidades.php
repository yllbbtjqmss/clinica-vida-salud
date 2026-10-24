<?php
// RF04 - Gestión de especialidades | Clínica Vida Salud
// RF01: acceso exclusivo para administrador.
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
cvs_exigir_roles(['administrador']);
$conexion = cvs_conexion();

if (!isset($_SESSION['csrf_especialidades'])) {
    $_SESSION['csrf_especialidades'] = bin2hex(random_bytes(32));
}

function e(mixed $valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function texto(array $origen, string $campo): string {
    return is_string($origen[$campo] ?? null) ? trim($origen[$campo]) : '';
}

function identificador(mixed $valor): int {
    if (!is_string($valor) && !is_int($valor)) return 0;
    return filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0;
}

function esActivo(mixed $valor): bool {
    return in_array($valor, [true, 1, '1', 't', 'true'], true);
}

function validarEspecialidad(array $datos): array {
    $errores = [];
    if ($datos['nombre'] === '') {
        $errores[] = 'El nombre de la especialidad es obligatorio.';
    }
    // Cuenta caracteres UTF-8 también cuando mbstring no está disponible.
    $longitud = preg_match_all('/./us', $datos['nombre']);
    if ($longitud === false) {
        $errores[] = 'El nombre contiene caracteres no válidos.';
    } elseif ($longitud > 100) {
        $errores[] = 'El nombre no puede superar los 100 caracteres.';
    }
    return $errores;
}

function obtenerEspecialidad(PDO $conexion, int $id): ?array {
    $consulta = $conexion->prepare('SELECT id_especialidad, nombre, descripcion, activo FROM especialidades WHERE id_especialidad = :id');
    $consulta->execute([':id'=>$id]);
    return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
}

function buscarDuplicada(PDO $conexion, string $nombre, int $excluirId): ?array {
    $consulta = $conexion->prepare('SELECT id_especialidad, activo FROM especialidades
        WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(CAST(:nombre AS TEXT))) AND id_especialidad <> :id');
    $consulta->execute([':nombre'=>$nombre, ':id'=>$excluirId]);
    return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mensajeDuplicada(?array $especialidad): string {
    if ($especialidad && !esActivo($especialidad['activo'])) {
        return 'Ya existe una especialidad con ese nombre, pero está inactiva. Busca en Inactivas o Todas y utiliza Reactivar en lugar de duplicarla.';
    }
    return 'Ya existe una especialidad con ese nombre. No se permiten duplicados aunque cambien las mayúsculas o los espacios al principio o al final.';
}

function registrarError(PDOException $ex): void {
    // No registrar parámetros, descripciones ni información de conexión.
    error_log('RF04: error de base de datos; SQLSTATE ' . $ex->getCode());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = texto($_POST, 'accion');
    $id = identificador($_POST['id_especialidad'] ?? null);
    $datosPost = ['nombre'=>texto($_POST, 'nombre'), 'descripcion'=>texto($_POST, 'descripcion')];
    $erroresPost = [];
    $avisoPost = '';
    $destino = 'especialidades.php';

    if (!hash_equals($_SESSION['csrf_especialidades'], texto($_POST, 'csrf'))) {
        $erroresPost[] = 'Solicitud no válida. Vuelve a intentarlo desde el formulario.';
    } elseif (!in_array($accion, ['crear', 'editar', 'desactivar', 'activar'], true)) {
        $erroresPost[] = 'Operación no válida.';
    } elseif ($accion !== 'crear' && $id === 0) {
        $erroresPost[] = 'El identificador de la especialidad no es válido.';
    } else {
        try {
            if (in_array($accion, ['crear', 'editar'], true)) {
                $erroresPost = validarEspecialidad($datosPost);
                if ($accion === 'editar' && !obtenerEspecialidad($conexion, $id)) {
                    $erroresPost[] = 'No se encontró la especialidad que deseas modificar.';
                }
                if (!$erroresPost) {
                    $duplicada = buscarDuplicada($conexion, $datosPost['nombre'], $accion === 'editar' ? $id : 0);
                    if ($duplicada) $erroresPost[] = mensajeDuplicada($duplicada);
                }
                if (!$erroresPost) {
                    $parametros = [':nombre'=>$datosPost['nombre'], ':descripcion'=>$datosPost['descripcion'] !== '' ? $datosPost['descripcion'] : null];
                    if ($accion === 'crear') {
                        $sql = 'INSERT INTO especialidades (nombre, descripcion) VALUES (:nombre, :descripcion)';
                    } else {
                        $sql = 'UPDATE especialidades SET nombre = :nombre, descripcion = :descripcion WHERE id_especialidad = :id';
                        $parametros[':id'] = $id;
                    }
                    $consulta = $conexion->prepare($sql);
                    $consulta->execute($parametros);
                    if ($consulta->rowCount() === 0) {
                        $erroresPost[] = 'No se encontró la especialidad para guardar los cambios.';
                    } else {
                        $avisoPost = $accion === 'crear' ? 'Especialidad registrada correctamente.' : 'Especialidad actualizada correctamente.';
                        $destino .= '?estado=todos';
                    }
                }
            } else {
                // Solo cambia el catálogo: no modifica asignaciones médicas ni citas.
                $activar = $accion === 'activar';
                $sql = $activar
                    ? 'UPDATE especialidades SET activo = TRUE WHERE id_especialidad = :id AND activo = FALSE'
                    : 'UPDATE especialidades SET activo = FALSE WHERE id_especialidad = :id AND activo = TRUE';
                $consulta = $conexion->prepare($sql);
                $consulta->execute([':id'=>$id]);
                if ($consulta->rowCount() > 0) {
                    $avisoPost = $activar ? 'Especialidad reactivada correctamente.' : 'Especialidad desactivada. Se conservan las asignaciones a médicos y todas las citas.';
                } else {
                    $erroresPost[] = 'La especialidad no existe o ya tiene el estado solicitado.';
                }
                $destino .= '?estado=todos';
            }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23505') {
                // El índice único también protege frente a registros simultáneos.
                $duplicada = null;
                try {
                    $duplicada = buscarDuplicada($conexion, $datosPost['nombre'], $accion === 'editar' ? $id : 0);
                } catch (PDOException $consultaEx) {
                    registrarError($consultaEx);
                }
                $erroresPost[] = mensajeDuplicada($duplicada);
            } else {
                registrarError($ex);
                $erroresPost[] = 'No fue posible completar la operación. Inténtalo nuevamente.';
            }
        }
    }

    // POST/Redirect/GET también ante errores: conserva los datos para corregirlos.
    $flash = ['errores'=>$erroresPost, 'aviso'=>$avisoPost];
    if ($erroresPost && in_array($accion, ['crear', 'editar'], true)) {
        $flash['datos'] = $datosPost;
        if ($accion === 'editar' && $id > 0) $destino = 'especialidades.php?accion=editar&id=' . $id;
    }
    $_SESSION['flash_especialidades'] = $flash;
    header('Location: ' . $destino, true, 303);
    exit;
}

$flash = $_SESSION['flash_especialidades'] ?? [];
unset($_SESSION['flash_especialidades']);
$errores = $flash['errores'] ?? [];
$aviso = $flash['aviso'] ?? '';
$datos = ['nombre'=>'', 'descripcion'=>''];
$especialidadEditada = null;
$accionVista = texto($_GET, 'accion');
$idVista = identificador($_GET['id'] ?? null);
$busqueda = texto($_GET, 'buscar');
$estadoFiltro = texto($_GET, 'estado');
if (!in_array($estadoFiltro, ['activos', 'inactivos', 'todos'], true)) $estadoFiltro = 'activos';

if ($accionVista === 'editar') {
    if ($idVista === 0) {
        $errores[] = 'El identificador de la especialidad no es válido.';
    } else {
        try {
            $especialidadEditada = obtenerEspecialidad($conexion, $idVista);
            if (!$especialidadEditada) {
                $errores[] = 'No se encontró la especialidad solicitada.';
            } else {
                $datos = ['nombre'=>$especialidadEditada['nombre'], 'descripcion'=>$especialidadEditada['descripcion'] ?? ''];
            }
        } catch (PDOException $ex) {
            registrarError($ex);
            $errores[] = 'No fue posible abrir la especialidad para editarla.';
        }
    }
} elseif ($accionVista !== '') {
    $errores[] = 'Acción no válida. Los cambios de estado solo se permiten desde los botones del listado.';
}
if (isset($flash['datos']) && ($accionVista === '' || $especialidadEditada)) $datos = $flash['datos'];

$lista = [];
try {
    $condiciones = [];
    $parametros = [];
    if ($estadoFiltro === 'activos') $condiciones[] = 'activo = TRUE';
    if ($estadoFiltro === 'inactivos') $condiciones[] = 'activo = FALSE';
    if ($busqueda !== '') {
        // Escapar comodines para buscar el texto literal ingresado por el usuario.
        $condiciones[] = "nombre ILIKE :buscar ESCAPE '!'";
        $parametros[':buscar'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busqueda) . '%';
    }
    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta = $conexion->prepare("SELECT id_especialidad, nombre, descripcion, activo FROM especialidades $where ORDER BY LOWER(nombre), id_especialidad");
    $consulta->execute($parametros);
    $lista = $consulta->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    registrarError($ex);
    $errores[] = 'No fue posible consultar el listado de especialidades.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Especialidades | Clínica Vida Salud</title>
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('especialidades', 'Especialidades'); ?>
<main class="ui-contenido">
<p><a class="boton secundario" href="index.php">← Volver al inicio</a></p>
<?php if ($aviso !== ''): ?><div class="mensaje" role="status"><?= e($aviso) ?></div><?php endif; ?>
<?php foreach ($errores as $error): ?><div class="mensaje error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="panel">
<h2><?= $especialidadEditada ? 'Editar especialidad #' . e($idVista) : 'Registrar especialidad' ?></h2>
<?php if ($especialidadEditada && !esActivo($especialidadEditada['activo'])): ?><p class="pequeno">Esta especialidad está inactiva. Guardar sus datos no la reactiva; utiliza Reactivar en el listado.</p><?php endif; ?>
<form method="post" action="especialidades.php">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_especialidades']) ?>">
<input type="hidden" name="accion" value="<?= $especialidadEditada ? 'editar' : 'crear' ?>">
<?php if ($especialidadEditada): ?><input type="hidden" name="id_especialidad" value="<?= e($idVista) ?>"><?php endif; ?>
<div class="campo"><label for="nombre">Nombre de la especialidad *</label><input id="nombre" name="nombre" maxlength="100" required value="<?= e($datos['nombre']) ?>" placeholder="Ej.: Cardiología"></div>
<div class="campo"><label for="descripcion">Descripción (opcional)</label><textarea id="descripcion" name="descripcion" placeholder="Describe brevemente el área de atención"><?= e($datos['descripcion']) ?></textarea></div>
<div class="acciones"><button type="submit"><?= $especialidadEditada ? 'Guardar cambios' : 'Registrar especialidad' ?></button><?php if ($especialidadEditada): ?><a class="boton secundario" href="especialidades.php?estado=todos">Cancelar edición</a><?php endif; ?></div>
</form>
</section>
<section class="panel">
<h2>Especialidades registradas</h2>
<form method="get" action="especialidades.php" class="filtros">
<div><label for="buscar">Buscar por nombre</label><input id="buscar" name="buscar" value="<?= e($busqueda) ?>" placeholder="Ej.: Pediatría"></div>
<div><label for="estado">Estado</label><select id="estado" name="estado"><option value="activos" <?= $estadoFiltro === 'activos' ? 'selected' : '' ?>>Activas</option><option value="inactivos" <?= $estadoFiltro === 'inactivos' ? 'selected' : '' ?>>Inactivas</option><option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todas</option></select></div>
<button type="submit">Buscar</button><a class="boton secundario" href="especialidades.php">Limpiar</a>
</form>
<p class="pequeno">Resultados: <?= e(count($lista)) ?>. Desactivar no elimina la especialidad ni cancela citas históricas o futuras. La asignación a médicos pertenece a RF03. Los módulos de horarios y reservas deberán comprobar que la especialidad esté activa antes de ofrecer nuevas citas.</p>
<div class="tabla"><table><thead><tr><th scope="col">ID</th><th scope="col">Nombre</th><th scope="col">Descripción</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="5">No se encontraron especialidades con los filtros seleccionados.</td></tr><?php endif; ?>
<?php foreach ($lista as $especialidad): ?>
<tr>
<td><?= e($especialidad['id_especialidad']) ?></td><td class="nombre"><?= e($especialidad['nombre']) ?></td><td class="descripcion"><?= e($especialidad['descripcion'] ?? 'Sin descripción') ?></td>
<td><span class="etiqueta <?= esActivo($especialidad['activo']) ? '' : 'inactivo' ?>"><?= esActivo($especialidad['activo']) ? 'Activa' : 'Inactiva' ?></span></td>
<td><div class="acciones">
<a class="boton secundario" href="especialidades.php?accion=editar&amp;id=<?= e($especialidad['id_especialidad']) ?>">Editar</a>
<form method="post" action="especialidades.php" onsubmit="return confirm(this.dataset.confirmacion);" data-confirmacion="<?= esActivo($especialidad['activo']) ? '¿Desactivar esta especialidad? Se conservarán sus asignaciones y todas las citas.' : '¿Reactivar esta especialidad?' ?>">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_especialidades']) ?>"><input type="hidden" name="id_especialidad" value="<?= e($especialidad['id_especialidad']) ?>"><input type="hidden" name="accion" value="<?= esActivo($especialidad['activo']) ? 'desactivar' : 'activar' ?>">
<button type="submit" class="boton <?= esActivo($especialidad['activo']) ? 'rojizo' : '' ?>"><?= esActivo($especialidad['activo']) ? 'Desactivar' : 'Reactivar' ?></button>
</form>
</div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
</main>
<?php cvsu_cierre(); ?>
</body>
</html>
