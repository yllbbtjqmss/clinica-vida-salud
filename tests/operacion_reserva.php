<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('CVS_TEST_AISLADO')!=='1' || getenv('CVS_DB_HOST')!=='127.0.0.1'
    || getenv('CVS_DB_NAME')!=='cvs_test_pagos' || getenv('CVS_DB_PORT')==='5432') exit(2);
require_once __DIR__.'/../includes/reservas_pagos.php';
try {
    $datos=json_decode((string)getenv('CVS_TEST_OPERACION'),true,16,JSON_THROW_ON_ERROR);
    $pdo=cvs_conexion();
    // Ambos procesos abren conexión antes de liberar la barrera temporal del runner.
    $inicio=min((float)getenv('CVS_TEST_START_AT'),microtime(true)+3);
    while (microtime(true)<$inicio) usleep(1000);
    if (($datos['accion'] ?? '')==='cobrar') {
        $id=$datos['id']; cvp_cobrar($pdo,$datos['actor'],$id,'efectivo',$datos['referencia'],true);
    } elseif (($datos['accion'] ?? '')==='legado') {
        $d=$datos['datos'];
        $id=cvp_insertar_cita($pdo,$datos['paciente'],(int)$d['id_medico'],(int)$d['id_especialidad'],$d['fecha'],$d['hora_inicio'],'13:00','programada');
    } else {
        $id=cvp_reservar($pdo,$datos['actor'],$datos['datos'],bin2hex(random_bytes(32)));
    }
    echo json_encode(['ok'=>true,'id'=>$id]);
} catch (Throwable $ex) { echo json_encode(['ok'=>false,'tipo'=>get_class($ex)]); }
