<?php
declare(strict_types=1);

// Rechazar HTTP antes de cargar la configuración privada o conectar con PostgreSQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este diagnóstico solo está disponible mediante PHP CLI.');
}

// No mostrar ni registrar detalles internos de la carga o de la conexión.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '0');

$correcto = false;
// Descartar también cualquier salida incidental del archivo de configuración.
ob_start();
try {
    @require 'C:/xampp/config_clinica/database.php';
    $correcto = isset($conexion) && $conexion instanceof PDO;
} catch (Throwable $e) {
    // No imprimir ni registrar el mensaje, la traza o los datos de la excepción.
    $correcto = false;
} finally {
    ob_end_clean();
}

if (!$correcto) {
    fwrite(STDERR, 'No se pudo comprobar la conexión.' . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Conexión PDO establecida correctamente.' . PHP_EOL);
