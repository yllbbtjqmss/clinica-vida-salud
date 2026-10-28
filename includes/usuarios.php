<?php
declare(strict_types=1);

function cvsu_id(mixed $valor): int {
    return is_string($valor) && preg_match('/\A[1-9][0-9]{0,9}\z/', $valor)
        ? (filter_var($valor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) ?: 0) : 0;
}

function cvsu_validar_clave(mixed $clave, mixed $confirmacion): string {
    $n = is_string($clave) ? preg_match_all('/./us', $clave) : false;
    if ($n === false || $n < 12 || strlen($clave) > 72 || str_contains($clave, "\0")) {
        return 'La contraseña debe tener al menos 12 caracteres UTF-8, como máximo 72 bytes y ningún carácter nulo.';
    }
    return is_string($confirmacion) && hash_equals($clave, $confirmacion) ? '' : 'Las contraseñas no coinciden.';
}

/** La llamada web debe haber validado CSRF. Revalida el administrador bajo bloqueo. */
function cvsu_guardar_usuario(PDO $pdo, int $actor, array $datos): int {
    $accion = cvs_texto($datos, 'accion');
    $id = cvsu_id($datos['id_usuario'] ?? null);
    if (!in_array($accion, ['crear', 'editar', 'activar', 'desactivar', 'clave'], true)
        || ($accion !== 'crear' && !$id)) throw new DomainException('Operación o usuario inválido.');
    $pdo->beginTransaction();
    try {
        // Serializa altas/ediciones incluso frente a los scripts CLI: evita carreras
        // de duplicados normalizados y de desactivación del último administrador.
        $pdo->exec('LOCK TABLE usuarios IN SHARE ROW EXCLUSIVE MODE');
        $q = $pdo->prepare('SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r USING (id_rol) WHERE id_usuario = :id');
        $q->execute([':id'=>$actor]);
        $admin = $q->fetch(PDO::FETCH_ASSOC);
        if (!$admin || !cvs_activo($admin['activo']) || $admin['rol'] !== 'administrador') {
            throw new DomainException('Tu cuenta ya no tiene permisos administrativos.');
        }
        $actual = null;
        if ($accion !== 'crear') {
            $q->execute([':id'=>$id]);
            $actual = $q->fetch(PDO::FETCH_ASSOC);
            if (!$actual) throw new DomainException('No se encontró la cuenta solicitada.');
        }
        if ($accion === 'clave' || $accion === 'crear') {
            $error = cvsu_validar_clave($datos['contrasena'] ?? null, $datos['confirmacion'] ?? null);
            if ($error !== '') throw new DomainException($error);
        }
        if (in_array($accion, ['crear', 'editar'], true)) {
            $nombre = cvs_texto($datos, 'nombre_usuario');
            $correo = cvs_texto($datos, 'correo');
            $rol = cvs_texto($datos, 'rol');
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,59}\z/', $nombre)) {
                throw new DomainException('Usuario: utiliza de 3 a 60 letras sin tilde, números, puntos o guiones; comienza con letra o número.');
            }
            if ($correo !== '' && (strlen($correo) > 150 || !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
                throw new DomainException('Introduce un correo electrónico válido de hasta 150 caracteres.');
            }
            if (!cvs_rol_valido($rol)) throw new DomainException('Selecciona un rol válido.');
            if ($id === $actor && $rol !== $actual['rol']) throw new DomainException('No puedes cambiar tu propio rol.');
            $dup = $pdo->prepare('SELECT 1 FROM usuarios WHERE id_usuario <> :id AND
                (LOWER(TRIM(nombre_usuario)) = LOWER(:nombre) OR LOWER(TRIM(correo)) = LOWER(:correo))');
            $dup->execute([':id'=>$id, ':nombre'=>$nombre, ':correo'=>$correo]);
            if ($dup->fetchColumn()) throw new DomainException('El nombre de usuario o correo ya está registrado.');
            $r = $pdo->prepare('SELECT id_rol FROM roles WHERE nombre = :rol');
            $r->execute([':rol'=>$rol]);
            $idRol = $r->fetchColumn();
            if (!$idRol) throw new DomainException('El rol no está configurado en la base de datos.');
            if ($actual && cvs_activo($actual['activo']) && $actual['rol'] === 'administrador' && $rol !== 'administrador') {
                cvsu_proteger_ultimo_admin($pdo);
            }
            $params = [':nombre'=>$nombre, ':correo'=>$correo === '' ? null : $correo, ':rol'=>$idRol];
            if ($accion === 'crear') {
                $params[':hash'] = password_hash($datos['contrasena'], PASSWORD_DEFAULT);
                $sql = 'INSERT INTO usuarios (nombre_usuario, correo, id_rol, contrasena_hash) VALUES (:nombre, :correo, :rol, :hash) RETURNING id_usuario';
            } else {
                $params[':id'] = $id;
                $sql = 'UPDATE usuarios SET nombre_usuario=:nombre, correo=:correo, id_rol=:rol WHERE id_usuario=:id RETURNING id_usuario';
            }
            $q = $pdo->prepare($sql);
            $q->execute($params);
            $id = (int)$q->fetchColumn();
            // Solo claves y tablas de esta lista fija pueden formar parte del SQL.
            foreach (['medico'=>['medicos', 'id_medico'], 'paciente'=>['pacientes', 'id_paciente']] as $tipo => [$tabla, $pk]) {
                $valor = $datos[$pk] ?? '';
                if (!is_string($valor)) throw new DomainException('El vínculo seleccionado no es válido.');
                $vinculo = $valor === '' ? 0 : cvsu_id($valor);
                if ($valor !== '' && (!$vinculo || $rol !== $tipo)) throw new DomainException('El vínculo debe corresponder al rol seleccionado.');
                if ($vinculo) {
                    $q = $pdo->prepare("SELECT id_usuario FROM $tabla WHERE $pk=:id FOR UPDATE");
                    $q->execute([':id'=>$vinculo]);
                    $fila = $q->fetch(PDO::FETCH_ASSOC);
                    if (!$fila || ($fila['id_usuario'] !== null && (int)$fila['id_usuario'] !== $id)) {
                        throw new DomainException('El registro no existe o ya está vinculado a otra cuenta.');
                    }
                }
                $q = $pdo->prepare("UPDATE $tabla SET id_usuario=NULL WHERE id_usuario=:usuario");
                $q->execute([':usuario'=>$id]);
                if ($vinculo) {
                    $q = $pdo->prepare("UPDATE $tabla SET id_usuario=:usuario WHERE $pk=:id");
                    $q->execute([':usuario'=>$id, ':id'=>$vinculo]);
                }
            }
        } elseif ($accion === 'clave') {
            $q = $pdo->prepare('UPDATE usuarios SET contrasena_hash=:hash WHERE id_usuario=:id');
            $q->execute([':hash'=>password_hash($datos['contrasena'], PASSWORD_DEFAULT), ':id'=>$id]);
        } else {
            if ($accion === 'desactivar' && $actual['rol'] === 'administrador' && cvs_activo($actual['activo'])) {
                cvsu_proteger_ultimo_admin($pdo);
            }
            $q = $pdo->prepare('UPDATE usuarios SET activo=:activo WHERE id_usuario=:id');
            $q->bindValue(':activo', $accion === 'activar', PDO::PARAM_BOOL);
            $q->bindValue(':id', $id, PDO::PARAM_INT);
            $q->execute();
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function cvsu_proteger_ultimo_admin(PDO $pdo): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM usuarios u JOIN roles r USING (id_rol) WHERE u.activo AND r.nombre='administrador'")->fetchColumn() <= 1) {
        throw new DomainException('Debe conservarse al menos una cuenta administradora activa.');
    }
}
