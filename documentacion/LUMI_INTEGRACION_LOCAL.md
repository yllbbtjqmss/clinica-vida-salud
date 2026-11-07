# Lumi integrada sobre main: validación local completada

## Estado de la integración

- Base: `origin/main` en `97165c1`, con Lista de espera de Melissa.
- Desarrollo protegido: `feature/integracion-ia`, commit `e89573e`.
- Worktree: `C:\xampp\htdocs\clinica-vida-salud-integrada`.
- Rama de trabajo: `feature/integracion-lumi-final`.
- Merge ejecutado con `--no-commit --no-ff`; no crear el commit final sin autorización.
- `includes/ui.php` se combinó automáticamente: conserva los menús/icono de Lista de
  espera y la integración de Lumi. Las páginas de negocio de Melissa no se editaron.
- La validación funcional posterior usa exclusivamente PostgreSQL local con datos
  sintéticos; no modifica las bases originales ni Render.

## Compatibilidad de Lista de espera

El catálogo y la detección de intenciones de Lumi reconocen Lista de espera antes
que temas genéricos como médicos, pacientes o citas. Su guía y enlace solo se ofrecen
a **administrador** y **recepcionista**. Paciente y médico reciben una denegación
sin instrucciones administrativas ni enlaces a esa sección. El destino se revalida
también al pulsarlo, incluso si el enlace procede de una sesión/rol anterior.

Lumi no lee filas de `lista_espera`, no muestra nombres de pacientes ni turnos
liberados y no registra, retira ni asigna solicitudes. Solo explica el formulario
existente. La presencia del enlace no prueba que la tabla o el módulo estén instalados
en la base local: se respetan los avisos de la pantalla de Melissa.

## Pruebas sin datos reales

Las suites PHP de Gemini, catálogo, navegación, continuidad, estado e integración
usan dobles y lectura de fuentes. `lumi_gemini_http.py` ejecuta el endpoint real en
una copia temporal con autenticación, PDO, pantallas y transporte Gemini ficticios.
Las pantallas ficticias incluyen Lista de espera, nunca ejecutan su lógica de negocio.

Resultado en este worktree: **748 comprobaciones PHP correctas**, auditoría de
**24 pantallas**, sintaxis PHP/JavaScript/Python correcta y regresiones HTTP/navegador
de continuidad, catálogo, activación, dictado, síntesis e interfaz satisfactorias.
Los archivos del directorio original y su índice se compararon mediante hashes y
permanecieron intactos, incluidos los dos archivos excluidos del commit de Lumi.

Se verifican los cuatro roles, autorización/denegación del nuevo destino, ambas
frases de activación, navegación entre páginas, historial y separación de cuentas.
Las regresiones de voz/interfaz sustituyen las API de audio por simulaciones. No
usar `tests/lumi_catalogo_postgres.php` ni la suite de reservas/pagos para esta fase.

```powershell
& C:\xampp\php\php.exe tests\lumi_navegacion.php
& C:\xampp\php\php.exe tests\lumi_integracion.php
python tests\lumi_gemini_http.py --browser-tools "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
```

## Antes de abrir la versión integrada en localhost

1. Autorizar una **base local aislada con datos ficticios** y preparar las variables
   de conexión solo para una terminal/proceso nuevo. No reutilizar ni alterar los
   servidores `8011`/`8012`, la configuración externa por defecto ni conexiones de
   producción. Sin una selección explícita, PHP puede cargar la conexión privada
   existente: no iniciar el recorrido de prueba de esa manera.
2. Confirmar de forma autorizada que esa base ya tiene el esquema necesario para
   Lista de espera y reservas/pagos. El arranque no instala el esquema. Si falta,
   detener la prueba funcional y decidir su preparación por separado; no ejecutar
   migraciones automáticamente.
3. Usar un puerto libre distinto, por ejemplo `8013`, y sesiones separadas. Los
   permisos de Chrome y `sessionStorage` se separan por origen/puerto, pero las cookies
   no: usar un nombre de sesión PHP distinto y, preferiblemente, otro perfil de Chrome.
4. En esa terminal, con la conexión local aislada ya autorizada/configurada, se puede
   iniciar manualmente sin activar Gemini:

   ```powershell
   $env:CVS_AI_ENABLED = '0'
   $sesiones = Join-Path $env:LOCALAPPDATA ('Temp\opencode\lumi-integrada-sesiones-' + [guid]::NewGuid())
   New-Item -ItemType Directory -Path $sesiones | Out-Null
   & C:\xampp\php\php.exe -d session.name=LUMI_INTEGRADA -d "session.save_path=$sesiones" -S 127.0.0.1:8013 -t C:\xampp\htdocs\clinica-vida-salud-integrada
   ```

   Este comando no configura ni verifica la conexión: el paso 1 es obligatorio.
   El arranque no ejecuta migraciones. No guardar sesiones ni credenciales
   dentro del repositorio. Una prueba con Gemini real necesita autorización separada
   y variables privadas solo de ese proceso local; no activar nada en producción.
5. Abrir `http://127.0.0.1:8013/` con cuentas ficticias de los cuatro roles:
   - Esfera, historial, catálogo y dictado disponibles según el navegador.
   - Administración/recepción: «Abrir Lista de espera», enlace correcto, historial
     conservado y guía para «¿Y ahora?»; Lumi no ejecuta los formularios.
   - Paciente/médico: sin enlace de Lista de espera y con denegación si lo solicitan;
     probar también que un enlace antiguo no concede acceso.
   - Micrófono: autorización inicial, «Hola, Lumi», «Oye, Lumi», navegación a Lista
     de espera, pausa por pestaña oculta/audio y desactivación en Voz y privacidad.
   - Cerrar sesión y cambiar de usuario: sin mensajes ni autorización de voz heredados.

## Resultado de la validación final autorizada

- `127.0.0.1:8013`, base aislada `cvs_integracion_pruebas` con 17 tablas,
  sesión `LUMI_INTEGRADA`, Gemini desactivado y pagos exclusivamente de prueba.
- 371 comprobaciones HTTP: login de cuatro roles, menús, permisos negativos,
  catálogo, reservas, pago simulado, aislamiento entre pacientes, reprogramación,
  cancelación y registro/retiro/asignación de Lista de espera.
- 748 comprobaciones PHP de Lumi, 59 archivos PHP sin errores de sintaxis y
  regresiones HTTP/navegador con transporte simulado satisfactorias.
- Navegador real en escritorio/móvil para cuatro roles: Lumi, navegación,
  continuidad, cierre de sesión y ambas activaciones de voz simuladas;
  sin errores JavaScript ni solicitudes externas de la aplicación.
- Pendiente manual: micrófono físico y voces de Chrome. El reconocimiento puede
  usar servicios externos: no probar audio real sin revisar o autorizar ese envío.
- Iniciar un servidor nuevo requiere las variables privadas de conexión y sesiones
  separadas descritas arriba; el repositorio no guarda credenciales ni sesiones.
