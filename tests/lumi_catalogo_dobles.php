<?php
declare(strict_types=1);
/** Doble sin conexión: datos exclusivamente ficticios y columnas privadas centinela. */
class LumiCatalogoFakeStatement extends PDOStatement {
    private array $parametros = [];
    private array $filas = [];
    public function __construct(private LumiCatalogoFakePDO $pdo, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->parametros[ltrim((string)$param, ':')] = $value;
        return true;
    }
    public function execute(?array $params = null): bool {
        foreach ($params ?? [] as $key=>$valor) $this->parametros[ltrim((string)$key, ':')] = $valor;
        $this->filas = $this->pdo->seleccionar($this->sql, $this->parametros);
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->filas; }
}

class LumiCatalogoFakePDO extends PDO {
    public array $consultasCatalogo = [];
    public array $especialidades;
    public array $medicos;
    public array $relaciones = [[1,1],[2,1],[3,2],[1,2],[4,1],[5,3]];
    public function __construct(public string $escenarioCatalogo = 'normal') {
        $this->especialidades = [
            ['id_especialidad'=>1,'nombre'=>'Cardiología','activo'=>true,'descripcion'=>'PRIVADO_DESCRIPCION'],
            ['id_especialidad'=>2,'nombre'=>'Pediatría','activo'=>true],
            ['id_especialidad'=>3,'nombre'=>'Neurología','activo'=>false],
            ['id_especialidad'=>4,'nombre'=>'Medicina General','activo'=>true],
        ];
        $this->medicos = [
            ['id'=>1,'nombres'=>'Profesional Alfa','apellidos'=>'Ficticio','activo'=>true,'correo'=>'PRIVADO_CORREO'],
            ['id'=>2,'nombres'=>'Profesional Beta','apellidos'=>'Ficticio','activo'=>true,'telefono'=>'PRIVADO_TELEFONO'],
            ['id'=>3,'nombres'=>'Profesional Gamma','apellidos'=>'Ficticio','activo'=>true,'registro_profesional'=>'PRIVADO_REGISTRO'],
            ['id'=>4,'nombres'=>'Profesional Inactivo','apellidos'=>'Ficticio','activo'=>false],
            ['id'=>5,'nombres'=>'Profesional Otra','apellidos'=>'Ficticio','activo'=>true],
        ];
        if ($escenarioCatalogo === 'vacio') $this->especialidades = [];
        if ($escenarioCatalogo === 'sin_medicos') $this->medicos = [];
        if ($escenarioCatalogo === 'ambiguo') {
            $this->especialidades[] = ['id_especialidad'=>5,'nombre'=>'Pediatría Especial','activo'=>true];
            $this->especialidades[] = ['id_especialidad'=>6,'nombre'=>'Pediatria Especial','activo'=>true];
        }
        if ($escenarioCatalogo === 'extenso') {
            for ($i=10;$i<30;$i++) {
                $this->especialidades[]=['id_especialidad'=>$i,'nombre'=>'Catálogo Ficticio '.$i,'activo'=>true];
                $this->medicos[]=['id'=>$i,'nombres'=>str_repeat('Nombre Ficticio ',6),'apellidos'=>str_repeat('Apellido ',10).$i,'activo'=>true];
                $this->relaciones[]=[$i,1];
            }
        }
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!preg_match('/^SELECT\b/', $query) || preg_match('/\b(INSERT|UPDATE|DELETE|CALL|cvp_expirar|cvs_expirar_reservas)\b/i', $query)) throw new LogicException('SQL no permitido en el doble.');
        return new LumiCatalogoFakeStatement($this, $query);
    }
    public function seleccionar(string $sql, array $params): array {
        $this->consultasCatalogo[] = ['sql'=>$sql,'parametros'=>$params];
        if ($this->escenarioCatalogo === 'fallo') throw new PDOException('DETALLE_PRIVADO_SQL');
        $activas=array_values(array_filter($this->especialidades, static fn($e)=>$e['activo']));
        if (str_contains($sql, 'FROM public.especialidades e')) {
            if (isset($params['nombre'])) {
                $normalizar = str_contains($sql, 'translate(');
                $activas=array_values(array_filter($activas, static fn($e)=>($normalizar ? lumi_catalogo_normalizar($e['nombre']) : mb_strtolower(trim($e['nombre']),'UTF-8')) === $params['nombre']));
            }
            usort($activas, static fn($a,$b)=>strcmp($a['nombre'],$b['nombre']));
            return array_map(static fn($e)=>['id_especialidad'=>$e['id_especialidad'],'nombre'=>$e['nombre']], array_slice($activas,0,$params['limite']??2));
        }
        if (!str_contains($sql, 'FROM public.medicos m')) throw new LogicException('Tabla no admitida.');
        $id = $params['especialidad'];
        if (!in_array($id,array_column($activas,'id_especialidad'),true)) return [];
        $ids=array_column(array_filter($this->relaciones, static fn($r)=>$r[1]===$id),0);
        $medicos=array_values(array_filter($this->medicos,static fn($m)=>$m['activo'] && in_array($m['id'],$ids,true)));
        usort($medicos, static fn($a,$b)=>strcmp($a['apellidos'].' '.$a['nombres'],$b['apellidos'].' '.$b['nombres']));
        return array_map(static fn($m)=>['nombre'=>$m['nombres'].' '.$m['apellidos']],array_slice($medicos,0,$params['limite']));
    }
}
