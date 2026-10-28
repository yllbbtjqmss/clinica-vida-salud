<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$actor = cvs_exigir_roles(['administrador']);
require_once __DIR__ . '/includes/usuarios.php';
require_once __DIR__ . '/includes/ui.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405); header('Allow: GET, POST'); exit('Método no permitido.');
}
$pdo = cvs_conexion();
$errores = [];
$aviso = $_SESSION['cvs_usuarios_aviso'] ?? '';
unset($_SESSION['cvs_usuarios_aviso']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $errores[] = 'Solicitud no válida. Recarga el formulario.';
    } else {
        try {
            $id = cvsu_guardar_usuario($pdo, $actor['id_usuario'], $_POST);
            $_SESSION['cvs_usuarios_aviso'] = 'Operación completada correctamente.';
            header('Location: usuarios.php?editar=' . $id, true, 303); exit;
        } catch (DomainException $ex) {
            $errores[] = $ex->getMessage();
        } catch (PDOException $ex) {
            error_log('Ciclo2 usuarios; SQLSTATE ' . $ex->getCode());
            $errores[] = $ex->getCode() === '23505' ? 'Usuario, correo o vínculo ya registrado.' : 'No fue posible guardar los cambios.';
        }
    }
}
$idEditar = cvsu_id($_GET['editar'] ?? ($_POST['id_usuario'] ?? null));
$datos = ['nombre_usuario'=>'', 'correo'=>'', 'rol'=>'recepcionista', 'id_medico'=>'', 'id_paciente'=>''];
$editado = null;
try {
    if ($idEditar) {
        $q = $pdo->prepare('SELECT u.id_usuario, u.nombre_usuario, u.correo, u.activo, r.nombre AS rol,
            m.id_medico, p.id_paciente FROM usuarios u JOIN roles r USING (id_rol)
            LEFT JOIN medicos m USING (id_usuario) LEFT JOIN pacientes p USING (id_usuario) WHERE u.id_usuario=:id');
        $q->execute([':id'=>$idEditar]);
        $editado = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editado) $errores[] = 'No se encontró el usuario solicitado.';
        else $datos = $editado;
    }
    if ($errores && in_array(cvs_texto($_POST, 'accion'), ['crear', 'editar'], true)) {
        foreach (array_keys($datos) as $campo) if (isset($_POST[$campo]) && is_string($_POST[$campo])) $datos[$campo] = cvs_texto($_POST, $campo);
    }
    $vinculos = [];
    foreach (['medicos'=>'id_medico', 'pacientes'=>'id_paciente'] as $tabla=>$pk) {
        $q = $pdo->prepare("SELECT $pk AS id, nombres, apellidos, activo FROM $tabla WHERE id_usuario IS NULL OR id_usuario=:id ORDER BY apellidos, nombres");
        $q->execute([':id'=>$idEditar]);
        $vinculos[$pk] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $buscar = mb_substr(cvs_texto($_GET, 'buscar'), 0, 150);
    $pagina = min(cvsu_id($_GET['pagina'] ?? '1') ?: 1, 100000);
    $patron = '%' . strtr($buscar, ['!'=>'!!', '%'=>'!%', '_'=>'!_']) . '%';
    $q = $pdo->prepare("SELECT u.id_usuario, u.nombre_usuario, u.correo, u.activo, r.nombre AS rol,
        m.nombres || ' ' || m.apellidos AS medico, p.nombres || ' ' || p.apellidos AS paciente
        FROM usuarios u JOIN roles r USING (id_rol) LEFT JOIN medicos m USING (id_usuario)
        LEFT JOIN pacientes p USING (id_usuario)
        WHERE u.nombre_usuario ILIKE :nombre ESCAPE '!' OR u.correo ILIKE :correo ESCAPE '!'
        ORDER BY u.id_usuario LIMIT 51 OFFSET :offset");
    $q->bindValue(':nombre', $patron); $q->bindValue(':correo', $patron);
    $q->bindValue(':offset', ($pagina - 1) * 50, PDO::PARAM_INT);
    $q->execute(); $lista = $q->fetchAll(PDO::FETCH_ASSOC);
    $siguiente = count($lista) > 50;
    if ($siguiente) array_pop($lista);
} catch (PDOException $ex) { cvs_error_base_datos($ex); }
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Usuarios | Clínica Vida Salud</title><link rel="stylesheet" href="assets/estilos.css"></head><body>
<?php cvsu_area('usuarios', 'Administración de usuarios'); ?><main class="ui-contenido">
<?php if ($aviso): ?><div class="mensaje" role="status"><?= cvs_escapar($aviso) ?></div><?php endif; ?>
<?php foreach ($errores as $error): ?><div class="error" role="alert"><?= cvs_escapar($error) ?></div><?php endforeach; ?>
<section class="panel"><h1><?= $editado ? 'Editar usuario' : 'Registrar usuario' ?></h1>
<form method="post" action="usuarios.php<?= $editado ? '?editar=' . $idEditar : '' ?>">
<input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>"><input type="hidden" name="accion" value="<?= $editado ? 'editar' : 'crear' ?>"><input type="hidden" name="id_usuario" value="<?= $editado ? $idEditar : '' ?>">
<div class="campos">
<div class="campo"><label for="nombre_usuario">Nombre de usuario *</label><input id="nombre_usuario" name="nombre_usuario" maxlength="60" required value="<?= cvs_escapar($datos['nombre_usuario']) ?>"></div>
<div class="campo"><label for="correo">Correo electrónico</label><input type="email" id="correo" name="correo" maxlength="150" value="<?= cvs_escapar($datos['correo']) ?>"></div>
<div class="campo"><label for="rol">Rol *</label><select id="rol" name="rol"><?php foreach (CVS_ROLES as $rol): ?><option value="<?= $rol ?>" <?= $datos['rol'] === $rol ? 'selected' : '' ?>><?= cvs_escapar(ucfirst($rol)) ?></option><?php endforeach; ?></select></div>
<?php foreach (['id_medico'=>'Registro médico (solo rol médico)', 'id_paciente'=>'Registro paciente (solo rol paciente)'] as $pk=>$etiqueta): ?>
<div class="campo"><label for="<?= $pk ?>"><?= cvs_escapar($etiqueta) ?></label><select id="<?= $pk ?>" name="<?= $pk ?>"><option value="">Sin vincular</option><?php foreach ($vinculos[$pk] as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (string)$datos[$pk] === (string)$v['id'] ? 'selected' : '' ?>><?= cvs_escapar($v['apellidos'] . ', ' . $v['nombres'] . ' (#' . $v['id'] . ')' . (cvs_activo($v['activo']) ? '' : ' · Inactivo')) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
<?php if (!$editado): ?>
<div class="campo"><label for="contrasena">Contraseña *</label><input type="password" id="contrasena" name="contrasena" autocomplete="new-password" required></div>
<div class="campo"><label for="confirmacion">Confirmar contraseña *</label><input type="password" id="confirmacion" name="confirmacion" autocomplete="new-password" required></div>
<?php endif; ?></div>
<p class="pequeno">Contraseñas: mínimo 12 caracteres y máximo 72 bytes. Los vínculos utilizan fichas existentes; no crean personas. Al cambiar de rol, deja sin seleccionar el vínculo anterior. Una ficha inactiva no habilita el panel personal.</p>
<div class="acciones"><button type="submit">Guardar usuario</button><a class="boton secundario" href="usuarios.php">Nuevo / cancelar edición</a></div></form></section>
<?php if ($editado): ?><section class="panel"><h2>Estado de la cuenta y contraseña</h2><p>Cuenta <?= cvs_activo($editado['activo']) ? 'activa' : 'inactiva' ?>. Un restablecimiento cierra las sesiones anteriores al realizar su próxima petición. Entrega la nueva contraseña por un canal privado.</p>
<form method="post" action="usuarios.php" onsubmit="return confirm('¿Confirmas cambiar el estado de esta cuenta?');"><input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>"><input type="hidden" name="id_usuario" value="<?= $idEditar ?>"><input type="hidden" name="accion" value="<?= cvs_activo($editado['activo']) ? 'desactivar' : 'activar' ?>"><button type="submit"><?= cvs_activo($editado['activo']) ? 'Desactivar cuenta' : 'Activar cuenta' ?></button></form>
<form method="post" action="usuarios.php"><input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>"><input type="hidden" name="id_usuario" value="<?= $idEditar ?>"><input type="hidden" name="accion" value="clave"><div class="campos"><div class="campo"><label for="nueva">Nueva contraseña</label><input type="password" id="nueva" name="contrasena" autocomplete="new-password" required></div><div class="campo"><label for="repetir">Confirmar nueva contraseña</label><input type="password" id="repetir" name="confirmacion" autocomplete="new-password" required></div></div><div class="acciones"><button type="submit">Restablecer contraseña</button></div></form></section><?php endif; ?>
<section class="panel"><h2>Usuarios registrados</h2><form method="get" action="usuarios.php" class="filtros"><div class="campo"><label for="buscar">Buscar por usuario o correo</label><input id="buscar" name="buscar" maxlength="150" value="<?= cvs_escapar($buscar) ?>"></div><button type="submit">Buscar</button></form>
<div class="tabla" tabindex="0" role="region" aria-label="Usuarios con desplazamiento horizontal"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Vínculo</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="6">No se encontraron usuarios.</td></tr><?php endif; ?>
<?php foreach ($lista as $u): ?><tr><td><?= cvs_escapar($u['nombre_usuario']) ?></td><td><?= cvs_escapar($u['correo'] ?? 'Sin correo') ?></td><td><?= cvs_escapar($u['rol']) ?></td><td><?= cvs_escapar($u['medico'] ?? $u['paciente'] ?? 'Sin vincular') ?></td><td><span class="etiqueta <?= cvs_activo($u['activo']) ? '' : 'inactivo' ?>"><?= cvs_activo($u['activo']) ? 'Activo' : 'Inactivo' ?></span></td><td><a class="boton secundario" href="usuarios.php?editar=<?= (int)$u['id_usuario'] ?>">Administrar</a></td></tr><?php endforeach; ?>
</tbody></table></div><nav class="paginacion" aria-label="Páginas de usuarios"><?php if ($pagina > 1): ?><a href="?<?= cvs_escapar(http_build_query(['buscar'=>$buscar, 'pagina'=>$pagina-1])) ?>">Anterior</a><?php endif; ?><span>Página <?= $pagina ?> · Hasta 50 resultados</span><?php if ($siguiente): ?><a href="?<?= cvs_escapar(http_build_query(['buscar'=>$buscar, 'pagina'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?></nav></section>
</main><?php cvsu_cierre(); ?></body></html>
