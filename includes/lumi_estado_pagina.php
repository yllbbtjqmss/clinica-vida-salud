<?php
declare(strict_types=1);
/** Estado mínimo para orientación. Solo SELECT de esquema/catálogos públicos;
 * nunca llama a cvp_expirar ni consulta pacientes, citas, reservas o pagos.
 */
function lumi_seccion_referida(array $servidor): ?string {
    $url = parse_url($servidor['HTTP_REFERER'] ?? '');
    if (!is_array($url) || isset($url['user']) || isset($url['pass'])) return null;
    $origen = parse_url((($servidor['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($servidor['HTTP_HOST'] ?? ''));
    if (!is_array($origen) || ($url['scheme'] ?? '') !== ($origen['scheme'] ?? '')
        || strtolower($url['host'] ?? '') !== strtolower($origen['host'] ?? '')
        || ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80)) !== ($origen['port'] ?? ($origen['scheme'] === 'https' ? 443 : 80))) return null;
    $directorio = rtrim(str_replace('\\', '/', dirname($servidor['SCRIPT_NAME'] ?? '/lumi_api.php')), '/');
    foreach (lumi_catalogo_funciones() as $id => $funcion) {
        if (($url['path'] ?? '') === $directorio . '/' . $funcion['ruta']) return $id;
    }
    return null;
}

function lumi_estado_pagina(PDO $pdo, string $id, string $rol): array {
    // Los permisos siempre se comprueban antes del esquema o de cualquier dato.
    if (lumi_destino_permitido($id, $rol) === null) return ['situacion'=>'acceso_denegado'];
    if (!in_array($id, ['reservar', 'pagos', 'tarifas', 'economicos'], true)) return ['situacion'=>'normal'];
    try {
        $instalado = $pdo->query("SELECT to_regclass('public.pagos') IS NOT NULL
            AND to_regprocedure('public.cvs_expirar_reservas()') IS NOT NULL
            AND to_regclass('public.tarifas_consulta') IS NOT NULL
            AND to_regclass('public.historial_tarifas') IS NOT NULL
            AND to_regclass('public.reservas_temporales') IS NOT NULL
            AND to_regclass('public.intentos_pago') IS NOT NULL
            AND to_regclass('public.movimientos_pago') IS NOT NULL
            AND to_regclass('public.bloqueos_agenda') IS NOT NULL")->fetchColumn();
        if (!cvs_activo($instalado)) return ['situacion'=>'instalacion_pendiente'];
        if ($id !== 'reservar') return ['situacion'=>'normal'];
        // Únicamente booleanos globales, sin identificadores, precios ni datos de personas.
        $oferta = $pdo->query("SELECT
            EXISTS(SELECT 1 FROM public.especialidades WHERE activo) AS especialidades,
            EXISTS(SELECT 1 FROM public.medico_especialidad me JOIN public.medicos m USING(id_medico)
                JOIN public.especialidades e USING(id_especialidad) WHERE m.activo AND e.activo) AS medicos,
            EXISTS(SELECT 1 FROM public.tarifas_consulta t JOIN public.medicos m USING(id_medico)
                JOIN public.especialidades e USING(id_especialidad) WHERE m.activo AND e.activo AND t.importe>0) AS tarifas,
            EXISTS(SELECT 1 FROM public.horarios h JOIN public.tarifas_consulta t USING(id_medico)
                JOIN public.medicos m USING(id_medico) JOIN public.especialidades e USING(id_especialidad)
                WHERE h.activo AND m.activo AND e.activo AND t.importe>0) AS horarios")->fetch(PDO::FETCH_ASSOC);
        foreach (['especialidades', 'medicos', 'tarifas', 'horarios'] as $dato) {
            if (!is_array($oferta) || !array_key_exists($dato, $oferta)) return ['situacion'=>'no_verificado'];
            if (!cvs_activo($oferta[$dato])) return ['situacion'=>'sin_datos', 'faltante'=>$dato];
        }
        return ['situacion'=>'normal'];
    } catch (Throwable) {
        // Un fallo de consulta no significa ni denegación de rol ni instalación ausente.
        return ['situacion'=>'no_verificado'];
    }
}

function lumi_orientar_estado(array $guia, PDO $pdo, string $rol): array {
    $id = $guia['seccion'] ?? null;
    if (!is_string($id)) return $guia; // Menú o denegación: conservar la decisión de permisos.
    $estado = lumi_estado_pagina($pdo, $id, $rol);
    $guia['situacion'] = $estado['situacion'];
    $texto = match ($estado['situacion']) {
        'acceso_denegado' => 'Esa sección no está disponible para tu rol actual. Consulta tus accesos desde Inicio.',
        'instalacion_pendiente' => 'Tu rol sí permite acceder a esta sección, pero el módulo de reservas y pagos está pendiente de instalación o incompleto en la base que usa esta aplicación. No es una restricción de tu cuenta. Solicita a administración que revise la instalación. No puedes continuar la reserva desde esta pantalla hasta que el módulo esté disponible; Lumi no instala ni modifica la base.',
        'sin_datos' => match ($estado['faltante']) {
            'especialidades' => 'El módulo está instalado, pero no hay especialidades activas disponibles.',
            'medicos' => 'El módulo está instalado, pero no hay médicos activos vinculados a especialidades activas.',
            'tarifas' => 'El módulo está instalado, pero no hay tarifas configuradas para una consulta activa.',
            'horarios' => 'El módulo está instalado, pero no hay horarios activos para las consultas con tarifa.',
        } . ' No es una restricción de tu rol. Solicita revisión a administración. No puedo completar esos datos desde Lumi.',
        'no_verificado' => 'No pude verificar el estado actual de esta sección. Esto no confirma un problema de permisos ni de instalación. Recarga la página y revisa su aviso antes de continuar; no se ha confirmado ninguna operación desde Lumi.',
        default => null,
    };
    if ($texto !== null) $guia['texto'] = $texto;
    elseif ($id === 'reservar') $guia['texto'] .= ' El servidor detecta catálogos configurados, pero eso no confirma tu selección, tu ficha ni un bloque libre. Si no aparecen médicos, tarifa u horarios para lo elegido, revisa la selección o consulta con recepción; no confirmes sin un resumen válido.';
    return $guia;
}
