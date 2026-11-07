<?php
declare(strict_types=1);
/** Integración opt-in: solo cvs_prueba_reservas local, transacción READ ONLY.
 * No autentica usuarios, abre pantallas ni carga el módulo de reservas/pagos.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array(getenv('CVS_DB_HOST'), ['127.0.0.1','localhost','::1'], true)
    || getenv('CVS_DB_NAME') !== 'cvs_prueba_reservas') {
    fwrite(STDERR,"Solo se admite CVS_DB_HOST local y CVS_DB_NAME=cvs_prueba_reservas explícitos.\n"); exit(1);
}
require_once dirname(__DIR__) . '/includes/conexion.php';
require_once dirname(__DIR__) . '/includes/lumi.php';
$casos=0;
function comprobar_catalogo_pg(bool $ok): void {
    global $casos;
    if (!$ok) throw new RuntimeException();
    $casos++;
}
try {
    $p=cvs_conexion();
    $p->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $q=$p->prepare("SELECT current_database() AS base, current_setting('transaction_read_only') AS solo_lectura, pg_current_xact_id_if_assigned() IS NULL AS sin_escrituras");
        $q->execute(); $identidad=$q->fetch(PDO::FETCH_ASSOC);
        comprobar_catalogo_pg($identidad['base']==='cvs_prueba_reservas' && $identidad['solo_lectura']==='on' && $identidad['sin_escrituras']);
        $q=$p->prepare('SELECT id_especialidad,nombre FROM public.especialidades WHERE activo=TRUE ORDER BY nombre,id_especialidad');
        $q->execute(); $especialidades=$q->fetchAll(PDO::FETCH_ASSOC);
        $comparar=$p->prepare("SELECT btrim(m.nombres || ' ' || m.apellidos) AS nombre FROM public.medicos m
            WHERE m.activo=TRUE AND m.id_medico IN (SELECT me.id_medico FROM public.medico_especialidad me
                JOIN public.especialidades e ON e.id_especialidad=me.id_especialidad
                WHERE e.activo=TRUE AND me.id_especialidad=:id)
            ORDER BY m.apellidos,m.nombres,m.id_medico LIMIT :limite");
        foreach (['paciente','medico','recepcionista','administrador'] as $rol) {
            comprobar_catalogo_pg(lumi_catalogo_especialidades($p,$rol)===array_slice($especialidades,0,LUMI_CATALOGO_MAX_FILAS+1));
            foreach ($especialidades as $e) {
                $encontrada=lumi_catalogo_buscar_especialidad($p,$rol,$e['nombre']);
                comprobar_catalogo_pg(count($encontrada)===1 && (int)$encontrada[0]['id_especialidad']===(int)$e['id_especialidad']);
                $comparar->bindValue(':id',(int)$e['id_especialidad'],PDO::PARAM_INT);
                $comparar->bindValue(':limite',LUMI_CATALOGO_MAX_FILAS+1,PDO::PARAM_INT);
                $comparar->execute();
                $medicos=lumi_catalogo_medicos($p,$rol,(int)$e['id_especialidad']);
                comprobar_catalogo_pg($medicos===$comparar->fetchAll(PDO::FETCH_ASSOC));
                foreach ($medicos as $m) comprobar_catalogo_pg(array_keys($m)===['nombre']);
                $r=lumi_resolver_catalogo('Qué médicos hay en '.$e['nombre'],$rol,$p);
                comprobar_catalogo_pg($r!==null && $r['origen']==='guia' && $r['situacion']!=='no_verificado' && mb_strlen($r['texto'])<=LUMI_MAX_RESPUESTA);
                // Variantes de la misma consulta; el nombre debe sobrevivir a conectores intermedios.
                foreach (['Qué médicos atienden en '.$e['nombre'], '¿Quién atiende en '.$e['nombre'].'?'] as $variante) {
                    $a=lumi_resolver_catalogo($variante,$rol,$p);
                    comprobar_catalogo_pg($a!==null && ($medicos || str_contains($a['texto'] ?? '','no hay médicos asociados')));
                    comprobar_catalogo_pg(str_contains($a['texto'] ?? '',$e['nombre']),'Conector intermedio conserva la especialidad real');
                }
            }
            comprobar_catalogo_pg(lumi_catalogo_buscar_especialidad($p,$rol,'LUMI_NO_EXISTE_'.bin2hex(random_bytes(10)))===[]);
        }
        $q=$p->prepare('SELECT id_especialidad,nombre FROM public.especialidades WHERE activo=FALSE');
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
            comprobar_catalogo_pg(lumi_catalogo_buscar_especialidad($p,'paciente',$e['nombre'])===[]);
            comprobar_catalogo_pg(lumi_catalogo_medicos($p,'paciente',(int)$e['id_especialidad'])===[]);
        }
        $q=$p->prepare("SELECT current_setting('transaction_read_only')='on' AND pg_current_xact_id_if_assigned() IS NULL");
        $q->execute(); comprobar_catalogo_pg($q->fetchColumn()===true);
        echo "OK: $casos comprobaciones reales en cvs_prueba_reservas; ",count($especialidades)," especialidades activas verificadas en cuatro roles.\n";
        echo "READ ONLY, sin XID de escritura; ROLLBACK. No se imprimen registros ni se llama a Gemini.\n";
    } finally { $p->exec('ROLLBACK'); }
} catch(Throwable) {
    fwrite(STDERR,"Falló la comprobación de catálogo; detalles privados omitidos. No se aplicaron cambios.\n"); exit(1);
}
