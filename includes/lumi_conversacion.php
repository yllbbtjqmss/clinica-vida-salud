<?php
declare(strict_types=1);
/** Única fuente de mensajes: sesión PHP autenticada. Nada de audio, URLs o tokens. */
const LUMI_MAX_MENSAJES_SESION = 25; // bienvenida + doce pares completos
const LUMI_MAX_CARACTERES_SESION = 14000;

function lumi_conversacion_iniciar(array $usuario, ?array $config = null): bool {
    if (!is_int($usuario['id_usuario'] ?? null) || $usuario['id_usuario'] < 1) throw new DomainException('Sesión no válida.');
    // Huella interna, nunca se devuelve ni se guarda en el navegador.
    $propietario = hash('sha256', session_id() . "\0" . $usuario['id_usuario'] . "\0" . $usuario['rol']);
    $existente = $_SESSION['lumi_conversacion'] ?? null;
    $reanudada = is_array($existente) && ($existente['propietario'] ?? '') === $propietario;
    if (!$reanudada) {
        $config ??= lumi_config_base();
        $_SESSION['lumi_conversacion'] = ['propietario' => $propietario, 'seccion' => null,
            'mensajes' => [['rol' => 'lumi', 'contenido' => $config['saludo'], 'origen' => 'bienvenida', 'destinos' => []]]];
    }
    return $reanudada;
}

function lumi_conversacion_recortar(): void {
    $mensajes = &$_SESSION['lumi_conversacion']['mensajes'];
    while (count($mensajes) > LUMI_MAX_MENSAJES_SESION
        || array_sum(array_map(static fn(array $m): int => mb_strlen($m['contenido']), $mensajes)) > LUMI_MAX_CARACTERES_SESION) {
        if (count($mensajes) <= 1) break;
        array_splice($mensajes, 1, 2); // preservar bienvenida y pares, sin saludos nuevos
    }
}

function lumi_conversacion_agregar(string $mensaje, array $respuesta): void {
    $origen = ($respuesta['origen'] ?? '') === 'guia' ? 'guia' : (($respuesta['estado'] ?? '') === 'ok' ? 'gemini' : 'aviso');
    $contenido = $respuesta['texto'] ?? $respuesta['aviso'] ?? 'No se pudo completar la consulta.';
    // No conservar consultas detectadas como sensibles, ni siquiera si la guía las
    // resolvió localmente. No es un anonimizador: usar siempre mensajes ficticios.
    try { lumi_validar_privacidad($mensaje); }
    catch (DomainException) { $mensaje = 'Consulta de orientación (texto no conservado por privacidad).'; }
    if (!is_string($contenido) || mb_strlen($contenido) > LUMI_MAX_RESPUESTA) $contenido = 'No se pudo completar la consulta.';
    try { lumi_validar_privacidad($contenido); }
    catch (DomainException) { $contenido = 'Respuesta no conservada por privacidad. Puedes pedir orientación general sin datos sensibles.'; $origen = 'aviso'; }
    $destinos = [];
    if ($origen === 'guia') foreach ($respuesta['navegacion'] ?? [] as $enlace) {
        if (is_string($enlace['id'] ?? null) && isset(lumi_catalogo_funciones()[$enlace['id']])) $destinos[] = $enlace['id'];
    }
    $_SESSION['lumi_conversacion']['mensajes'][] = ['rol' => 'usuario', 'contenido' => $mensaje, 'origen' => $origen, 'destinos' => []];
    $_SESSION['lumi_conversacion']['mensajes'][] = ['rol' => 'lumi', 'contenido' => $contenido, 'origen' => $origen, 'destinos' => array_values(array_unique($destinos))];
    lumi_conversacion_recortar();
}

function lumi_conversacion_publica(string $rol, bool $reanudada = true): array {
    $mensajes = [];
    foreach ($_SESSION['lumi_conversacion']['mensajes'] as $mensaje) {
        $mensajes[] = ['rol' => $mensaje['rol'], 'contenido' => $mensaje['contenido'], 'origen' => $mensaje['origen'],
            'navegacion' => $mensaje['origen'] === 'guia' ? lumi_enlaces_permitidos($rol, $mensaje['destinos']) : []];
    }
    return ['reanudada' => $reanudada, 'mensajes' => $mensajes,
        'maxMensajes' => LUMI_MAX_MENSAJES_SESION, 'maxCaracteres' => LUMI_MAX_CARACTERES_SESION];
}

/** Contexto calculado, no una segunda copia persistente. Guías/avisos nunca salen a Gemini. */
function lumi_conversacion_contexto(): array {
    $pares = [];
    $mensajes = $_SESSION['lumi_conversacion']['mensajes'];
    for ($i = 1; $i + 1 < count($mensajes); $i += 2) {
        $pregunta = $mensajes[$i]; $respuesta = $mensajes[$i + 1];
        if ($pregunta['origen'] !== 'gemini' || $respuesta['origen'] !== 'gemini') continue;
        try { lumi_validar_privacidad($pregunta['contenido']); lumi_validar_privacidad($respuesta['contenido']); }
        catch (DomainException) { continue; }
        $pares[] = [['rol' => 'usuario', 'contenido' => $pregunta['contenido']], ['rol' => 'lumi', 'contenido' => $respuesta['contenido']]];
    }
    $pares = array_slice($pares, -LUMI_MAX_HISTORIAL);
    while ($pares && array_sum(array_map(static fn(array $par): int => mb_strlen($par[0]['contenido']) + mb_strlen($par[1]['contenido']), $pares)) > LUMI_MAX_HISTORIAL_CARACTERES) array_shift($pares);
    return lumi_normalizar_historial($pares ? array_merge(...$pares) : []);
}
