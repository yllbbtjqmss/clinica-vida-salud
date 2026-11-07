-- Etapa 1 del asistente virtual (LUMI): tabla de configuración única editable por administración.
-- Aplicar UNA VEZ, manualmente, con respaldo previo. No automatizar su ejecución en producción.
-- Alejar de pagos/citas: ninguna FK a tablas financieras y ninguna lectura de datos clínicos.
BEGIN;
SET LOCAL lock_timeout = '15s';

CREATE TABLE lumi_config (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    nombre VARCHAR(40) NOT NULL CHECK (length(btrim(nombre)) BETWEEN 2 AND 40),
    saludo VARCHAR(400) NOT NULL CHECK (length(btrim(saludo)) BETWEEN 5 AND 400),
    -- Personalidad e instrucciones base que el servidor envía como mensaje de sistema.
    tono TEXT NOT NULL CHECK (length(btrim(tono)) <= 1500),
    -- Tema visual reservado para la interfaz (clinica | oscuro). Etapa actual usa 'clinica'.
    tema VARCHAR(20) NOT NULL CHECK (tema IN ('clinica','oscuro')),
    -- Reservado para la etapa de voz; por ahora solo se almacena.
    voz VARCHAR(120) NOT NULL DEFAULT '',
    actualizado_por INTEGER REFERENCES usuarios(id_usuario),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

INSERT INTO lumi_config (id, nombre, saludo, tono, tema) VALUES (
    1,
    'Lumi',
    '¡Hola! Soy Lumi, tu asistente inteligente de Clínica Vida Salud. Estoy aquí para ayudarte con tus consultas y orientarte sobre nuestros servicios. ¿En qué puedo ayudarte hoy?',
    'Trato cercano, respetuoso y claro. Respuestas breves, sin tecnicismos innecesarios, siempre en español. Cercanía profesional sin exageraciones entusiastas ni exceso de disculpas.',
    'clinica'
);

COMMIT;
