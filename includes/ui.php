<?php
/**
 * Helpers de presentación: marca, menú lateral por rol y barra superior.
 * Solo maqueta la interfaz; toda la lógica de seguridad vive en
 * includes/auth.php, includes/sesion.php e includes/conexion.php.
 */

declare(strict_types=1);

function cvsu_texto(string $valor): string
{
    if (function_exists('cvs_escapar')) {
        return cvs_escapar($valor);
    }
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function cvsu_icono(string $clave): string
{
    // Las dos pantallas de RF08 comparten el icono de calendario con flechas.
    $alias = array(
        'seleccionar_reprogramacion' => 'reprogramar',
        'reprogramar_cita' => 'reprogramar',
    );
    $clave = $alias[$clave] ?? $clave;

    $trazados = array(
        'inicio' => '<path d="M3.5 11 12 4l8.5 7M5.8 10v9.5h12.4V10"/>',
        'pacientes' => '<circle cx="9" cy="7.5" r="3.2"/><path d="M3 19.5c0-3.2 2.7-5.3 6-5.3s6 2.1 6 5.3"/><path d="M16 4.7A3 3 0 0 1 17 10.7M17.5 14.6c2 .8 3.5 2.3 3.5 4.9"/>',
        'medicos' => '<circle cx="10" cy="7.5" r="3.2"/><path d="M3.5 19.5c0-3.2 2.8-5.2 6.5-5.2 2.2 0 4.1.7 5.2 1.9"/><path d="M18.3 9.5v2.8a1.9 1.9 0 0 1-3.8 0v-.6M15.1 5.6h1.6M18.2 5.6h.2"/>',
        'especialidades' => '<rect x="4.5" y="3.5" width="15" height="17" rx="2.5"/><path d="M8.5 8h7M8.5 11.5h7M8.5 15h4.5"/>',
        'horarios' => '<circle cx="12" cy="12" r="8.3"/><path d="M12 7.3V12l3 2"/>',
        'citas' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8.5 2.8v4M15.5 2.8v4"/>',
        'reprogramar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8.5 2.8v4M15.5 2.8v4"/><path d="M7.5 14.2h5.4"/><path d="M11 12.4l2 1.8-2 1.8"/><path d="M14.9 16.8H18"/>',
        'cancelar' => '<circle cx="12" cy="12" r="8.3"/><path d="M9.2 9.2l5.6 5.6M14.8 9.2l-5.6 5.6"/>',
        'asistencia' => '<circle cx="12" cy="12" r="8.3"/><path d="M8.5 12.2l2.3 2.3 4.7-4.9"/>',
        'historial' => '<path d="M20 12a8 8 0 1 1-2.7-6"/><path d="M20 4.5V9h-4.5M12 8v4.3l2.7 1.6"/>',
        'agenda' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8.5 2.8v4M15.5 2.8v4"/><path d="M7.5 13h2M11 13h2M14.5 13h2M7.5 16.5h2M11 16.5h2"/>',
        'salir' => '<path d="M9.5 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3.5"/><path d="M14.8 16.2 19.2 12l-4.4-4.2M19.2 12H9.5"/>',
        'entrar' => '<path d="M13.5 20H17a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3.5"/><path d="M9.2 16.2 4.8 12l4.4-4.2M4.8 12h9.7"/>',
        'corazon' => '<path d="M12 20.8s-7.4-4.6-9-8.9c-1-2.6.5-5.4 3.1-6.1 2-.5 4.2.5 5.9 2.7 1.7-2.2 3.9-3.2 5.9-2.7 2.6.7 4.1 3.5 3.1 6.1-1.6 4.3-9 8.9-9 8.9z"/>',
    );
    $d = isset($trazados[$clave]) ? $trazados[$clave] : $trazados['inicio'];

    return '<svg class="cvs-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $d
        . '</svg>';
}

function cvsu_icono_archivo(string $url): string
{
    $base = basename(parse_url($url, PHP_URL_PATH) ?: '');
    $base = preg_replace('/\.php$/', '', $base);

    return cvsu_icono($base === '' ? 'inicio' : $base);
}

/**
 * Páginas visibles en el menú según el rol autenticado.
 * Devuelve una lista de [etiqueta, url].
 */
function cvsu_paginas(?array $usuario): array
{
    $rol = $usuario['rol'] ?? null;
    $inicio = array(array('Inicio', 'index.php'));

    if ($rol === null) {
        return array_merge($inicio, array(array('Iniciar sesión', 'login.php')));
    }
    if ($rol === 'administrador') {
        return array_merge($inicio, array(
            array('Pacientes', 'pacientes.php'),
            array('Médicos', 'medicos.php'),
            array('Especialidades', 'especialidades.php'),
            array('Horarios médicos', 'horarios.php'),
            array('Registrar citas', 'citas.php'),
            array('Reprogramar citas', 'seleccionar_reprogramacion.php'),
            array('Cancelar citas', 'cancelar_cita.php'),
            array('Control de asistencia', 'asistencia_citas.php'),
            array('Historial de citas', 'historial_citas.php'),
            array('Agenda médica', 'agenda_medica.php'),
        ));
    }
    if ($rol === 'recepcionista') {
        return array_merge($inicio, array(
            array('Pacientes', 'pacientes.php'),
            array('Registrar citas', 'citas.php'),
            array('Reprogramar citas', 'seleccionar_reprogramacion.php'),
            array('Cancelar citas', 'cancelar_cita.php'),
            array('Control de asistencia', 'asistencia_citas.php'),
            array('Historial de citas', 'historial_citas.php'),
            array('Agenda médica', 'agenda_medica.php'),
        ));
    }

    return $inicio;
}

function cvsu_nombre_archivo(array $pagina): string
{
    $ruta = parse_url($pagina[1], PHP_URL_PATH) ?: '';
    $base = basename($ruta);
    $base = preg_replace('/\.php$/', '', $base);

    return $base === '' ? 'index' : $base;
}

/**
 * Imprime la estructura visual fija: casilla del menú móvil, velo,
 * barra lateral con la marca y barra superior con el usuario actual.
 */
function cvsu_area(string $activo, string $titulo): void
{
    $usuario = function_exists('cvs_usuario_actual') ? cvs_usuario_actual() : null;
    $paginas = cvsu_paginas($usuario);
    $nombresRol = array(
        'administrador' => 'Administrador',
        'recepcionista' => 'Recepcionista',
        'medico' => 'Médico',
        'paciente' => 'Paciente',
    );

    echo '<input type="checkbox" id="cvs-menu" class="cvs-menu">', "\n";
    echo '<label class="cvs-velo" for="cvs-menu" aria-hidden="true"></label>', "\n";

    echo '<div class="ui-app">', "\n";

    echo '<aside class="cvs-marco">', "\n";
    echo '<div class="cvs-marca">';
    echo '<span class="cvs-logo"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">',
        '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>',
        '</svg></span>';
    echo '<span class="cvs-marca-texto">';
    echo '<span class="cvs-nombre">Clínica Vida Salud</span>';
    echo '<span class="cvs-lema">Cuidamos tu vida, siempre</span>';
    echo '</span>', '</div>', "\n";

    echo '<nav class="cvs-nav" aria-label="Menú principal">', "\n";
    foreach ($paginas as $pagina) {
        $clave = cvsu_nombre_archivo($pagina);
        $clase = $clave === $activo ? ' class="cvs-activo"' : '';
        echo '<a href="', cvsu_texto($pagina[1]), '"', $clase, '>';
        echo cvsu_icono($clave);
        echo '<span>', cvsu_texto($pagina[0]), '</span>';
        echo '</a>', "\n";
    }
    echo '</nav>', "\n";

    echo '<div class="cvs-promo">', "\n";
    echo '<h2>Una comunidad más saludable</h2>', "\n";
    echo '<p>Atención humana, tecnología que cuida.</p>', "\n";
    echo '</div>', "\n";
    echo '</aside>', "\n";

    echo '<header class="cvs-topbar">', "\n";
    echo '<label class="cvs-hamburguesa" for="cvs-menu" aria-label="Abrir menú">';
    echo '<span></span><span></span><span></span>', '</label>', "\n";
    echo '<p class="cvs-titulo">', cvsu_texto($titulo), '</p>', "\n";

    echo '<div class="cvs-top-derecha">', "\n";
    if ($usuario !== null) {
        $nombre = trim($usuario['nombre_usuario'] ?? '');
        $inicial = mb_strtoupper(mb_substr($nombre === '' ? 'U' : $nombre, 0, 1));
        $rolNombre = $nombresRol[$usuario['rol']] ?? (string) ($usuario['rol'] ?? '');
        echo '<span class="cvs-usuario">';
        echo '<span class="cvs-avatar" aria-hidden="true">', cvsu_texto($inicial), '</span>';
        echo '<span class="cvs-datos"><strong>', cvsu_texto($nombre), '</strong>';
        echo '<span>', cvsu_texto($rolNombre), '</span></span>';
        echo '</span>', "\n";
        if (function_exists('cvs_csrf_token')) {
            echo '<form method="post" action="logout.php" style="display:inline">',
                '<input type="hidden" name="csrf" value="', cvsu_texto(cvs_csrf_token()), '">',
                '<button type="submit" class="cvs-salir" title="Cerrar sesión" aria-label="Cerrar sesión">',
                cvsu_icono('salir'),
                '</button></form>', "\n";
        }
    } else {
        echo '<a class="cvs-usuario" href="login.php">';
        echo '<span class="cvs-avatar" aria-hidden="true">', cvsu_icono('entrar'), '</span>';
        echo '<span class="cvs-datos"><strong>Iniciar sesión</strong></span>';
        echo '</a>', "\n";
    }
    echo '</div>', "\n";
    echo '</header>', "\n";
}

/** Cierra el contenedor principal abierto por cvsu_area(). */
function cvsu_cierre(): void
{
    echo '</div>', "\n";
}
