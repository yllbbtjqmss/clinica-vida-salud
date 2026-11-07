<?php
declare(strict_types=1);
require_once __DIR__ . '/reservas_pagos.php';

/** Importes exactos del libro de movimientos; los intentos no multiplican ingresos. */
function cvp_reporte(PDO $pdo, string $desde, string $hasta, string $entorno, array $filtros = []): array {
    cvp_fecha($desde); cvp_fecha($hasta);
    if ($desde>$hasta || !in_array($entorno,['real','demo'],true)) throw new DomainException('Filtros económicos inválidos.');
    $params=[':desde'=>$desde,':hasta'=>$hasta,':modalidad'=>$entorno==='demo' ? 'demo' : 'presencial'];
    $restriccion = '';
    foreach (['id_medico'=>'m.id_medico','id_especialidad'=>'e.id_especialidad'] as $campo=>$columna) {
        if (($filtros[$campo] ?? '') !== '') {
            $id=cvp_id($filtros[$campo]); if (!$id) throw new DomainException('Filtro de consulta inválido.');
            $restriccion .= " AND $columna=:$campo"; $params[':'.$campo]=$id;
        }
    }
    if (($filtros['estado'] ?? '') !== '') {
        if (!in_array($filtros['estado'],CVP_ESTADOS,true)) throw new DomainException('Estado de pago inválido.');
        $restriccion .= ' AND p.estado=:estado'; $params[':estado']=$filtros['estado'];
    }
    $metodo = cvp_online_disponible($pdo) ? "COALESCE(p.metodo_online,p.metodo,'Sin método')" : "COALESCE(p.metodo,'Sin método')";
    $base=" FROM movimientos_pago v JOIN pagos p USING(id_pago)
        LEFT JOIN citas c ON c.id_cita=p.id_cita LEFT JOIN reservas_temporales r ON r.id_reserva=p.id_reserva
        JOIN medicos m ON m.id_medico=COALESCE(c.id_medico,r.id_medico)
        JOIN especialidades e ON e.id_especialidad=COALESCE(c.id_especialidad,r.id_especialidad)
        WHERE p.modalidad=:modalidad AND v.tipo IN ('cobro','cobro_tardio','reembolso')
        AND (v.creado_en AT TIME ZONE 'America/La_Paz')::date BETWEEN CAST(:desde AS DATE) AND CAST(:hasta AS DATE)" . $restriccion;
    $q=$pdo->prepare("SELECT COALESCE(SUM(v.importe) FILTER (WHERE v.tipo IN ('cobro','cobro_tardio')),0)::text AS cobrado,
        COALESCE(SUM(v.importe) FILTER (WHERE v.tipo='reembolso'),0)::text AS reembolsado,
        COALESCE(SUM(CASE WHEN v.tipo='reembolso' THEN -v.importe ELSE v.importe END),0)::text AS neto,
        COUNT(DISTINCT p.id_pago) FILTER (WHERE v.tipo IN ('cobro','cobro_tardio')) AS exitosos".$base);
    $q->execute($params); $resumen=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT COALESCE(SUM(p.importe) FILTER (WHERE p.estado IN ('pendiente','procesando')),0)::text AS pendiente,
        COUNT(*) FILTER (WHERE p.estado='fallido') AS fallidos,
        COUNT(*) FILTER (WHERE p.estado='reembolso_pendiente') AS devoluciones_pendientes,
        COALESCE(SUM(p.importe) FILTER (WHERE p.estado='reembolso_pendiente'),0)::text AS importe_devoluciones,
        COUNT(*) FILTER (WHERE p.estado='reembolsado') AS devoluciones_completadas,
        COUNT(*) AS operaciones FROM pagos p
        LEFT JOIN citas c ON c.id_cita=p.id_cita LEFT JOIN reservas_temporales r ON r.id_reserva=p.id_reserva
        JOIN medicos m ON m.id_medico=COALESCE(c.id_medico,r.id_medico)
        JOIN especialidades e ON e.id_especialidad=COALESCE(c.id_especialidad,r.id_especialidad)
        WHERE p.modalidad=:modalidad
        AND (p.creado_en AT TIME ZONE 'America/La_Paz')::date BETWEEN CAST(:desde AS DATE) AND CAST(:hasta AS DATE)" . $restriccion);
    $q->execute($params); $resumen=array_merge($resumen,$q->fetch(PDO::FETCH_ASSOC));
    $grupos=[];
    // Expresiones fijas: nunca identificadores recibidos por GET.
    foreach (['fecha'=>"to_char(v.creado_en AT TIME ZONE 'America/La_Paz','YYYY-MM-DD')",'especialidad'=>'e.nombre',
        'medico'=>"m.nombres || ' ' || m.apellidos || ' (#' || m.id_medico || ')'",'metodo'=>$metodo] as $tipo=>$expresion) {
        $q=$pdo->prepare("SELECT $expresion AS grupo,
            COALESCE(SUM(v.importe) FILTER (WHERE v.tipo IN ('cobro','cobro_tardio')),0)::text AS cobrado,
            COALESCE(SUM(v.importe) FILTER (WHERE v.tipo='reembolso'),0)::text AS devuelto,
            COALESCE(SUM(CASE WHEN v.tipo='reembolso' THEN -v.importe ELSE v.importe END),0)::text AS neto"
            .$base." GROUP BY $expresion ORDER BY $expresion");
        $q->execute($params); $grupos[$tipo]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['resumen'=>$resumen,'grupos'=>$grupos];
}
