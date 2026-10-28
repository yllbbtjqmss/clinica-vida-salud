<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['paciente']);
require_once __DIR__ . '/includes/personal.php';
require_once __DIR__ . '/includes/ui.php';
cvsp_solo_get(['periodo', 'pagina']);
$periodo = $_GET['periodo'] ?? 'proximas';
$pagina = filter_var($_GET['pagina'] ?? '1', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>100000]]);
if (!in_array($periodo, ['proximas', 'anteriores', 'todas'], true) || !$pagina) {
    http_response_code(400); exit('El período o la página solicitada no es válido.');
}
$pdo = cvs_conexion(); $citas = []; $total = 0; $paginas = 1;
try {
    $q = $pdo->prepare('SELECT nombres, apellidos, fecha_nacimiento, telefono, correo FROM pacientes WHERE id_usuario=:usuario AND activo=TRUE');
    $q->execute([':usuario'=>$usuario['id_usuario']]); $paciente = $q->fetch(PDO::FETCH_ASSOC);
    if ($paciente) {
        $ahora = cvsd_ahora()->format('Y-m-d H:i:s');
        $condicion = match ($periodo) {
            'proximas' => ' AND c.fecha + c.hora_fin >= CAST(:ahora AS TIMESTAMP)',
            'anteriores' => ' AND c.fecha + c.hora_fin < CAST(:ahora AS TIMESTAMP)',
            default => ''
        };
        $params = [':usuario'=>$usuario['id_usuario']];
        if ($condicion !== '') $params[':ahora'] = $ahora;
        $base = ' FROM citas c JOIN pacientes p ON p.id_paciente=c.id_paciente
            JOIN medicos m ON m.id_medico=c.id_medico JOIN especialidades e ON e.id_especialidad=c.id_especialidad
            WHERE p.id_usuario=:usuario AND p.activo=TRUE' . $condicion;
        $q = $pdo->prepare('SELECT COUNT(*)' . $base); $q->execute($params); $total = (int)$q->fetchColumn();
        $paginas = max(1, (int)ceil($total / 50)); $pagina = min($pagina, $paginas);
        $orden = $periodo === 'proximas' ? 'ASC' : 'DESC';
        $q = $pdo->prepare("SELECT c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.estado,
            m.nombres || ' ' || m.apellidos AS persona, e.nombre AS especialidad" . $base . " ORDER BY c.fecha $orden, c.hora_inicio $orden, c.id_cita $orden LIMIT 50 OFFSET :offset");
        foreach ($params as $k=>$v) $q->bindValue($k, $v);
        $q->bindValue(':offset', ($pagina-1)*50, PDO::PARAM_INT); $q->execute(); $citas = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $ex) { cvs_error_base_datos($ex); }
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mis citas | Clínica Vida Salud</title><link rel="stylesheet" href="assets/estilos.css"></head><body>
<?php cvsu_area('mis_citas', 'Mis citas y perfil'); ?><main class="ui-contenido"><section class="panel"><h1>Mis citas y perfil</h1>
<?php if (!$paciente): ?><p class="aviso">Tu cuenta todavía no tiene un registro de paciente activo vinculado. Solicita al administrador que revise el vínculo.</p>
<?php else: ?><h2>Mi perfil básico</h2><dl class="detalle"><?php foreach (['nombres'=>'Nombres', 'apellidos'=>'Apellidos', 'fecha_nacimiento'=>'Fecha de nacimiento', 'telefono'=>'Teléfono', 'correo'=>'Correo de la ficha'] as $campo=>$etiqueta): ?><div><dt><?= cvs_escapar($etiqueta) ?></dt><dd><?= cvs_escapar($paciente[$campo] ?? 'No registrado') ?></dd></div><?php endforeach; ?></dl>
<p>Para corregir tu ficha o gestionar una cita, comunícate con recepción.</p><?php endif; ?></section>
<?php if ($paciente): ?><section class="panel"><h2>Consulta de mis citas</h2><form method="get" action="mis_citas.php" class="filtros"><div class="campo"><label for="periodo">Período</label><select id="periodo" name="periodo"><?php foreach (['proximas'=>'Próximas y en curso', 'anteriores'=>'Anteriores', 'todas'=>'Todas'] as $v=>$et): ?><option value="<?= $v ?>" <?= $periodo === $v ? 'selected' : '' ?>><?= $et ?></option><?php endforeach; ?></select></div><button type="submit">Consultar</button></form><p>Se conservan todos los estados, incluidas las canceladas. Las anteriores se distinguen por la finalización del horario en America/La_Paz. Total: <?= $total ?>.</p>
<?php cvsp_tabla_citas($citas, 'Médico'); ?><nav class="paginacion" aria-label="Páginas de mis citas"><?php if ($pagina > 1): ?><a href="?<?= cvs_escapar(http_build_query(['periodo'=>$periodo, 'pagina'=>$pagina-1])) ?>">Anterior</a><?php endif; ?><span>Página <?= $pagina ?> de <?= $paginas ?></span><?php if ($pagina < $paginas): ?><a href="?<?= cvs_escapar(http_build_query(['periodo'=>$periodo, 'pagina'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?></nav></section><?php endif; ?>
</main><?php cvsu_cierre(); ?></body></html>
