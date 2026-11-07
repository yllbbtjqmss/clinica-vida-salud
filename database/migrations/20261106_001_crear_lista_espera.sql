BEGIN;

CREATE TABLE lista_espera (
    id_lista_espera INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_paciente INTEGER NOT NULL REFERENCES pacientes(id_paciente),
    id_especialidad INTEGER NOT NULL REFERENCES especialidades(id_especialidad),
    id_medico INTEGER REFERENCES medicos(id_medico),
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE NOT NULL,
    hora_desde TIME,
    hora_hasta TIME,
    estado VARCHAR(20) NOT NULL DEFAULT 'activa',
    id_cita_asignada INTEGER REFERENCES citas(id_cita),
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lista_espera_medico_especialidad
        FOREIGN KEY (id_medico, id_especialidad)
        REFERENCES medico_especialidad(id_medico, id_especialidad),
    CONSTRAINT ck_lista_espera_fechas CHECK (fecha_hasta >= fecha_desde),
    CONSTRAINT ck_lista_espera_horas_completas CHECK (
        (hora_desde IS NULL AND hora_hasta IS NULL)
        OR (hora_desde IS NOT NULL AND hora_hasta IS NOT NULL)
    ),
    CONSTRAINT ck_lista_espera_horas CHECK (
        hora_desde IS NULL OR hora_hasta > hora_desde
    ),
    CONSTRAINT ck_lista_espera_estado CHECK (
        estado IN ('activa', 'retirada', 'asignada')
    ),
    CONSTRAINT ck_lista_espera_asignacion CHECK (
        (estado = 'asignada') = (id_cita_asignada IS NOT NULL)
    )
);

CREATE UNIQUE INDEX uq_lista_espera_preferencia_activa
    ON lista_espera (
        id_paciente,
        id_especialidad,
        COALESCE(id_medico, 0),
        fecha_desde,
        fecha_hasta,
        COALESCE(hora_desde, TIME '00:00'),
        COALESCE(hora_hasta, TIME '00:00')
    )
    WHERE estado = 'activa';

CREATE INDEX ix_lista_espera_compatibilidad_activa
    ON lista_espera (
        id_especialidad,
        id_medico,
        fecha_desde,
        fecha_hasta,
        creado_en
    )
    WHERE estado = 'activa';

CREATE INDEX ix_lista_espera_paciente
    ON lista_espera (id_paciente, estado, creado_en DESC);

CREATE UNIQUE INDEX uq_lista_espera_cita_asignada
    ON lista_espera (id_cita_asignada)
    WHERE id_cita_asignada IS NOT NULL;

COMMIT;
