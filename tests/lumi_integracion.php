<?php
declare(strict_types=1);
// Auditoría de fuentes/plantillas, nunca ejecuta pantallas de negocio ni conecta PostgreSQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ui.php';
$raiz = dirname(__DIR__);
$comprobaciones = 0;
function comprobar_integracion(bool $ok, string $caso): void {
    global $comprobaciones;
    if (!$ok) throw new RuntimeException('FALLO: ' . $caso);
    $comprobaciones++;
}
function llamadas_integracion(string $codigo): array {
    $tokens = token_get_all($codigo);
    $llamadas = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) continue;
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
        if (($tokens[$j] ?? null) === '(') $llamadas[] = $token[1];
    }
    return $llamadas;
}
$pantallas = [];
foreach (glob($raiz . '/*.php') as $archivo) {
    $codigo = file_get_contents($archivo);
    $llamadas = llamadas_integracion($codigo);
    if (!str_contains($codigo, '/includes/auth.php') || (!preg_match('/<html\b/i', $codigo) && !in_array('cvp_inicio', $llamadas, true)) || basename($archivo) === 'login.php') continue;
    $directa = in_array('cvsu_area', $llamadas, true) && in_array('cvsu_cierre', $llamadas, true);
    $compartida = in_array('cvp_inicio', $llamadas, true) && in_array('cvp_fin', $llamadas, true);
    comprobar_integracion($directa || $compartida, 'Integración de Lumi en ' . basename($archivo));
    $pantallas[] = basename($archivo);
    echo 'OK plantilla autenticada: ', basename($archivo), $compartida ? ' (cierre compartido)' : '', "\n";
}
$ui = file_get_contents($raiz . '/includes/ui.php');
$pagosUi = file_get_contents($raiz . '/includes/pagos_ui.php');
comprobar_integracion((bool)preg_match('/function cvsu_cierre\([^)]*\).*?cvsu_lumi\(\)/s', $ui), 'El cierre común renderiza Lumi');
comprobar_integracion((bool)preg_match('/function cvp_fin\([^)]*\).*?cvsu_cierre\(\)/s', $pagosUi), 'La plantilla compartida conserva Lumi sin editar negocio');
foreach (['administrador', 'recepcionista', 'medico', 'paciente'] as $rol) {
    foreach (cvsu_paginas(['rol' => $rol]) as [, $ruta]) comprobar_integracion(in_array($ruta, $pantallas, true), 'Menú ' . $rol . ': ' . $ruta);
    foreach (lumi_funciones_permitidas($rol) as $funcion) comprobar_integracion(in_array($funcion['ruta'], $pantallas, true), 'Destino del asistente ' . $rol . ': ' . $funcion['ruta']);
}
$ficha = file_get_contents($raiz . '/ficha_reserva.php');
comprobar_integracion(str_contains($ficha, 'Content-Disposition: attachment') && in_array('cvp_fin', llamadas_integracion($ficha), true), 'Ficha en pantalla con widget; descarga autónoma sin asistente');
comprobar_integracion(!str_contains($ui, 'id="lumi-activacion"') && !str_contains($ui, 'id="lumi-activacion-ayuda"'), 'Sin checkbox ni instrucciones técnicas permanentes');
echo 'TOTAL: ', count($pantallas), ' pantallas integradas; ', $comprobaciones, " comprobaciones de fuentes y menús de cuatro roles. Sin BD ni servicios externos.\n";
