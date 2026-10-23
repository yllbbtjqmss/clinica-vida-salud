<?php
// RF01: crea una sola cuenta de prueba por ejecución, exclusivamente por consola.
declare(strict_types=1);

// Rechazar la web antes de leer credenciales o cargar la conexión.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Esta herramienta solo está disponible mediante PHP CLI.');
}

// No exponer ni registrar trazas, argumentos o detalles internos ante errores.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '0');

function cvs_prueba_fallar(string $mensaje): never {
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit(1);
}

if ($argc !== 2 || $argv[1] !== '--entrada-powershell') {
    cvs_prueba_fallar(
        'Utiliza PowerShell con Read-Host -AsSecureString y envía un objeto JSON UTF-8 por la tubería con --entrada-powershell. '
        . 'Campos: rol, nombre_usuario, contrasena y confirmacion. Roles: recepcionista, medico o paciente. '
        . 'No escribas secretos en argumentos, archivos o comandos.'
    );
}

// PowerShell 5.1 necesita $OutputEncoding = New-Object System.Text.UTF8Encoding($false).
// Read-Host -AsSecureString oculta la entrada. La tubería JSON contiene texto claro
// en memoria: liberar los BSTR con ZeroFreeBSTR y las referencias en finally en PS.
// Ni unset() ni el recolector de .NET garantizan borrar todas las copias de memoria.
if (!function_exists('stream_isatty') || stream_isatty(STDIN)) {
    cvs_prueba_fallar('No introduzcas contraseñas directamente en PHP. Utiliza la tubería desde PowerShell con entrada oculta.');
}
$estadoEntrada = fstat(STDIN);
if ($estadoEntrada === false || ($estadoEntrada['mode'] & 0170000) !== 0010000) {
    cvs_prueba_fallar('Solo se aceptan credenciales mediante una tubería, no desde archivos u otros dispositivos.');
}

$entrada = stream_get_contents(STDIN, 16385);
if ($entrada === false || $entrada === '' || strlen($entrada) > 16384) {
    cvs_prueba_fallar('No se recibió una entrada válida desde PowerShell.');
}
try {
    $datos = json_decode($entrada, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $ex) {
    cvs_prueba_fallar('La entrada no es un objeto JSON válido en UTF-8.');
} finally {
    unset($entrada);
}
if (!is_array($datos) || count($datos) !== 4
    || !is_string($datos['rol'] ?? null)
    || !is_string($datos['nombre_usuario'] ?? null)
    || !is_string($datos['contrasena'] ?? null)
    || !is_string($datos['confirmacion'] ?? null)) {
    cvs_prueba_fallar('La entrada debe contener rol, nombre de usuario, contraseña y confirmación.');
}

$nombreRol = $datos['rol'];
$nombre = trim($datos['nombre_usuario']);
$contrasena = $datos['contrasena'];
$confirmacion = $datos['confirmacion'];
unset($datos);

// Lista cerrada: nunca se permite crear administradores ni roles arbitrarios.
if (!in_array($nombreRol, ['recepcionista', 'medico', 'paciente'], true)) {
    cvs_prueba_fallar('Rol no permitido. Selecciona recepcionista, medico o paciente.');
}
if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,59}\z/', $nombre) !== 1) {
    cvs_prueba_fallar('El nombre debe tener de 3 a 60 caracteres: letras sin tilde, números, punto, guion o guion bajo; debe comenzar con letra o número.');
}
// Mismas reglas que crear_administrador.php. No recortar ni normalizar secretos.
// El máximo en bytes evita el truncamiento silencioso de bcrypt.
$caracteres = preg_match_all('/./us', $contrasena);
if ($caracteres === false || $caracteres < 12 || strlen($contrasena) > 72 || str_contains($contrasena, "\0")) {
    cvs_prueba_fallar('La contraseña debe ser UTF-8 válida, tener al menos 12 caracteres, ocupar como máximo 72 bytes y no contener caracteres nulos.');
}
if (!hash_equals($contrasena, $confirmacion)) {
    cvs_prueba_fallar('La contraseña y su confirmación no coinciden. No se creó ninguna cuenta.');
}
unset($confirmacion);

$completado = false;
// El helper compartido puede finalizar por sí mismo ante un fallo de conexión.
register_shutdown_function(static function () use (&$completado): void {
    if (!$completado) exit(1);
});

$conexion = null;
try {
    $hash = password_hash($contrasena, PASSWORD_DEFAULT);
    unset($contrasena);

    @require_once __DIR__ . '/../includes/conexion.php';
    $conexion = cvs_conexion();
    $conexion->beginTransaction();

    $consultaRol = $conexion->prepare('SELECT id_rol FROM roles WHERE nombre = :nombre');
    $consultaRol->execute([':nombre'=>$nombreRol]);
    $rol = $consultaRol->fetch(PDO::FETCH_ASSOC);
    if (!$rol) {
        $conexion->rollBack();
        cvs_prueba_fallar('No existe el rol seleccionado. Revisa los datos iniciales antes de continuar.');
    }

    $consultaNombre = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE nombre_usuario = :nombre');
    $consultaNombre->execute([':nombre'=>$nombre]);
    if ($consultaNombre->fetch(PDO::FETCH_ASSOC)) {
        $conexion->rollBack();
        cvs_prueba_fallar('Ese nombre de usuario ya existe. No se sobrescribió ni modificó la cuenta.');
    }

    // UNIQUE(nombre_usuario) rechaza también duplicados entre procesos simultáneos.
    // Solo se inserta en usuarios: sin permisos especiales ni asociaciones clínicas.
    $insertar = $conexion->prepare('INSERT INTO usuarios (id_rol, nombre_usuario, contrasena_hash, activo)
        VALUES (:rol, :nombre, :hash, TRUE)');
    $insertar->execute([':rol'=>(int)$rol['id_rol'], ':nombre'=>$nombre, ':hash'=>$hash]);
    unset($hash);
    $conexion->commit();
    $completado = true;
    fwrite(STDOUT, 'Cuenta de prueba creada correctamente con el rol ' . $nombreRol . '.' . PHP_EOL);
    fwrite(STDOUT, 'Para futuras pantallas personales será obligatorio asociar médicos y pacientes a sus registros mediante id_usuario.' . PHP_EOL);
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
        cvs_prueba_fallar('El nombre de usuario ya está registrado. No se sobrescribió ninguna cuenta.');
    }
    cvs_prueba_fallar('No fue posible crear la cuenta de prueba. Revisa la disponibilidad de PostgreSQL y vuelve a intentarlo.');
}
