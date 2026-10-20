-- CLINICA VIDA SALUD - Estructura inicial de base de datos
-- PostgreSQL 18 (compatible con versiones modernas de PostgreSQL)
-- Ejecutar una sola vez dentro de la base de datos clinica_vida_salud.
-- Los datos que se registren posteriormente NO se comparten automaticamente entre instalaciones locales.

BEGIN;

-- Permite impedir reservas de intervalos superpuestos por medico.
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE roles (
    id_rol INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL UNIQUE,
    descripcion VARCHAR(150)
);

CREATE TABLE usuarios (
    id_usuario INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_rol INTEGER NOT NULL REFERENCES roles(id_rol),
    nombre_usuario VARCHAR(60) NOT NULL UNIQUE,
    correo VARCHAR(150) UNIQUE,
    contrasena_hash VARCHAR(255) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pacientes (
    id_paciente INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_usuario INTEGER UNIQUE REFERENCES usuarios(id_usuario),
    ci VARCHAR(25) UNIQUE,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo VARCHAR(25),
    telefono VARCHAR(30) NOT NULL,
    correo VARCHAR(150),
    direccion VARCHAR(255),
    tipo_sangre VARCHAR(5),
    alergias TEXT,
    antecedentes TEXT,
    contacto_emergencia VARCHAR(150),
    telefono_emergencia VARCHAR(30),
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medicos (
    id_medico INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_usuario INTEGER UNIQUE REFERENCES usuarios(id_usuario),
    registro_profesional VARCHAR(50) NOT NULL UNIQUE,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    telefono VARCHAR(30),
    correo VARCHAR(150),
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE especialidades (
    id_especialidad INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    activo BOOLEAN NOT NULL DEFAULT TRUE
);

-- No permite nombres duplicados sin importar mayusculas/minusculas o espacios laterales.
CREATE UNIQUE INDEX uq_especialidades_nombre ON especialidades (LOWER(TRIM(nombre)));

CREATE TABLE medico_especialidad (
    id_medico INTEGER NOT NULL REFERENCES medicos(id_medico),
    id_especialidad INTEGER NOT NULL REFERENCES especialidades(id_especialidad),
    PRIMARY KEY (id_medico, id_especialidad)
);

CREATE TABLE horarios (
    id_horario INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_medico INTEGER NOT NULL REFERENCES medicos(id_medico),
    dia_semana SMALLINT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_minutos SMALLINT NOT NULL DEFAULT 15 CHECK (duracion_minutos > 0),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT ck_horarios_horas CHECK (hora_fin > hora_inicio)
);

-- Evita que un mismo medico tenga horarios semanales activos que se solapen.
ALTER TABLE horarios ADD CONSTRAINT ex_horarios_medico
    EXCLUDE USING gist (
        id_medico WITH =,
        dia_semana WITH =,
        tsrange(DATE '2000-01-03' + hora_inicio, DATE '2000-01-03' + hora_fin, '[)') WITH &&
    ) WHERE (activo);

CREATE TABLE citas (
    id_cita INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo VARCHAR(25) NOT NULL UNIQUE,
    id_paciente INTEGER NOT NULL REFERENCES pacientes(id_paciente),
    id_medico INTEGER NOT NULL,
    id_especialidad INTEGER NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo VARCHAR(255),
    estado VARCHAR(20) NOT NULL DEFAULT 'programada',
    motivo_cancelacion TEXT,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cita_medico_especialidad FOREIGN KEY (id_medico, id_especialidad)
        REFERENCES medico_especialidad(id_medico, id_especialidad),
    CONSTRAINT ck_cita_horas CHECK (hora_fin > hora_inicio),
    CONSTRAINT ck_cita_estado CHECK (estado IN ('programada', 'confirmada', 'atendida', 'cancelada', 'inasistencia'))
);

-- Exclusion atomica: dos reservas vigentes del mismo medico no pueden solaparse,
-- incluso si se guardan desde dos ventanas al mismo tiempo.
ALTER TABLE citas ADD CONSTRAINT ex_citas_medico_sin_superposicion
    EXCLUDE USING gist (
        id_medico WITH =,
        tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&
    ) WHERE (estado IN ('programada', 'confirmada'));

CREATE INDEX ix_citas_fecha ON citas(fecha);
CREATE INDEX ix_citas_paciente_fecha ON citas(id_paciente, fecha);
CREATE INDEX ix_citas_medico_fecha ON citas(id_medico, fecha);

INSERT INTO roles (nombre, descripcion) VALUES
    ('administrador', 'Administra usuarios, medicos, especialidades y reportes'),
    ('recepcionista', 'Registra pacientes y gestiona citas'),
    ('medico', 'Consulta su agenda y gestiona la atencion autorizada'),
    ('paciente', 'Consulta su perfil y sus citas');

INSERT INTO especialidades (nombre, descripcion) VALUES
    ('Pediatria', 'Atencion medica de ninos y adolescentes'),
    ('Medicina general', 'Atencion primaria y consultas generales'),
    ('Geriatria', 'Atencion de adultos mayores');

COMMIT;

-- COMPROBACION (ejecutar despues de crear las tablas):
-- SELECT table_name FROM information_schema.tables
-- WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
-- ORDER BY table_name;
