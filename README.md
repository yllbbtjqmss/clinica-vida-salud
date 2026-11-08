# CLÍNICA VIDA SALUD

**Sistema integral de gestión de servicios médicos**

Plataforma web para administrar **pacientes, médicos, especialidades, horarios, citas y pagos**, con acceso diferenciado para administradores, recepcionistas, médicos y pacientes.

Permite reservar, reprogramar y cancelar citas, gestionar una lista de espera, controlar la asistencia, registrar pagos presenciales y **simular pagos en línea con tarjeta y QR**, consultar comprobantes y generar reportes administrativos y financieros. También incorpora **Lumi**, una asistente virtual con Google Gemini, navegación guiada e interacción por voz.

Desarrollado como proyecto académico con **PHP 8.2, PostgreSQL, JavaScript, Apache y Docker**, y publicado en Render.

**[Abrir Clínica Vida Salud](https://clinica-vida-salud-p56z.onrender.com/)** · **[Repositorio](https://github.com/yllbbtjqmss/clinica-vida-salud)**

> **Acceso:** se requiere una cuenta autorizada. Las credenciales se entregan de forma privada.


---

## Contenido

- [Funcionalidades del sistema](#funcionalidades)
- [Roles y permisos](#roles-y-permisos)
- [Base de datos](#base-de-datos)
- [Gestión de pagos](#gestion-de-pagos)
- [Lumi, asistente virtual](#lumi-asistente-virtual)
- [Tecnologías](#tecnologias)
- [Variables de entorno](#variables-de-entorno)
- [Instalación local](#instalacion-local)
- [Administrador inicial](#administrador-inicial)
- [Despliegue en Render](#despliegue-en-render)
- [Pruebas realizadas](#pruebas-realizadas)
- [Bitácoras del proyecto](#bitacoras-del-proyecto)
- [Equipo y créditos](#equipo-y-creditos)
- [Documentación técnica](#documentacion-tecnica)

---

<a id="funcionalidades"></a>

## Funcionalidades del sistema

| Módulo | Funciones principales |
|:--|:--|
| **Autenticación y roles** | Inicio de sesión por usuario o correo, cookies `HttpOnly`, protección CSRF, bloqueo tras cinco intentos fallidos por sesión y control de permisos en el servidor. |
| **Pacientes y usuarios** | Registro, edición, activación o desactivación de cuentas; vinculación entre usuarios y fichas; restablecimientos auditables. |
| **Médicos y especialidades** | Gestión de fichas profesionales, especialidades y relación de múltiples especialidades por médico. |
| **Horarios y disponibilidad** | Horarios semanales y bloques de atención calculados por el servidor, con claves y restricciones `EXCLUDE USING gist` para evitar superposiciones. |
| **Reservas de citas** | Selección de especialidad, médico y horario; código de reserva; bloqueo temporal de hasta 10 minutos que vence sin cron; reprogramación que conserva la cita y cancelación con confirmación. |
| **Pagos** | Registro de pagos presenciales (efectivo o QR en recepción) y simulación académica de pagos en línea con tarjeta o QR, con verificación administrativa. |
| **Fichas y comprobantes** | Consulta e impresión/PDF de fichas y comprobantes mediante el navegador, con fechas en formato boliviano (`DD/MM/AAAA HH:mm`). |
| **Lista de espera** | Solicitudes por especialidad, médico opcional, rango de fechas y horas; estados `activa`, `retirada` y `asignada`, con controles contra asignaciones duplicadas. |
| **Lumi** | Chat con Gemini, orientación según el rol, consulta de especialidades y médicos, historial de sesión, voz y ajustes administrativos. |
| **Reportes** | Consultas administrativas con filtros, indicadores, gráficos e impresión desde el navegador. |
| **Despliegue** | Aplicación PHP/Apache en Docker, publicada desde `main` en Render y conectada a PostgreSQL mediante una conexión segura. |

<a id="roles-y-permisos"></a>

## Roles y permisos

| Rol | Acceso principal |
|:--|:--|
| **Administrador** | Gestión completa de usuarios, pacientes, médicos, especialidades, horarios, citas, pagos, reportes, lista de espera y configuración de Lumi. También revisa los pagos simulados. |
| **Recepcionista** | Gestión de pacientes, citas, pagos, lista de espera y agenda administrativa; sin permisos de administración general. |
| **Médico** | Consulta de su agenda, citas y horarios propios mediante `mi_agenda.php`, y acceso a la información de sus perfiles vinculados. |
| **Paciente** | Reserva y consulta de sus propias citas, pagos, fichas y comprobantes, sin acceso a información de otros pacientes. |

Los permisos se verifican en el **servidor**, no solamente ocultando opciones de la interfaz.

---

<a id="base-de-datos"></a>

## Base de datos

El sistema utiliza **PostgreSQL 18** y cuenta con **18 tablas** organizadas por función.

### Usuarios y atención médica

| Tabla | Descripción |
|:--|:--|
| `roles` | Define administrador, recepcionista, médico y paciente. |
| `usuarios` | Almacena las cuentas y credenciales de acceso. |
| `pacientes` | Registra la información de los pacientes. |
| `medicos` | Contiene las fichas de los profesionales médicos. |
| `especialidades` | Registra las especialidades disponibles. |
| `medico_especialidad` | Vincula médicos con una o varias especialidades. |
| `horarios` | Organiza los días y las horas de atención. |
| `citas` | Registra las citas médicas y sus estados. |

### Reservas y pagos

| Tabla | Descripción |
|:--|:--|
| `tarifas_consulta` | Define los precios de las consultas. |
| `historial_tarifas` | Conserva los cambios de tarifas. |
| `reservas_temporales` | Administra bloqueos temporales durante la reserva. |
| `pagos` | Registra importes, modalidades y estados de pago. |
| `intentos_pago` | Guarda los intentos de pago. |
| `movimientos_pago` | Conserva el historial de movimientos financieros. |
| `bloqueos_agenda` | Ayuda a evitar reservas simultáneas del mismo horario. |
| `solicitudes_pago_prueba` | Registra las solicitudes de verificación de pagos simulados con tarjeta o QR. |

### Lista de espera y asistente virtual

| Tabla | Descripción |
|:--|:--|
| `lista_espera` | Administra solicitudes de pacientes que esperan un turno disponible. |
| `lumi_config` | Guarda nombre, bienvenida, tono y preferencias de Lumi. |

Las tablas se relacionan mediante claves primarias y foráneas para mantener la consistencia de los datos.

<a id="gestion-de-pagos"></a>

## Gestión y verificación de pagos

Clínica Vida Salud permite gestionar pagos **presenciales** y demostrar el flujo de pagos **en línea simulados**, sin conectarse a una pasarela bancaria real.

- **Tarjeta (simulación):** el paciente elige esta opción y el sistema genera una solicitud con referencia de seguimiento. No captura datos de una tarjeta bancaria real.
- **Código QR (simulación):** muestra un QR de demostración, el importe y una referencia de la operación.
- **Pago presencial:** recepción registra y verifica el pago asociado a la cita, por efectivo o QR de recepción.
- **Verificación:** el personal autorizado revisa los pagos; la aprobación o el rechazo de las solicitudes en línea simuladas se gestiona desde administración.
- **Seguimiento:** cada operación conserva un estado, una referencia y su historial.
- **Comprobantes:** el sistema permite consultar e imprimir los comprobantes correspondientes.

> **Importante:** los pagos con tarjeta y QR de la plataforma son parte de una **simulación académica** y no procesan dinero real.

---

<a id="lumi-asistente-virtual"></a>

## Lumi, asistente virtual

**Lumi** es la asistente de Clínica Vida Salud, integrada con **Google Gemini** para responder preguntas y orientar a los usuarios dentro del sistema.

- **Chat inteligente:** responde consultas mediante inteligencia artificial.
- **Navegación guiada:** indica cómo acceder a las funciones permitidas para cada rol.
- **Información de la clínica:** consulta especialidades y médicos disponibles.
- **Interacción por voz:** permite dictar mensajes y escuchar respuestas, según las capacidades y permisos del navegador.
- **Personalización:** el administrador puede modificar el nombre, la bienvenida y el tono de comunicación.
- **Privacidad y permisos:** no consulta historiales clínicos privados ni guarda el contenido del chat en la base de datos. Las consultas dirigidas a Gemini se procesan mediante el servicio externo de Google.
- **Disponibilidad:** la integración con Gemini está habilitada para usuarios autenticados en la aplicación desplegada en Render.

Lumi es una herramienta de **orientación** y no reemplaza la evaluación de un profesional de salud.

<a id="tecnologias"></a>

## Tecnologías

| Tecnología | Uso |
|:--|:--|
| **PHP 8.2** | Desarrollo del sistema y lógica del servidor. |
| **PostgreSQL 18** | Base de datos relacional. |
| **JavaScript nativo** | Interacciones de la interfaz y funciones de Lumi. |
| **Apache** | Servidor web. |
| **Docker** | Empaquetado y despliegue de la aplicación. |
| **Render** | Publicación del sistema y servicios de base de datos. |
| **Google Gemini** | Generación de respuestas de Lumi. |
| **Git y GitHub** | Control de versiones y repositorio del proyecto. |

El contenedor usa `php:8.2-apache-bookworm` con `pdo_pgsql`. El proyecto no requiere Composer ni npm para ejecutar la aplicación.

---

<a id="variables-de-entorno"></a>

## Variables de entorno

Las variables se configuran desde **Render → Environment**, o directamente en el entorno de ejecución cuando se trabaja de forma local.

**Las contraseñas y claves privadas nunca deben subirse a GitHub.** PHP no carga archivos `.env` automáticamente; la conexión a la base de datos se gestiona desde [`includes/conexion.php`](includes/conexion.php).

| Variable | Función |
|:--|:--|
| `CVS_DB_HOST`, `CVS_DB_PORT`, `CVS_DB_NAME`, `CVS_DB_USER`, `CVS_DB_PASSWORD` | Configuración de conexión a PostgreSQL. |
| `CVS_DB_SSLMODE` | Conexión segura a PostgreSQL; en Render se usa `require`. |
| `CVS_HTTPS` | Se configura en `1` detrás del proxy HTTPS de Render. |
| `PORT` | Puerto del contenedor; valor predeterminado: `10000`. |
| `CVS_PAGOS_DEMO` | Activa el simulador académico de pagos con tarjeta y QR. |
| `CVS_AI_ENABLED` | Habilita la integración de Lumi con Gemini cuando su valor es `1`. |
| `CVS_AI_MODEL` | Identificador del modelo de Gemini utilizado. |
| `GEMINI_API_KEY` | Clave privada para autenticar las solicitudes a Gemini. |

### Configuración de Gemini

En la aplicación publicada, Lumi tiene habilitada su integración con Gemini. Para habilitarla en otro entorno, se necesitan las siguientes variables:

```env
CVS_AI_ENABLED=1
CVS_AI_MODEL=NOMBRE_DEL_MODELO
GEMINI_API_KEY=TU_CLAVE_PRIVADA
```

Los valores anteriores son **ejemplos**: hay que utilizar un modelo válido y una clave privada propia. No se deben compartir ni guardar claves reales en el repositorio.

---

<a id="instalacion-local"></a>

## Instalación local (Windows · XAMPP · PostgreSQL)

### 1. Clonar el repositorio

Desde PowerShell, ubicar el proyecto en la carpeta de XAMPP:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/yllbbtjqmss/clinica-vida-salud.git
cd clinica-vida-salud
```

### 2. Preparar la base de datos

**Solo para instalaciones nuevas.** Si la base ya existe, no se debe volver a crear ni importar el esquema encima de los datos actuales. Las migraciones se aplican en el orden indicado y **una sola vez**.

```powershell
psql -X -h localhost -p 5432 -U postgres -W -v ON_ERROR_STOP=1 -c "CREATE DATABASE clinica_vida_salud WITH ENCODING 'UTF8';"

psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\clinica_vida_salud.sql

psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261104_reservas_pagos.sql

psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261105_metodos_online.sql

psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migrations\20261106_001_crear_lista_espera.sql

psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\migraciones\20261107_lumi_config.sql
```

La base inicial crea la extensión `btree_gist`, necesaria para la lista de espera. Ajustar el puerto o el usuario si la instalación local de PostgreSQL es distinta.

### 3. Configurar la conexión privada

Crear el archivo `C:/xampp/config_clinica/database.php` **fuera del repositorio**, con la conexión PDO de PostgreSQL, o configurar las variables `CVS_DB_*` en el entorno del proceso PHP/Apache.

Consultar el archivo [`.env.example`](.env.example) como referencia. No publicar la configuración privada.

### 4. Verificar la instalación

Ejecutar desde la raíz del proyecto:

```powershell
& "C:\xampp\php\php.exe" verificar.php
```

Si no existe una cuenta administrativa, crearla mediante el procedimiento de [Administrador inicial](#administrador-inicial).

### 5. Configurar Lumi localmente (opcional)

Para utilizar Gemini en local se deben definir `CVS_AI_ENABLED`, `CVS_AI_MODEL` y `GEMINI_API_KEY` en el **entorno de PHP**, con valores válidos y privados, como se explica en [Configuración de Gemini](#configuracion-de-gemini).

**Nota:** la versión publicada en Render ya tiene habilitada la integración de Lumi con Gemini. No es necesario instalar el proyecto localmente para usar la aplicación web.

---

<a id="administrador-inicial"></a>

## Administrador inicial

Para comenzar a utilizar una instalación nueva, se debe crear la cuenta del **administrador inicial**. El siguiente procedimiento solicita el nombre y una contraseña de forma privada desde PowerShell.

La contraseña no se escribe directamente en el comando ni se guarda en el repositorio.

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

Una vez creada la cuenta, ingresar a:

**[http://localhost/clinica-vida-salud/login.php](http://localhost/clinica-vida-salud/login.php)**

Desde **Usuarios**, el administrador puede crear y gestionar otras cuentas, asignar roles y vincular los registros existentes de pacientes y médicos.

---

<a id="despliegue-en-render"></a>

## Despliegue en Render

**[Aplicación publicada](https://clinica-vida-salud-p56z.onrender.com/)** · **[Guía de despliegue](deploy/DESPLIEGUE.md)**

Clínica Vida Salud está desplegada en Render y permite acceder al sistema desde internet a través de cuentas autorizadas.

- **Servidor:** PHP y Apache mediante Docker, conectado a la rama `main` del repositorio.
- **Base de datos:** PostgreSQL como servicio separado, con conexión TLS (`sslmode=require`) y el esquema de 18 tablas.
- **Seguridad:** protección de rutas privadas desde [`deploy/apache.conf`](deploy/apache.conf) y configuración de errores desde [`deploy/php.ini`](deploy/php.ini).
- **Lumi:** integración con Gemini habilitada en el entorno publicado.
- **Disponibilidad:** en el plan gratuito, el servicio puede suspenderse por inactividad y tardar en reactivarse.

El *health check* de `/login.php` comprueba que el sitio responde, pero **no garantiza por sí solo** el funcionamiento de la base de datos.

<a id="pruebas-realizadas"></a>

## Pruebas y regresiones realizadas

Durante el desarrollo se comprobó el funcionamiento, la seguridad y la integración de los módulos.

| Prueba | Alcance o resultado |
|:--|:--|
| **Funcionamiento general** | 371 comprobaciones HTTP de autenticación, permisos, reservas, pagos y lista de espera. |
| **Interfaz y navegación** | Pruebas con Playwright en escritorio y móvil, incluyendo la interfaz de Lumi y la navegación guiada. |
| **Reservas y pagos** | 50 comprobaciones correctas de reservas, pagos simulados, controles de acceso y operaciones simultáneas. |
| **Integración de Lumi** | Pruebas de chat, roles, historial, voz y configuración administrativa con servicios de IA y audio simulados. |
| **Gemini en Render** | Integración habilitada y prueba manual de conversación en la aplicación publicada. |

Las pruebas automatizadas utilizaron **bases aisladas y datos ficticios**; no modificaron la base de producción. La prueba manual en Render se realizó por separado.

---

<a id="bitacoras-del-proyecto"></a>

## Bitácoras del proyecto

Las bitácoras documentan las etapas principales del desarrollo, desde la primera versión hasta la publicación de Clínica Vida Salud en Render.

- [Bitácora de los ciclos 1 y 2](documentacion/bitacoras/ciclos-1-y-2.md)
- [Documentación de reservas y pagos](documentacion/RESERVAS_Y_PAGOS.md)
- [Integración de Lumi y lista de espera](documentacion/LUMI_INTEGRACION_LOCAL.md)

| Fecha / fase | Actividad | Resultado |
|:--|:--|:--|
| **Octubre 2026 · Ciclo 1** | Diseño de la base de datos, gestión de citas y estructura inicial. | Primera versión funcional. |
| **Octubre 2026 · Ciclo 2** | Desarrollo de usuarios, roles, paneles y configuración de Docker. | Módulos integrados y funcionales. |
| **Noviembre 2026 · Reservas y pagos** | Implementación de reservas, pagos de demostración con tarjeta y QR, y lista de espera. | Funciones implementadas y probadas. |
| **07/11/2026 · Lumi** | Integración de chat, navegación guiada, voz y configuración administrativa. | Integración mediante el [Pull Request #3](https://github.com/yllbbtjqmss/clinica-vida-salud/pull/3) en `main`. |
| **Noviembre 2026 · IA** | Activación de Google Gemini en Render y prueba manual del chat. | Asistente inteligente disponible en la aplicación publicada. |
| **Noviembre 2026 · Etapa final** | Correcciones de interfaz, revisión de módulos y actualización del README. | Preparación para la presentación académica. |

---

<a id="equipo-y-creditos"></a>

## Equipo y créditos

**Grupo 17 · Gestión 2-2026**

**Integrantes**

- **Durán Pérez Melissa** — 224052349
- **Vasquez Cordero Angelica Yandira** — 224029460

**Universidad:** Universidad Autónoma Gabriel René Moreno (UAGRM)  
**Facultad:** Ingeniería en Ciencias de la Computación y Telecomunicaciones  
**Materia:** Sistemas de Información I  
**Docente:** MSc. Ing. Angélica Gazón Cuéllar  

---

<p align="center"><strong>Clínica Vida Salud · Grupo 17 · UAGRM · 2026</strong></p>
