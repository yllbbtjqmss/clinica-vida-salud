# Lumi · catálogo real con respuestas locales (etapa 4B)

## Alcance

Lumi responde con datos actuales de PostgreSQL sobre **especialidades y médicos
activos** del catálogo, mediante consultas PHP estrictamente de lectura, incluso
cuando Gemini está desactivada (por ejemplo, en el servidor `8012` con
`CVS_AI_ENABLED=0`).

Ejemplos admitidos:

- «¿Qué especialidades ofrece la clínica?» — lista de especialidades activas.
- «¿Qué médicos atienden Cardiología?» — profesionales activos asociados a la
  especialidad en `medico_especialidad`.
- «¿Existe la especialidad X?» — confirmación del nombre registrado.
- «¿Cómo elijo especialidad y médico?» y «¿Qué sigue después de seleccionar un
  médico?» — guía textual del formulario de reservas, sin consultar catálogo.

Nada inventado: sin especialidades, médicos, horarios, precios ni citas libres.
Que un profesional figure en el catálogo **no** confirma disponibilidad; los
bloques reales se consultan en el formulario autorizado, no en el chat.

## Límites de datos

Solo tres tablas: `public.especialidades`, `public.medicos` y
`public.medico_especialidad`. Proyecciones públicas mínimas: nombre de
especialidad y nombre completo del profesional. **Nunca** se leen
`id_usuario`, registro profesional, contacto, pacientes, usuarios, citas, pagos,
horarios ni tarifas. Nunca se ejecuta SQL aportado por el cliente ni por Gemini.

Los endpoints del módulo de reservas **no se reutilizan**: pueden escribir
vencimientos (`cvp_expirar()`) incluso en solicitudes GET.

La coincidencia de especialidad es exacta primero (minúsculas y espacios); se
acepta sin tildes solo si no hubo coincidencia exacta. Con dos coincidencias,
Lumi pide el nombre exacto en vez de elegir una.

## Por qué respuestas locales

- **Privacidad**: los turnos de catálogo no se envían a Gemini ni a Google y
  quedan fuera del contexto del proveedor (`lumi_conversacion_contexto()`)
  a pesar de guardarse en el historial de sesión.
- **Disponibilidad**: el chat sigue útil sin clave de IA; en ese caso el panel
  muestra «Modo local: catálogo y guía disponibles; Gemini no está disponible».
- **Antes de IA**: el catálogo se resuelve antes de `lumi_resolver_guia()` y de
  cualquier llamada al proveedor.

## Mensajes de estado

- Catálogo vacío o sin médicos asociados: `situacion: sin_datos`, sin inventar
  resultados.
- Fallo de PostgreSQL: `situacion: no_verificado`, mensaje genérico sin
  detalles internos.
- Consulta sobre disponibilidad: aviso de que el catálogo no confirma citas
  libres, horarios ni precios.
- Consulta con datos privados: el filtro `lumi_validar_privacidad()` conserva
  su mensaje («No se envió la consulta a Gemini…») y `lumi_api.php`
  responde 400.

## Archivos

- `includes/lumi_catalogo.php` — núcleo de la etapa: `lumi_resolver_catalogo()`,
  `lumi_catalogo_intencion()`, `lumi_catalogo_especialidades()`,
  `lumi_catalogo_medicos()`, `lumi_catalogo_buscar_especialidad()`,
  `lumi_catalogo_listar_texto()`; tope de `LUMI_CATALOGO_MAX_FILAS = 10` filas
  por respuesta con nota de lista parcial.
- `lumi_api.php` — `localDisponible` en `estado`, orden catálogo → guía → IA y
  continuador local cuando Gemini no está disponible.
- `assets/lumi.js` — `chatDisponible()` mantiene el envío habilitado con IA
  apagada; el aviso de modo local no retira micrófono ni altavoz.
- `includes/lumi.php` — carga del catálogo antes del resto de Lumi.

## Pruebas

- `tests/lumi_catalogo.php` — checklist unitario sin PostgreSQL: intención por
  rol, privacidad, tildes, ambigüedad, catálogo vacío, fallo seguro, límites y
  auditoría de que solo existe SQL preparado de lectura sobre las tres tablas.
- `tests/lumi_catalogo_dobles.php` — PDO ficticio con centinelas `PRIVADO_*`
  para verificar que ninguna columna sensible sale en las respuestas.
- `tests/lumi_catalogo.cjs` — prueba de navegador sobre el PHP temporal de
  `lumi_gemini_http.py` (cuatro roles, escritorio y móvil, texto, voz y
  continuidad; dictado revisable que no envía solo).
- `tests/lumi_catalogo_postgres.php` — integración opt-in contra
  `cvs_prueba_reservas` real: exige `CVS_DB_HOST` local y
  `CVS_DB_NAME=cvs_prueba_reservas`, abre transacción
  `ISOLATION LEVEL REPEATABLE READ READ ONLY`, comprueba la ausencia de XID de
  escritura y cierra con `ROLLBACK`. No imprime registros ni llama a Gemini.

## Cómo validar en localhost:8012 con Gemini desactivado

1. Inicia sesión en `http://localhost:8012/login.php` con la cuenta de paciente
   de prueba de `cvs_prueba_reservas`.
2. Abre la esfera de Lumi en cualquier página autenticada; el aviso debe
   decir «Modo local: catálogo y guía disponibles; Gemini no está disponible»
   y el envío debe estar habilitado.
3. Pregunta «¿Qué especialidades ofrece la clínica?» y compara la lista con el
   selector del formulario en Reservar cita: debe coincidir y omitir las
   especialidades inactivas.
4. Pregunta «¿Qué médicos atienden [especialidad]?» y compara con el selector
   de médicos tras elegir esa especialidad. Con un nombre inexistente, la
   respuesta debe indicar la ausencia sin inventar.
5. Revisa que no aparezcan identificadores, registros profesionales,
   contactos, pacientes ni precios en el panel.
6. Pregunta «¿Cómo elijo especialidad y médico?» y «¿Qué sigue después de
   seleccionar un médico?»: Lumi debe dar la guía del formulario sin abrir
   páginas ni confirmar reservas.
7. Repite la prueba con cuentas de médico, recepción y administración: los
   enlaces del panel se filtran por rol.
8. Alternativa automatizada desde consola:
   `php.exe tests\lumi_catalogo_postgres.php` con `CVS_DB_*` apuntando a la
   base `cvs_prueba_reservas` local. Todo queda en transacción `READ ONLY`
   con `ROLLBACK`; no modifica datos.
