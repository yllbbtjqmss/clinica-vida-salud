# Lumi: estado de página y diagnóstico local de solo lectura

## Hallazgo local — 07/11/2026

Se identificó el proceso PHP que escucha en `127.0.0.1:8011` (PID 1908 durante la
revisión), con document root `C:\xampp\htdocs\clinica-vida-salud`. Se verificó su
entorno de proceso sin imprimir ni conservar credenciales: **no tiene
`CVS_DB_HOST`**, por lo que `includes/conexion.php` carga
`C:/xampp/config_clinica/database.php`. No se asumió que el entorno de la terminal
coincidiera con el servidor.

Una conexión de diagnóstico reproduciendo esa selección devolvió:

- Base **`clinica_vida_salud`**, servidor **`127.0.0.1:5432`**, esquema **`public`**.
- `search_path`: `"$user", public`.
- Todas las consultas de diagnóstico se ejecutaron en transacciones
  **`BEGIN READ ONLY`**, terminadas con `ROLLBACK`; `transaction_read_only=on`.
- Existen las ocho tablas originales y la extensión `btree_gist`, además de las
  exclusiones originales de citas y horarios.
- Faltan `tarifas_consulta`, `historial_tarifas`, `reservas_temporales`, `pagos`,
  `intentos_pago`, `movimientos_pago`, `bloqueos_agenda` y `solicitudes_pago_prueba`.
- Faltan `cvs_expirar_reservas()`, `cvs_guardia_agenda()`, `cvs_bloqueo_cita()`,
  `cvs_bloqueo_reserva()`, `cvs_finanzas_cita()`, `cvs_pago_inmutable()`,
  `cvs_historial_inmutable()` y `cvs_metodo_online_inmutable()`; no aparecen los
  disparadores `cvs_*` del módulo ni la columna `pagos.metodo_online`.

El aviso lo emite `cvp_requerir_modulo()` porque `cvp_disponible()` exige
`public.pagos` y `public.cvs_expirar_reservas()`. Es una instalación ausente, no una
denegación al rol paciente. Las dependencias corresponden a
`20261104_reservas_pagos.sql` y `20261105_metodos_online.sql`. **No se ejecutaron.**

### Base aislada existente

En el mismo PostgreSQL local existe **`cvs_prueba_reservas`**. Se comprobó por
lectura la presencia de las ocho tablas originales, las ocho del módulo, las ocho
funciones anteriores, los once disparadores habilitados, `btree_gist`, la exclusión
de bloqueos y `pagos.metodo_online`. Los booleanos de catálogos indican que existen
especialidades, médicos vinculados, tarifas y horarios activos.

**Sí puede servir como base aislada para probar la instalación existente**, sin
migrarla otra vez. Esto no acredita credenciales de pacientes ficticios, fichas
vinculadas ni disponibilidad de una combinación/fecha concreta: no se consultaron
registros personales ni se inició un recorrido de reservas. Debe utilizarse mediante
un servidor de prueba separado y una configuración explícita autorizada, sin cambiar
la conexión del servidor `8011`. En esta tarea no se inició ni reconfiguró ningún
servidor. No se accedió a Render.

No se abrieron endpoints funcionales del módulo contra las bases reales: incluso
un GET de reservas puede ejecutar `cvp_expirar()` y escribir vencimientos.

## Corrección exclusivamente en Lumi

La frase «Ya elegí especialidad y médico, ¿qué hago después?» coincidía con la
palabra `medico` y seleccionaba **Médicos**, pantalla administrativa. Además, el
seguimiento no reconocía «qué hago después» y solo se usaba si ningún tema había
coincidido. Ahora se distingue selección de consulta de navegación administrativa;
pedir explícitamente **Abrir Médicos/Usuarios/Especialidades** sigue denegado para
pacientes, aunque exista contexto de reserva.

`includes/lumi_estado_pagina.php` separa:

| Situación | Orientación |
|---|---|
| `acceso_denegado` | El rol no puede abrir el destino; no consulta esquema ni datos |
| `instalacion_pendiente` | Faltan dependencias básicas del módulo; no propone continuar el formulario |
| `sin_datos` | Esquema básico presente, pero faltan especialidades, médicos vinculados, tarifas u horarios activos |
| `normal` | Instrucciones condicionales para fecha, bloque, resumen y modalidad disponible |
| `no_verificado` | Fallo de lectura; no lo presenta como denegación ni instalación ausente |

El endpoint revalida el rol y consulta el estado en cada nueva guía de las secciones
del módulo. No es un auditor completo de integridad: un esquema básico presente no
acredita todos los triggers, reglas o datos necesarios para confirmar una operación.

La página actual se identifica desde la referencia del mismo origen y ruta exacta
del catálogo; se ignoran query y cualquier texto del DOM. Esto permite seguimiento
al entrar directamente, no solo desde los enlaces de Lumi. Si el navegador omite la
referencia, se usa la última sección autorizada de la conversación; sin contexto ni
tema explícito no se inventa una página. La referencia **no concede autorización**:
los destinos y cada clic siguen validados por rol.

El estado proviene de **SELECT estáticos del servidor**, nunca de un campo
`estado_pagina` aportado por JavaScript. Solo se leen metadatos y `EXISTS` sobre
catálogos de oferta. No se leen pacientes, usuarios, citas, reservas, pagos,
comprobantes, importes, nombres ni selecciones del formulario. No se ejecutan
funciones de vencimiento ni acciones del módulo.

Los catálogos configurados globalmente no prueban que haya un médico, tarifa o
bloque disponible para una selección concreta. Lumi lo explica expresamente y
remite a los avisos reales de la pantalla; no afirma haber visto campos elegidos,
ficha vinculada ni un resumen. El formulario conserva toda su validación original.

Los estados quedan en la respuesta local y su texto se conserva con origen `guia`;
no se envían a Gemini. No se modificaron el transporte de Gemini, el almacenamiento
del historial, los controles de voz, la interfaz ni el mecanismo de autorización.
Las respuestas viejas del historial no se reescriben: enviar otra pregunta obtiene
la orientación corregida y el estado actual.

## Archivos de esta corrección

- `includes/lumi_estado_pagina.php`: lectura mínima y mensajes por estado.
- `includes/lumi_navegacion.php`: intención y seguimiento sin confundir médicos.
- `includes/lumi.php`: incluye el nuevo helper; transporte Gemini sin cambios.
- `lumi_api.php`: contexto de página autorizado y aplicación del estado local.
- `tests/lumi_estado_pagina.php`: casos unitarios con PDO ficticio.
- `tests/lumi_gemini_http.py`: pruebas de estados en copia temporal autenticada.
- `tests/lumi_continuidad.cjs`: frase exacta y estado pendiente en escritorio/móvil.
- Esta documentación y enlaces desde las guías de continuidad/navegación.

## Validación

Ejecutar `php tests/lumi_estado_pagina.php` y las regresiones indicadas en
[LUMI_CONTINUIDAD.md](LUMI_CONTINUIDAD.md). Las pruebas de HTTP/navegador usan sesiones,
pantallas, PDO y transporte Gemini ficticios en una copia temporal; no escriben en
las bases locales ni llaman al proveedor. La lectura real de PostgreSQL se limitó
al diagnóstico anterior y a verificar las consultas de estado en ambas bases dentro
de transacciones de solo lectura.

Para comprobar en `8011`, conservando la sesión ficticia actual: recargar la página,
abrir Lumi y repetir la frase. Debe explicar **instalación pendiente, no restricción
de cuenta**. Pedir **Abrir Médicos** debe continuar denegado. No se necesita borrar
el historial ni cambiar Gemini. La prueba del formulario completo debe hacerse
aparte, con autorización, en la base aislada.
