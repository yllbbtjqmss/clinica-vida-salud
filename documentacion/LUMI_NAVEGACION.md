# Lumi · Etapa 4A: guía de uso y navegación controlada

## Alcance

Lumi explica cómo usar pantallas existentes y ofrece accesos que requieren un clic
explícito. No consulta expedientes ni movimientos privados, no ejecuta formularios,
no aprueba pagos y no registra, cancela ni reprograma citas.

Se inspeccionaron el menú `cvsu_paginas`, las guardias `cvs_exigir_roles` y los
formularios relevantes. El catálogo estático vive en `includes/lumi_navegacion.php`.
Las pruebas comparan sus rutas y permisos con el menú y el código fuente real para
detectar divergencias cuando cambie la aplicación.

## Funciones por rol

| Rol | Accesos de la guía |
|---|---|
| Paciente | Inicio, Reservar cita, Mis citas y perfil, Mis reservas y pagos |
| Médico | Inicio, Mi agenda (incluye sus horarios de atención) |
| Recepción | Inicio, pacientes, registro/reprogramación/cancelación de citas, asistencia, historial de citas, agenda médica, consulta de tarifas, pagos y cobros |
| Administración | Los accesos de gestión anteriores, más usuarios, médicos, especialidades, horarios médicos, reportes administrativos/económicos y preferencias de Lumi |

Administración y recepción utilizan el registro administrativo de citas, no el
autoservicio del paciente. Solo administración modifica tarifas; recepción las
consulta. El paciente solicita cancelaciones y reprogramaciones a recepción:
la guía no le ofrece formularios administrativos. El médico no recibe enlaces a pagos.

Las páginas de ficha, detalle de pago y reprogramación que requieren identificar un
registro no son destinos directos del catálogo. Se orienta a seleccionarlo desde
su listado autorizado. No se construyen IDs, importes, fechas ni referencias ficticias.

## Funcionamiento y seguridad

1. PHP revalida sesión y rol con la autenticación existente.
2. `estado` devuelve solo los identificadores y títulos autorizados para los botones.
3. Las intenciones de uso reconocidas se resuelven **localmente** con guía estática
   y aparecen rotuladas «Guía del sistema». No son una respuesta fingida de Gemini.
    Se mantienen CSRF, tamaño de petición y límite de solicitudes.
   Para reservas/pagos se añade [estado controlado del servidor](LUMI_ESTADO_PAGINA.md)
   de instalación y catálogos, sin leer registros personales ni ejecutar operaciones.
4. En ese flujo no se envía el mensaje ni su historial a Google. Los mensajes se
   conservan en la sesión PHP para [continuar entre páginas](LUMI_CONTINUIDAD.md),
   pero se excluyen del contexto reenviado en conversaciones generales.
   Una petición de navegación desconocida ofrece opciones conocidas, no una URL inventada.
5. Los demás mensajes siguen la conexión Gemini existente y sus filtros de privacidad.
   Solo se amplía el prompt con conocimiento público estático de las pantallas: sin
   rol de sesión, identidad, registros de pacientes, cuentas ni rutas de archivo.
6. Gemini no proporciona enlaces ejecutables: su salida sigue siendo texto plano.
   El frontend ignora destinos de respuestas que no provienen de la guía y cruza
   los IDs sugeridos con el catálogo autorizado recibido desde PHP.
7. Cada enlace apunta a `lumi_api.php?accion=ir&destino=ID`. **Solo al pulsarlo** se
   solicita ese destino; PHP vuelve a validar el rol y resuelve el ID mediante la
   lista permitida. Rechaza URLs, traversal, IDs desconocidos y destinos de otro rol.
   Solo se redirige a una pantalla raíz conocida, sin parámetros ni acciones de registros.
8. Las pantallas de destino conservan intactas sus propias comprobaciones de acceso,
   propiedad, CSRF y confirmación. Si el rol cambia, un enlace antiguo no concede acceso.

El catálogo y el control de enlaces no dependen de una llamada a Gemini. Se mantiene
el comportamiento de habilitación del chat según su configuración existente; un
acceso autorizado no implica que haya datos o que un módulo pendiente esté instalado.

No se modifica el transporte, endpoint externo, cabecera, clave, modelo, cuota,
timeouts ni parámetros de generación de Gemini. Tampoco se modifica `includes/ui.php`,
PostgreSQL, el menú general, las páginas de negocio ni los archivos de voz.

## Probar manualmente en localhost

Con el servidor y Gemini ya configurados, recargar la página e iniciar sesión con
cuentas ficticias de los cuatro roles:

- **Paciente:** «¿Cómo reservo una cita?», «Llévame a mis pagos», «¿Cuánto cuesta una
  consulta?», «Quiero cancelar mi cita». Debe orientar sin consultar datos actuales;
  el cambio o cancelación se solicita a recepción.
- **Médico:** «Muéstrame mi agenda» o «¿Qué citas tengo?». Debe ofrecer Mi agenda,
  no recitar citas privadas. «Abrir pagos» no ofrece ese destino.
- **Recepción:** «Registrar una cita», «Consultar tarifas», «Ver horarios médicos».
  Debe abrir los listados apropiados y aclarar que las tarifas son de consulta.
- **Administración:** «Ver reportes financieros», «Abrir usuarios», «Configurar Lumi».
- **Todos:** «Muéstrame las funciones del sistema» presenta solo los accesos de la cuenta.
  «Abre una sección inexistente» ofrece alternativas conocidas.

Comprobar que preguntar no cambia la URL ni ejecuta operaciones. Solo el botón/enlace
abre la sección. Probar Tab/Enter y móvil. Una solicitud como «Aprueba mi pago» debe
aclarar que Lumi no ejecuta esa operación y orientar al formulario autorizado.

Repetir una pregunta con el micrófono: revisar el dictado, enviarlo manualmente y
escuchar la guía mediante el altavoz. El lector recibe solo el texto explicativo,
no URLs ni el contenido de los botones. Cerrar el chat sigue deteniendo la voz.

## Pruebas automatizadas sin servicios reales

```powershell
& 'C:\xampp\php\php.exe' tests\lumi_navegacion.php
& 'C:\xampp\php\php.exe' tests\lumi_gemini.php
python tests\lumi_gemini_http.py
$env:CVS_BROWSER_TOOLS = "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
node tests\lumi_visual.cjs
node tests\lumi_voz.cjs
```

Las pruebas verifican rutas reales, igualdad con los permisos del menú y guardias,
escalada de roles, cambio de rol tras emitir un enlace, URLs arbitrarias, botones
generados exclusivamente desde la guía, navegación solo por clic/teclado e interacción
con dictado y lectura simulados. El runner HTTP usa una copia temporal y autenticación/
PDO ficticios, sin seguir destinos de negocio; `curl_exec` está deshabilitado en su PHP.

No se abren cuentas reales, no se consulta ni modifica PostgreSQL y no se consumen
peticiones Gemini. La calidad de respuestas reales y el micrófono físico requieren
revisión manual; no se atribuye una prueba de login real a las identidades simuladas.
