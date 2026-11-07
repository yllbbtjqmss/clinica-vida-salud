# Lumi · continuidad entre páginas

## Causa y arquitectura

Antes los mensajes vivían únicamente en variables/DOM de `assets/lumi.js`; cada
carga de página creaba otro widget cerrado y agregaba un saludo nuevo. No existía
un historial conversacional PHP: solo una cuota por sesión y el contexto enviado
desde el navegador. Ahora la sesión PHP es la **única fuente de verdad**.

- `includes/lumi_conversacion.php` conserva bienvenida, pares usuario/respuesta,
  origen (Gemini/guía/aviso) y IDs de tarjetas. Máximo 25 mensajes y 14000 caracteres;
  al alcanzar el límite elimina pares antiguos, manteniendo una sola bienvenida.
- El contexto Gemini se calcula desde esos mismos mensajes, sin duplicarlo en otra
  variable persistente: máximo ocho pares y 6000 caracteres, como antes. Se excluyen
  bienvenida, guías y avisos. Un historial aportado por un cliente antiguo se valida
  por compatibilidad, pero nunca se utiliza para construir el contexto.
- El transcript se vincula internamente a cuenta, rol y sesión. No se devuelve esa
  identidad ni se guarda su huella en el navegador. Logout y caducidad ya limpian
  `$_SESSION`; no se modificó el sistema de autenticación ni el cierre de sesión.
- `sessionStorage` contiene **solo** apertura, desplazamiento de mensajes y
  desplazamiento del panel. No contiene conversaciones, borradores, IDs de usuario,
  claves, CSRF, cookies, credenciales, identificadores de sesión ni grabaciones.
- Cada página solicita `GET estado`: valida la sesión antes de restaurar los mensajes.
  No se restaura texto desde cachés del navegador. Al volver mediante atrás/adelante
  se revalida; las instantáneas se limpian al salir. Volver a una pestaña revalida.
- La apertura automática usa el estado visual únicamente si PHP confirma una
  conversación reanudada de la cuenta/rol/sesión actuales. Una conversación nueva
  no hereda automáticamente la apertura de una cuenta anterior.

## Comportamiento

Los accesos de Lumi navegan normalmente mediante el endpoint de lista permitida.
Antes de salir se detiene la voz y se conserva la vista. En el destino, Lumi se abre
sin activar micrófono, reproducir audio ni robar el foco; mantiene mensajes y lectura.
Si se cerró manualmente, permanece cerrada al recargar. Cerrar no borra mensajes.

Al reconstruir tarjetas, el servidor vuelve a filtrar sus IDs con el rol actual;
cada clic vuelve a validar permisos. No se guardan URLs ni autorizaciones antiguas.
La sección elegida con un enlace autorizado o la página referida del mismo origen
sirve para responder localmente a «¿Qué hago ahora?». La
[orientación por estado](LUMI_ESTADO_PAGINA.md) distingue instalación y catálogos
insuficientes mediante lectura mínima del servidor, sin consultar registros
personales ni afirmar que conoce los campos elegidos.

**Nueva conversación** pide confirmación si hay mensajes del usuario, cancela la voz
y borra solo el transcript y el contexto de orientación, no la cuota de solicitudes.
Es un POST autenticado con CSRF. Cancelar la confirmación no cambia nada.

El cierre de sesión limpia el estado visual y el DOM; se avisa a otras pestañas con
un mensaje genérico por BroadcastChannel cuando está disponible, sin datos privados.
Aunque el navegador no lo soporte, el servidor sigue impidiendo restaurar mensajes
de otra cuenta. No hay garantía de borrar de inmediato una pestaña congelada por el
navegador: al reactivarse se revalida antes de volver a restaurar su conversación.

Cambiar de página, abrir/cerrar el panel y restaurar NO envía un POST de mensaje ni
vuelve a llamar a Gemini. Solo un envío manual inicia una nueva consulta. No hay
reintentos automáticos de consultas cuyo resultado no se haya recibido: se evita
duplicar consumo. Las peticiones simultáneas de una misma sesión respetan el bloqueo
de sesiones PHP; una consulta en curso puede retrasar la carga del estado de destino.

Si `sessionStorage` está bloqueado, siguen conservándose los mensajes en PHP; la
apertura y la posición entre documentos pueden requerir intervención manual.

## Privacidad

Usar exclusivamente mensajes ficticios. Los textos detectados como sensibles o como
claves se sustituyen por un aviso en el historial, no se conservan. La detección es
preventiva y no una anonimización completa de nombres o información clínica libre.
No se agregan datos de PostgreSQL, nombres de cuenta, rol o información de sesión
al proveedor. Los mensajes de prueba permanecen solo durante la sesión autenticada;
el servidor aplica su política normal de almacenamiento y limpieza de sesiones PHP.
No se introduce ninguna tabla, migración, fichero de conversación propio ni log.

## Prueba manual en localhost

1. Iniciar sesión con una cuenta ficticia; abrir Lumi y enviar una pregunta general.
2. Pedir «Cómo reservar una cita» y pulsar **Abrir Reservar cita**. Debe navegar y
   reaparecer abierta con la respuesta general y la tarjeta anterior, sin nuevo saludo.
3. Preguntar «Ya entré a Reservar cita, ¿qué hago ahora?». Escuchar la guía con el
   altavoz. Solicitar «Abrir mis pagos» y comprobar una segunda navegación.
4. Subir el desplazamiento para leer una instrucción anterior y recargar: debe
   recuperar esa posición. Cerrar/reabrir conserva mensajes; cerrar y recargar
   mantiene el panel cerrado hasta pulsar de nuevo la esfera.
5. Pulsar **Nueva conversación**: cancelar debe preservar todo; aceptar debe dejar
   únicamente la bienvenida. Una pregunta nueva no debe llevar contexto del hilo borrado.
6. Cambiar de cuenta (también entre dos pacientes), de rol y cerrar sesión. La nueva
   cuenta no debe recibir mensajes ni tarjetas anteriores. Volver a iniciar sesión
   con la cuenta original después de logout debe comenzar sin el historial viejo.
7. Revisar micrófono/altavoz, teclado, móvil y varios enlaces consecutivos. Ni la voz
   ni la reproducción deben reanudarse automáticamente tras navegar.

## Pruebas automatizadas aisladas

```powershell
& 'C:\xampp\php\php.exe' tests\lumi_conversacion.php
python tests\lumi_gemini_http.py --browser-tools "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
$env:CVS_BROWSER_TOOLS = "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
node tests\lumi_visual.cjs
node tests\lumi_voz.cjs
& 'C:\xampp\php\php.exe' tests\lumi_navegacion.php
& 'C:\xampp\php\php.exe' tests\lumi_gemini.php
```

El runner integra PHP real, sesiones PHP y el logout existente en una copia temporal,
con autenticación/usuarios/pantallas/transportes ficticios. No usa PostgreSQL. Solo
esa copia inyecta el transporte simulado; `curl_exec` está deshabilitado y no hay
interruptor de simulación productivo. Los contadores verifican que restaurar no
consume Gemini. El navegador comprueba 1440 y 390 px, reinicio confirmado/cancelado,
scroll, roles, cuentas, logout y lectura de tarjetas; se ejecutan también regresiones
de voz, navegación y proveedor. Sin `--browser-tools` corren solo las pruebas HTTP.

No equivale a probar credenciales o micrófono físicos ni cuentas reales de la clínica.
