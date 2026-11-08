<?php
declare(strict_types=1);
/**
 * Preferencias administrativas del asistente virtual (etapa 1): nombre,
 * saludo, personalidad/tono y tema. El chat de usuarios no cambia de
 * comportamiento; solo la personalidad que el servidor envía al proveedor.
 */
require_once __DIR__ . '/includes/auth.php';
$usuario = cvs_exigir_roles(['administrador']);
require_once __DIR__ . '/includes/lumi.php';
require_once __DIR__ . '/includes/ui.php';

$pdo = cvs_conexion();
$error = null; $ok = null;
$instalada = false; $filas = 0;
try {
    $instalada = cvs_activo($pdo->query("SELECT to_regclass('public.lumi_config') IS NOT NULL")->fetchColumn());
} catch (PDOException $ex) { cvs_error_base_datos($ex); }
$config = lumi_config($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!cvs_csrf_valido($_POST['csrf'] ?? null)) throw new DomainException('Solicitud no válida. Recarga el formulario.');
        $entrada = [
            'nombre' => cvs_texto($_POST, 'nombre'),
            'saludo' => cvs_texto($_POST, 'saludo'),
            'tono' => cvs_texto($_POST, 'tono'),
            'tema' => cvs_texto($_POST, 'tema'),
            'voz' => cvs_texto($_POST, 'voz'),
        ];
        if (mb_strlen($entrada['nombre']) < 2 || mb_strlen($entrada['nombre']) > 40) throw new DomainException('El nombre requiere entre 2 y 40 caracteres.');
        if (mb_strlen($entrada['saludo']) < 5 || mb_strlen($entrada['saludo']) > 400) throw new DomainException('El saludo requiere entre 5 y 400 caracteres.');
        if (mb_strlen($entrada['tono']) > 1500) throw new DomainException('La personalidad no debe exceder 1500 caracteres.');
        if (!in_array($entrada['tema'], LUMI_TEMAS, true)) throw new DomainException('Tema visual no disponible.');
        if (mb_strlen($entrada['voz']) > 120) throw new DomainException('Identificador de voz demasiado largo.');
        // La configuración vive solo en esta tabla; jamás toca pacientes, pagos ni citas.
        $guardar = $pdo->prepare('INSERT INTO lumi_config (id, nombre, saludo, tono, tema, voz, actualizado_por)
            VALUES (1, :nombre, :saludo, :tono, :tema, :voz, :responsable)
            ON CONFLICT (id) DO UPDATE SET nombre = EXCLUDED.nombre, saludo = EXCLUDED.saludo, tono = EXCLUDED.tono,
                tema = EXCLUDED.tema, voz = EXCLUDED.voz, actualizado_por = EXCLUDED.actualizado_por,
                actualizado_en = clock_timestamp()');
        $guardar->execute($entrada + [':responsable' => (int)$usuario['id_usuario']]);
        $config = lumi_config($pdo);
        $ok = 'Preferencias guardadas. El nombre y el saludo se aplican en una nueva conversación de Lumi; tema y voz quedan guardados para etapas posteriores.';
    } catch (DomainException $ex) {
        $error = $ex->getMessage();
    } catch (PDOException $ex) { cvs_error_base_datos($ex); }
}
?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Asistente LUMI | Clínica Vida Salud</title><link rel="stylesheet" href="assets/estilos.css"></head><body>
<?php cvsu_area('lumi', 'Asistente LUMI'); ?><main class="ui-contenido"><section class="panel"><h1>Asistente LUMI</h1>
<p>Personaliza la identidad y el comportamiento de Lumi, la asistente inteligente de Clínica Vida Salud. Configura su nombre, mensaje de bienvenida y estilo de comunicación.</p>
<?php if ($error !== null): ?><p class="error" role="alert"><?= cvs_escapar($error) ?></p><?php endif; ?>
<?php if ($ok !== null): ?><p class="mensaje" role="status"><?= cvs_escapar($ok) ?></p><?php endif; ?>
<?php if (!$instalada): ?>
<p class="aviso">La tabla de preferencias no existe todavía en esta base. Un administrador debe aplicar manualmente <code>database/migraciones/20261107_lumi_config.sql</code>. Mientras tanto, el chat usa la personalidad de respaldo y este formulario está deshabilitado.</p>
<?php endif; ?>
<form method="post" action="lumi.php" class="cvs-formulario">
    <input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>">
    <div class="campo"><label for="nombre">Nombre visible de la asistente</label><input id="nombre" name="nombre" maxlength="40" required value="<?= cvs_escapar($config['nombre']) ?>" <?= $instalada ? '' : 'disabled' ?>></div>
    <div class="campo"><label for="saludo">Mensaje de bienvenida</label><textarea id="saludo" name="saludo" rows="3" required <?= $instalada ? '' : 'disabled' ?>><?= cvs_escapar($config['saludo']) ?></textarea></div>
    <div class="campo"><label for="tono">Personalidad e instrucciones adicionales</label><textarea id="tono" name="tono" rows="4" <?= $instalada ? '' : 'disabled' ?>><?= cvs_escapar($config['tono']) ?></textarea><p class="pequeno">Hasta 1500 caracteres; se envía al proveedor como parte de las instrucciones del sistema.</p></div>
    <div class="campo"><label for="tema">Tema visual</label><select id="tema" name="tema" <?= $instalada ? '' : 'disabled' ?>><?php foreach (['clinica' => 'Clínica (azul petróleo), por defecto', 'oscuro' => 'Oscuro (guardado; sin cambio visual todavía)'] as $clave => $etiqueta): ?><option value="<?= $clave ?>" <?= $clave === $config['tema'] ? 'selected' : '' ?>><?= $etiqueta ?></option><?php endforeach; ?></select><p class="pequeno">La etapa actual muestra siempre el tema Clínica; la selección se guarda para una etapa posterior.</p></div>
    <div class="campo"><label for="voz">Preferencia de voz (reservado)</label><select id="voz" name="voz" <?= $instalada ? '' : 'disabled' ?>><option value="" <?= $config['voz'] === '' ? 'selected' : '' ?>>Sin preferencia configurada</option><?php foreach (['femenina-es-boliviana' => 'Femenina · español (reserva para la etapa 2)', 'femenina-es-es' => 'Femenina · español España'] as $clave => $etiqueta): ?><option value="<?= $clave ?>" <?= $clave === $config['voz'] ? 'selected' : '' ?>><?= $etiqueta ?></option><?php endforeach; ?></select><p class="pequeno">Se guarda para la etapa de voz; todavía no altera la interfaz.</p></div>
    <button type="submit" <?= $instalada ? '' : 'disabled' ?>>Guardar preferencias</button>
</form>
<p class="pequeno">Notas: Lumi no consulta expedientes ni ofrece diagnósticos o tratamientos; para las pruebas usa únicamente mensajes ficticios sin información personal. La conexión con el proveedor de IA se activa únicamente desde el servidor (variables de entorno del servicio; nunca introduzcas claves en este formulario).</p>
</section></main>
<?php cvsu_cierre(); ?>
</body></html>
