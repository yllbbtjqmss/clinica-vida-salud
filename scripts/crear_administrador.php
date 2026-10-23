<?php
// RF01: alta inicial exclusivamente por consola. No es un gestor de usuarios.
declare(strict_types=1);

// Este control debe ejecutarse antes de cargar la conexión o leer datos.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Esta herramienta solo está disponible mediante PHP CLI.');
}

function cvs_admin_fallar(string $mensaje): never {
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit(1);
}

if ($argc !== 2 || $argv[1] !== '--entrada-powershell') {
    cvs_admin_fallar(
        'Utiliza PowerShell con Read-Host -AsSecureString y envía un objeto JSON por la tubería a esta herramienta con --entrada-powershell. '
        . 'Campos: nombre_usuario, contrasena y confirmacion. No escribas secretos en argumentos, archivos o comandos.'
    );
}

// PHP CLI en Windows no ofrece aquí una lectura nativa oculta de contraseñas.
// Rechazar la entrada interactiva: los secretos deben llegar desde la tubería
// del procedimiento PowerShell, que pregunta mediante Read-Host -AsSecureString.
if (!function_exists('stream_isatty') || stream_isatty(STDIN)) {
    cvs_admin_fallar('No introduzcas contraseñas directamente en PHP. Utiliza la tubería segura desde PowerShell.');
}
$estadoEntrada = fstat(STDIN);
if ($estadoEntrada !== false && ($estadoEntrada['mode'] & 0170000) === 0100000) {
    cvs_admin_fallar('No se permite leer credenciales desde un archivo. Utiliza una tubería desde PowerShell.');
}

$entrada = stream_get_contents(STDIN, 16385);
if ($entrada === false || $entrada === '' || strlen($entrada) > 16384) {
    cvs_admin_fallar('No se recibió una entrada válida desde PowerShell.');
}
try {
    $datos = json_decode($entrada, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $ex) {
    // No mostrar la entrada ni el detalle del error de JSON.
    cvs_admin_fallar('La entrada no es un objeto JSON válido en UTF-8.');
} finally {
    unset($entrada);
}
if (!is_array($datos) || count($datos) !== 3
    || !is_string($datos['nombre_usuario'] ?? null)
    || !is_string($datos['contrasena'] ?? null)
    || !is_string($datos['confirmacion'] ?? null)) {
    cvs_admin_fallar('La entrada debe contener nombre de usuario, contraseña y confirmación.');
}

$nombre = trim($datos['nombre_usuario']);
$contrasena = $datos['contrasena'];
$confirmacion = $datos['confirmacion'];
unset($datos);

if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,59}\z/', $nombre) !== 1) {
    cvs_admin_fallar('El nombre debe tener de 3 a 60 caracteres: letras sin tilde, números, punto, guion o guion bajo; debe comenzar con letra o número.');
}
// No recortar ni normalizar contraseñas. El máximo evita el truncamiento
// silencioso de bcrypt, utilizado por PASSWORD_DEFAULT en el PHP actual.
$caracteres = preg_match_all('/./us', $contrasena);
if ($caracteres === false || $caracteres < 12 || strlen($contrasena) > 72 || str_contains($contrasena, "\0")) {
    cvs_admin_fallar('La contraseña debe ser UTF-8 válida, tener al menos 12 caracteres, ocupar como máximo 72 bytes y no contener caracteres nulos.');
}
if (!hash_equals($contrasena, $confirmacion)) {
    cvs_admin_fallar('La contraseña y su confirmación no coinciden. No se creó ninguna cuenta.');
}
unset($confirmacion);

$completado = false;
// El helper compartido finaliza por sí mismo ante un fallo de conexión.
// Garantizar un código de salida fallido también en ese caso.
register_shutdown_function(static function () use (&$completado): void {
    if (!$completado) exit(1);
});

$conexion = null;
try {
    @require_once __DIR__ . '/../includes/conexion.php';
    $conexion = cvs_conexion();
    $conexion->beginTransaction();

    // Serializa ejecuciones de ESTA herramienta hasta terminar la transacción.
    // No crea tablas ni modifica el esquema. La pareja es un identificador fijo.
    $bloqueo = $conexion->prepare('SELECT pg_advisory_xact_lock(:aplicacion, :operacion)');
    $bloqueo->bindValue(':aplicacion', 1129729107, PDO::PARAM_INT);
    $bloqueo->bindValue(':operacion', 1, PDO::PARAM_INT);
    $bloqueo->execute();

    $consultaRol = $conexion->prepare('SELECT id_rol FROM roles WHERE nombre = :nombre');
    $consultaRol->execute([':nombre'=>'administrador']);
    $rol = $consultaRol->fetch(PDO::FETCH_ASSOC);
    if (!$rol) {
        $conexion->rollBack();
        cvs_admin_fallar('No existe el rol administrador. Revisa los datos iniciales antes de continuar.');
    }

    $consultaNombre = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE nombre_usuario = :nombre');
    $consultaNombre->execute([':nombre'=>$nombre]);
    if ($consultaNombre->fetch(PDO::FETCH_ASSOC)) {
        $conexion->rollBack();
        cvs_admin_fallar('Ese nombre de usuario ya existe. No se sobrescribió ni modificó la cuenta.');
    }

    $consultaAdministrador = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE id_rol = :rol LIMIT 1');
    $consultaAdministrador->execute([':rol'=>(int)$rol['id_rol']]);
    if ($consultaAdministrador->fetch(PDO::FETCH_ASSOC)) {
        $conexion->rollBack();
        cvs_admin_fallar('Ya existe una cuenta de administrador, activa o inactiva. Esta herramienta solo crea la primera; no se creó otra cuenta.');
    }

    $hash = password_hash($contrasena, PASSWORD_DEFAULT);
    unset($contrasena);
    $insertar = $conexion->prepare('INSERT INTO usuarios (id_rol, nombre_usuario, contrasena_hash, activo)
        VALUES (:rol, :nombre, :hash, TRUE)');
    $insertar->execute([':rol'=>(int)$rol['id_rol'], ':nombre'=>$nombre, ':hash'=>$hash]);
    unset($hash);
    $conexion->commit();
    $completado = true;
    fwrite(STDOUT, 'Administrador inicial creado correctamente. Ya puedes probar el inicio de sesión.' . PHP_EOL);
} catch (Throwable $ex) {
    if ($conexion instanceof PDO && $conexion->inTransaction()) {
        try {
            $conexion->rollBack();
        } catch (Throwable $rollbackEx) {
            // No mostrar ni registrar detalles del motor o de la conexión.
        }
    }
    unset($contrasena, $confirmacion, $hash);
    if ($ex instanceof PDOException && (string)$ex->getCode() === '23505') {
        cvs_admin_fallar('El nombre de usuario ya está registrado. No se sobrescribió ninguna cuenta.');
    }
    // No mostrar ni registrar mensajes de excepción, SQL, contraseñas o hashes.
    cvs_admin_fallar('No fue posible crear el administrador. Revisa la disponibilidad de PostgreSQL y vuelve a intentarlo.');
}
