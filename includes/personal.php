<?php
declare(strict_types=1);
require_once __DIR__ . '/dashboard.php';

function cvsp_solo_get(array $permitidos): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405); header('Allow: GET'); exit('Este panel solo permite consultas.');
    }
    foreach ($_GET as $clave=>$valor) {
        if (!in_array($clave, $permitidos, true) || !is_string($valor) || str_contains($valor, "\0")) {
            http_response_code(400); exit('Parámetros no válidos. Utiliza los filtros del panel personal.');
        }
    }
}

function cvsp_tabla_citas(array $citas, string $persona): void {
    echo '<div class="tabla" tabindex="0" role="region" aria-label="Citas con desplazamiento horizontal"><table><thead><tr><th>Código</th><th>Fecha</th><th>Horario</th><th>', cvs_escapar($persona), '</th><th>Especialidad</th><th>Estado</th></tr></thead><tbody>';
    if (!$citas) echo '<tr><td colspan="6">No hay citas para los filtros seleccionados.</td></tr>';
    foreach ($citas as $c) {
        echo '<tr><td>', cvs_escapar($c['codigo']), '</td><td>', cvs_escapar($c['fecha']), '</td><td>',
            cvs_escapar(substr($c['hora_inicio'], 0, 5) . ' – ' . substr($c['hora_fin'], 0, 5)), '</td><td>',
            cvs_escapar($c['persona']), '</td><td>', cvs_escapar($c['especialidad']), '</td><td><span class="estado est-',
            cvs_escapar($c['estado']), '">', cvs_escapar($c['estado']), '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
