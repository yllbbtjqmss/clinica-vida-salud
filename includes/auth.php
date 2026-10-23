<?php
declare(strict_types=1);
require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/conexion.php';

const CVS_ROLES = ['administrador', 'recepcionista', 'medico', 'paciente'];

function cvs_rol_valido(mixed $rol): bool {
    return is_string($rol) && in_array($rol, CVS_ROLES, true);
}

function cvs_activo(mixed $valor): bool {
    return in_array($valor, [true, 1, '1', 't', 'true'], true);
}

function cvs_guardar_identidad(array $usuario): void {
    // Esta función recibe únicamente resultados de consultas, nunca $_POST/$_GET.
    $_SESSION['cvs_auth'] = [
        'id_usuario'=>(int)$usuario['id_usuario'],
        'id_rol'=>(int)$usuario['id_rol'],
        'nombre_usuario'=>(string)$usuario['nombre_usuario'],
        'rol'=>(string)$usuario['rol']
    ];
    $_SESSION['cvs_ultima_actividad'] = time();
}

function cvs_usuario_actual(): ?array {
    if (!isset($_SESSION['cvs_auth'])) return null;
    $identidad = $_SESSION['cvs_auth'];
    $id = is_array($identidad) ? ($identidad['id_usuario'] ?? null) : null;
    if (!is_int($id) || $id < 1 || $id > 2147483647) {
        cvs_olvidar_autenticacion();
        return null;
    }
    try {
        // Revalidar en cada petición: no autorizar con un rol antiguo de la sesión.
        $consulta = cvs_conexion()->prepare('SELECT u.id_usuario, u.id_rol, u.nombre_usuario, u.activo, r.nombre AS rol
            FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = :id');
        $consulta->execute([':id'=>$id]);
        $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        cvs_error_base_datos($ex);
    }
    if (!$usuario || !cvs_activo($usuario['activo']) || !cvs_rol_valido($usuario['rol'])) {
        cvs_olvidar_autenticacion();
        return null;
    }
    cvs_guardar_identidad($usuario);
    return $_SESSION['cvs_auth'];
}

function cvs_exigir_autenticacion(): array {
    $usuario = cvs_usuario_actual();
    if ($usuario === null) {
        // Los puntos de entrada de esta etapa están en la raíz del proyecto.
        header('Location: login.php', true, 303);
        exit;
    }
    return $usuario;
}

function cvs_denegar_acceso(): never {
    http_response_code(403);
    exit('No tienes permisos para acceder a este recurso.');
}

function cvs_exigir_roles(array $rolesPermitidos): array {
    $usuario = cvs_exigir_autenticacion();
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) cvs_denegar_acceso();
    return $usuario;
}

function cvs_exigir_propietario(string $tipo, int $idRegistro): void {
    // Uso futuro: la autorización se basa en las FK reales id_usuario.
    // No concede acceso automático a terceros, ni siquiera por ser administrador.
    $mapa = [
        'paciente'=>['tabla'=>'pacientes', 'clave'=>'id_paciente'],
        'medico'=>['tabla'=>'medicos', 'clave'=>'id_medico']
    ];
    if (!isset($mapa[$tipo]) || $idRegistro < 1 || $idRegistro > 2147483647) cvs_denegar_acceso();
    $usuario = cvs_exigir_roles([$tipo]);
    $tabla = $mapa[$tipo]['tabla'];
    $clave = $mapa[$tipo]['clave'];
    try {
        $consulta = cvs_conexion()->prepare("SELECT $clave FROM $tabla WHERE $clave = :id AND id_usuario = :usuario AND activo = TRUE");
        $consulta->execute([':id'=>$idRegistro, ':usuario'=>$usuario['id_usuario']]);
        if (!$consulta->fetch(PDO::FETCH_ASSOC)) cvs_denegar_acceso();
    } catch (PDOException $ex) {
        cvs_error_base_datos($ex);
    }
}
