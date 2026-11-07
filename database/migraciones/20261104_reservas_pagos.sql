-- Incremental, una sola vez, mediante psql -X -v ON_ERROR_STOP=1 -f ...
-- Revisar y respaldar antes de aplicar. No recrea tablas ni inventa tarifas.
BEGIN;
SET LOCAL lock_timeout = '15s';
LOCK TABLE citas IN SHARE ROW EXCLUSIVE MODE;

CREATE TABLE tarifas_consulta (
    id_medico INTEGER NOT NULL,
    id_especialidad INTEGER NOT NULL,
    importe NUMERIC(12,2) NOT NULL CHECK (importe > 0),
    moneda VARCHAR(3) NOT NULL DEFAULT 'BOB' CHECK (moneda = 'BOB'),
    actualizado_por INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (id_medico, id_especialidad),
    FOREIGN KEY (id_medico, id_especialidad) REFERENCES medico_especialidad(id_medico, id_especialidad)
);

CREATE TABLE historial_tarifas (
    id_historial BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_medico INTEGER NOT NULL,
    id_especialidad INTEGER NOT NULL,
    importe_anterior NUMERIC(12,2),
    importe_nuevo NUMERIC(12,2) NOT NULL CHECK (importe_nuevo > 0),
    responsable INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (id_medico, id_especialidad) REFERENCES medico_especialidad(id_medico, id_especialidad)
);

CREATE TABLE reservas_temporales (
    id_reserva INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo VARCHAR(25) NOT NULL UNIQUE,
    id_paciente INTEGER NOT NULL REFERENCES pacientes(id_paciente),
    creado_por INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    id_medico INTEGER NOT NULL,
    id_especialidad INTEGER NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL CHECK (hora_fin > hora_inicio),
    estado VARCHAR(15) NOT NULL DEFAULT 'activa'
        CHECK (estado IN ('activa','convertida','fallida','vencida','cancelada')),
    vence_en TIMESTAMPTZ NOT NULL,
    id_cita INTEGER UNIQUE REFERENCES citas(id_cita) DEFERRABLE INITIALLY DEFERRED,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (id_medico, id_especialidad) REFERENCES medico_especialidad(id_medico, id_especialidad),
    CHECK ((estado = 'convertida') = (id_cita IS NOT NULL))
);
CREATE INDEX ix_reservas_vencimiento ON reservas_temporales(vence_en) WHERE estado = 'activa';

CREATE TABLE pagos (
    id_pago INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    referencia VARCHAR(40) NOT NULL UNIQUE,
    id_cita INTEGER UNIQUE REFERENCES citas(id_cita),
    id_reserva INTEGER UNIQUE REFERENCES reservas_temporales(id_reserva),
    id_paciente INTEGER NOT NULL REFERENCES pacientes(id_paciente),
    creado_por INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    importe NUMERIC(12,2) NOT NULL CHECK (importe > 0),
    moneda VARCHAR(3) NOT NULL DEFAULT 'BOB' CHECK (moneda = 'BOB'),
    modalidad VARCHAR(12) NOT NULL CHECK (modalidad IN ('presencial','demo')),
    es_simulado BOOLEAN GENERATED ALWAYS AS (modalidad = 'demo') STORED,
    metodo VARCHAR(20) CHECK (metodo IN ('efectivo','qr_recepcion','demostracion')),
    estado VARCHAR(25) NOT NULL DEFAULT 'pendiente' CHECK (estado IN
        ('pendiente','procesando','pagado','fallido','vencido','anulado','reembolso_pendiente','reembolsado')),
    referencia_cobro VARCHAR(120) UNIQUE,
    responsable_cobro INTEGER REFERENCES usuarios(id_usuario),
    idempotencia VARCHAR(64) NOT NULL UNIQUE CHECK (idempotencia ~ '^[a-f0-9]{64}$'),
    huella_solicitud VARCHAR(64) NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    confirmado_en TIMESTAMPTZ,
    CHECK (id_cita IS NOT NULL OR id_reserva IS NOT NULL),
    CHECK (modalidad <> 'presencial' OR (id_cita IS NOT NULL AND id_reserva IS NULL)),
    CHECK (modalidad <> 'demo' OR id_reserva IS NOT NULL),
    CHECK (metodo IS NULL OR (modalidad = 'demo') = (metodo = 'demostracion')),
    CHECK ((estado IN ('pagado','reembolso_pendiente','reembolsado')) = (confirmado_en IS NOT NULL)),
    CHECK (confirmado_en IS NULL OR (metodo IS NOT NULL AND referencia_cobro IS NOT NULL AND responsable_cobro IS NOT NULL))
);
CREATE INDEX ix_pagos_paciente ON pagos(id_paciente, creado_en DESC);
CREATE INDEX ix_pagos_estado ON pagos(estado, creado_en);

CREATE TABLE intentos_pago (
    id_intento BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_pago INTEGER NOT NULL REFERENCES pagos(id_pago),
    proveedor VARCHAR(25) NOT NULL CHECK (proveedor = 'demostracion'),
    referencia_externa VARCHAR(120) NOT NULL UNIQUE,
    resultado VARCHAR(15) NOT NULL CHECK (resultado IN ('exitoso','fallido','pendiente','vencido')),
    idempotencia VARCHAR(64) NOT NULL UNIQUE CHECK (idempotencia ~ '^[a-f0-9]{64}$'),
    responsable INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE movimientos_pago (
    id_movimiento BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_pago INTEGER NOT NULL REFERENCES pagos(id_pago),
    tipo VARCHAR(30) NOT NULL CHECK (tipo IN ('creacion','pendiente','cobro','cobro_tardio',
        'fallo','vencimiento','cancelacion','solicitud_reembolso','reembolso',
        'rechazo_reembolso','reprogramacion')),
    estado_anterior VARCHAR(25),
    estado_nuevo VARCHAR(25) NOT NULL,
    importe NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (importe >= 0),
    referencia VARCHAR(120),
    responsable INTEGER REFERENCES usuarios(id_usuario),
    clave_evento VARCHAR(160) NOT NULL UNIQUE,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    CHECK ((tipo IN ('cobro','cobro_tardio','reembolso') AND importe > 0)
        OR (tipo NOT IN ('cobro','cobro_tardio','reembolso') AND importe = 0))
);
CREATE UNIQUE INDEX uq_movimiento_cobro ON movimientos_pago(id_pago) WHERE tipo IN ('cobro','cobro_tardio');
CREATE UNIQUE INDEX uq_movimiento_reembolso ON movimientos_pago(id_pago) WHERE tipo = 'reembolso';
CREATE UNIQUE INDEX uq_reembolso_referencia ON movimientos_pago(referencia) WHERE tipo = 'reembolso' AND referencia IS NOT NULL;
CREATE INDEX ix_movimientos_fecha ON movimientos_pago(creado_en, id_pago);

-- Una sola exclusión para citas Y reservas: no existe una ventana entre tablas.
-- La exclusión original de citas y la de horarios se conservan intactas.
CREATE TABLE bloqueos_agenda (
    id_bloqueo BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_cita INTEGER UNIQUE REFERENCES citas(id_cita),
    id_reserva INTEGER UNIQUE REFERENCES reservas_temporales(id_reserva),
    id_medico INTEGER NOT NULL REFERENCES medicos(id_medico),
    intervalo TSRANGE NOT NULL CHECK (NOT isempty(intervalo)),
    activo BOOLEAN NOT NULL,
    CHECK ((id_cita IS NULL) <> (id_reserva IS NULL)),
    EXCLUDE USING gist (id_medico WITH =, intervalo WITH &&) WHERE (activo)
);
INSERT INTO bloqueos_agenda(id_cita, id_medico, intervalo, activo)
SELECT id_cita, id_medico, tsrange(fecha + hora_inicio, fecha + hora_fin, '[)'),
    estado IN ('programada','confirmada') FROM citas;

CREATE FUNCTION cvs_expirar_reservas() RETURNS VOID LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE r RECORD;
BEGIN
    -- Serialización explícita de escrituras de agenda, adecuada al alcance académico.
    PERFORM pg_advisory_xact_lock(1129729107, 701);
    FOR r IN UPDATE reservas_temporales SET estado = 'vencida', actualizado_en = clock_timestamp()
        WHERE estado = 'activa' AND (vence_en <= clock_timestamp()
            OR fecha + hora_inicio <= timezone('America/La_Paz', clock_timestamp()))
        RETURNING id_reserva
    LOOP
        UPDATE bloqueos_agenda SET activo = FALSE WHERE id_reserva = r.id_reserva;
        WITH cambio AS (
            UPDATE pagos SET estado = 'vencido', actualizado_en = clock_timestamp()
            WHERE id_reserva = r.id_reserva AND estado IN ('pendiente','procesando') RETURNING id_pago
        ) INSERT INTO movimientos_pago(id_pago,tipo,estado_nuevo,clave_evento)
          SELECT id_pago,'vencimiento','vencido','vencimiento:' || r.id_reserva FROM cambio
          ON CONFLICT (clave_evento) DO NOTHING;
    END LOOP;
END;
$$;

CREATE FUNCTION cvs_guardia_agenda() RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM cvs_expirar_reservas();
    RETURN NULL;
END;
$$;
CREATE TRIGGER cvs_guardia_citas BEFORE INSERT OR UPDATE ON citas
    FOR EACH STATEMENT EXECUTE FUNCTION cvs_guardia_agenda();
CREATE TRIGGER cvs_guardia_reservas BEFORE INSERT ON reservas_temporales
    FOR EACH STATEMENT EXECUTE FUNCTION cvs_guardia_agenda();

CREATE FUNCTION cvs_bloqueo_cita() RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    INSERT INTO bloqueos_agenda(id_cita,id_medico,intervalo,activo)
    VALUES (NEW.id_cita,NEW.id_medico,tsrange(NEW.fecha+NEW.hora_inicio,NEW.fecha+NEW.hora_fin,'[)'),
        NEW.estado IN ('programada','confirmada'))
    ON CONFLICT (id_cita) DO UPDATE SET id_medico=EXCLUDED.id_medico,
        intervalo=EXCLUDED.intervalo, activo=EXCLUDED.activo;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cvs_bloqueo_cita AFTER INSERT OR UPDATE ON citas FOR EACH ROW EXECUTE FUNCTION cvs_bloqueo_cita();

CREATE FUNCTION cvs_bloqueo_reserva() RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(1129729107, 701);
    INSERT INTO bloqueos_agenda(id_reserva,id_medico,intervalo,activo)
    VALUES (NEW.id_reserva,NEW.id_medico,tsrange(NEW.fecha+NEW.hora_inicio,NEW.fecha+NEW.hora_fin,'[)'),NEW.estado='activa')
    ON CONFLICT (id_reserva) DO UPDATE SET activo=EXCLUDED.activo,
        id_medico=EXCLUDED.id_medico, intervalo=EXCLUDED.intervalo;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cvs_bloqueo_reserva AFTER INSERT OR UPDATE ON reservas_temporales
    FOR EACH ROW EXECUTE FUNCTION cvs_bloqueo_reserva();

CREATE FUNCTION cvs_finanzas_cita() RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
DECLARE p RECORD; siguiente TEXT; actor INTEGER;
BEGIN
    actor := NULLIF(current_setting('cvs.actor_id', TRUE),'')::INTEGER;
    SELECT * INTO p FROM pagos WHERE id_cita = NEW.id_cita FOR UPDATE;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF NEW.estado = 'cancelada' AND OLD.estado <> 'cancelada' THEN
        siguiente := CASE WHEN p.estado IN ('pagado','reembolso_pendiente') THEN 'reembolso_pendiente'
            WHEN p.estado IN ('pendiente','procesando') THEN 'anulado' ELSE p.estado END;
        UPDATE pagos SET estado=siguiente, actualizado_en=clock_timestamp() WHERE id_pago=p.id_pago;
        INSERT INTO movimientos_pago(id_pago,tipo,estado_anterior,estado_nuevo,responsable,clave_evento)
        VALUES (p.id_pago,'cancelacion',p.estado,siguiente,actor,'cancelacion:' || NEW.id_cita);
        IF siguiente='reembolso_pendiente' THEN
            INSERT INTO movimientos_pago(id_pago,tipo,estado_anterior,estado_nuevo,responsable,clave_evento)
            VALUES (p.id_pago,'solicitud_reembolso',p.estado,siguiente,actor,'solicitud_cancelacion:' || NEW.id_cita);
        END IF;
    ELSIF (NEW.fecha,NEW.hora_inicio,NEW.hora_fin) IS DISTINCT FROM (OLD.fecha,OLD.hora_inicio,OLD.hora_fin) THEN
        INSERT INTO movimientos_pago(id_pago,tipo,estado_anterior,estado_nuevo,referencia,responsable,clave_evento)
        VALUES (p.id_pago,'reprogramacion',p.estado,p.estado,
            OLD.fecha || ' ' || OLD.hora_inicio || ' > ' || NEW.fecha || ' ' || NEW.hora_inicio,
            actor,'reprogramacion:' || NEW.id_cita || ':' || txid_current());
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cvs_finanzas_cita AFTER UPDATE ON citas FOR EACH ROW EXECUTE FUNCTION cvs_finanzas_cita();

CREATE FUNCTION cvs_pago_inmutable() RETURNS TRIGGER LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'No se eliminan registros financieros'; END IF;
    IF (NEW.importe,NEW.moneda,NEW.modalidad,NEW.id_paciente,NEW.creado_por,NEW.id_reserva,
        NEW.idempotencia,NEW.huella_solicitud,NEW.referencia,NEW.creado_en)
        IS DISTINCT FROM (OLD.importe,OLD.moneda,OLD.modalidad,OLD.id_paciente,OLD.creado_por,OLD.id_reserva,
        OLD.idempotencia,OLD.huella_solicitud,OLD.referencia,OLD.creado_en) THEN
        RAISE EXCEPTION 'El acuerdo financiero es inmutable';
    END IF;
    IF OLD.id_cita IS NOT NULL AND NEW.id_cita IS DISTINCT FROM OLD.id_cita THEN
        RAISE EXCEPTION 'La cita financiera es inmutable';
    END IF;
    IF OLD.confirmado_en IS NOT NULL AND
        (NEW.confirmado_en,NEW.metodo,NEW.referencia_cobro,NEW.responsable_cobro)
        IS DISTINCT FROM (OLD.confirmado_en,OLD.metodo,OLD.referencia_cobro,OLD.responsable_cobro) THEN
        RAISE EXCEPTION 'El cobro original es inmutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cvs_pago_inmutable BEFORE UPDATE OR DELETE ON pagos FOR EACH ROW EXECUTE FUNCTION cvs_pago_inmutable();

CREATE FUNCTION cvs_historial_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'El historial financiero es de solo adicion'; END;
$$;
CREATE TRIGGER cvs_movimiento_inmutable BEFORE UPDATE OR DELETE ON movimientos_pago
    FOR EACH ROW EXECUTE FUNCTION cvs_historial_inmutable();
CREATE TRIGGER cvs_intento_inmutable BEFORE UPDATE OR DELETE ON intentos_pago
    FOR EACH ROW EXECUTE FUNCTION cvs_historial_inmutable();
CREATE TRIGGER cvs_tarifa_historial_inmutable BEFORE UPDATE OR DELETE ON historial_tarifas
    FOR EACH ROW EXECUTE FUNCTION cvs_historial_inmutable();

COMMIT;
