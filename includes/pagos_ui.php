<?php
declare(strict_types=1);
require_once __DIR__ . '/reservas_pagos.php';
require_once __DIR__ . '/ui.php';

// Una consulta auxiliar inesperada tampoco debe mostrar SQL, parámetros ni trazas.
set_exception_handler(static function (Throwable $ex): void {
    cvp_error($ex, 'error_no_controlado');
    if (!headers_sent()) http_response_code(500);
    echo '<p class="mensaje error" role="alert">No fue posible completar la solicitud. Vuelve a intentarlo más tarde.</p>';
});

function cvp_inicio(string $activo, string $titulo): void {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>',
        cvs_escapar($titulo), ' | Clínica Vida Salud</title><link rel="stylesheet" href="assets/estilos.css"></head><body>';
    cvsu_area($activo, $titulo);
    echo '<main class="ui-contenido cvp-area">';
}

function cvp_fin(): void { echo '</main>'; cvsu_cierre(); echo '</body></html>'; }

function cvp_requerir_modulo(PDO $pdo): void {
    if (!cvp_disponible($pdo)) {
        http_response_code(503); cvp_inicio('pagos', 'Módulo pendiente de instalación');
        echo '<section class="panel"><h1>Módulo pendiente de instalación</h1><p>Un administrador debe revisar y aplicar manualmente la migración de reservas y pagos en esta base. Las citas anteriores se conservan.</p></section>';
        cvp_fin(); exit;
    }
}

function cvp_formulario(string $ambito): string {
    $clave = bin2hex(random_bytes(32));
    $_SESSION['cvp_formularios'][$clave] = $ambito;
    while (count($_SESSION['cvp_formularios']) > 40) array_shift($_SESSION['cvp_formularios']);
    return $clave;
}

function cvp_validar_post(string $ambito): string {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cvs_csrf_valido($_POST['csrf'] ?? null)) {
        http_response_code(403); throw new DomainException('Solicitud no válida. Recarga el formulario.');
    }
    $clave = cvp_clave($_POST['operacion'] ?? null);
    if (($_SESSION['cvp_formularios'][$clave] ?? null) !== $ambito) {
        http_response_code(403); throw new DomainException('El formulario venció o no pertenece a esta operación. Recarga la página.');
    }
    return $clave;
}

function cvp_ocultos(string $clave): void {
    echo '<input type="hidden" name="csrf" value="', cvs_escapar(cvs_csrf_token()), '">',
        '<input type="hidden" name="operacion" value="', cvs_escapar($clave), '">';
}

function cvp_estado(string $estado): string { return ucfirst(str_replace('_', ' ', $estado)); }

/** Imagen compartida del QR; nunca genera un código ni modifica el pago. */
function cvp_qr_visual(bool $autocontenido = false): void {
    $ruta = 'assets/img/qr-pago-demo.png';
    $archivo = dirname(__DIR__) . '/' . $ruta;
    $imagen = is_readable($archivo) ? @getimagesize($archivo) : false;
    echo '<div class="cvp-qr" data-qr-fijo>';
    if ($imagen && $imagen['mime'] === 'image/png') {
        $src = $autocontenido ? 'data:image/png;base64,' . base64_encode(file_get_contents($archivo)) : $ruta;
        echo '<img src="', cvs_escapar($src), '" alt="Código QR para el pago a Clínica Vida Salud" width="',
            (int)$imagen[0], '" height="', (int)$imagen[1], '">';
    } else {
        // Sin imagen de referencia no se inventa otro QR ni se muestra un enlace roto.
        echo '<p class="cvp-qr-no-disponible" role="status">El código QR no está disponible. Consulta con recepción.</p>';
    }
    echo '</div>';
}

function cvp_resumen(array $p): void {
    echo '<dl class="detalle cvp-detalle">';
    foreach (['paciente'=>'Paciente','especialidad'=>'Especialidad','medico'=>'Médico','fecha'=>'Fecha',
        'hora_inicio'=>'Inicio','hora_fin'=>'Finalización','duracion'=>'Duración (minutos)'] as $campo=>$etiqueta) {
        if (isset($p[$campo])) echo '<div><dt>', $etiqueta, '</dt><dd>', cvs_escapar($campo === 'fecha' ? cvp_fecha_visible($p[$campo],false) : (in_array($campo,['hora_inicio','hora_fin'],true) ? substr($p[$campo],0,5) : $p[$campo])), '</dd></div>';
    }
    echo '<div><dt>Importe acordado</dt><dd class="cvp-precio">', cvs_escapar(cvp_bob($p['importe'])), '</dd></div></dl>';
}

function cvp_mensaje(?string $error): void {
    if ($error) echo '<div class="mensaje error" role="alert">', cvs_escapar($error), '</div>';
    $mensaje = $_SESSION['cvp_aviso'] ?? null; unset($_SESSION['cvp_aviso']);
    if (is_string($mensaje)) echo '<div class="mensaje" role="status">', cvs_escapar($mensaje), '</div>';
}

function cvp_redirigir_pago(int $id, string $mensaje): never {
    $_SESSION['cvp_aviso'] = $mensaje;
    header('Location: pago.php?id=' . $id, true, 303); exit;
}

function cvp_solo_metodos(array $permitidos): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $permitidos, true)) {
        http_response_code(405); header('Allow: ' . implode(', ', $permitidos)); exit('Método no permitido.');
    }
}
