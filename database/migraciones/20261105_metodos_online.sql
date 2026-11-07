-- Aplicar UNA VEZ después de 20261104_reservas_pagos.sql. No reetiqueta cobros históricos.
BEGIN;
SET LOCAL lock_timeout = '15s';
ALTER TABLE pagos ADD COLUMN metodo_online VARCHAR(10)
    CHECK (metodo_online IN ('tarjeta','qr'));
ALTER TABLE pagos ADD CONSTRAINT ck_pago_metodo_online
    CHECK (metodo_online IS NULL OR modalidad = 'demo');

-- La columna metodo original y sus controles permanecen intactos.
CREATE FUNCTION cvs_metodo_online_inmutable() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.metodo_online IS DISTINCT FROM OLD.metodo_online THEN
        RAISE EXCEPTION 'El metodo en linea acordado es inmutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER cvs_metodo_online_inmutable BEFORE UPDATE ON pagos
    FOR EACH ROW EXECUTE FUNCTION cvs_metodo_online_inmutable();

CREATE TABLE solicitudes_pago_prueba (
    id_pago INTEGER PRIMARY KEY REFERENCES pagos(id_pago),
    solicitado_por INTEGER NOT NULL REFERENCES usuarios(id_usuario),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE TRIGGER cvs_solicitud_prueba_inmutable BEFORE UPDATE OR DELETE ON solicitudes_pago_prueba
    FOR EACH ROW EXECUTE FUNCTION cvs_historial_inmutable();
COMMIT;
