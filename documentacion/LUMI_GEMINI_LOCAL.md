# Lumi · Gemini por texto, solo en localhost

Esta etapa sustituye el adaptador OpenAI previo por REST de Gemini desde PHP. No
activa ningún servicio, no contiene claves y no requiere nuevas tablas ni migraciones.
No modifica reservas, pagos, Melissa ni Render. El CSS y el diseño se conservan.

## Activación local

1. Obtener una clave propia en [Google AI Studio](https://aistudio.google.com/apikey).
   Revisar condiciones de uso, privacidad, región y cuotas del modelo antes de usarlo.
   No se garantiza disponibilidad gratuita: no habilitar facturación para esta prueba.
2. Copiar el ID exacto de un modelo disponible en tu cuenta, compatible con
   `generateContent`. No se selecciona ni se inventa uno por defecto.
3. PHP necesita cURL, mbstring y un almacén de certificados CA válido. No desactivar
   la verificación TLS para resolver errores de certificados en XAMPP.
4. Definir estas variables **solo en el proceso local**:

   | Variable | Valor |
   |---|---|
   | `CVS_AI_ENABLED` | `1` para habilitar; ausente o `0` mantiene apagado |
   | `CVS_AI_MODEL` | ID real del modelo Gemini seleccionado |
   | `GEMINI_API_KEY` | Clave privada de Google AI Studio |

   `.env.example` es documentación, PHP **no lo carga automáticamente**.
   `OPENAI_API_KEY` ya no se utiliza. Nunca poner claves en `lumi.php`, JS, Git,
   capturas de pantalla o comandos que se compartan con el equipo.

   Desde PowerShell, ejemplo con entrada oculta de la clave (no se imprime):

   ```powershell
   $env:CVS_AI_MODEL = Read-Host 'ID exacto del modelo de Google AI Studio'
   $secreto = Read-Host 'GEMINI_API_KEY' -AsSecureString
   $env:GEMINI_API_KEY = [System.Net.NetworkCredential]::new('', $secreto).Password
   $env:CVS_AI_ENABLED = '1'
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:8008 -t 'C:\xampp\htdocs\clinica-vida-salud'
   ```

   Este servidor usa la conexión PostgreSQL **ya configurada**, sin modificarla ni
   ejecutar SQL de instalación. No definir nuevas variables de base para probar IA.
   Si las cookies de tu configuración exigen HTTPS, usa el HTTPS local existente;
   no cambies la seguridad de las sesiones para probar el chat.

   Las variables de una terminal no cambian un Apache de XAMPP que ya esté abierto.
   Para Apache, definirlas en configuración privada fuera del repositorio y reiniciar
   solo el servicio local; no subirlas a Render. Acceder por localhost/loopback, no por LAN.
5. Abrir `http://127.0.0.1:8008/login.php`, iniciar sesión con una cuenta de prueba y
   abrir la esfera. Recargar la página tras cambiar variables. Una configuración
   completa habilita la entrada; la primera respuesta comprueba realmente el acceso
   al proveedor. Una clave sintácticamente válida puede ser rechazada por Google.
6. Escribir únicamente texto general ficticio, por ejemplo «¿Qué puedes hacer como
   asistente virtual?». Cerrar/reabrir, navegar y recargar conservan el historial
   limitado de la sesión PHP. **Nueva conversación** lo reinicia; cerrar sesión lo
   elimina. No se guardan mensajes en BD, localStorage ni sessionStorage.
   Ver [continuidad entre páginas](LUMI_CONTINUIDAD.md).
7. Al terminar, detener el servidor con Ctrl+C y limpiar la terminal:

   ```powershell
   $env:CVS_AI_ENABLED = '0'
   Remove-Item Env:GEMINI_API_KEY -ErrorAction SilentlyContinue
   Remove-Item Env:CVS_AI_MODEL -ErrorAction SilentlyContinue
   Remove-Variable secreto -ErrorAction SilentlyContinue
   ```

## Diagnóstico seguro de la configuración

### Claves admitidas

La [guía oficial de Gemini](https://ai.google.dev/gemini-api/docs/api-key) confirma
las Auth keys como opción predeterminada desde el 28/05/2026 y mantiene claves
estándar con restricciones explícitas. Ambos tipos usan la cabecera `x-goog-api-key`.

Esta versión valida dos perfiles locales explícitos:

- **Auth:** prefijo literal `AQ.` y 50 caracteres `[A-Za-z0-9_-]`: 53 en total.
- **Estándar:** prefijo literal `AIza` y 35 caracteres `[A-Za-z0-9_-]`: 39 en total.

El validador no acepta cadenas genéricas, prefijos desconocidos, longitudes distintas,
puntos adicionales, comillas, Unicode, `/`, `+`, `=`, controles ni inyección de cabeceras.
Se conserva la normalización previa de espacios exteriores; los controles internos
siguen rechazados. La guía oficial no define una gramática exhaustiva para futuras
versiones: estos son los perfiles admitidos por Lumi, no una comprobación de validez
criptográfica, vigencia o permisos ante Google.

El rechazo concreto de `AQ.` en la versión anterior se debía a que su expresión
regular genérica **no admitía el punto**. Se sustituyó por una lista cerrada de formatos,
no por un comodín que acepte cualquier contenido. La clave permanece únicamente en
la cabecera HTTPS privada del servidor, nunca en URL, JSON enviado, HTML, JS ni logs.

### Consulta del diagnóstico

Con sesión iniciada, desde la consola de DevTools de **la misma página y puerto**:

```javascript
fetch('lumi_api.php?accion=configuracion', {credentials: 'same-origin'})
  .then(r => r.json())
  .then(datos => console.table(datos));
```

Este GET exige sesión, origen permitido y conexión local. No consulta Gemini. Devuelve
solo booleanos: no contiene clave, fragmentos, longitud ni hash. No abrirlo con otra
aplicación ni imprimir `getenv()`/`$_SERVER`/`$_ENV` completos para diagnosticar.

- `ia_activada`, `acceso_local` y `curl_disponible` deben ser `true`.
- `clave_presente` y `clave_formato_valido` separan ausencia de formato rechazado.
- `modelo_presente` y `modelo_formato_valido` hacen lo mismo con el modelo.
- `configuracion_valida: true` permite habilitar la entrada del chat. **No prueba**
  que Google acepte la clave ni que esa cuenta tenga acceso al modelo.
- `clave_con_espacios_exteriores` / `modelo_con_espacios_exteriores` muestran si se
  normalizaron espacios, tabulaciones o saltos de línea externos. No se aceptan
  controles internos ni se eliminan comillas o caracteres arbitrarios.
- `clave_en_getenv`, `clave_en_env` y `clave_en_server` indican únicamente en qué
  mecanismos existe una variable. Se prioriza `getenv()`, después `$_ENV` y finalmente
  la variable exacta de `$_SERVER`; **nunca** cabeceras `HTTP_*`. Si `getenv()` existe
  pero está vacío, no se sustituye silenciosamente con otra credencial.

La misma lectura normalizada se utiliza para validar, enviar la cabecera privada y
evitar que la clave aparezca en la respuesta. No hay `putenv()` ni cargador de `.env`
en la inicialización productiva de Lumi. Las pruebas modifican solo su propio proceso.

El antiguo aviso «Falta configurar Gemini…» mezclaba clave ausente, clave con formato
rechazado y modelo ausente/inválido, sin normalizar espacios. No era una respuesta de
Google. `gemini-2.5-flash-lite` cumple la validación local: si se reproduce ese aviso
en una copia anterior no significa que Google haya rechazado ese modelo.

Una comprobación desde `php -r` en una terminal no verifica las variables de un
servidor ya iniciado en otro proceso. Detener y arrancar el servidor desde la terminal
configurada; después recargar la página, ya que el chat guarda su estado inicial en
memoria durante esa visita. No es necesario volver a introducir ni compartir la clave
si ya está correctamente definida en esa terminal.

Los errores del proveedor se distinguen **solo al realizar una consulta explícita**:
credencial rechazada (401 o razón estructurada `API_KEY_INVALID`/`API_KEY_EXPIRED`),
permisos/restricciones (403), modelo no disponible (404), cuota (429), solicitud
incompatible (400 sin razón de clave), red, timeout o certificados TLS. No se devuelve
el mensaje crudo de Google ni se realiza una consulta automática para comprobar la clave.

## Seguridad y límites

- Sesión y rol revalidados por la autenticación existente; JSON con 401 al vencer.
- POST JSON, CSRF, validación de estructura y tamaño, veinte intentos por sesión cada
  diez minutos, 700 caracteres por consulta, ocho pares de historial y 6000 caracteres
  totales de contexto. El cuerpo entrante está limitado a 32 KiB.
- Una petición al proveedor, sin reintentos automáticos, máximo 500 tokens de salida,
  timeout de 30 s y respuesta HTTP limitada a 64 KiB. La cuota por sesión no constituye
  un presupuesto global de gasto: revisar también los límites de la cuenta de Google.
- Clave solo en `x-goog-api-key`, destino HTTPS fijo, TLS verificado y sin redirects.
  No se registran claves, conversaciones ni respuestas crudas del proveedor.
- Se envía únicamente configuración de personalidad y texto del chat. No se adjuntan
  cuentas, nombres de usuario, fichas, rol, datos clínicos ni contenido de la página.
- El aviso del chat exige texto ficticio. Un filtro rechaza datos evidentes como
  contactos, documentos y algunas formas de identificarse, incluido el historial.
  **Es un filtro preventivo, no anonimización completa**: no detecta todos los nombres
  ni datos clínicos en lenguaje libre. No introducir información real, ni siquiera
  para comprobar el filtro. Revisar además las condiciones de datos del plan gratuito.
- Las instrucciones prohíben inventar datos de la clínica, diagnosticar, prescribir o
  ejecutar acciones. Los modelos pueden equivocarse: las respuestas no acreditan
  disponibilidad, precios ni citas. No hay SQL, herramientas ni acceso clínico para IA.
- El texto devuelto se muestra con `textContent`, sin ejecutar HTML ni Markdown.

## Pruebas sin solicitudes reales

```powershell
& 'C:\xampp\php\php.exe' tests\lumi_gemini.php
python tests\lumi_gemini_http.py
$env:CVS_BROWSER_TOOLS = "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
node tests\lumi_visual.cjs
```

PHP prueba el contrato real `generateContent`, conversión de historial, errores HTTP,
bloqueos, JSON inválido, tamaños, timeout, privacidad y cuota con transporte inyectado.
El test HTTP usa una copia temporal del endpoint y autenticación ficticia, sin BD.
Las pruebas de navegador usan roles y respuestas interceptadas; no acreditan el login
contra cuentas reales, calidad de respuestas reales, cuotas ni validez de una clave.

Referencias oficiales consultadas:
- https://ai.google.dev/gemini-api/docs/api-key (Auth keys, estándares restringidas y cabecera REST)
- https://ai.google.dev/api/generate-content (`generateContent`, `contents`, roles y respuestas)
- https://ai.google.dev/gemini-api/docs/text-generation
