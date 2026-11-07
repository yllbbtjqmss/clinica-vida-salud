<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/lumi.php';
function cvs_activo(mixed $v): bool { return in_array($v, [true, 1, '1', 't', 'true'], true); }
final class LumiEstadoStatement extends PDOStatement {
    public function __construct(private mixed $valor) {}
    public function fetchColumn(int $column = 0): mixed { return $this->valor; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->valor; }
}
final class LumiEstadoPDO extends PDO {
    public array $consultas = [];
    public function __construct(public bool $instalado = true, public ?string $faltante = null, public bool $fallar = false) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->consultas[] = $query;
        if ($this->fallar) throw new PDOException('Fallo ficticio: no debe revelarse.');
        if (str_contains($query, 'to_regclass')) return new LumiEstadoStatement($this->instalado);
        $datos = array_fill_keys(['especialidades','medicos','tarifas','horarios'], true);
        if ($this->faltante !== null) $datos[$this->faltante] = false;
        return new LumiEstadoStatement($datos);
    }
}
$casos = 0;
function comprobar_estado(bool $ok, string $caso): void {
    global $casos;
    if (!$ok) throw new RuntimeException('FALLO: ' . $caso);
    $casos++;
}
$frase = 'Ya elegí especialidad y médico, ¿qué hago después?';
foreach ([null, 'reservar', 'pagos'] as $contexto) {
    $guia = lumi_resolver_guia($frase, 'paciente', $contexto);
    comprobar_estado($guia['seccion'] === 'reservar', 'No confundir médico seleccionado con sección administrativa');
    comprobar_estado(str_contains($guia['texto'], 'selecciona la fecha y un bloque'), 'Siguiente paso condicional');
}
foreach (['Ya elegí médico, ¿qué hago después?', '¿Qué hago después?', 'Ya entré a Reservar cita, ¿qué hago ahora?'] as $fraseContextual) {
    comprobar_estado(lumi_resolver_guia($fraseContextual, 'paciente', 'reservar')['seccion'] === 'reservar', 'Seguimiento de página');
}
foreach (['Abrir Médicos', 'Abrir Usuarios', 'Abrir Especialidades', '¿Qué hago ahora para abrir Médicos?'] as $prohibida) {
    $pdo = new LumiEstadoPDO(false);
    $guia = lumi_orientar_estado(lumi_resolver_guia($prohibida, 'paciente', 'reservar'), $pdo, 'paciente');
    comprobar_estado($guia['situacion'] === 'acceso_denegado' && $pdo->consultas === [], 'No ocultar una denegación real con contexto o instalación');
}
foreach (['reservar'=>'paciente', 'pagos'=>'paciente', 'tarifas'=>'recepcionista', 'economicos'=>'administrador'] as $id=>$rol) {
    comprobar_estado(lumi_estado_pagina(new LumiEstadoPDO(false), $id, $rol)['situacion'] === 'instalacion_pendiente', 'Instalación por sección');
}
$pdo = new LumiEstadoPDO(false);
$guia = lumi_resolver_guia($frase, 'paciente', 'reservar');
$pendiente = lumi_orientar_estado($guia, $pdo, 'paciente');
comprobar_estado($pendiente['situacion'] === 'instalacion_pendiente' && str_contains($pendiente['texto'], 'No es una restricción'), 'Instalación no equivale a permiso');
comprobar_estado(!str_contains($pendiente['texto'], 'selecciona la fecha'), 'No orientar a formulario bloqueado');
comprobar_estado(count($pdo->consultas) === 1, 'Sin consultar datos cuando falta esquema');
foreach (['especialidades','medicos','tarifas','horarios'] as $faltante) {
    $estado = lumi_orientar_estado($guia, new LumiEstadoPDO(true, $faltante), 'paciente');
    comprobar_estado($estado['situacion'] === 'sin_datos' && str_contains($estado['texto'], 'No es una restricción'), 'Catálogo insuficiente: ' . $faltante);
}
$pdo = new LumiEstadoPDO();
$normal = lumi_orientar_estado($guia, $pdo, 'paciente');
comprobar_estado($normal['situacion'] === 'normal' && str_contains($normal['texto'], 'Revisar resumen'), 'Flujo normal');
comprobar_estado(str_contains($normal['texto'], 'no confirma tu selección'), 'No afirmar estado de formulario ni disponibilidad');
$error = lumi_orientar_estado($guia, new LumiEstadoPDO(true, null, true), 'paciente');
comprobar_estado($error['situacion'] === 'no_verificado' && !str_contains($error['texto'], 'Fallo ficticio'), 'Fallo seguro sin inventar permisos ni revelar SQL');
$denegada = new LumiEstadoPDO();
comprobar_estado(lumi_estado_pagina($denegada, 'reservar', 'medico')['situacion'] === 'acceso_denegado' && !$denegada->consultas, 'Autorización antes de consultas');
foreach ($pdo->consultas as $sql) {
    comprobar_estado(preg_match('/^SELECT\b/', $sql) === 1, 'Únicamente SELECT');
    comprobar_estado(!preg_match('/\b(FROM|JOIN)\s+(?:public\.)?(pacientes|usuarios|citas|pagos|reservas_temporales|bloqueos_agenda)\b/i', $sql), 'Sin consultar registros sensibles');
    comprobar_estado(!preg_match('/\b(INSERT|UPDATE|DELETE|CALL|FOR UPDATE)\b/i', $sql), 'Sin escrituras o bloqueos');
}
$servidor = ['HTTP_HOST'=>'127.0.0.1:8011', 'SCRIPT_NAME'=>'/lumi_api.php', 'HTTP_REFERER'=>'http://127.0.0.1:8011/reservar_cita.php?dato=no_leer'];
comprobar_estado(lumi_seccion_referida($servidor) === 'reservar', 'Página directa sin enlace previo; query ignorada');
foreach (['http://otro.test:8011/reservar_cita.php', 'http://127.0.0.1:8012/reservar_cita.php', 'https://127.0.0.1:8011/reservar_cita.php', 'http://127.0.0.1:8011/otra/reservar_cita.php', 'http://127.0.0.1:8011/desconocida.php', 'http://usuario@127.0.0.1:8011/reservar_cita.php', ''] as $referencia) {
    $servidor['HTTP_REFERER'] = $referencia;
    comprobar_estado(lumi_seccion_referida($servidor) === null, 'Referencias no permitidas ignoradas');
}
$servidor['SCRIPT_NAME'] = '/clinica/lumi_api.php';
$servidor['HTTP_REFERER'] = 'http://127.0.0.1:8011/clinica/reservar_cita.php';
comprobar_estado(lumi_seccion_referida($servidor) === 'reservar', 'Instalación en subdirectorio');
echo "OK: $casos comprobaciones de estado, seguimiento, lectura mínima y autorización; sin PostgreSQL ni Gemini.\n";
