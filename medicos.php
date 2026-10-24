<?php
/**
 * RF03 - Gestión de médicos
 * Clínica Vida Salud
 */

require_once __DIR__ . '/includes/auth.php';
cvs_exigir_roles(['administrador']);
$conexion = cvs_conexion();

// Generación de token CSRF
if (empty($_SESSION['csrf_medicos'])) {
    $_SESSION['csrf_medicos'] = bin2hex(random_bytes(32));
}

// Helper para escapar salida (evitar XSS)
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errores = [];
$aviso   = $_SESSION['aviso_medicos'] ?? null;
unset($_SESSION['aviso_medicos']);

// Datos del formulario
$datos = [
    'id_medico'            => '',
    'registro_profesional' => '',
    'nombres'              => '',
    'apellidos'            => '',
    'telefono'             => '',
    'correo'               => '',
    'activo'               => '1'
];
$especialidades_sel = [];

// Cargar especialidades activas para el formulario
try {
    $espStmt = $conexion->query("SELECT id_especialidad, nombre FROM especialidades WHERE activo = TRUE ORDER BY nombre ASC");
    $especialidades_lista = $espStmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $especialidades_lista = [];
    $errores[] = 'No se pudieron cargar las especialidades.';
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Protección CSRF
    if (!hash_equals($_SESSION['csrf_medicos'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no válida. Recarga la página.';
    }

    $accion = $_POST['accion'] ?? '';

    // Acción: activar/desactivar
    if ($accion === 'toggle' && !$errores) {
        $id_medico_toggle = filter_var($_POST['id_medico'] ?? null, FILTER_VALIDATE_INT);
        if (!$id_medico_toggle) {
            $errores[] = 'Identificador de médico inválido.';
        } else {
            try {
                $stmtT = $conexion->prepare("UPDATE medicos SET activo = NOT activo WHERE id_medico = :id");
                $stmtT->bindValue(':id', $id_medico_toggle, PDO::PARAM_INT);
                $stmtT->execute();
                $_SESSION['aviso_medicos'] = 'Estado del médico actualizado correctamente.';
                header('Location: medicos.php');
                exit;
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errores[] = 'No se pudo cambiar el estado del médico.';
            }
        }
    }

    // Acción: guardar (crear o actualizar)
    if ($accion === 'guardar' && !$errores) {
        $datos['id_medico']            = trim((string)($_POST['id_medico'] ?? ''));
        $datos['registro_profesional'] = trim((string)($_POST['registro_profesional'] ?? ''));
        $datos['nombres']              = trim((string)($_POST['nombres'] ?? ''));
        $datos['apellidos']            = trim((string)($_POST['apellidos'] ?? ''));
        $datos['telefono']             = trim((string)($_POST['telefono'] ?? ''));
        $datos['correo']               = trim((string)($_POST['correo'] ?? ''));
        $datos['activo']               = (isset($_POST['activo']) && $_POST['activo'] === '1') ? '1' : '0';

        // Especialidades seleccionadas
        $especialidades_sel = [];
        if (isset($_POST['especialidades']) && is_array($_POST['especialidades'])) {
            foreach ($_POST['especialidades'] as $espId) {
                $val = filter_var($espId, FILTER_VALIDATE_INT);
                if ($val) {
                    $especialidades_sel[] = $val;
                }
            }
            $especialidades_sel = array_values(array_unique($especialidades_sel));
        }

        // Validaciones
        if ($datos['registro_profesional'] === '' || $datos['nombres'] === '' || $datos['apellidos'] === '') {
            $errores[] = 'Completa registro profesional, nombres y apellidos.';
        }
        if (strlen($datos['registro_profesional']) > 50 || strlen($datos['nombres']) > 100 || strlen($datos['apellidos']) > 100) {
            $errores[] = 'Uno de los campos supera la longitud permitida.';
        }
        if ($datos['telefono'] !== '' && strlen($datos['telefono']) > 30) {
            $errores[] = 'El teléfono supera la longitud permitida.';
        }
        if ($datos['correo'] !== '') {
            if (strlen($datos['correo']) > 150 || !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'Introduce un correo electrónico válido.';
            }
        }

        // Validar especialidades activas
        if ($especialidades_sel) {
            $placeholders = implode(',', array_fill(0, count($especialidades_sel), '?'));
            try {
                $chk = $conexion->prepare("SELECT COUNT(*) FROM especialidades WHERE activo = TRUE AND id_especialidad IN ($placeholders)");
                $chk->execute($especialidades_sel);
                if ((int)$chk->fetchColumn() !== count($especialidades_sel)) {
                    $errores[] = 'Una o más especialidades seleccionadas no son válidas.';
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errores[] = 'No se pudo validar las especialidades.';
            }
        }

        $esNuevo = ($datos['id_medico'] === '');
        if ($esNuevo && empty($especialidades_sel)) {
            $errores[] = 'Debe seleccionar al menos una especialidad activa para registrar el médico.';
        }

        if (!$errores) {
            try {
                $conexion->beginTransaction();

                if ($esNuevo) {
                    // Insertar médico (una sola ejecución)
                    $sqlIns = "INSERT INTO medicos (registro_profesional, nombres, apellidos, telefono, correo, activo)
                               VALUES (:registro_profesional, :nombres, :apellidos, :telefono, :correo, :activo)
                               RETURNING id_medico";
                    $stmtIns = $conexion->prepare($sqlIns);
                    $activoBoolIns = ($datos['activo'] === '1');
                    $stmtIns->bindValue(':registro_profesional', $datos['registro_profesional'], PDO::PARAM_STR);
                    $stmtIns->bindValue(':nombres', $datos['nombres'], PDO::PARAM_STR);
                    $stmtIns->bindValue(':apellidos', $datos['apellidos'], PDO::PARAM_STR);
                    if ($datos['telefono'] === '') {
                        $stmtIns->bindValue(':telefono', null, PDO::PARAM_NULL);
                    } else {
                        $stmtIns->bindValue(':telefono', $datos['telefono'], PDO::PARAM_STR);
                    }
                    if ($datos['correo'] === '') {
                        $stmtIns->bindValue(':correo', null, PDO::PARAM_NULL);
                    } else {
                        $stmtIns->bindValue(':correo', $datos['correo'], PDO::PARAM_STR);
                    }
                    $stmtIns->bindValue(':activo', $activoBoolIns, PDO::PARAM_BOOL);
                    $stmtIns->execute();
                    $nuevoId = (int)$stmtIns->fetchColumn();

                    if (empty($especialidades_sel)) {
                        throw new PDOException('Debe asignar al menos una especialidad al médico.', 'P0001');
                    }
                    // Asignar especialidades
                    $sqlMe = "INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (:id_medico, :id_especialidad)";
                    $stmtMe = $conexion->prepare($sqlMe);
                    foreach ($especialidades_sel as $espId) {
                        $stmtMe->bindValue(':id_medico', $nuevoId, PDO::PARAM_INT);
                        $stmtMe->bindValue(':id_especialidad', (int)$espId, PDO::PARAM_INT);
                        $stmtMe->execute();
                    }

                    $conexion->commit();
                    $_SESSION['aviso_medicos'] = 'Médico registrado correctamente.';
                    header('Location: medicos.php');
                    exit;
                } else {
                    // Actualizar médico (una sola ejecución)
                    $idMed = (int)$datos['id_medico'];
                    $sqlUpd = "UPDATE medicos 
                               SET registro_profesional = :registro_profesional,
                                   nombres = :nombres,
                                   apellidos = :apellidos,
                                   telefono = :telefono,
                                   correo = :correo,
                                   activo = :activo
                               WHERE id_medico = :id_medico";
                    $stmtUpd = $conexion->prepare($sqlUpd);
                    $activoBool = ($datos['activo'] === '1');
                    $stmtUpd->bindValue(':registro_profesional', $datos['registro_profesional'], PDO::PARAM_STR);
                    $stmtUpd->bindValue(':nombres', $datos['nombres'], PDO::PARAM_STR);
                    $stmtUpd->bindValue(':apellidos', $datos['apellidos'], PDO::PARAM_STR);
                    if ($datos['telefono'] === '') {
                        $stmtUpd->bindValue(':telefono', null, PDO::PARAM_NULL);
                    } else {
                        $stmtUpd->bindValue(':telefono', $datos['telefono'], PDO::PARAM_STR);
                    }
                    if ($datos['correo'] === '') {
                        $stmtUpd->bindValue(':correo', null, PDO::PARAM_NULL);
                    } else {
                        $stmtUpd->bindValue(':correo', $datos['correo'], PDO::PARAM_STR);
                    }
                    $stmtUpd->bindValue(':activo', $activoBool, PDO::PARAM_BOOL);
                    $stmtUpd->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                    $stmtUpd->execute();

                    // Sincronizar especialidades de forma diferencial para conservar relaciones históricas cuando sea posible
                    // Obtener asignaciones actuales
                    $stmtCur = $conexion->prepare("SELECT id_especialidad FROM medico_especialidad WHERE id_medico = :id_medico");
                    $stmtCur->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                    $stmtCur->execute();
                    $actuales = array_map('intval', array_column($stmtCur->fetchAll(), 'id_especialidad'));

                    $aAgregar = array_diff($especialidades_sel, $actuales);
                    $aEliminar = array_diff($actuales, $especialidades_sel);

                    // Si se van a eliminar todas y hay citas, puede fallar por FK -> manejado en catch
                    if (!empty($aEliminar)) {
                        $placeholdersDel = implode(',', array_fill(0, count($aEliminar), '?'));
                        $sqlDelSel = "DELETE FROM medico_especialidad WHERE id_medico = ? AND id_especialidad IN ($placeholdersDel)";
                        $stmtDelSel = $conexion->prepare($sqlDelSel);
                        $paramsDel = array_merge([$idMed], array_values($aEliminar));
                        $stmtDelSel->execute($paramsDel);
                    }

                    if (!empty($aAgregar)) {
                        $sqlInsSel = "INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (:id_medico, :id_especialidad)";
                        $stmtInsSel = $conexion->prepare($sqlInsSel);
                        foreach ($aAgregar as $espIdAdd) {
                            $stmtInsSel->bindValue(':id_medico', $idMed, PDO::PARAM_INT);
                            $stmtInsSel->bindValue(':id_especialidad', (int)$espIdAdd, PDO::PARAM_INT);
                            $stmtInsSel->execute();
                        }
                    }

                    // Si no quedan especialidades, no forzamos inserción; si hay FK por citas fallará y rollback
                    $conexion->commit();
                    $_SESSION['aviso_medicos'] = 'Médico actualizado correctamente.';
                    header('Location: medicos.php');
                    exit;
                }
            } catch (PDOException $e) {
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                if ($e->getCode() === '23505') {
                    $errores[] = 'Ese número de registro profesional ya está registrado.';
                } elseif ($e->getCode() === '23503') {
                    error_log($e->getMessage());
                    $errores[] = 'No se puede modificar las especialidades: existen citas asociadas que dependen de las asignaciones actuales. Revisa las citas del médico antes de quitar especialidades.';
                } elseif ($e->getCode() === '23514' || $e->getCode() === 'P0001') {
                    $errores[] = 'Debe asignar al menos una especialidad activa al médico.';
                } else {
                    error_log($e->getMessage());
                    $errores[] = 'No se pudo guardar el médico.';
                }
            }
        }
    }
}

// Cargar médico para edición
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {
    $idEdit = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
    if ($idEdit) {
        try {
            $stmtEd = $conexion->prepare("SELECT id_medico, registro_profesional, nombres, apellidos, telefono, correo, activo FROM medicos WHERE id_medico = :id");
            $stmtEd->bindValue(':id', $idEdit, PDO::PARAM_INT);
            $stmtEd->execute();
            $medEd = $stmtEd->fetch();
            if ($medEd) {
                $datos['id_medico']            = $medEd['id_medico'];
                $datos['registro_profesional'] = $medEd['registro_profesional'];
                $datos['nombres']              = $medEd['nombres'];
                $datos['apellidos']            = $medEd['apellidos'];
                $datos['telefono']             = $medEd['telefono'] ?? '';
                $datos['correo']               = $medEd['correo'] ?? '';
                $datos['activo']               = $medEd['activo'] ? '1' : '0';

                $stmtMes = $conexion->prepare("SELECT id_especialidad FROM medico_especialidad WHERE id_medico = :id");
                $stmtMes->bindValue(':id', $idEdit, PDO::PARAM_INT);
                $stmtMes->execute();
                $especialidades_sel = array_map('intval', array_column($stmtMes->fetchAll(), 'id_especialidad'));
            } else {
                $errores[] = 'Médico no encontrado.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errores[] = 'No se pudo cargar el médico para editar.';
        }
    } else {
        $errores[] = 'Identificador de médico inválido.';
    }
}

// Búsqueda y listado
$q = trim((string)($_GET['q'] ?? ''));
$lista = [];
try {
    if ($q === '') {
        $sqlList = "SELECT m.id_medico, m.registro_profesional, m.nombres, m.apellidos, m.telefono, m.correo, m.activo,
                           COALESCE(string_agg(DISTINCT e.nombre, ', ' ORDER BY e.nombre), '') AS especialidades
                    FROM medicos m
                    LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
                    LEFT JOIN especialidades e ON e.id_especialidad = me.id_especialidad
                    GROUP BY m.id_medico, m.registro_profesional, m.nombres, m.apellidos, m.telefono, m.correo, m.activo
                    ORDER BY m.id_medico DESC
                    LIMIT 200";
        $stmtList = $conexion->query($sqlList);
        $lista = $stmtList->fetchAll();
    } else {
        $qLike = '%' . mb_strtolower($q, 'UTF-8') . '%';
        $sqlList = "SELECT m.id_medico, m.registro_profesional, m.nombres, m.apellidos, m.telefono, m.correo, m.activo,
                           COALESCE(string_agg(DISTINCT e.nombre, ', ' ORDER BY e.nombre), '') AS especialidades
                    FROM medicos m
                    LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
                    LEFT JOIN especialidades e ON e.id_especialidad = me.id_especialidad
                    WHERE LOWER(m.nombres) LIKE :q_nombre OR LOWER(m.apellidos) LIKE :q_apellido OR LOWER(e.nombre) LIKE :q_especialidad
                    GROUP BY m.id_medico, m.registro_profesional, m.nombres, m.apellidos, m.telefono, m.correo, m.activo
                    ORDER BY m.id_medico DESC
                    LIMIT 200";
        $stmtList = $conexion->prepare($sqlList);
        $stmtList->bindValue(':q_nombre', $qLike, PDO::PARAM_STR);
        $stmtList->bindValue(':q_apellido', $qLike, PDO::PARAM_STR);
        $stmtList->bindValue(':q_especialidad', $qLike, PDO::PARAM_STR);
        $stmtList->execute();
        $lista = $stmtList->fetchAll();
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $lista = [];
    $errores[] = 'No se pudo cargar el listado de médicos.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Médicos | Clínica Vida Salud</title>
    <link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/ui.php'; cvsu_area('medicos', 'Médicos'); ?>
<main class="ui-contenido">
    <section class="panel">
        <h2><?= $datos['id_medico'] === '' ? 'Registrar nuevo médico' : 'Editar médico' ?></h2>
        <?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
        <?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post" action="medicos.php" id="formMedico" novalidate>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_medicos']) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_medico" value="<?= e($datos['id_medico']) ?>">
            <div class="campos">
                <div>
                    <label for="registro_profesional">Registro profesional *</label>
                    <input id="registro_profesional" name="registro_profesional" maxlength="50" required value="<?= e($datos['registro_profesional']) ?>">
                </div>
                <div>
                    <label for="nombres">Nombres *</label>
                    <input id="nombres" name="nombres" maxlength="100" required value="<?= e($datos['nombres']) ?>">
                </div>
                <div>
                    <label for="apellidos">Apellidos *</label>
                    <input id="apellidos" name="apellidos" maxlength="100" required value="<?= e($datos['apellidos']) ?>">
                </div>
                <div>
                    <label for="telefono">Teléfono</label>
                    <input id="telefono" name="telefono" maxlength="30" value="<?= e($datos['telefono']) ?>">
                </div>
                <div>
                    <label for="correo">Correo electrónico</label>
                    <input id="correo" name="correo" type="email" maxlength="150" value="<?= e($datos['correo']) ?>">
                </div>
                <div>
                    <label for="activo">Estado</label>
                    <select id="activo" name="activo">
                        <option value="1" <?= $datos['activo'] === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $datos['activo'] === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:18px">
                <label>Especialidades (seleccione una o varias) *</label>
                <?php if (empty($especialidades_lista)): ?>
                    <p style="color:#7a8a8d">No hay especialidades activas registradas.</p>
                <?php else: ?>
                    <div class="especialidades">
                        <?php foreach ($especialidades_lista as $esp): ?>
                            <?php $checked = in_array((int)$esp['id_especialidad'], $especialidades_sel, true) ? 'checked' : ''; ?>
                            <label class="chk">
                                <input type="checkbox" name="especialidades[]" value="<?= e($esp['id_especialidad']) ?>" <?= $checked ?>>
                                <span><?= e($esp['nombre']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="acciones">
                <button type="submit">Guardar médico</button>
                <?php if ($datos['id_medico'] !== ''): ?>
                    <a class="btn btn-sec" href="medicos.php">Cancelar edición</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" id="medicos-registrados">
        <h2>Médicos registrados</h2>
        <form method="get" action="medicos.php#medicos-registrados" class="busqueda" style="margin-bottom:18px">
            <input type="text" name="q" placeholder="Buscar por nombre, apellido o especialidad" value="<?= e($q) ?>">
            <button type="submit" class="mini">Buscar</button>
            <?php if ($q !== ''): ?><a class="btn btn-sec mini" href="medicos.php#medicos-registrados">Limpiar</a><?php endif; ?>
        </form>
        <?php if ($q !== '' && empty($lista)): ?>
            <div class="mensaje error">No se encontraron médicos con el criterio de búsqueda "<?= e($q) ?>".</div>
        <?php endif; ?>
        <div class="tabla">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Registro profesional</th>
                        <th>Nombres</th>
                        <th>Apellidos</th>
                        <th>Teléfono</th>
                        <th>Correo</th>
                        <th>Especialidades</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($lista)): ?>
                    <tr><td colspan="9"><?= $q === '' ? 'No hay médicos para mostrar.' : 'Sin coincidencias.' ?></td></tr>
                <?php else: ?>
                    <?php foreach ($lista as $med): ?>
                        <tr>
                            <td><?= e($med['id_medico']) ?></td>
                            <td><?= e($med['registro_profesional']) ?></td>
                            <td><?= e($med['nombres']) ?></td>
                            <td><?= e($med['apellidos']) ?></td>
                            <td><?= e($med['telefono'] ?? '—') ?></td>
                            <td><?= e($med['correo'] ?? '—') ?></td>
                            <td><?= $med['especialidades'] !== '' ? e($med['especialidades']) : '—' ?></td>
                            <td>
                                <?php if ($med['activo']): ?>
                                    <span class="estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <a class="btn mini" href="medicos.php?editar=<?= e($med['id_medico']) ?>">Editar</a>
                                    <form method="post" action="medicos.php" style="margin:0" onsubmit="return confirm('¿Confirmas cambiar el estado de este médico?');">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_medicos']) ?>">
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="id_medico" value="<?= e($med['id_medico']) ?>">
                                        <button type="submit" class="btn btn-sec mini"><?= $med['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function(){
    const form = document.getElementById('formMedico');
    if(!form) return;
    form.addEventListener('submit', function(e){
        const reg = document.getElementById('registro_profesional');
        const nom = document.getElementById('nombres');
        const ape = document.getElementById('apellidos');
        const cor = document.getElementById('correo');
        let mensajes = [];
        if(reg && reg.value.trim() === '') mensajes.push('El registro profesional es obligatorio.');
        if(nom && nom.value.trim() === '') mensajes.push('Los nombres son obligatorios.');
        if(ape && ape.value.trim() === '') mensajes.push('Los apellidos son obligatorios.');
        if(cor && cor.value.trim() !== ''){
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if(!re.test(cor.value.trim())) mensajes.push('El correo electrónico no tiene un formato válido.');
        }
        if(mensajes.length > 0){
            e.preventDefault();
            alert(mensajes.join('\n'));
        }
    });
})();
</script>
<?php cvsu_cierre(); ?>
</body>
</html>
