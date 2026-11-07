<?php
declare(strict_types=1);
/** Catálogo estático revisado contra cvsu_paginas y las autorizaciones de cada pantalla.
 * No consulta registros, no ejecuta acciones y no admite destinos generados por IA.
 */
function lumi_catalogo_funciones(): array {
    $gestion = ['administrador', 'recepcionista'];
    $admin = ['administrador'];
    return [
        'inicio' => ['ruta' => 'index.php', 'roles' => ['administrador', 'recepcionista', 'medico', 'paciente'],
            'titulo' => 'Inicio', 'guia' => 'En Inicio encuentras los accesos y el panel que corresponde a tu cuenta.'],
        'reservar' => ['ruta' => 'reservar_cita.php', 'roles' => ['paciente'], 'titulo' => 'Reservar cita',
            'guia' => 'Para reservar, elige especialidad y médico, después fecha y un bloque disponible. Pulsa Revisar resumen, comprueba el importe y elige la modalidad de pago antes de confirmar. Necesitas una ficha activa vinculada. La página vuelve a validar la disponibilidad al confirmar.'],
        'mis_citas' => ['ruta' => 'mis_citas.php', 'roles' => ['paciente'], 'titulo' => 'Mis citas y perfil',
            'guia' => 'En Mis citas y perfil puedes revisar tus datos básicos y filtrar citas próximas, anteriores o todas. Para corregir tu ficha, cancelar o reprogramar, comunícate con recepción.'],
        'mi_agenda' => ['ruta' => 'mi_agenda.php', 'roles' => ['medico'], 'titulo' => 'Mi agenda',
            'guia' => 'En Mi agenda selecciona una fecha y pulsa Consultar para ver tus citas de ese día. Debajo aparecen tus horarios de atención configurados por administración. Necesitas un registro médico activo vinculado.'],
        'pagos' => ['ruta' => 'pagos.php', 'roles' => ['administrador', 'recepcionista', 'paciente'], 'titulo' => 'Pagos y cobros',
            'guia' => 'En Pagos y cobros puedes filtrar por estado, método y fechas. Abre Ver ficha / pago para revisar una operación y sus documentos. La aprobación de pagos en línea es exclusiva de administración.',
            'variantes' => ['paciente' => ['titulo' => 'Mis reservas y pagos', 'guia' => 'En Mis reservas y pagos encontrarás tus operaciones. Pulsa Ver ficha / pago para consultar referencia, importe, estado y plazo, o ver e imprimir la ficha. Un comprobante de pago se ofrece cuando hay un cobro confirmado.']]],
        'pacientes' => ['ruta' => 'pacientes.php', 'roles' => $gestion, 'titulo' => 'Pacientes',
            'guia' => 'En Pacientes se registran y editan fichas; el listado permite seleccionar una ficha y consultar su historial de citas. Realiza cualquier cambio únicamente desde el formulario correspondiente.'],
        'registrar' => ['ruta' => 'citas.php', 'roles' => $gestion, 'titulo' => 'Registrar citas',
            'guia' => 'En Registrar citas selecciona paciente activo, especialidad, médico, fecha y un bloque disponible. Revisa el importe y pulsa Registrar cita desde el formulario. Con el módulo de pagos instalado se requiere tarifa y se genera una cuenta presencial pendiente.'],
        'reprogramar' => ['ruta' => 'seleccionar_reprogramacion.php', 'roles' => $gestion, 'titulo' => 'Reprogramar citas',
            'guia' => 'En Reprogramar citas selecciona una cita vigente del listado. En su formulario elige la nueva fecha y un bloque disponible, revisa los datos y confirma personalmente.'],
        'cancelar' => ['ruta' => 'cancelar_cita.php', 'roles' => $gestion, 'titulo' => 'Cancelar citas',
            'guia' => 'En Cancelar citas selecciona la cita del listado, revisa la confirmación y registra el motivo en el formulario. La cancelación solo se realiza cuando confirmas allí.'],
        'asistencia' => ['ruta' => 'asistencia_citas.php', 'roles' => $gestion, 'titulo' => 'Control de asistencia',
            'guia' => 'En Control de asistencia consulta las citas del listado y abre la confirmación del resultado. Revisa la cita antes de registrar asistencia o inasistencia desde su formulario.'],
        'historial' => ['ruta' => 'historial_citas.php', 'roles' => $gestion, 'titulo' => 'Historial de citas',
            'guia' => 'En Historial de citas selecciona un paciente para consultar sus citas. Es un historial administrativo de citas, no una consulta de expedientes médicos desde Lumi.'],
        'lista_espera' => ['ruta' => 'lista_espera.php', 'roles' => $gestion, 'titulo' => 'Lista de espera',
            'guia' => 'En Lista de espera puedes revisar solicitudes y registrar una solicitud seleccionando paciente, especialidad, médico, fecha y un bloque ocupado de la agenda. Las sugerencias priorizan solicitudes antiguas para turnos futuros liberados. Realiza cualquier registro, retiro o asignación personalmente en sus formularios; asignar un turno requiere confirmación y el módulo de pagos configurado. Lumi no consulta solicitudes ni pacientes, no registra solicitudes ni asigna turnos. Si la pantalla informa que el módulo no está disponible, solicita revisión a administración.'],
        'agenda' => ['ruta' => 'agenda_medica.php', 'roles' => $gestion, 'titulo' => 'Agenda médica',
            'guia' => 'En Agenda médica selecciona médico y fecha para consultar su agenda y las citas del día. La disponibilidad real se consulta en la página, no en este chat.'],
        'tarifas' => ['ruta' => 'tarifas.php', 'roles' => $gestion, 'titulo' => 'Tarifas de consultas',
            'guia' => 'En Tarifas de consultas se muestran los importes por médico y especialidad. Administración puede configurar una tarifa; recepción solo puede consultarla. Un cambio no altera importes ya acordados.'],
        'medicos' => ['ruta' => 'medicos.php', 'roles' => $admin, 'titulo' => 'Médicos',
            'guia' => 'En Médicos puedes consultar el listado y abrir los formularios de registro o edición de profesionales y sus especialidades. Los cambios se confirman en esa pantalla.'],
        'especialidades' => ['ruta' => 'especialidades.php', 'roles' => $admin, 'titulo' => 'Especialidades',
            'guia' => 'En Especialidades se registran, editan y activan o desactivan las especialidades de la clínica mediante sus formularios.'],
        'horarios' => ['ruta' => 'horarios.php', 'roles' => $admin, 'titulo' => 'Horarios médicos',
            'guia' => 'En Horarios médicos se configuran día de la semana, horas de atención y duración de turnos para cada médico. Revisa el formulario y el listado antes de confirmar cambios.'],
        'usuarios' => ['ruta' => 'usuarios.php', 'roles' => $admin, 'titulo' => 'Usuarios',
            'guia' => 'En Usuarios puedes crear o editar cuentas, asignar su rol y vincularlas a una ficha cuando corresponda. El listado permite buscar por usuario o correo. No introduzcas datos de acceso en el chat.'],
        'reportes' => ['ruta' => 'reportes.php', 'roles' => $admin, 'titulo' => 'Reportes',
            'guia' => 'En Reportes administrativos selecciona los filtros del período para consultar actividad, citas, pacientes y agenda. Los indicadores se calculan dentro de esa pantalla.'],
        'economicos' => ['ruta' => 'reportes_financieros.php', 'roles' => $admin, 'titulo' => 'Reportes económicos',
            'guia' => 'En Reportes económicos puedes filtrar fechas, especialidad, médico y estado. Revisa el libro seleccionado y los importes de cobros, pendientes y devoluciones dentro de la página.'],
        'preferencias_lumi' => ['ruta' => 'lumi.php', 'roles' => $admin, 'titulo' => 'Asistente LUMI',
            'guia' => 'En Asistente LUMI puedes ajustar las preferencias disponibles de identidad y presentación. La conexión de IA se configura fuera de ese formulario, en el entorno privado del servidor.'],
    ];
}

function lumi_funciones_permitidas(string $rol): array {
    $permitidas = [];
    foreach (lumi_catalogo_funciones() as $id => $funcion) {
        if (!in_array($rol, $funcion['roles'], true)) continue;
        $permitidas[$id] = array_replace($funcion, $funcion['variantes'][$rol] ?? []);
    }
    return $permitidas;
}

/** Solo ID/título hacia el cliente; los paths permanecen en la lista del servidor. */
function lumi_enlaces_permitidos(string $rol, ?array $ids = null): array {
    $enlaces = [];
    foreach (lumi_funciones_permitidas($rol) as $id => $funcion) {
        if ($ids === null || in_array($id, $ids, true)) $enlaces[] = ['id' => $id, 'titulo' => $funcion['titulo']];
    }
    return $enlaces;
}

function lumi_destino_permitido(mixed $id, string $rol): ?string {
    if (!is_string($id) || preg_match('/\A[a-z_]{1,32}\z/', $id) !== 1) return null;
    $ruta = lumi_funciones_permitidas($rol)[$id]['ruta'] ?? null;
    // Defensa adicional: solo pantallas raíz sin parámetros, esquemas ni acciones/IDs.
    return is_string($ruta) && preg_match('/\A[a-z_]+\.php\z/', $ruta) === 1 ? $ruta : null;
}

/** Conocimiento genérico estático: jamás se incluyen permisos/identidad de una sesión. */
function lumi_conocimiento_publico(): string {
    $texto = "Guía general de pantallas existentes (no confirma permisos de quien conversa):\n";
    foreach (lumi_catalogo_funciones() as $funcion) $texto .= $funcion['titulo'] . ': ' . $funcion['guia'] . "\n";
    return $texto;
}

/** Resolución local conservadora: si no hay tema conocido, continúa la conversación Gemini. */
function lumi_resolver_guia(string $mensaje, string $rol, ?string $contexto = null): ?array {
    $texto = strtr(mb_strtolower($mensaje, 'UTF-8'), ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n']);
    $id = null; $extra = '';
    if (preg_match('/\blistas?(?:\s+de)?\s+espera\b/', $texto)) $id = 'lista_espera';
    elseif (preg_match('/\b(reprogram\w*|(?:cambi\w*|mover)\b.*\b(?:fecha|hora|cita))/', $texto)) {
        $id = $rol === 'paciente' ? 'mis_citas' : 'reprogramar';
        if ($rol === 'paciente') $extra = 'Para solicitar un cambio de fecha u horario, comunícate con recepción. ';
    } elseif (preg_match('/\bcancel\w*\b/', $texto)) {
        $id = $rol === 'paciente' ? 'mis_citas' : 'cancelar';
        if ($rol === 'paciente') $extra = 'Para solicitar una cancelación, comunícate con recepción. ';
    } elseif (preg_match('/\b(?:configur\w*|preferencia\w*|nombre|personalidad)\b.*\b(?:lumi|asistente)\b/', $texto)) $id = 'preferencias_lumi';
    elseif (preg_match('/\b(?:abrir|abre|gestionar|administrar|editar|eliminar)\s+(?:la seccion (?:de )?)?especialidades\b/', $texto)) $id = 'especialidades';
    elseif (preg_match('/\b(report\w*.*(?:econom|financ)|ingresos)\w*/', $texto)) $id = 'economicos';
    elseif (preg_match('/\breport\w*/', $texto)) $id = 'reportes';
    elseif (preg_match('/\b(usuario\w*|cuentas? de acceso)\b/', $texto)) $id = 'usuarios';
    elseif (preg_match('/\b(asistencia|inasistencia|asistio|asistieron)\b/', $texto)) $id = 'asistencia';
    elseif (preg_match('/\b(historial|anteriores|pasadas)\b/', $texto)) $id = $rol === 'paciente' ? 'mis_citas' : ($rol === 'medico' ? 'mi_agenda' : 'historial');
    elseif (preg_match('/\b(pag\w*|cobr\w*|comprobante\w*|qr|recibo\w*|reembolso\w*)\b/', $texto)) $id = 'pagos';
    elseif (preg_match('/\b(tarifa\w*|precio\w*|costo\w*|cuesta|importe\w*)\b/', $texto)) $id = $rol === 'paciente' ? 'reservar' : 'tarifas';
    elseif (preg_match('/\breserv\w*\b|\b(agendar|sacar|solicitar|pedir|programar|registrar|crear)\b.*\b(cita\w*|consulta\w*|turno\w*)\b/', $texto)) $id = $rol === 'paciente' ? 'reservar' : 'registrar';
    elseif (preg_match('/\b(horario\w*)\b/', $texto)) $id = $rol === 'medico' ? 'mi_agenda' : ($rol === 'paciente' ? 'reservar' : ($rol === 'recepcionista' ? 'agenda' : 'horarios'));
    elseif (preg_match('/\b(agenda|disponibilidad)\b/', $texto)) $id = $rol === 'medico' ? 'mi_agenda' : ($rol === 'paciente' ? 'reservar' : 'agenda');
    elseif ($rol === 'paciente' && preg_match('/\bespecialidad\w*\b.*\bmedico\w*\b|\bmedico\w*\b.*\bespecialidad\w*\b/', $texto)
        && !preg_match('/\b(abrir|abre|gestionar|administrar|registrar|crear|editar|eliminar)\b/', $texto)) $id = 'reservar';
    elseif (preg_match('/\bmedico\w*\b/', $texto)) $id = 'medicos';
    elseif (preg_match('/\bespecialidad\w*\b/', $texto)) $id = $rol === 'paciente' ? 'reservar' : 'especialidades';
    elseif (preg_match('/\bpacientes?\b/', $texto)) $id = 'pacientes';
    elseif (preg_match('/\b(perfil|mis citas|mi cita|citas|proxima consulta)\b/', $texto)) $id = $rol === 'paciente' ? 'mis_citas' : ($rol === 'medico' ? 'mi_agenda' : 'registrar');
    elseif (preg_match('/\b(inicio|dashboard|panel principal)\b/', $texto)) $id = 'inicio';
    $menu = preg_match('/\b(menu|funciones|secciones|que puedes hacer|como usar el sistema|ayuda con el sistema)\b/', $texto) === 1;
    $seguimiento = preg_match('/\b(que hago (?:ahora|despues)|siguiente paso|y ahora|y luego|como sigo|ya entre|ya estoy)\b/', $texto) === 1;
    $abrirOtra = preg_match('/\b(abre|abrir|llevame|ir a|acceder|entrar|navegar|gestionar|administrar|editar|eliminar)\b/', $texto) === 1;
    $paso = preg_match('/\b(?:que sigue|que viene|despues de (?:elegir|seleccionar|escoger))\b/', $texto) === 1;
    if (!$menu && !$abrirOtra && ($paso || ($seguimiento && preg_match('/\bespecialidad\w*\b.*\bmedico\w*\b|\bmedico\w*\b.*\bespecialidad\w*\b/', $texto) === 1))
        && ($id === null || in_array($id, ['medicos', 'especialidades', 'horarios'], true))) {
        // «¿Qué sigue después de…?» y «ya elegí especialidad y médico…» orientan el formulario de
        // reserva para pacientes; otros roles reciben orientación por sección, no un formulario ajeno.
        $menu = $rol !== 'paciente';
        $id = $rol === 'paciente' ? 'reservar' : null;
    } elseif (!$menu && $seguimiento && !$abrirOtra && $contexto !== null && lumi_destino_permitido($contexto, $rol) !== null
        && ($id === null || ($contexto === 'reservar' && in_array($id, ['medicos', 'especialidades', 'horarios', 'reservar'], true)))) {
        $id = $contexto;
    }
    if ($id === null && !$menu) {
        if (!preg_match('/\b(abre|abrir|llevame|ir a|acceder|entrar|navegar)\b/', $texto)) return null;
        return ['estado'=>'ok', 'origen'=>'guia', 'texto'=>'No identifiqué una sección disponible con ese nombre. Puedes elegir uno de estos accesos o decirme qué necesitas hacer.', 'navegacion'=>lumi_enlaces_permitidos($rol)];
    }
    if ($id === null) return ['estado'=>'ok', 'origen'=>'guia', 'texto'=>'Puedo orientarte paso a paso por las secciones que tienes disponibles. Elige una de estas opciones o dime qué quieres hacer.', 'navegacion'=>lumi_enlaces_permitidos($rol)];
    $funcion = lumi_funciones_permitidas($rol)[$id] ?? null;
    if ($funcion === null) return ['estado'=>'ok', 'origen'=>'guia', 'situacion'=>'acceso_denegado', 'texto'=>'Esa sección no está disponible para tu cuenta. Puedes revisar tus opciones desde Inicio o consultar con administración.', 'navegacion'=>lumi_enlaces_permitidos($rol, ['inicio'])];
    $operacion = preg_match('/\b(cancela|reprograma|crea|registra|modifica|cambia|elimina|aprueba|confirma|cobra|asigna|retira)\b/', $texto) === 1;
    $datos = preg_match('/\b(cuanto|cuesta|tengo|tiene|hay|disponibilidad|quien|quienes|cuando|cual es|cuales son)\b/', $texto) === 1;
    $limite = $operacion ? 'Puedo guiarte, pero no realizar esa operación por ti. ' : ($datos ? 'No consulto tus registros ni confirmo disponibilidad individual desde el chat. ' : '');
    $instruccion = $id === 'reservar' && ($paso || $seguimiento)
        ? 'Si ya elegiste especialidad y médico, selecciona la fecha y un bloque disponible. Después pulsa Revisar resumen, comprueba el importe y elige una modalidad de pago disponible antes de confirmar en el formulario. Si ya ves el resumen, revísalo antes de confirmar. Lumi no reserva ni confirma por ti.'
        : $funcion['guia'];
    return ['estado'=>'ok', 'origen'=>'guia', 'seccion'=>$id, 'texto'=>$limite . $extra . $instruccion . ' Si quieres abrir la sección, pulsa el enlace.', 'navegacion'=>lumi_enlaces_permitidos($rol, [$id])];
}
