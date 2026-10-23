<?php
declare(strict_types=1);

function cvs_error_base_datos(Throwable $ex): never {
    // No registrar mensajes, trazas, parámetros ni detalles de la conexión privada.
    $detalle = $ex instanceof PDOException ? 'PDOException' : 'Error de inicializacion';
    $codigo = (string)$ex->getCode();
    if ($ex instanceof PDOException && preg_match('/^[A-Z0-9]{5}$/D', $codigo) === 1) {
        $detalle .= '; SQLSTATE ' . $codigo;
    }
    error_log('RF01: fallo de base de datos; ' . $detalle);
    http_response_code(500);
    exit('No se pudo establecer la conexión con la base de datos');
}

function cvs_conexion(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    try {
        // Carga diferida: las páginas anónimas no necesitan conectarse.
        // Suprime advertencias de carga que podrían revelar la ruta privada.
        @require 'C:/xampp/config_clinica/database.php';
        if (!isset($conexion) || !($conexion instanceof PDO)) {
            throw new RuntimeException('Conexion PDO no disponible.');
        }
        if (!$conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)) {
            throw new RuntimeException('No se pudo configurar PDO.');
        }
        $pdo = $conexion;
        return $pdo;
    } catch (Throwable $ex) {
        cvs_error_base_datos($ex);
    }
}
