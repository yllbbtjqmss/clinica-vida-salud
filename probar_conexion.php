<?php
try {
    require "C:/xampp/config_clinica/database.php";
    $consulta = $conexion->query("SELECT current_database()");
    $resultado = $consulta->fetchColumn();
    echo "¡Conexión exitosa con la base de datos: "
         . htmlspecialchars($resultado) . "!";
} catch (Throwable $e) {
    http_response_code(500);
    echo "No se pudo comprobar la conexión.";
}
