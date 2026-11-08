<a id="presentacion"></a>

# CLÍNICA VIDA SALUD

**Sistema web para la gestión administrativa de citas médicas, pagos simulados, lista de espera y asistente virtual (Lumi), por rol.**

Proyecto universitario (Grupo 17 · Sistemas de Información I · UAGRM · 2-2026) desarrollado en PHP 8.2 y PostgreSQL. Estado **al 07/11/2026**: el sistema está integrado y publicado en Render, con cuatro roles operativos, 18 tablas PostgreSQL, reservas con bloqueo temporal, pagos con simulación académica (tarjeta y QR), Lista de espera, y la asistente Lumi con navegación guiada, catálogos locales, historial de sesión y voz. No sustituye una historia clínica electrónica ni un sistema clínico de producción; usar únicamente datos ficticios.

## Enlaces

- **Aplicación publicada (Render):** <https://clinica-vida-salud-p56z.onrender.com/>
- **Repositorio (GitHub):** <https://github.com/yllbbtjqmss/clinica-vida-salud>
- **Historial de `main`:** <https://github.com/yllbbtjqmss/clinica-vida-salud/commits/main/>

El acceso es autenticado; las credenciales se entregan de forma privada.

<a id="funcionalidades"></a>

## Capacidades del sistema actual

| Módulo | Alcance implementado y verificado |
|---|---|
| Autenticación y roles | Sesiones del servidor con cookies HttpOnly, acceso por usuario o correo, CSRF, bloqueo tras 5 fallos por sesión y autorización de rol en cada petición (403 proveniente del servidor, no de enlaces ocultos) |
| Pacientes y usuarios | Altas, ediciones, activación/desactivación, vínculo cuenta–ficha y restablecimientos auditables |
| Médicos y especialidades | Fichas profesionales y catálogo de especialidades con unicidad normalizada; asignación N:M médico–especialidad |
| Horarios y disponibilidad | Horarios semanales por médico con Bloques de atención calculados en servidor, riesgo de solapamiento resuelto por PK/FK y restricciones `EXCLUDE USING gist` |
| Reservas | Reserva del paciente por especialidad/médico/bloque, código de reserva, bloqueo temporal de hasta 10 minutos y vencimiento sin cron; reprogramación conservando identidad y cancelación con confirmación |
| Pagos | **Simulación académica**: pago presencial verificado (efectivo o QR de recepción) y pago en línea con tarjeta/QR sintéticos de solo lectura; sin transacciones bancarias reales |
| Fichas y comprobantes | Ficha de reserva y comprobante administrativo compatibles con impresión/PDF del navegador; fecha de emisión en formato boliviano DD/MM/AAAA HH:mm |
| Lista de espera | Registro con preferencias (especialidad, médico opcional, rango de fechas y horas), estados `activa`, `retirada` y `asignada`, compatible con bloqueos; asignación a cita con índices únicos que evitan duplicados |
| Lumi (asistente) | Chat por sesión autenticada, navegación guiada por rol (enlaces con revalidación en servidor), consultas de catálogo local (especialidades y médicos), historial limitado por sesión, activación y lectura por voz; preferencias administrativas editables (`nombre`, saludo y tono) almacenadas en `lumi_config` |
| Reportes | Secciones administrativas con filtros, indicadores, gráficos CSS e impresión del navegador |
| Despliegue | Docker PHP 8.2 + Apache, rama `main` conectada en Render, PostgreSQL separado con TLS, esquema de migraciones aplicado manualmente con respaldo previo |

<a id="roles-permisos"></a>

## Roles y permisos

| Rol | Funcionalidades principales |
|---|---|
| `administrador` | Módulo completo: usuarios y vínculos, pacientes, médicos, especialidades, horarios, citas, pagos (incluida la aprobación del simulador), reportes, lista de espera y configuración de Lumi |
| `recepcionista` | Pacientes, citas, pagos, lista de espera y agenda administrativa. Sin administración de usuarios, médicos, especialidades ni reportes financieros administrativos |
| `medico` | `mi_agenda.php`: citas propias, horarios y perfiles vinculación pagos completados |
| `paciente` | Reserva propia, mis citas, mis pagos y ficha/comprobante propio sin acceso a datos de terceros |

La separación se aplica en el servidor; los IDs propios siempre se deriven de la sesión y no de la URL.

<a id="base-datos"></a>

## Base de datos (18 tablas)

El esquema parte de [database/clinica_vida_salud.sql](database/clinica_vida_salud.sql) (ocho tablas con roles, usuarios, personas y citas) y se completa con migraciones manuales en orden, cada una aplicada una sola vez con respaldo previo:

1. [`20261104_reservas_pagos.sql`](database/migraciones/20261104_reservas_pagos.sql): `tarifas_consulta`, `historial_tarifas`, `reservas_temporales`, `pagos`, `intentos_pago`, `movimientos_pago`, `bloqueos_agenda` con inmutabilidad de pagos y auditoría (historial y preservación financiera).
2. [`20261105_metodos_online.sql`](database/migraciones/20261105_metodos_online.sql): `metodo_online` (tarjeta/QR) con control `ck_pago_metodo_online` y `solicitudes_pago_prueba` auditada; no reetiqueta cobros históricos.
3. [`20261106_001_crear_lista_espera.sql`](database/migrations/20261106_001_crear_lista_espera.sql): `lista_espera` con preferencias, FK compuesta médico–especialidad y estados coherentes (`estado = 'asignada'` ⇔ `id_cita_asignada` no nulo); requiere la extensión `btree_gist`.
4. [`20261107_lumi_config.sql`](database/migraciones/20261107_lumi_config.sql): `lumi_config` (fila única `id=1`: nombre, saludo, tono, tema, voz, responsable y fecha; FK a `usuarios`) para las preferencias de Lumi. Se aplica manualmente con respaldo previo; no se debe repetir si la tabla ya existe.

Los estados de pagos (`pendiente`, `procesando`, `pagado`, `fallido`, `vencido`, `anulado`, `reembolso_pendiente`, `reembolsado`) y de citas (`programada`, `atendida`, `cancelada`, `inasistencia`, y `confirmada` disponible en el esquema) se conservan con inmutabilidad de movimientos y solicitud de devolución asistida. Los pagos y cambios nunca eliminan el historial financiero.

<a id="gestion-demo"></a>

## Pagos con simulación académica

- Tarjeta: **PAN, vencimiento y CVV simulados de solo lectura** provistos por la pantalla; el servidor recibe una referencia controlada, nunca datos de tarjeta.
- QR: imagen fija de demostración (`assets/img/qr-pago-demo.png`) con importe y referencia; sin generador online, sin pasarela bancaria.
- Aprobación de pagos en línea: únicamente el administrador registra el resultado (aprobado, rechazado, vencido) y ambos libros (presencial/demostración) quedan separados en los reportes.
- **No se realizan transacciones reales**; la respaldo financiero es de demostración académica y usa mensajes ficticios.

<a id="lumi"></a>

## Lumi, la asistente de la clínica

- **Chat:** mensajes por sesión validada, límite de consultas por ventana de tiempo y conversación reconstruible dentro de la sesión; historial limitado, sin registros de contenido en la base.
- **Navegación guiada:** frases de catálogo y guía local generan enlaces según el rol; el botón se activa solo si el servidor autura la función del destinatario (revalidación en `lumi_api.php?accion=ir` con 403 si no corresponde).
- **Catálogos:** consultas locales de especialidades y médicos sin exponer datos clínicos.
- **Voz:** activación por voz (palabra de despertar) y lectura de respuesta en el navegador mediante `lumi-voz.js`; se usa sólo con el permiso del micrófono del usuario y sin conservar audio. La prueba de micrófono no fue validada de forma automática; quedará pendiente de recorrido manual en Render.
- **Preferencias (administrador):** pantalla `lumi.php` conectada a `lumi_config`: nombre visible, mensaje de bienvenida y tono; el nombre y el saludo se reflejan en la conversación del chat. Tema y voz quedan guardados pero **aún sin cambio visual o audible automatizado** (reservados para una siguiente etapa).
- **Gemini y claves deshabilitadas en producción:** `CVS_AI_ENABLED=0` en Render; el chat funciona con guías, navegación y catálogos locales. La activación y claves del proveedor (p. ej. Gemini) se configuran sólo en el servidor y nunca desde el formulario administrativo. Lumi no consulta expedientes ni ofrece diagnósticos o tratamientos; se usa únicamente con mensajes ficticios sin información personal.

<a id="tecnologias"></a>

## Tecnologías

PHP **8.2** (Docker `php:8.2-apache-bookworm` con `pdo_pgsql`), **PostgreSQL 18**, JavaScript nativo sin librerías, Apache, Docker/Render y Git/GitHub. Sin Composer ni npm.

<a id="variables-entorno"></a>

## Variables de entorno de despliegue

Definir los secretos únicamente en el entorno del servicio (Render), nunca en el repositorio. PHP **no carga `.env` automáticamente**; la prioridad de conexión está implementada en [`includes/conexion.php`](includes/conexion.php).

| Variable | Uso |
|---|---|
| `CVS_DB_HOST` / `CVS_DB_PORT` / `CVS_DB_NAME` / `CVS_DB_USER` / `CVS_DB_PASSWORD` | Conexión PostgreSQL (host interno en Render, con TLS `require`) |
| `CVS_DB_SSLMODE` | `require` en Render |
| `CVS_HTTPS` | `1` detrás del proxy HTTPS de Render (cookies Secure) |
| `PORT` | Puerto de escucha del contenedor (predeterminado `10000`) |
| `CVS_PAGOS_DEMO` | Habilita el simulador académico de pagos en línea (tarjeta/QR con datos sintéticos); sin valor hay simulador por defecto en el código. No es una pasarela real |
| `CVS_AI_ENABLED` | Proveedor IA (Gemini); **debe mantenerse `0` en Render**: el chat funciona con catálogos locales |
| `CVS_AI_MODEL` / `GEMINI_API_KEY` | Solo si se activa el proveedor localmente; definir nunca desde el formulario administrativo, y sin valores reales en el repositorio |

<a id="-instrucciones-para-ejecutar-localmente"></a>

## Instalación local (Windows · XAMPP · PostgreSQL)

1. **Clonar y situarse:**

```powershell
cd C:\xampp\htdocs
git clone https://github.com/yllbbtjqmss/clinica-vida-salud.git
cd clinica-vida-salud
```

2. **Preparar la base** (solo instalación nueva; una base ya finalizada no debe demolirse):

```powershell
psql -X -h localhost -p 5432 -U postgres -W -v ON_ERROR_STOP=1 -c "CREATE DATABASE clinica_vida_salud WITH ENCODING 'UTF8';"
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\clinica_vida_salud.sql
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261104_reservas_pagos.sql
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261105_metodos_online.sql
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migrations\20261106_001_crear_lista_espera.sql
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261107_lumi_config.sql
```

La extensión `btree_gist` la crea `clinica_vida_salud.sql`; sin ella, la migración de lista de espera fallará. Ajustar puerto/usuario a la instalación local. Cada migración se aplica una sola vez.

3. **Configurar la conexión privada** fuera del repositorio: crear `C:/xampp/config_clinica/database.php` (PHP lo carga automáticamente para XAMPP) con una instancia `PDO` de PostgreSQL, o exportar variables `CVS_DB_*` para el proceso PHP/Apache. Los marcadores son de referencia; no subir el archivo ni compartir su contenido. Ver ejemplo en [`.env.example`](.env.example).

4. **Comprobar el entorno y crear el administrador inicial** si falta:

```powershell
& "C:\xampp\php\php.exe" verificar.php
```

<a id="administrador-inicial"></a>

### Administrador inicial

Cuenta única de administrador creada por CLI con entrada oculta (sin contraseñas en comandos ni archivos):

```powershell
$OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$nombre  = Read-Host 'Nombre del administrador inicial'
$secreto = Read-Host 'Contraseña (mínimo 12 caracteres; máximo 72 bytes)' -AsSecureString
$repetir = Read-Host 'Confirmación' -AsSecureString
$b1 = [IntPtr]::Zero; $b2 = [IntPtr]::Zero
try {
    $b1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secreto)
    $b2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($repetir)
    @{ nombre_usuario = $nombre
       contrasena    = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b1)
       confirmacion  = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b2) } |
        ConvertTo-Json -Compress |
        & "C:\xampp\php\php.exe" scripts\crear_administrador.php --entrada-powershell
} finally {
    if ($b1 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b1) }
    if ($b2 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b2) }
    $datos = $null; $secreto = $null; $repetir = $null
}
```

Luego abrir <http://localhost/clinica-vida-salud/login.php> y, desde **Usuarios**, crear las demás cuentas vinculando fichas existentes de médicos/pacientes.

<a id="render"></a>

## Despliegue en Render

[(Aplicación publicada)](https://clinica-vida-salud-p56z.onrender.com/) · [Guía de despliegue](deploy/DESPLIEGUE.md).

- Servicio Web **Docker** con `Dockerfile` en la raíz, rama **`main`** conectada; el build copia solo el código necesario y no crea cuentas ni importa SQL.
- PostgreSQL como servicio separado con `sslmode=require`; el esquema de 18 tablas ya aplicado en la instancia de producción, siempre con respaldo previo (verificado manualmente).
- Configuración del contenedor: [`deploy/apache.conf`](deploy/apache.conf) bloquea el acceso HTTP a `includes/`, `scripts/`, diagnósticos y archivos de configuración; [`deploy/php.ini`](deploy/php.ini) oculta errores al navegador.
- Health check sobre `/login.php` acredita respuesta web, no la conexión de la base.
- Plan gratuito: el servicio puede suspenderse por inactividad y las sesiones viven en `/tmp` contenerizado; considerar al preparar la demostración.

<a id="pruebas"></a>

## Pruebas y regresas ejecutadas

Resumen del cierre del proyecto (noviembre de 2026), con evidencia versionada en el repositorio:

| Suite | Alcance | Evidencia |
|---|---|---|
| Regresión HTTP de integración (4 roles × todos los módulos) | 371 comprobaciones HTTP: autenticación, permisos (403/200 esperados), reservas, pagos demo, fichas, lista de espera, Lumi (estado, catálogo, navegación, CSRF, límites) | Registro de ejecución en bitácora; servidor aislado con base de prueba |
| Navegador Playwright (4 roles × escritorio/móvil) | Login real, chat Lumi, navegación guiada, historial de sesión, sin errores JS ni peticiones externas | `tests/lumi_visual.cjs` y comando de cierre |
| Suite de reservas y pagos | 50 comprobaciones en entorno aislado, incluidos flujos repetidos por concurrencia y verificando tarjeta y QR sintéticos | [`documentacion/pruebas-mejora-pagos.json`](documentacion/pruebas-mejora-pagos.json) |
| Integración Lumi + lista de espera | Migración `lumi_config` ensayada en base aislada (estructura, fila inicial, FK/CHECK), formulario admin guardar/recuperar, denegación 403 a otros roles, chat y navegación intactos | [`documentacion/LUMI_INTEGRACION_LOCAL.md`](documentacion/LUMI_INTEGRACION_LOCAL.md) |

Para repetir la suite de reservas y pagos sin navegador (45 comprobaciones):

```powershell
python tests\ejecutar_reservas_pagos.py --evidencia documentacion\pruebas-mejora-pagos.json
```

Todas las suites usan bases locales o efímeras con datos sintéticos; nunca la base de producción.

<a id="bitacoras"></a>

## Bitácoras del proyecto

La [bitácora de los Ciclos 1 y 2](documentacion/bitacoras/ciclos-1-y-2.md) reconstruye actividades, propósito, resultado y evidencia de las primeras fases. El desarrollo posterior (reservas y pagos, Lumi y lista de espera) se recorre en [la guía técnica](documentacion/RESERVAS_Y_PAGOS.md) y en la documentación de integración de Lumi; el Pull Request [#3](https://github.com/yllbbtjqmss/clinica-vida-salud/pull/3) consolidó esa integración en `main` (commit de fusión `56c9f0bd0eba7cab841be443326c089c2eb2a783`).

| Fecha / fase | Actividad | Resultado |
|---|---|---|
| Octubre 2026 · Ciclo 1 | Núcleo administrativo de citas, informe y modelo | Base funcional versionada |
| Octubre 2026 · Ciclo 2 | Usuarios web, vínculos, paneles personales y Docker | Incremento integrado ([`3415f3e`](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/3415f3e)) |
| Noviembre 2026 · reservas y pagos | Migraciones 20261104/05, fila de pagos, tarjeta y QR sintéticos, lista de espera 20261106 | Módulos probados en entorno aislado |
| 07/11/2026 · Lumi | Voz, navegación, catálogos, historial, `lumi_config` (20261107) y despliegue con respaldo | PR #3 fusionado a `main`; producción verificada |
| Noviembre 2026 · cierre | Correcciones visuales (ficha, emisión, pagos demo discretos) y README al estado actual | Preparación final para entrega |

<a id="usuarios-demostracion"></a>

## Usuarios de demostración

No se publican contraseñas ni credenciales de PostgreSQL. Las cuentas se crean desde **Usuarios** (administrador) o por la [alta inicial CLI](#administrador-inicial), con contraseñas de mínimo 12 caracteres entregadas por canal privado. Para la demostración académica usar perfiles y pacientes **ficticios**.

<a id="limitaciones"></a>

## Limitaciones conocidas

- Voz de Lumi: implementada en navegador, pendiente de prueba manual de micrófono en Render; lectura y despertar dependen del soporte del navegador.
- Tema oscuro y voz configurados en `lumi_config` permanecen guardados sin efecto visual/audible todavía (etapa reservada).
- Pagos: simulación académica; sin relación con pasarelas reales. `confirmada` existe en el esquema pero no hay operación web específica de confirmación de cita.
- La exclusión de intervalos protege por médico, no por paciente; no se acredita limitación centralizada por IP o auditoría de seguridad completa.
- Plan gratuito de Render: servicio suspendible y sesiones efímeras; PostgreSQL Free tiene vencimiento y límites de plan, verificar el panel antes de la defensa.

<a id="equipo"></a>

## Equipo y créditos

**Grupo 17 · Santa Cruz, Bolivia · 2-2026**

| Integrante | Cargo |
|---|---|
| Durán Pérez Melissa | Integrante del Grupo 17 |
| Vásquez Cordero Angélica Yandira | Integrante del Grupo 17 |

- **Universidad Autónoma Gabriel René Moreno** · Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones.
- **Materia:** Sistemas de Información I · **Docente:** MSc. Ing. Angélica Gazón Cuéllar.

<a id="reservas-pagos"></a>

## Documentación técnica complementaria

- [Guía técnica de reservas y pagos](documentacion/RESERVAS_Y_PAGOS.md): instalación de migraciones, prueba manual con PHP de XAMPP y recorrido de las 50 comprobaciones.
- [Recorrido QR y de imagen fija de pago](documentacion/QR_PAGO_FIJO.md).
- [Integración local de Lumi y lista de espera](documentacion/LUMI_INTEGRACION_LOCAL.md): validación por roles, voz, catálogos y navegación.
- [Despliegue en Render](deploy/DESPLIEGUE.md) y su registro de revisión [REVISION.md](deploy/REVISION.md).
- Informe académico del Ciclo 1: [PDF](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.pdf).
