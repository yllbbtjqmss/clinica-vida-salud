<?php
/**
 * Helpers de presentación: marca, menú lateral por rol y barra superior.
 * Solo maqueta la interfaz; toda la lógica de seguridad vive en
 * includes/auth.php, includes/sesion.php e includes/conexion.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/lumi.php';

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
        'mi_agenda' => 'agenda',
        'mis_citas' => 'citas',
        'usuarios' => 'pacientes',
        'reservar_cita' => 'citas',
        'pagos' => 'reportes',
        'tarifas' => 'especialidades',
        'reportes_financieros' => 'reportes',
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
        'reportes' => '<path d="M4 20.2h16"/><path d="M7.2 20.2v-6.1"/><path d="M12 20.2V6.8"/><path d="M16.8 20.2v-9.4"/>',
        'salir' => '<path d="M9.5 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3.5"/><path d="M14.8 16.2 19.2 12l-4.4-4.2M19.2 12H9.5"/>',
        'entrar' => '<path d="M13.5 20H17a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3.5"/><path d="M9.2 16.2 4.8 12l4.4-4.2M4.8 12h9.7"/>',
        'corazon' => '<path d="M12 20.8s-7.4-4.6-9-8.9c-1-2.6.5-5.4 3.1-6.1 2-.5 4.2.5 5.9 2.7 1.7-2.2 3.9-3.2 5.9-2.7 2.6.7 4.1 3.5 3.1 6.1-1.6 4.3-9 8.9-9 8.9z"/>',
        'lumi' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
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
            array('Usuarios', 'usuarios.php'),
            array('Pacientes', 'pacientes.php'),
            array('Médicos', 'medicos.php'),
            array('Especialidades', 'especialidades.php'),
            array('Horarios médicos', 'horarios.php'),
            array('Asistente LUMI', 'lumi.php'),
            array('Registrar citas', 'citas.php'),
            array('Tarifas de consultas', 'tarifas.php'),
            array('Pagos y cobros', 'pagos.php'),
            array('Reportes económicos', 'reportes_financieros.php'),
            array('Reprogramar citas', 'seleccionar_reprogramacion.php'),
            array('Cancelar citas', 'cancelar_cita.php'),
            array('Control de asistencia', 'asistencia_citas.php'),
            array('Historial de citas', 'historial_citas.php'),
            array('Agenda médica', 'agenda_medica.php'),
            array('Reportes', 'reportes.php'),
        ));
    }
    if ($rol === 'recepcionista') {
        return array_merge($inicio, array(
            array('Consultar tarifas', 'tarifas.php'),
            array('Pagos y cobros', 'pagos.php'),
            array('Pacientes', 'pacientes.php'),
            array('Registrar citas', 'citas.php'),
            array('Reprogramar citas', 'seleccionar_reprogramacion.php'),
            array('Cancelar citas', 'cancelar_cita.php'),
            array('Control de asistencia', 'asistencia_citas.php'),
            array('Historial de citas', 'historial_citas.php'),
            array('Agenda médica', 'agenda_medica.php'),
        ));
    }

    if ($rol === 'medico') return array_merge($inicio, [['Mi agenda', 'mi_agenda.php']]);
    if ($rol === 'paciente') return array_merge($inicio, [
        ['Reservar cita', 'reservar_cita.php'], ['Mis citas y perfil', 'mis_citas.php'], ['Mis reservas y pagos', 'pagos.php']
    ]);
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

/** Módulo del asistente virtual: solo para sesiones autenticadas. */
function cvsu_lumi(): void
{
    static $renderizada = false;
    if ($renderizada) return;
    if (!function_exists('cvs_usuario_actual')) return;
    $usuario = cvs_usuario_actual();
    if ($usuario === null) return;
    $renderizada = true;
    $tema = LUMI_TEMAS[0];
    echo '<link rel="stylesheet" href="assets/lumi.css?v=', (int)filemtime(dirname(__DIR__) . '/assets/lumi.css'), '">', "\n";
    echo '<div id="lumi-raiz" data-lumi-tema="', cvsu_texto($tema), '" data-lumi-estado="silencio" data-lumi-abierto="false">', "\n";
    echo '<button id="lumi-botón" type="button" aria-expanded="false" aria-haspopup="dialog" aria-controls="lumi-panel" title="Abrir la asistente inteligente">';
    echo '<span class="lumi-orbe" aria-hidden="true"></span>';
    echo '<span class="lumi-despertar" aria-hidden="true"></span>';
    echo '<span class="cvs-solo-lectura">Abrir la asistente inteligente</span>', '</button>', "\n";
    echo '<button id="lumi-voz-reactivar" type="button" class="lumi-reactivar" aria-label="Reactivar llamada por voz" title="Reactivar llamada por voz" hidden>';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M6 10v2a6 6 0 0 0 12 0v-2M12 18v3M9 21h6"/></svg></button>';
    echo '<section id="lumi-panel" role="dialog" aria-modal="false" aria-labelledby="lumi-titulo" aria-hidden="true" inert>', "\n";
    echo '<header class="lumi-cabecera">';
    echo '<span class="lumi-orbe" aria-hidden="true"></span>';
    echo '<div><h2 id="lumi-titulo">Asistente Lumi</h2><p>Inteligencia artificial de Clínica Vida Salud</p></div>';
    echo '<button id="lumi-cerrar" type="button" aria-label="Cerrar el asistente">';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>', '</button>', '</header>', "\n";
    echo '<div id="lumi-dialogo">';
    echo '<div class="lumi-chat-acciones"><button id="lumi-nueva" type="button" disabled>Nueva conversación</button></div>';
    echo '<ul id="lumi-mensajes" role="log" aria-live="polite" aria-label="Conversación con la asistente"></ul>';
    echo '<p id="lumi-aviso" role="status" hidden></p>';
    echo '<div id="lumi-voz-consentimiento" class="lumi-consentimiento" role="group" aria-labelledby="lumi-voz-invitacion" hidden>';
    echo '<strong id="lumi-voz-invitacion">¿Quieres llamarme con la voz?</strong>';
    echo '<p id="lumi-voz-consentimiento-texto">Con tu permiso, escucharé «Hola, Lumi» u «Oye, Lumi» con esta pestaña visible y el chat cerrado. El navegador puede procesar el audio externamente. No guardamos grabaciones. Puedes desactivarlo en Voz y privacidad.</p>';
    echo '<div class="lumi-voz-acciones"><button id="lumi-voz-autorizar" type="button" aria-describedby="lumi-voz-consentimiento-texto" disabled>Permitir micrófono</button><button id="lumi-voz-posponer" type="button">Ahora no</button></div></div>';
    echo '<p id="lumi-voz-estado" class="lumi-voz-estado" role="status" aria-live="polite" hidden></p>';
    echo '<button id="lumi-voz-detener" class="lumi-detener-audio" type="button" disabled hidden>Detener audio</button>';
    echo '<details class="lumi-voz-nota"><summary>Voz y privacidad</summary>';
    echo '<p id="lumi-voz-aviso">El micrófono solo se activa al autorizar la llamada por voz o al pulsar el micrófono para dictar. El permiso de dictado no habilita por sí solo la llamada por voz. No guardamos grabaciones. El reconocimiento y algunas voces del navegador pueden usar servicios externos.</p>';
    echo '<p>Con tu autorización, «Hola, Lumi» u «Oye, Lumi» abren la conversación sin enviar mensajes. La preferencia se conserva en esta pestaña durante tu sesión al navegar. La escucha se pausa al ocultar la página, abrir el panel o reproducir audio. Chrome puede detenerla o pedir otra interacción; no funciona permanentemente en segundo plano.</p>';
    echo '<p id="lumi-voz-preferencia">Llamada por voz sin autorizar.</p>';
    echo '<div class="lumi-voz-acciones"><button id="lumi-voz-permitir" type="button" aria-describedby="lumi-voz-aviso" disabled>Permitir llamada por voz</button><button id="lumi-voz-reintentar" type="button" hidden>Reactivar micrófono</button><button id="lumi-voz-desactivar" type="button" hidden>Desactivar llamada por voz</button></div>';
    echo '<div class="lumi-voz-controles"><label for="lumi-voz-seleccion">Voz <select id="lumi-voz-seleccion" disabled><option>Voz automática en español</option></select></label></div>';
    echo '<p id="lumi-privacidad-texto"></p>';
    echo '<p>Lumi orienta tu paso por el sistema. No brinda diagnósticos ni recetas, y su orientación no reemplaza la atención médica profesional.</p>';
    echo '</details>';
    echo '<form id="lumi-envio" action="lumi_api.php?accion=mensaje" method="post">';
    echo '<button id="lumi-microfono" type="button" aria-label="Dictar consulta en español" title="Dictar consulta en español" aria-pressed="false" aria-describedby="lumi-voz-estado lumi-voz-aviso" disabled>';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M6 10v2a6 6 0 0 0 12 0v-2M12 18v3M9 21h6"/></svg></button>';
    echo '<textarea id="lumi-entrada" name="mensaje" maxlength="700" rows="1" placeholder="Activa JavaScript para conversar con Lumi" aria-label="Escribe tu consulta para la asistente" autocomplete="off" disabled></textarea>';
    echo '<button type="submit" aria-label="Enviar consulta" title="Enviar consulta" disabled>';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12L19.5 5l-4.2 14-3.8-5.6z"/><path d="M11.5 13.4L19.5 5"/></svg>', '</button>';
    echo '</form>', "\n";
    echo '</div>', '</section>', '</div>', "\n";
    echo '<script src="assets/lumi-voz.js?v=', (int)filemtime(dirname(__DIR__) . '/assets/lumi-voz.js'), '" defer></script>', "\n";
    echo '<script src="assets/lumi.js?v=', (int)filemtime(dirname(__DIR__) . '/assets/lumi.js'), '" defer></script>', "\n";
}

/** Cierra el contenedor principal abierto por cvsu_area(). */
function cvsu_cierre(): void
{
    cvsu_lumi();
    echo '</div>', "\n";
}
