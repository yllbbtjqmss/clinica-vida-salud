# Lumi · Etapa 3: voz del navegador

Primera versión local con Web Speech API: `SpeechRecognition` (o
`webkitSpeechRecognition`) y `speechSynthesis`. No se añade un proveedor de voz,
clave, dependencia ni migración. La conexión PHP/Gemini y sus variables no cambian.

## Probar en Chrome

1. Iniciar el servidor local con la configuración Gemini que ya funciona. Abrir
   Chrome en el mismo localhost/puerto, iniciar sesión con una cuenta de prueba y
   recargar la página. La voz requiere JavaScript y localhost o HTTPS; no se
   desactiva ninguna protección del navegador.
2. Abrir Lumi. Revisar **Voz y privacidad** antes de usar el micrófono.
   Sin habilitar la activación opcional, ni abrir el panel ni recibir una respuesta
   activa el micrófono o el audio.
3. Pulsar el micrófono turquesa y conceder el permiso que solicite Chrome. Si ya
   existe una decisión guardada, Chrome puede no volver a mostrar el diálogo.
   Comprobar el micrófono de entrada y los permisos del sitio/sistema operativo.
4. Decir algo ficticio, por ejemplo «¿Qué puedes hacer como asistente virtual?».
   Durante la escucha cambia la esfera y aparece **Escuchando en español**.
   Pulsar otra vez el micrófono para terminar o esperar a que Chrome finalice.
   Una sesión tiene un límite de 30 segundos.
5. El dictado se agrega al borrador existente, con el límite del campo. Durante el
   reconocimiento se bloquean temporalmente edición/envío para evitar perder texto.
   Al finalizar, **revisar y editar** la transcripción antes de pulsar Enviar.
   La voz jamás envía automáticamente un mensaje a Gemini.
6. La respuesta aparece escrita como siempre. Pulsar su pequeño botón de altavoz
   para leerla. El selector **Voz**, dentro de **Voz y privacidad**, permite elegir
   otra voz instalada/disponible.
   **Detener audio** o el mismo altavoz detienen la lectura. No hay reproducción automática.
7. Comprobar que el micrófono está deshabilitado mientras Lumi habla y que los
   altavoces están deshabilitados durante el dictado.
8. Cerrar el panel con X, Escape o la esfera: dictado y reproducción deben terminar.
   Cambiar a otra pestaña o salir de la página también cancela la voz. El dictado
   manual no se reanuda por sí solo. La activación opcional se describe abajo.
9. Probar además escritura normal, permiso denegado y vista móvil. En pantallas muy
   bajas el contenido del panel tiene desplazamiento para acceder a los controles.

## Idioma y voces

El dictado utiliza `es-BO`. La voz automática de lectura prioriza voces españolas
habitualmente femeninas, primero Bolivia y después Latinoamérica; luego otras voces
españolas o la predeterminada del navegador. La API **no proporciona un campo de
género**: la preferencia femenina se estima a partir de nombres conocidos y no puede
garantizarse. Se muestran las opciones reales para elegir manualmente.

Las voces pueden aparecer después de cargar la página (`voiceschanged`). Si no hay
voz española, se informa y se ofrece la predeterminada/alternativas disponibles.
Es posible instalar una voz española desde las opciones de idioma del sistema;
su disponibilidad depende de Chrome y del sistema operativo, no de Gemini.

La elección manual dura esta página: no se guarda en PostgreSQL ni localStorage.
Los controles administrativos reservados de etapas anteriores no se modifican.

## Privacidad, costes y limitaciones

- No se usa `MediaRecorder`, ni se guardan archivos o grabaciones, ni se manda audio
  al backend PHP. Solo el texto revisado sigue el flujo de Gemini ya existente.
- El reconocimiento de Chrome **puede enviar audio a servicios externos** y requerir
  Internet. Algunas voces de síntesis también pueden ser remotas. Usar únicamente
  contenido ficticio, nunca nombres, documentos, claves ni información clínica real.
- No se contrata ningún servicio de voz. Enviar el texto a Gemini conserva las cuotas
  y condiciones de la conexión ya configurada: voz del navegador no implica Gemini gratis.
- Si falta reconocimiento o síntesis, se informa sin bloquear el chat escrito.
  Los errores de permiso, dispositivo, red, idioma y reproducción se muestran sin
  registrar el audio ni detalles sensibles.

## Pruebas automatizadas

```powershell
$env:CVS_BROWSER_TOOLS = "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
node tests\lumi_voz.cjs
node tests\lumi_visual.cjs
```

Playwright es una herramienta externa de pruebas ya utilizada por Lumi, no una
dependencia de la aplicación. El test de voz reemplaza las APIs del navegador y las
respuestas Gemini con simulaciones. Se prueban paciente/administrador a 390 y 1440 px,
dictado editable antes de envío, selección de voz, exclusión entre escucha/reproducción,
cierre, eventos tardíos, límites y errores. El test visual cubre también 360 px y móvil
horizontal, teclado, saludo único y movimiento reducido.

**Pendiente manual:** micrófono físico, diálogo real de permisos, exactitud de la
transcripción, sonido, género/calidad de voces y compatibilidad de dispositivos reales.
Estas pruebas no consumen Gemini ni consultan PostgreSQL.

## Llamada natural: «Hola, Lumi» / «Oye, Lumi»

1. En Chrome, abrir el sistema en localhost/HTTPS e iniciar sesión con cualquiera
   de los cuatro roles. Al abrir Lumi por primera vez aparece **¿Quieres llamarme
   con la voz?**, con una explicación del uso del micrófono y sus posibles servicios
   externos. Solo **Permitir micrófono** solicita el acceso: permitirlo también
   en Chrome y, si corresponde, en Windows. No se activa al mostrar la invitación,
   al abrir Lumi ni por haber autorizado antes el dictado manual.
   **Ahora no** descarta la invitación durante esta sesión; no vuelve a insistir
   en cada página. Se puede autorizar después desde **Voz y privacidad**.
2. Cerrar el panel y mantener visible esa pestaña. La señal en la esfera y su
   etiqueta accesible indican la escucha real, la solicitud de permiso o un bloqueo.
   Decir **Hola, Lumi** u **Oye, Lumi** abre el historial existente: no añade una
   bienvenida, no llena el borrador ni envía una consulta. Para dictar, usar el
   micrófono; revisar el texto y pulsar Enviar como siempre.
3. Para desactivarla: **Voz y privacidad → Desactivar llamada por voz**. También
   puede revocarse el micrófono en los permisos del sitio de Chrome. La decisión
   de desactivar se recuerda al navegar, sin perder el dictado manual.
   Si Chrome exige otra interacción o detiene la escucha, aparece un pequeño botón
   de micrófono junto a la esfera: **Reactivar llamada por voz**. Ese clic explícito
   vuelve a intentarlo, sin reconfigurar la preferencia. También existe **Reactivar
   micrófono** dentro de Voz y privacidad. Si se denegó el permiso, revisarlo primero
   en Chrome. Un bloqueo se recuerda entre páginas: no hay bucles de permisos.
4. La preferencia es de esta pestaña y esta sesión, separada por puerto y ruta del
   sistema. Se puede restaurar al navegar o recargar tras validar la sesión.
   Cambiar de cuenta/sesión revoca la preferencia, incluso si otra pestaña ya creó
    una conversación nueva. Solo se guardan la decisión, el estado de bloqueo y una
    huella del token de sesión: no el token, datos de cuenta, transcripciones ni audio.
    Se respetan las autorizaciones explícitas guardadas por la versión anterior.
5. La detección se pausa con el panel abierto, durante el dictado/reproducción y
   al ocultar o abandonar la página. Al volver a una página visible se revalida
   la sesión antes de rearmar. Se espera el evento de cierre del reconocimiento
   anterior: no se ejecutan dos reconocedores a la vez.

Chrome puede detener el reconocimiento, requerir Internet o exigir otro gesto o
permiso al navegar. No se promete escucha permanente ni funcionamiento en segundo
plano. No hay grabaciones en la aplicación; Chrome puede procesar audio externamente.
Si no hay compatibilidad, se conservan la esfera, el chat escrito y el dictado
manual cuando esté disponible.

## Interfaz limpia y privacidad

- No aparecen pancartas permanentes de modo local, modelo o pruebas. Tampoco aparece
  el antiguo pie «Asistente virtual · orientación general, no reemplaza atención
  médica profesional», ni se sustituye por otro pie largo.
- **Voz y privacidad** es un desplegable cerrado inicialmente, con texto adaptado
  a la disponibilidad de Gemini. Con Gemini habilitado explica el envío de consultas
  y contexto reciente pertinente a Google; catálogo y guía permanecen locales.
  Se conserva la indicación de usar mensajes ficticios y no compartir datos sensibles.
- Los errores y funciones no disponibles se explican cuando corresponden. No se
  cambian las restricciones médicas, de privacidad, de navegación ni del proveedor.
- No hay checkbox ni instrucciones técnicas permanentes en el panel. El selector
  de voz y las acciones de autorizar/desactivar quedan dentro del desplegable.
  Detener audio solo aparece durante una reproducción; el dictado conserva su botón.
- Para comprobarlo: recargar con Ctrl+F5, abrir Lumi en ambos servidores y revisar
  el panel y su borde inferior. Desplegar Voz y privacidad; en 8011 debe informar
  del procesamiento externo si Gemini está configurado, sin activarlo en 8012.

### Regresión aislada de estas mejoras

```powershell
python tests\lumi_gemini_http.py --browser-tools "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
& C:\xampp\php\php.exe tests\lumi_integracion.php
```

Usa un servidor PHP temporal, sesiones/catálogos ficticios y un transporte Gemini
simulado, sin tocar 8011/8012 ni PostgreSQL. Incluye continuidad, catálogo y activación
en administrador, recepcionista, médico y paciente a 390/1440 px. La prueba verifica
consentimiento inicial, aceptación y rechazo recordados, ambas frases repetidas,
no envíos automáticos, historial y saludo único, liberación asíncrona del micrófono,
pausa por visibilidad/audio, errores sin reintentos ocultos y separación entre cuentas.
Un escenario exige `navigator.userActivation` para simular Chrome bloqueando la
reanudación automática: se comprueba un único intento y la recuperación mediante clic.
Las suites `lumi_voz.cjs` y `lumi_visual.cjs` cubren dictado, síntesis, degradación,
privacidad desplegable, errores contextuales, respuestas tardías tras salir y ausencia
de avisos/pie permanentes. `lumi_integracion.php` audita las 23 pantallas autenticadas
y los menús/destinos de los cuatro roles, incluidos los cierres compartidos. No ejecuta
la lógica de esas pantallas ni abre sus datos. No faltaban integraciones; no se editaron
páginas de negocio. Las descargas autónomas y la impresión siguen sin asistente.

**Requiere Chrome y micrófono físicos:** permiso real y su recuperación, detección
de las frases con ruido/acentos, restricciones del navegador tras navegación,
transcripción, reproducción audible y comportamiento al cambiar de pestaña.
