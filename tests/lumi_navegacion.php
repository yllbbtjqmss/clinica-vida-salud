<?php
declare(strict_types=1);
// Solo CLI, catálogo/menú/código fuente; no se abren páginas de negocio ni bases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ui.php';
$casos = 0;
function verificar(bool $ok, string $caso): void {
    global $casos;
    if (!$ok) throw new RuntimeException('FALLO: ' . $caso);
    $casos++;
}
$roles = ['paciente', 'medico', 'recepcionista', 'administrador'];
$catalogo = lumi_catalogo_funciones();
foreach ($roles as $rol) {
    $menu = array_column(cvsu_paginas(['rol' => $rol]), 1);
    $rutas = array_column(lumi_funciones_permitidas($rol), 'ruta');
    sort($menu); sort($rutas);
    verificar($menu === $rutas, 'Catálogo coincide con menú real: ' . $rol);
    foreach ($catalogo as $id => $funcion) {
        $permitido = in_array($rol, $funcion['roles'], true);
        verificar(lumi_destino_permitido($id, $rol) === ($permitido ? $funcion['ruta'] : null), 'Matriz permiso ' . $rol . '/' . $id);
    }
    foreach (['https://example.invalid', '//example.invalid', '../usuarios.php', 'usuarios.php', 'pagos?id=1', 'javascript:alert(1)', 'cancelar?accion=cancelar', '%2e%2e', '', ['usuarios']] as $destino) {
        verificar(lumi_destino_permitido($destino, $rol) === null, 'Destino arbitrario rechazado: ' . $rol);
    }
    $guia = lumi_resolver_guia('¿Qué funciones puedes mostrarme?', $rol);
    verificar($guia['origen'] === 'guia' && count($guia['navegacion']) === count($menu), 'Menú local filtrado ' . $rol);
}
foreach ($catalogo as $funcion) {
    $archivo = dirname(__DIR__) . '/' . $funcion['ruta'];
    verificar(is_file($archivo), 'Destino existe: ' . $funcion['ruta']);
    if ($funcion['ruta'] === 'index.php') continue;
    preg_match('/cvs_exigir_roles\(\[([^\]]+)\]\)/', file_get_contents($archivo), $match);
    preg_match_all("/'([a-z]+)'/", $match[1] ?? '', $declarados);
    $esperados = $funcion['roles']; $reales = $declarados[1]; sort($esperados); sort($reales);
    verificar($esperados === $reales, 'Permisos coinciden con guardia real de ' . $funcion['ruta']);
}
$preguntas = [
    ['paciente', '¿Cómo reservo una cita?', 'reservar'],
    ['paciente', 'Quiero reservar', 'reservar'],
    ['paciente', 'Llévame a mis citas', 'mis_citas'],
    ['paciente', '¿Cuánto cuesta una consulta?', 'reservar'],
    ['paciente', 'Cancela mi cita', 'mis_citas'],
    ['paciente', 'Quiero reprogramar mi cita', 'mis_citas'],
    ['paciente', 'Abre mis pagos', 'pagos'],
    ['medico', 'Muéstrame mi agenda', 'mi_agenda'],
    ['medico', '¿Qué citas tengo?', 'mi_agenda'],
    ['recepcionista', 'Registrar una cita', 'registrar'],
    ['recepcionista', 'Ver horarios médicos', 'agenda'],
    ['recepcionista', 'Consultar tarifas', 'tarifas'],
    ['administrador', 'Ver reportes financieros', 'economicos'],
    ['administrador', 'Abrir pacientes', 'pacientes'],
    ['administrador', 'Configurar Lumi', 'preferencias_lumi'],
];
foreach ($preguntas as [$rol, $pregunta, $id]) {
    $guia = lumi_resolver_guia($pregunta, $rol);
    verificar(($guia['navegacion'][0]['id'] ?? '') === $id, 'Intención local ' . $pregunta);
    verificar(!str_contains($guia['texto'], '.php') && mb_strlen($guia['texto']) <= LUMI_MAX_RESPUESTA, 'Guía acotada sin rutas libres');
}
foreach ([['paciente', 'Abrir usuarios'], ['medico', 'Abrir pagos'], ['recepcionista', 'Reportes económicos'], ['paciente', 'Abrir médicos']] as [$rol, $pregunta]) {
    $guia = lumi_resolver_guia($pregunta, $rol);
    verificar(array_column($guia['navegacion'], 'id') === ['inicio'], 'No ofrece función ajena: ' . $rol);
}
verificar(str_contains(lumi_resolver_guia('Aprueba mi pago', 'administrador')['texto'], 'no realizar'), 'Lumi no ejecuta aprobaciones');
verificar(lumi_resolver_guia('Hola, ¿cómo estás?', 'paciente') === null, 'Conversación libre mantiene Gemini');
verificar(lumi_funciones_permitidas('inventado') === [], 'Rol desconocido sin destinos');
verificar(!str_contains(lumi_conocimiento_publico(), '.php'), 'Conocimiento público sin rutas ni datos de sesión');
echo "OK: $casos comprobaciones de catálogo, rutas reales, permisos, roles e intenciones. Sin PostgreSQL ni Gemini.\n";
