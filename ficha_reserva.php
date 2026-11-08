<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$usuario=cvs_exigir_roles(['administrador','recepcionista','paciente']);
require_once __DIR__ . '/includes/pagos_ui.php';
cvp_solo_metodos(['GET']); $pdo=cvs_conexion(); cvp_requerir_modulo($pdo);
$id=cvp_id($_GET['id'] ?? null); $tipo=cvs_texto($_GET,'tipo') ?: 'ficha';
try {
    if (!in_array($tipo,['ficha','comprobante'],true)) throw new DomainException('Documento no disponible.');
    cvp_expirar($pdo); $p=cvp_pago($pdo,$id,(int)$usuario['id_usuario']);
    if ($tipo==='comprobante' && !$p['confirmado_en']) throw new DomainException('No hay un pago confirmado que permita emitir comprobante.');
} catch (Throwable $ex) {
    http_response_code(404); cvp_inicio('pagos','Documento no disponible'); cvp_mensaje($ex instanceof DomainException ? $ex->getMessage() : cvp_error($ex,'ficha')); cvp_fin(); exit;
}
$descargar=($_GET['descargar'] ?? null)==='1';
if ($descargar) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="'.$tipo.'-'.$id.'.html"');
    echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clínica Vida Salud · Documento administrativo</title><style>',
        file_get_contents(__DIR__.'/assets/estilos.css'), '</style></head><body><main class="ui-contenido cvp-area">';
} else {
    cvp_inicio('pagos',$tipo==='ficha' ? 'Ficha de reserva' : 'Comprobante administrativo');
}
?>
<article class="panel cvp-ficha"><header><p class="cvp-marca">CLÍNICA VIDA SALUD</p><h1><?= $tipo==='ficha' ? 'Ficha de reserva' : 'Comprobante administrativo de pago' ?></h1><p class="cvp-codigo"><?= cvs_escapar($p['codigo_cita'] ?: $p['codigo_reserva']) ?></p></header>
<?php if ($p['modalidad']==='demo' && ($p['metodo_online'] ?? null) !== 'qr'): ?><p class="pequeno" role="note"><?= cvs_escapar(CVP_DEMO_AVISO) ?></p><?php endif; ?>
<?php cvp_resumen($p); ?>
<dl class="detalle cvp-detalle"><div><dt>Estado de cita</dt><dd><?= cvs_escapar($p['estado_cita'] ? cvp_estado($p['estado_cita']) : 'Sin cita confirmada · reserva '.cvp_estado($p['estado_reserva'])) ?></dd></div><div><dt>Estado del pago</dt><dd><?= cvs_escapar(cvp_estado($p['estado'])) ?></dd></div><div><dt>Modalidad / método</dt><dd><?= cvs_escapar(cvp_metodo($p)) ?></dd></div><div><dt>Referencia de operación</dt><dd><?= cvs_escapar($p['referencia']) ?></dd></div><?php if ($p['confirmado_en']): ?><div><dt>Referencia del cobro</dt><dd><?= cvs_escapar($p['referencia_cobro']) ?></dd></div><div><dt>Fecha y hora del cobro</dt><dd><?= cvs_escapar(cvp_fecha_visible($p['confirmado_en'])) ?></dd></div><div><dt>Responsable del registro</dt><dd><?= cvs_escapar($p['responsable_nombre']) ?></dd></div><?php endif; ?></dl>
<?php if ($tipo==='ficha' && ($p['metodo_online'] ?? null) === 'qr' && $p['estado_reserva']==='activa'): ?>
<section class="cvp-qr-panel cvp-qr-ficha" aria-labelledby="cvp-qr-titulo"><h2 id="cvp-qr-titulo">Pago mediante QR</h2>
<?php cvp_qr_visual($descargar); ?>
<p>Importe a pagar: <strong><?= cvs_escapar(cvp_bob($p['importe'])) ?></strong></p>
<p class="cvp-codigo">Referencia: <?= cvs_escapar($p['referencia']) ?></p>
<p class="pequeno">Consulta el estado actualizado y el plazo de tu reserva en tu cuenta.</p></section>
<?php endif; ?>
<?php if ($p['modalidad']==='presencial' && $p['estado']==='pendiente'): ?><p><strong>El pago se realizará en recepción.</strong></p><?php endif; ?>
<p class="pequeno">Emitido el: <span id="cvp-emision"><?= cvs_escapar(cvp_fecha_visible(date('c',(int)$p['servidor_epoch']))) ?></span>. Los cambios o cancelaciones no eliminan el historial financiero.</p>
<?php if ($p['modalidad']==='demo' && ($p['metodo_online'] ?? null) === 'qr'): ?><p class="pequeno cvp-nota-academica" role="note">Presentación académica · Confirmación sujeta a verificación administrativa.</p><?php endif; ?>
<script>(function(){var el=document.getElementById('cvp-emision');if(!el)return;function emision(){try{el.textContent=new Date().toLocaleString('es-BO',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false,timeZone:'America/La_Paz'});}catch(e){}}document.addEventListener('beforeprint',emision);if(window.matchMedia){var m=window.matchMedia('print');if(m&&m.addEventListener)m.addEventListener('change',function(v){if(v.matches)emision();});}})();</script>
<div class="acciones cvp-no-imprimir"><button type="button" data-imprimir>Imprimir / guardar como PDF</button><?php if (!$descargar): ?><a class="boton secundario" href="pago.php?id=<?= $id ?>">Volver</a><?php endif; ?></div></article>
<?php if ($descargar): ?>
<script>document.querySelector('[data-imprimir]').addEventListener('click',function(){window.print();});</script>
</main></body></html>
<?php else: ?>
<script src="assets/reservas.js" defer></script>
<?php cvp_fin(); endif; ?>
