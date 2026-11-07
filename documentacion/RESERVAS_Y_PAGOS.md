# Reservas y pagos — desarrollo posterior al Ciclo 2

**Fecha:** 04/11/2026 · **Rama local:** `feature/reservas-pagos`.

[Volver al README](../README.md#reservas-pagos) · [Evidencia ejecutada](pruebas-reservas-pagos.json) · [Bitácora](bitacoras/ciclos-1-y-2.md#desarrollo-posterior-al-ciclo-2-reservas-y-pagos)

El módulo está implementado para pruebas locales. **No se ha desplegado ni se han migrado las bases principales de XAMPP o Render.** Las pruebas automáticas crean sus propios datos ficticios en un clúster PostgreSQL nuevo, efímero y accesible únicamente por loopback.

> **Ajuste visual del 06/11:** el QR generado descrito en la entrega histórica del
> 05/11 se sustituyó por la ruta de imagen fija `assets/img/qr-pago-demo.png`.
> Falta guardar el PNG original adjunto al chat. Consultar
> [estado, archivos, pruebas y recorrido local](QR_PAGO_FIJO.md).
> No cambió la lógica financiera; la evidencia histórica se conserva sin sobrescribir.

## Actualización profesional — 05/11/2026

Esta actualización amplía la entrega del 04/11 sin sustituir su arquitectura. La [evidencia original de 40 casos](pruebas-reservas-pagos.json) se conserva. Los resultados nuevos se guardan por separado en [`pruebas-mejora-pagos.json`](pruebas-mejora-pagos.json).

### Modalidad, método y seguridad

- **Pago en línea → Tarjeta / QR** y **Pago presencial**. Los métodos en línea son opciones anidadas, no modalidades paralelas. Sin JavaScript también se puede enviar la selección; PHP vuelve a validarla.
- Internamente se conserva `modalidad='demo'` y `es_simulado` generado por PostgreSQL. `pagos.metodo` no cambia: sigue registrando `demostracion` al confirmar un cobro en línea. La nueva columna **`metodo_online`** distingue `tarjeta` y `qr` desde el acuerdo inicial y es inmutable. Los pagos históricos mantienen NULL, no se atribuyen a tarjeta o QR por inferencia.
- El **paciente no aprueba resultados**. Solo administración usa **Verificación de pagos → Registrar resultado**, con los permisos, CSRF, transacciones e idempotencia existentes. El cambio de lenguaje no representa una integración bancaria.
- Una sola marca discreta **«Entorno de pruebas · Sin transacciones bancarias»** aparece en cada pantalla/documento aplicable. Métodos y reportes también identifican los movimientos de prueba y mantienen libros separados.

### Tarjeta: instrumento sintético cerrado

Se muestran número `0000 0000 0000 0000`, titular `PACIENTE DE PRUEBA`, vencimiento `12/99` y código `000` oculto. **Son marcadores sintéticos, no una tarjeta bancaria válida ni un instrumento admitido por un proveedor.**

Los cuatro campos son **de solo lectura, sin atributo `name`**, con autocompletado desactivado. Esta decisión es deliberada: no se ofrece un formulario libre donde alguien pueda introducir un PAN/CVV auténtico. No se implementa validación Luhn para aceptar tarjetas bancarias. La comprobación cliente solo admite los valores predefinidos; una modificación por herramientas del navegador se rechaza visualmente.

El POST contiene exclusivamente `csrf`, `operacion`, `accion=solicitar_tarjeta` y `referencia_prueba=CVS-TARJETA-SINTETICA-V1`. La operación aleatoria se vincula en sesión al pago y usuario; la referencia fija solo identifica el instrumento de laboratorio, **no es una credencial ni autorización para cobrar**. PHP rechaza campos adicionales o referencias no admitidas sin conservarlos ni registrarlos. Una petición maliciosa puede ignorar JavaScript, pero como máximo puede solicitar procesamiento de su propia operación: nunca aprobarla.

`cvp_solicitar_tarjeta()` crea una única fila en `solicitudes_pago_prueba` y un movimiento de procesamiento con responsable. No guarda campos del instrumento. El pago cambia a `procesando`, sin crear cita. Para registrar aprobación/rechazo de tarjeta, el administrador debe encontrar primero esa solicitud. La aprobación oportuna crea la cita confirmada; el rechazo libera el bloqueo; el éxito tardío sigue la revisión de devolución existente. Los reenvíos no crean cobros ni solicitudes duplicados.

### QR local y escaneable

Librería de producción: **qrcode-generator 1.4.4, Kazuhiko Arase, licencia MIT**, incluida sin modificaciones en [`assets/vendor/qrcode-generator/`](../assets/vendor/qrcode-generator/README.md), fijada al commit `9bd2163ddc1628d1ec8ff22ea288a747275ef442`. No hay CDN ni API externa. `assets/pagos.js` genera un SVG nítido con corrección M y margen de cuatro módulos o superior.

Cada QR contiene únicamente **`CVS:PRUEBA:PAGO-<24 caracteres hexadecimales>`**, derivado de la referencia aleatoria única del pago. No es una URL pública ni contiene nombres, importes, horarios, cuenta bancaria, token de sesión o autorización. Un lector muestra esa referencia; **escanear no cobra, no inicia sesión ni confirma nada**. La pantalla indica discretamente que es QR de referencia, no de transferencia.

El checkout presenta clínica, importe, referencia, consulta, médico/especialidad, plazo y estado pendiente de verificación. El contador usa la hora devuelta por PostgreSQL y un reloj monotónico del navegador, solo como ayuda visual; la caducidad sigue decidiéndose en servidor. Actualizar estado consulta el resultado autorizado. La opción «Regresar al resumen» vuelve al resumen de la misma operación, sin generar otro bloqueo ni alterar su acuerdo.

### Interfaz, documentos y reportes

Fechas visibles `04/11/2026, 16:09` en `America/La_Paz`, fechas de consulta `04/11/2026` y horas `16:09`. El almacenamiento conserva toda su precisión. Los importes se muestran como **`Bs 100,00`**, sin sufijo BOB repetido. La moneda se explica en los encabezados correspondientes.

Checkout centrado, campos uniformes, tarjetas anidadas, estado de envío y mensajes naturales. Las tablas aprovechan el ancho del escritorio y conservan desplazamiento **dentro de su contenedor** en móvil, sin ocultar columnas. Fichas/comprobantes mantienen impresión, descarga HTML autocontenida, identificación no fiscal, datos de consulta y estados separados; añaden método preciso, fecha del cobro y responsable. No se atribuyen documentos a bancos.

Los reportes incorporan filtros **fecha, especialidad, médico y estado de pago**. Los filtros se aplican tanto al libro de movimientos como a los saldos actuales; no son filtros del estado clínico. Se incluyen profesionales/especialidades inactivos para no ocultar historia. La agrupación por método distingue tarjeta, QR en línea, efectivo presencial, QR presencial verificado e histórico en línea sin método específico. Se mantienen bruto, pendiente, devoluciones y neto. Los intentos y solicitudes no se suman como ingreso.

**Archivos de esta mejora:** nuevos `assets/pagos.js`, `assets/vendor/qrcode-generator/{qrcode.js,LICENSE,README.md}`, `database/migraciones/20261105_metodos_online.sql`, `tests/pagos_navegador.cjs` y `documentacion/pruebas-mejora-pagos.json`. Se amplían las seis pantallas del módulo (`reservar_cita.php`, `pago.php`, `pagos.php`, `ficha_reserva.php`, `tarifas.php`, `reportes_financieros.php`), los helpers `includes/reservas_pagos.php`, `includes/pagos_ui.php`, `includes/reportes_pagos.php`, `assets/reservas.js`, `assets/estilos.css`, el precio visible de `citas.php`, el esquema de instalación, los dos archivos de pruebas existentes y README/guía/bitácora. El adaptador y la migración originales se conservan.

### Migración y comprobación del estado

Nueva migración: [`database/migraciones/20261105_metodos_online.sql`](../database/migraciones/20261105_metodos_online.sql), **después de `20261104_reservas_pagos.sql`**. Agrega `pagos.metodo_online`, restricciones y trigger de inmutabilidad, más `solicitudes_pago_prueba` con una fila por pago y protección contra edición/borrado. No modifica la migración anterior ni reetiqueta cobros existentes.

Antes de aplicar nada, comprobar explícitamente la conexión y el esquema **en `cvs_prueba_reservas`**:

```powershell
psql -X -h 127.0.0.1 -p 5432 -U postgres -d cvs_prueba_reservas -W -v ON_ERROR_STOP=1 -c "SELECT current_database(), inet_server_addr(), inet_server_port(); SELECT to_regclass('public.pagos') AS pagos, to_regclass('public.solicitudes_pago_prueba') AS solicitudes; SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='pagos' AND column_name='metodo_online'; SELECT tgname FROM pg_trigger WHERE tgname='cvs_metodo_online_inmutable';"
```

| Resultado | Acción manual tras respaldo/revisión |
|---|---|
| No existe `pagos` | Si están las ocho tablas originales, aplicar primero 20261104 y luego 20261105 |
| Existe `pagos`, pero no columna, tabla de solicitudes ni trigger nuevos | Aplicar solamente 20261105 |
| Existen columna, tabla y trigger | No volver a aplicar la migración |
| Estado parcial o inesperado | Detenerse y revisar con el responsable; no borrar ni intentar reparar automáticamente |

Para una base existente con el módulo anterior, ejecutar manualmente:

```powershell
psql -X -h 127.0.0.1 -p 5432 -U postgres -d cvs_prueba_reservas -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261105_metodos_online.sql
```

El esquema de instalación nueva ya incluye **ambas** migraciones mediante `\ir`; no aplicarlas de nuevo después. La aplicación sigue permitiendo consultar/gestionar operaciones anteriores y reportes sobre el esquema del 04/11; los nuevos métodos requieren la segunda migración. No se ejecutó SQL sobre XAMPP principal ni Render.

### Cómo revisar localmente

Mantener el procedimiento aislado de la sección 11, apuntando explícitamente a `cvs_prueba_reservas`. No cambiar el archivo de conexión privada. Después de revisar/aplicar las migraciones necesarias:

1. Como paciente, elegir consulta, revisar resumen y seleccionar **Pago en línea → Tarjeta**. Revisar los datos sintéticos y pulsar **Pagar Bs …**. Debe quedar en procesamiento, no pagado.
2. En otra sesión de administrador, abrir esa operación y usar **Verificación de pagos** para aprobar o rechazar. Volver al paciente y actualizar. Una aprobación oportuna debe confirmar la cita y ofrecer comprobante.
3. Crear otra reserva con **Pago en línea → QR**. Escanear con un lector genérico, no una aplicación bancaria: solo debe mostrar `CVS:PRUEBA:PAGO-…`. El administrador verifica el resultado; no existe botón del paciente para aprobarlo.
4. Probar abandono/vencimiento, pago tardío y reenvíos. Revisar también efectivo, QR presencial, cancelaciones, reprogramaciones y los filtros económicos en los dos libros.
5. Revisar impresión/PDF y dispositivos reales con el equipo antes de publicar.

### Pruebas nuevas y herramientas

El runner conserva los casos anteriores y aplica ambas migraciones solamente a sus clústeres temporales. **No sobrescribir la evidencia histórica**: usar una ruta nueva.

```powershell
python tests\ejecutar_reservas_pagos.py --evidencia documentacion\pruebas-mejora-pagos.json
```

Opcionalmente, para reproducir las comprobaciones de navegador/decodificación, instalar **solo herramientas de prueba** fuera del repositorio:

```powershell
$herramientas = "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
npm.cmd install --prefix $herramientas playwright@1.58.2 jsqr@1.4.0 --no-audit --no-fund
python tests\ejecutar_reservas_pagos.py --evidencia documentacion\pruebas-mejora-pagos.json --browser-tools $herramientas
```

El test utiliza Edge/Chromium local si está en la ruta estándar; en otro entorno se necesita el navegador Chromium de Playwright (`playwright install chromium` desde esas herramientas). No es una dependencia de la aplicación. Las capturas contienen exclusivamente los datos ficticios del clúster y se guardan en la carpeta externa de herramientas, no en Git.

**Resultado ejecutado el 05/11/2026: 50 comprobaciones OK**, 45 de lógica/HTTP/migración/concurrencia y cinco de navegador (Edge/Chromium). Se verificó sintaxis de 42 PHP y de los JavaScript propios. La evidencia nueva identifica qué se ejecutó. Incluye migración compatible, lista cerrada de tarjeta, autorización y solicitudes idempotentes, rechazo y aprobación, QR dinámico decodificado **desde su SVG renderizado** por **jsQR** independiente, reportes por método/entorno/filtros, comprobantes y conservación del historial. Las pruebas de navegador cubren anchos 360, 390, 768 y 1440 px, tablas de escritorio sin desplazamiento innecesario, ausencia de desbordamiento global y aviso visible en el medio de impresión. Una ejecución sin `--browser-tools` indica expresamente que no corrió esas pruebas. No equivale a una prueba con cámara física, todos los navegadores o impresión/PDF real.

### Conexión bancaria futura

No hay pasarela contratada ni cobro real. Sustituir los marcadores de solo lectura por **campos alojados/tokenización del proveedor**, nunca por un formulario que envíe PAN/CVV a este PHP. Sustituir la referencia sintética por un identificador oficial validado y vinculado al pedido, con secretos fuera de Git, endpoints oficiales y validación de eventos/conciliación en servidor. El QR bancario deberá provenir de una respuesta auténtica del proveedor. Mantener importe/moneda, idempotencia, propiedad, vencimiento y revisión de pagos tardíos; separar inequívocamente sandbox/producción. Una redirección, captura, escaneo o modificación del cliente nunca constituye prueba de pago.

## 1. Alcance y decisiones

- Se conservan PHP procedimental, los puntos de entrada de la raíz, las funciones compartidas en `includes/` y la hoja visual existente. No se añade un framework, Composer ni npm.
- El paciente reserva para la ficha activa asociada a su sesión. Cualquier `id_paciente` enviado por ese rol se ignora; no se permite elegir otra persona.
- La tarifa pertenece a **médico–especialidad** y es un importe positivo `NUMERIC(12,2)` en BOB. No se cargan tarifas de ejemplo ni se valorizan retroactivamente citas anteriores. No hay consultas gratuitas, cuotas ni pagos parciales en esta versión.
- El importe del pago es una copia inmutable de la tarifa acordada. El resumen se conserva en la sesión del servidor; si la tarifa cambió antes de confirmar, se exige revisar nuevamente el precio. No se convierte dinero a `float` en PHP ni se toma un importe del navegador como importe a cobrar.
- El registro administrativo existente sigue disponible. Después de instalar el módulo, las nuevas citas registradas en `citas.php` requieren tarifa y generan una cuenta **presencial pendiente**, conservando el motivo opcional.
- La modalidad en línea utiliza únicamente un adaptador académico controlado por administración. **No hay integración bancaria ni transferencias reales.**

## 2. Archivos de implementación

| Archivo o grupo | Responsabilidad |
|---|---|
| [`reservar_cita.php`](../reservar_cita.php) | Selección, disponibilidad, resumen servidor y confirmación del paciente |
| [`tarifas.php`](../tarifas.php) | Configuración administrativa y consulta de tarifas por recepción; historial de cambios |
| [`pagos.php`](../pagos.php) | Listado por rol, filtros de creación/estado/método y paginación |
| [`pago.php`](../pago.php) | Estado, cobro presencial, simulador autorizado, revisión de devoluciones e historial |
| [`ficha_reserva.php`](../ficha_reserva.php) | Ficha y comprobante separados; impresión/PDF del navegador y descarga HTML autocontenida |
| [`reportes_financieros.php`](../reportes_financieros.php) | Indicadores y agrupaciones económicas exclusivas del administrador |
| [`includes/reservas_pagos.php`](../includes/reservas_pagos.php) | Autorización, importes exactos, disponibilidad, transacciones, reservas, cobros y devoluciones |
| [`includes/pasarela_demo.php`](../includes/pasarela_demo.php) | Contrato local y único adaptador disponible: demostración |
| [`includes/pagos_ui.php`](../includes/pagos_ui.php) | Marco visual, CSRF, formularios vinculados a sesión, resumen y errores seguros |
| [`includes/reportes_pagos.php`](../includes/reportes_pagos.php) | Consultas económicas sin mezclar intentos con ingresos |
| [`assets/reservas.js`](../assets/reservas.js), [`assets/estilos.css`](../assets/estilos.css) | Interfaz nativa, selección de turnos, respuesta a errores e impresión |
| `citas.php`, `cancelar_cita.php`, `reprogramar_cita.php`, `asistencia_citas.php` | Compatibilidad e integración del bloqueo transaccional con operaciones existentes |
| `includes/ui.php`, `includes/personal.php`, `includes/dashboard.php`, `mis_citas.php`, `mi_agenda.php`, `reportes.php` | Menú, estado administrativo del pago, disponibilidad compartida y enlaces económicos |

El Dockerfile existente ya copia los PHP de la raíz, `includes/` y `assets/`; no necesita copiar SQL, documentación ni pruebas. No se modificó el mecanismo de conexión privada ni las credenciales.

## 3. Migración y modelo de datos

**Migración exacta:** [`database/migraciones/20261104_reservas_pagos.sql`](../database/migraciones/20261104_reservas_pagos.sql).

| Tabla nueva | Datos y garantías principales |
|---|---|
| `tarifas_consulta` | PK médico–especialidad, importe positivo, BOB, FK a la asignación, responsable y fecha |
| `historial_tarifas` | Importe anterior/nuevo y responsable; solo adición |
| `reservas_temporales` | Paciente, creador, consulta, intervalo, vencimiento de 10 minutos, estado y cita resultante |
| `pagos` | Acuerdo monetario, modalidad, método, estado, referencias únicas, responsable del cobro y marcas temporales; una cuenta por cita/reserva |
| `intentos_pago` | Respuestas ficticias del proveedor de demostración, referencia externa y clave de idempotencia únicas |
| `movimientos_pago` | Libro de creación, cobro, fallo, vencimiento, cancelación, reprogramación y revisión/devolución; referencias y responsable, sin edición/borrado |
| `bloqueos_agenda` | Ocupación conjunta de citas y reservas temporales; exclusión GiST por médico e intervalo activo |

Las ocho tablas originales no se recrean ni reciben columnas nuevas. La migración inicial agrega siete tablas, índices, funciones y disparadores; la actualización del 05/11 añade una octava tabla del módulo (`solicitudes_pago_prueba`) y la columna `pagos.metodo_online`. La migración inicial copia **solo la ocupación de las citas existentes** a `bloqueos_agenda`; no modifica sus estados ni crea pagos o tarifas para ellas. Las FK nuevas conservan las asignaciones históricas utilizadas.

La migración es transaccional, se aplica **una vez** y usa un límite de espera de bloqueo de 15 segundos. Repetirla produce error y rollback, no borra ni reinicia datos. Se recomienda respaldar y aplicarla en una ventana sin escrituras concurrentes. No hay migrador automático en PHP, Docker o el arranque del servidor.

Para instalaciones nuevas, [`database/clinica_vida_salud.sql`](../database/clinica_vida_salud.sql) conserva la creación de las ocho tablas e incorpora al final la migración mediante **`\ir` de psql**. Debe ejecutarse con `psql`, no pegarse íntegro en un ejecutor que desconozca metacomandos. La base original y la extensión se crean en una transacción y el incremento en otra; usar `ON_ERROR_STOP=1` y revisar cualquier fallo. No aplicar después la migración por segunda vez.

## 4. Concurrencia y vencimientos

1. Todas las escrituras de agenda del módulo toman un candado transaccional común con `pg_advisory_xact_lock(1129729107, 701)` **antes** de bloquear filas. Esta serialización global es deliberada para el alcance académico; no es un diseño de alto rendimiento por médico.
2. PostgreSQL conserva intactas `ex_citas_medico_sin_superposicion` y `ex_horarios_medico`. Además, `bloqueos_agenda` tiene una exclusión común entre citas y reservas. No basta con consultar ambas tablas por separado.
3. Los disparadores sincronizan los bloqueos incluso si una cita se inserta o reprograma desde el código administrativo previo. Un conflicto revierte toda la transacción, incluido cualquier registro financiero asociado.
4. Antes de una escritura de citas o inserción de reserva se vencen los bloqueos caducados. Las consultas de reserva/pagos también actualizan los vencimientos; la disponibilidad descarta los vencidos. No hace falta cron, JavaScript, un worker ni mantener Render encendido.
5. La hora de referencia procede de `clock_timestamp()` de PostgreSQL; los intervalos clínicos se interpretan en `America/La_Paz`. La reserva también caduca si ya comenzó el turno, aunque aún no hayan transcurrido 10 minutos.
6. Aprobar una respuesta demo revalida paciente, oferta y horario activos. Se verifica el plazo al actualizar la reserva. La FK diferida desde la reserva a la cita permite liberar su bloqueo e insertar la cita dentro de la misma transacción, sin una ventana libre visible a otras operaciones.
7. Si la respuesta exitosa llega después del vencimiento, fallo, abandono o invalidación de la oferta, **no se crea otra cita**. El importe simulado recibido queda en `reembolso_pendiente`, con movimiento `cobro_tardio`, para revisión. Nunca se recupera un turno ya reasignado.

El candado común y las exclusiones se probaron con procesos y conexiones independientes; no se confía en el bloqueo de sesiones PHP para resolver concurrencia. La exclusión sigue siendo por **médico**, no impide citas simultáneas de un paciente con profesionales diferentes.

## 5. Estados separados

### Cita

Los cinco estados originales se conservan: `programada`, `confirmada`, `atendida`, `cancelada`, `inasistencia`.

- Presencial: se crea una cita `programada` y una cuenta `pendiente`.
- Demo: inicialmente solo existe una reserva temporal. La aprobación autorizada y oportuna crea una cita `confirmada` y marca el pago `pagado`, identificado permanentemente como simulado.
- Pagar no registra asistencia. El módulo original de asistencia sigue aplicando sus propias reglas temporales.

### Pago

| Estado | Significado |
|---|---|
| `pendiente` | Cuenta presencial por cobrar o reserva demo esperando respuesta |
| `procesando` | Respuesta demo pendiente; el vencimiento original no se prorroga |
| `pagado` | Cobro presencial verificado o simulación aprobada; la modalidad distingue ambos |
| `fallido` | Respuesta demo rechazada; no crea cita y libera el turno |
| `vencido` | Reserva temporal expirada; no crea cita |
| `anulado` | Cuenta pendiente cancelada o reserva abandonada, sin cobro; deja de considerarse deuda |
| `reembolso_pendiente` | Cobro que requiere revisión tras cancelación o respuesta tardía; no implica devolución ejecutada |
| `reembolsado` | Devolución completa registrada por un administrador tras confirmar su verificación |

La reserva temporal usa `activa`, `convertida`, `fallida`, `vencida` o `cancelada`. No se reutilizan estados clínicos para representar pagos. Las tablas financieras guardan creación, actualización y confirmación del cobro; las devoluciones se fechan en el libro de movimientos.

## 6. Operaciones por rol

| Rol | Operaciones |
|---|---|
| Administrador | Tarifas, citas, cobros, simulador, revisión de devoluciones, historial y reportes económicos |
| Recepcionista | Tarifas de lectura; registro de citas y cobros completos en efectivo o QR verificado; consulta de pagos de recepción; sin edición de importes ni autorización de devoluciones |
| Paciente | Reserva propia, selección de modalidad, consulta de citas/pagos, abandono de reserva temporal, ficha y comprobante propios |
| Médico | Agenda y estado administrativo del pago (`DEMO` cuando corresponde); sin importes, referencias, cobros ni acceso a las pantallas financieras |

### Flujo del paciente

1. Tener cuenta y ficha activa vinculada. Abrir **Reservar cita**.
2. Elegir especialidad activa y médico activo asignado. Las consultas sin tarifa se identifican y no permiten completar la reserva.
3. Seleccionar fecha y bloque disponible. La interfaz informa precio y moneda.
4. Revisar resumen: paciente, contacto básico, especialidad, médico, inicio, fin, duración e importe. El resumen aún no ocupa el turno.
5. Confirmar **Pagar en la clínica** o **Pagar en línea — demostración**. PHP vuelve a comprobar identidad, tarifa y disponibilidad; las restricciones resuelven el conflicto si alguien ocupó el bloque.
6. Consultar resultado en **Mis reservas y pagos** y acceder a ficha/impresión. Las reservas temporales aún sin cita se consultan allí, no se confunden con citas confirmadas en la agenda.

### Cobro presencial

En **Pagos y cobros → Ver ficha / pago**, recepción o administración selecciona efectivo o QR verificado. Debe confirmar que recibió y verificó **el importe completo**, y para QR indicar referencia. No basta una captura del paciente. Se guardan método, fecha, referencia y usuario responsable. Las referencias de cobro no se pueden reutilizar; la combinación de transacción, fila bloqueada e índice único permite un solo movimiento de cobro aunque se reenvíe el formulario o dos operadores actúen simultáneamente.

No se editan importes desde esa pantalla. Este registro es una declaración administrativa del operador, no una validación automática de un banco. No hay campo para cargar archivos de comprobantes: se conservan referencias y constancias generadas por el sistema, sin almacenar imágenes bancarias ni datos de tarjetas.

### Pago en línea de demostración

La pantalla identifica discretamente **Entorno de pruebas · Sin transacciones bancarias**. Presenta una referencia de prueba y, para QR, un código escaneable **no cobrable**. En otra sesión de administrador, abrir la misma operación y usar **Verificación de pagos** para registrar procesamiento, aprobación, rechazo o vencimiento. En tarjeta debe existir primero la solicitud sintética. El paciente puede actualizar la consulta o abandonar el bloqueo, pero no elegir el resultado del proveedor.

`CVS_PAGOS_DEMO` está habilitado por defecto para la presentación académica. `CVS_PAGOS_DEMO=0` desactiva nuevas reservas demo y su simulador; no convierte operaciones existentes en reales ni borra su historial. Las reservas ya creadas podrán vencer normalmente. No hay secretos de proveedor ni endpoints bancarios en `.env.example`.

## 7. Cancelación, reprogramación y devoluciones

- La reprogramación administrativa mantiene la misma cita y su cuenta, precio y cobro. Un disparador añade el cambio de horario al historial financiero sin duplicar ingresos. No cambia el médico/especialidad.
- Una cancelación sin cobro pasa la cuenta a `anulado` y conserva todos sus movimientos; ya no se puede cobrar desde recepción.
- Una cancelación pagada genera una solicitud `reembolso_pendiente`, pero no registra una devolución ni ejecuta transferencias.
- Solo un administrador puede completar una devolución. Debe confirmar su verificación externa e indicar referencia; si el pago era demo, la devolución también lo es. Se conserva el cobro original y se añade un único movimiento de devolución.
- El administrador puede rechazar la solicitud, dejando constancia y el importe cobrado sin devolver. Las devoluciones son completas; no hay devolución parcial ni reposición automática de citas.
- Las citas anteriores sin pago se siguen consultando, reprogramando y cancelando sin inventarles un importe histórico.

## 8. Reportes y documentos

**Reportes económicos** es una pantalla complementaria enlazada desde los reportes originales, no los sustituye. Se consulta un libro separado: presencial verificado (**predeterminado**) o demostración. Nunca se combinan para presentar ingresos reales.

- Cobrado bruto, devoluciones y neto proceden únicamente de movimientos monetarios dentro del período.
- Los intentos pendientes o fallidos y el número total de citas no son ingresos.
- El importe pendiente y el estado de devoluciones se muestran como estado actual de cuentas **creadas** dentro del rango; la pantalla distingue este criterio del período de cobros.
- Las agrupaciones por fecha, médico, especialidad y método muestran bruto, devuelto y neto. Una devolución pendiente aún no reduce el neto hasta registrarse como completada.

La **ficha de reserva** identifica consulta y estados, pero no acredita por sí sola un pago. El **comprobante administrativo** solo está disponible si existe cobro confirmado. Ambas vistas permiten imprimir o guardar PDF con el navegador. La ficha también se descarga como HTML autocontenido, con estilos y aviso demo cuando corresponde. No se generan facturas fiscales ni recibos bancarios auténticos.

## 9. Seguridad y límites

- Autorización en PHP y revalidación del rol/actividad dentro de las transacciones. Los documentos aplican la misma propiedad que el detalle de pago; no se autorizan IDs ajenos desde el navegador.
- CSRF en todas las mutaciones nuevas; formularios vinculados a la sesión y a la operación concreta; PRG y claves de idempotencia. El botón deshabilitado por JavaScript es solo una ayuda visual.
- Consultas preparadas, escape HTML, importes exactos y restricciones SQL. Acuerdos y cobros originales inmutables; libro e intentos de solo adición.
- Errores nuevos registran operación genérica y SQLSTATE, no cuerpos, contraseñas, cookies, tokens, datos bancarios o motivos clínicos. No se implementa almacenamiento de tarjetas/CVV ni contraseñas bancarias.
- Las cuentas deben usar un usuario PostgreSQL de ejecución con privilegios adecuados; el propietario/administrador del motor sigue pudiendo administrar el esquema. Los disparadores no pretenden proteger contra un DBA malicioso.
- Se conserva el límite de intentos de login por sesión existente; no se añade aquí un control centralizado de abuso por IP/cuenta, una auditoría de toda la aplicación ni un sistema clínico productivo.

## 10. Ejecutar las pruebas automáticas seguras

Desde la raíz, con Python 3, PHP 8.2 de XAMPP y binarios PostgreSQL locales:

```powershell
python tests\ejecutar_reservas_pagos.py --php "C:\xampp\php\php.exe" --pg-bin "C:\Program Files\PostgreSQL\18\bin" --evidencia documentacion\pruebas-mejora-pagos.json
```

El runner no lee `C:/xampp/config_clinica/database.php`, descarta destinos PostgreSQL heredados y crea un clúster en una carpeta aleatoria `cvs-pagos-*` bajo `%LOCALAPPDATA%\Temp\opencode`. Usa `127.0.0.1`, puerto aleatorio distinto de 5432, bases `cvs_test_pagos` y `cvs_test_incremental`, cuentas ficticias y clave generada en memoria. Arranca PHP solo en loopback; al terminar detiene y elimina **su propio clúster temporal**. No usar esos nombres para datos que se necesiten conservar. Los PHP de pruebas rechazan HTTP y entornos ajenos al runner.

Se probaron instalación nueva, migración sobre una cita preexistente, lógica, acceso HTTP y concurrencia con procesos/conexiones independientes. La evidencia JSON no conserva contraseñas, tokens, cookies ni datos de pacientes. El runner devuelve salida no cero si falla algún caso. No es una carga de datos para la base local principal.

### Cobertura ejecutada

| Requisito solicitado | Evidencia automática |
|---|---|
| 1. Reserva propia | P01, H05 |
| 2. Precio validado por servidor | P02, P13, P23, H05 |
| 3. Presencial pendiente | P03, H05 |
| 4. Cobro de recepción | P04, H06 |
| 5. Evitar doble cobro | P05, C02, H06 |
| 6. Éxito simulado autorizado | P06, H07 |
| 7. Confirmación de cita tras aprobar | P07, H07 |
| 8. Fallo sin confirmación | P08 |
| 9. Vencimiento libera turno | P09, P19 |
| 10. Sin doble reserva concurrente | P10, P25, C01, C03 |
| 11. Aislamiento de pagos | P11, H02 |
| 12. Médico sin cobros | P12, H01 |
| 13. Administrador cambia tarifas futuras | P13, P23 |
| 14. Compatibilidad de citas anteriores | M01, P14, H04, H08 |
| 15. Cancelación/reprogramación preservan finanzas | P15, P20, H09 |
| 16. Importes consistentes en reportes | P16 |
| 17. Constancias reales/demo diferenciadas | P17, H10; contenido HTML verificado, impresión física pendiente |

Pruebas adicionales: cobro tardío sin recuperar turno (P18), referencias/historial (P21), oferta inválida (P22), perfil desactivado durante la espera (P24), aprobación repetida con claves distintas (P26), CSRF (H03) y páginas originales/ampliadas (H04).

**Resultado ejecutado el 04/11/2026: 40 casos OK**, conservados en [el JSON](pruebas-reservas-pagos.json). También se comprobaron sintaxis de 42 archivos PHP y sintaxis JavaScript con Node. Esto no sustituye pruebas visuales, carga sostenida, un ensayo concurrente de cada cambio de catálogo ni una auditoría de seguridad completa.

## 11. Prueba manual con PHP de XAMPP, sin cambiar la base actual

### A. Base nueva y separada

Los siguientes comandos son para que el equipo los revise y ejecute **manualmente**; no fueron ejecutados contra el PostgreSQL principal. Suponen `psql` en `PATH`, puerto local 5432 y un usuario instalador `postgres`. Ajustar esos parámetros a la instalación propia. No usar el nombre de la base principal:

```powershell
psql -X -h 127.0.0.1 -p 5432 -U postgres -d postgres -W -v ON_ERROR_STOP=1 -c "CREATE DATABASE cvs_prueba_reservas;"
psql -X -h 127.0.0.1 -p 5432 -U postgres -d cvs_prueba_reservas -W -v ON_ERROR_STOP=1 -f .\database\clinica_vida_salud.sql
```

Si la base de prueba **ya tiene las ocho tablas antiguas y aún no el módulo**, respaldarla y aplicar **solo**:

```powershell
psql -X -h 127.0.0.1 -p 5432 -U postgres -d cvs_prueba_reservas -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261104_reservas_pagos.sql
psql -X -h 127.0.0.1 -p 5432 -U postgres -d cvs_prueba_reservas -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261105_metodos_online.sql
```

No ejecutar el esquema original ni ambos procedimientos sobre una base ya ampliada. No usar `DROP`, `TRUNCATE` ni restaurar datos reales para demostrar este módulo.

### B. Conectar solo una consola temporal a esa base

Desde la raíz del proyecto, en una consola PowerShell sin transcripción, configurar variables **del proceso**, no del sistema. El usuario puede ser distinto de `postgres`; debe contar con permisos sobre la base de prueba:

```powershell
$env:CVS_DB_HOST = '127.0.0.1'
$env:CVS_DB_PORT = '5432'
$env:CVS_DB_NAME = 'cvs_prueba_reservas'
$env:CVS_DB_USER = Read-Host 'Usuario PostgreSQL de la base de prueba'
$env:CVS_DB_SSLMODE = 'disable'
$env:CVS_HTTPS = '0'
$env:CVS_PAGOS_DEMO = '1'
$secretoBD = Read-Host 'Contraseña PostgreSQL (no se mostrará)' -AsSecureString
$punteroBD = [IntPtr]::Zero
try {
    $punteroBD = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secretoBD)
    $env:CVS_DB_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($punteroBD)
} finally {
    if ($punteroBD -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($punteroBD) }
    $secretoBD = $null
}
```

El secreto queda en memoria del proceso; cerrar esa consola al terminar. No imprimir variables ni guardar este bloque con valores reales. No se modifica el archivo privado que utiliza el Apache habitual de XAMPP.

Crear el administrador inicial de **esta base de prueba** con el [procedimiento CLI existente del README](../README.md#administrador-inicial), manteniendo la misma consola configurada. El script rechaza reemplazar un administrador existente. Luego iniciar un servidor de prueba usando PHP de XAMPP:

```powershell
& "C:\xampp\php\php.exe" -S 127.0.0.1:8090 -t .
```

Abrir <http://127.0.0.1:8090/login.php>. Detener con `Ctrl+C` y cerrar la consola para descartar su entorno. Es un servidor de desarrollo, **no** una alternativa de producción a Apache/Docker.

### C. Si se requiere Apache de XAMPP

Para no redirigir el sitio local actual a otra base, usar un **VirtualHost de prueba separado**, limitado a loopback, con DocumentRoot de este proyecto y puerto distinto. En una configuración privada fuera de Git puede declararse, por ejemplo:

```apache
Listen 127.0.0.1:8081
<VirtualHost 127.0.0.1:8081>
    DocumentRoot "C:/xampp/htdocs/clinica-vida-salud"
    <Directory "C:/xampp/htdocs/clinica-vida-salud">
        Require local
        Options -Indexes
        AllowOverride None
        SetEnv CVS_DB_HOST "127.0.0.1"
        SetEnv CVS_DB_PORT "5432"
        SetEnv CVS_DB_NAME "cvs_prueba_reservas"
        SetEnv CVS_DB_USER "TU_USUARIO_LOCAL"
        SetEnv CVS_DB_PASSWORD "TU_SECRETO_LOCAL_PRIVADO"
        SetEnv CVS_DB_SSLMODE "disable"
        SetEnv CVS_HTTPS "0"
        SetEnv CVS_PAGOS_DEMO "1"
    </Directory>
</VirtualHost>
```

Los dos textos en mayúsculas son marcadores que se sustituyen **solo en el archivo privado**. El archivo no forma parte de la entrega ni se debe subir a Git. Revisar la inclusión de ese VirtualHost en la configuración local, comprobar `httpd -t` y reiniciar Apache manualmente tras revisión. No alterar la conexión ni el VirtualHost principal. Acceso de prueba: <http://127.0.0.1:8081/>. No se configuró ni reinició Apache durante esta tarea.

### D. Recorrido manual pendiente

1. Crear médico y paciente ficticios, asignar especialidades y horarios futuros; crear y vincular sus cuentas desde Usuarios.
2. Como administrador, configurar una tarifa propia de ensayo. No existen precios oficiales precargados.
3. Como paciente, reservar presencialmente y comprobar la ficha; como recepción, verificar y registrar el cobro. Reenviar debe mostrar el mismo estado, no otro cobro.
4. En otra reserva, elegir demo. En una segunda sesión de administrador probar los cuatro resultados del simulador; el paciente solo debe poder consultarlos.
5. Probar vencimiento, dos navegadores sobre el mismo turno y pago tardío; consultar historial y reportes separados.
6. Reprogramar y cancelar citas pagadas y pendientes desde las pantallas originales; revisar que no se borre ni duplique dinero.
7. Revisar móvil/escritorio, teclado y contraste; imprimir y guardar como PDF ficha/comprobante. **Estas comprobaciones visuales y de impresión no fueron ejecutadas.**

## 12. Preparación posterior para Render, sin desplegar ahora

1. Revisar cambios y migración; completar la prueba manual local y un respaldo/restauración verificable. El equipo decide cuándo registrar/subir sus cambios; no se ejecutaron `git add`, `commit` o `push` en esta tarea.
2. Confirmar en el panel la base y rama reales, permisos y vigencia del plan. La URL publicada del Ciclo 2 no implica que este incremento ya esté allí.
3. Probar construcción/ejecución Docker en un entorno disponible; el Dockerfile copia las rutas nuevas automáticamente y no incorpora SQL, pruebas o secretos. La construcción Docker **no se ejecutó** durante esta tarea.
4. Respaldar la base remota, detener escrituras durante la ventana acordada y aplicar **una vez** la migración incremental, con destino explícito verificado y `ON_ERROR_STOP=1`, siguiendo las precauciones de [la guía histórica](../deploy/DESPLIEGUE.md). Nunca volver a importar el esquema original en Render ni copiar la base local.
5. Publicar manualmente el código revisado después de migrar. Mantener las variables privadas existentes; `CVS_HTTPS=1` para HTTPS y configurar `CVS_PAGOS_DEMO` según la presentación. No cargar `.env` mediante Git ni cambiar contraseñas actuales.
6. Configurar tarifas desde la web y recorrer funcionalidades con fichas ficticias. Los datos anteriores permanecen; las citas antiguas no adquieren una deuda inferida. El módulo no requiere una tarea periódica para vencer reservas si Render se suspende.

No se proporciona una reversión destructiva del SQL. Ante un fallo, no eliminar las tablas financieras ni sus movimientos: revisar el error y el respaldo con el responsable de la base. Código y esquema deben revisarse conjuntamente.

## 13. Integración bancaria futura — no implementada

El contrato `CvsPasarelaPagos` y su adaptador de demostración están aislados del flujo de reserva. La operación `simular()` es exclusiva del laboratorio y **no debe utilizarse como prueba de pago real**. Para una pasarela oficial hará falta un adaptador y un flujo de notificaciones autenticadas propio, no simplemente cambiar la etiqueta de esta clase.

Se necesitan documentación y acceso oficial del proveedor, ambiente de pruebas, credenciales privadas, mecanismo verificable de autenticidad de eventos, referencias únicas y consulta de conciliación. Se deben comprobar en servidor importe exacto, BOB, cuenta/orden destinataria, estado final, idempotencia y vigencia de la reserva. Una pantalla, captura, QR escaneado o redirección no confirma un cobro. Una respuesta tardía seguirá sin poder asignar un turno ocupado y requerirá revisión/devolución según las reglas oficiales.

No se inventan URLs, contratos, comisiones, tokens, bancos ni respuestas de una API. Hasta completar esa integración y sus pruebas, **todo pago en línea seguirá siendo demostración** y quedará excluido de los ingresos presenciales.
