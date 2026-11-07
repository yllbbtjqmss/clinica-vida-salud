<?php
declare(strict_types=1);
// Solo CLI: representación de roles ficticios, sin autenticar usuarios ni conectar PostgreSQL.
if (PHP_SAPI !== 'cli' || getenv('CVS_LUMI_VISUAL_TEST') !== '1') {
    http_response_code(404);
    exit;
}
$rolPrueba = $argv[1] ?? '';
function cvs_usuario_actual(): ?array {
    global $rolPrueba;
    return in_array($rolPrueba, ['paciente', 'administrador', 'medico', 'recepcionista'], true)
        ? ['id_usuario' => 1, 'nombre_usuario' => 'Cuenta ficticia', 'rol' => $rolPrueba] : null;
}
require_once dirname(__DIR__) . '/includes/ui.php';
if (($argv[2] ?? '') === 'funciones') {
    echo json_encode(lumi_enlaces_permitidos($rolPrueba), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($rolPrueba === 'config') {
    echo json_encode(lumi_config_base(), JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="assets/estilos.css"><title>Lumi · prueba visual aislada</title></head><body>
<?php cvsu_area('inicio', 'Prueba visual de Lumi'); ?>
<main class="ui-contenido"><h1>Prueba con identidad ficticia</h1><a href="#contenido" id="contenido">Contenido de la página</a></main>
<?php cvsu_cierre(); cvsu_lumi(); // Una segunda invocación no debe duplicar el widget. ?>
</body></html>
