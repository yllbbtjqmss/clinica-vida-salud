<?php
session_start();
require 'C:/xampp/config_clinica/database.php';
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$errores = [];
$datos = ['ci'=>'', 'nombres'=>'', 'apellidos'=>'', 'fecha_nacimiento'=>'', 'sexo'=>'', 'telefono'=>'', 'correo'=>'', 'direccion'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($datos as $campo => $valor) {
        $datos[$campo] = trim((string)($_POST[$campo] ?? ''));
    }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errores[] = 'Solicitud no válida. Recarga la página.';
    }
    if ($datos['nombres'] === '' || $datos['apellidos'] === '' || $datos['fecha_nacimiento'] === '' || $datos['telefono'] === '') {
        $errores[] = 'Completa nombres, apellidos, fecha de nacimiento y teléfono.';
    }
    if (strlen($datos['nombres']) > 100 || strlen($datos['apellidos']) > 100 || strlen($datos['ci']) > 25 || strlen($datos['telefono']) > 30 || strlen($datos['direccion']) > 255) {
        $errores[] = 'Uno de los campos supera la longitud permitida.';
    }
    if ($datos['correo'] !== '' && (strlen($datos['correo']) > 150 || !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL))) {
        $errores[] = 'Introduce un correo electrónico válido.';
    }
    if ($datos['sexo'] !== '' && !in_array($datos['sexo'], ['Femenino', 'Masculino', 'Otro', 'Prefiere no indicar'], true)) {
        $errores[] = 'Selecciona una opción válida para sexo.';
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
    if (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha_nacimiento'] || $fecha > new DateTimeImmutable('today')) {
        $errores[] = 'La fecha de nacimiento no es válida.';
    }
    if (!$errores) {
        try {
            $sql = 'INSERT INTO pacientes (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion) VALUES (:ci, :nombres, :apellidos, :fecha, :sexo, :telefono, :correo, :direccion)';
            $stmt = $conexion->prepare($sql);
            $stmt->execute([
                ':ci' => $datos['ci'] !== '' ? $datos['ci'] : null,
                ':nombres' => $datos['nombres'],
                ':apellidos' => $datos['apellidos'],
                ':fecha' => $datos['fecha_nacimiento'],
                ':sexo' => $datos['sexo'] !== '' ? $datos['sexo'] : null,
                ':telefono' => $datos['telefono'],
                ':correo' => $datos['correo'] !== '' ? $datos['correo'] : null,
                ':direccion' => $datos['direccion'] !== '' ? $datos['direccion'] : null,
            ]);
            $_SESSION['aviso'] = 'Paciente registrado correctamente.';
            header('Location: pacientes.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $errores[] = 'Ese CI ya está registrado.';
            } else {
                error_log($e->getMessage());
                $errores[] = 'No se pudo guardar el paciente.';
            }
        }
    }
}
try {
    $lista = $conexion->query('SELECT id_paciente, ci, nombres, apellidos, fecha_nacimiento, telefono FROM pacientes WHERE activo = TRUE ORDER BY id_paciente DESC LIMIT 100')->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $lista = [];
    $errores[] = 'No se pudo cargar el listado.';
}
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$aviso = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pacientes | Clínica Vida Salud</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f7f8;color:#19333c;font-family:Arial,sans-serif}header{background:#146d73;color:white;padding:22px max(20px,calc((100vw - 1050px)/2))}header h1{margin:0;font-size:25px}header p{margin:7px 0 0}main{max-width:1050px;margin:28px auto;padding:0 16px}.panel{background:white;border-radius:13px;padding:25px;margin-bottom:24px;box-shadow:0 3px 20px #0a30301a}h2{margin-top:0;font-size:21px}.campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}label{display:block;font-weight:600;font-size:14px;margin-bottom:7px}input,select{width:100%;border:1px solid #cbd6d9;border-radius:7px;padding:11px;font-size:15px;background:white}button{background:#146d73;color:white;border:0;border-radius:8px;padding:12px 22px;font-weight:bold;margin-top:20px;cursor:pointer}.mensaje{border-radius:7px;padding:12px 15px;margin-bottom:15px;background:#e2f7e9}.error{background:#fce6e6}.tabla{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:630px}td,th{text-align:left;border-bottom:1px solid #e5ebee;padding:12px;font-size:14px}th{background:#edf5f5}@media(max-width:650px){.campos{grid-template-columns:1fr}.panel{padding:17px}}
</style>
</head>
<body>
<header><h1>Clínica Vida Salud</h1><p>Gestión de pacientes · Versión local de desarrollo</p></header>
<main>
<section class="panel">
<h2>Registrar nuevo paciente</h2>
<?php if ($aviso): ?><div class="mensaje"><?= e($aviso) ?></div><?php endif; ?>
<?php foreach ($errores as $error): ?><div class="mensaje error"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" action="pacientes.php">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<div class="campos">
<div><label for="nombres">Nombres *</label><input id="nombres" name="nombres" maxlength="100" required value="<?= e($datos['nombres']) ?>"></div>
<div><label for="apellidos">Apellidos *</label><input id="apellidos" name="apellidos" maxlength="100" required value="<?= e($datos['apellidos']) ?>"></div>
<div><label for="ci">CI (opcional)</label><input id="ci" name="ci" maxlength="25" value="<?= e($datos['ci']) ?>"></div>
<div><label for="fecha_nacimiento">Fecha de nacimiento *</label><input id="fecha_nacimiento" type="date" name="fecha_nacimiento" required max="<?= date('Y-m-d') ?>" value="<?= e($datos['fecha_nacimiento']) ?>"></div>
<div><label for="sexo">Sexo</label><select id="sexo" name="sexo"><option value="">No indicado</option><?php foreach (['Femenino','Masculino','Otro','Prefiere no indicar'] as $opcion): ?><option value="<?= e($opcion) ?>" <?= $datos['sexo'] === $opcion ? 'selected' : '' ?>><?= e($opcion) ?></option><?php endforeach; ?></select></div>
<div><label for="telefono">Teléfono *</label><input id="telefono" name="telefono" maxlength="30" required value="<?= e($datos['telefono']) ?>"></div>
<div><label for="correo">Correo electrónico</label><input id="correo" name="correo" type="email" maxlength="150" value="<?= e($datos['correo']) ?>"></div>
<div><label for="direccion">Dirección</label><input id="direccion" name="direccion" maxlength="255" value="<?= e($datos['direccion']) ?>"></div>
</div><button type="submit">Guardar paciente</button>
</form>
</section>
<section class="panel"><h2>Pacientes registrados</h2><div class="tabla"><table><thead><tr><th>Código</th><th>CI</th><th>Nombres</th><th>Apellidos</th><th>Nacimiento</th><th>Teléfono</th></tr></thead><tbody>
<?php if (!$lista): ?><tr><td colspan="6">Todavía no hay pacientes para mostrar.</td></tr><?php endif; ?>
<?php foreach ($lista as $paciente): ?><tr><td><?= e($paciente['id_paciente']) ?></td><td><?= e($paciente['ci'] ?? '—') ?></td><td><?= e($paciente['nombres']) ?></td><td><?= e($paciente['apellidos']) ?></td><td><?= e($paciente['fecha_nacimiento']) ?></td><td><?= e($paciente['telefono']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</main></body></html>
