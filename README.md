<a id="presentacion"></a>

# CLÍNICA VIDA SALUD

**Sistema web para la gestión administrativa de citas médicas y consultas personales por rol.**

Proyecto universitario que centraliza pacientes, oferta médica, horarios y reservas para reducir la dispersión de registros y controlar conflictos de agenda. Desarrollado en PHP y PostgreSQL, integra un núcleo administrativo y paneles de consulta para médicos y pacientes. No sustituye una historia clínica electrónica ni un sistema clínico de producción.

> **[Abrir la aplicación publicada en Render](https://clinica-vida-salud-p56z.onrender.com/)**
>
> Acceso autenticado con cuentas autorizadas; las credenciales se entregan de forma privada.

[Repositorio en GitHub](https://github.com/yllbbtjqmss/clinica-vida-salud) · [Historial de commits de main](https://github.com/yllbbtjqmss/clinica-vida-salud/commits/main/)

| Referencia | Estado al 02/11/2026 |
|---|---|
| Desarrollo | Ciclos 1 y 2 integrados; funcionalidades y pendientes detallados abajo |
| Línea base de código | `3415f3e`, compartida por `main` y `ciclo2-desarrollo` locales y remotas |
| Integración | Fast-forward a `main`, sin conflictos; corroborado por el reflog local |
| Despliegue | Publicación y pruebas funcionales en Render confirmadas manualmente por el equipo |
| Revisión pública | Portada y `login.php` respondieron HTTP 200, sin autenticación; no equivale a repetir las pruebas funcionales |

## Índice

1. [Presentación](#presentacion)
2. [Descripción general y objetivos](#descripcion-general)
3. [Estado actual del proyecto](#estado-actual)
4. [Funcionalidades principales](#funcionalidades)
5. [Roles y permisos](#roles-permisos)
6. [Tecnologías utilizadas](#tecnologias)
7. [Arquitectura y estructura real](#arquitectura)
8. [Base de datos](#base-datos)
9. [Usuarios de prueba y demostración](#usuarios-demostracion)
10. [Requisitos previos](#requisitos)
11. [Instalación y configuración local](#instalacion-local)
12. [Despliegue en Render](#render)
13. [Manual breve de uso](#manual-uso)
14. [Pruebas funcionales y resultados](#pruebas)
15. [Bitácoras del proyecto](#bitacoras)
16. [Integrantes del equipo](#equipo)
17. [Organización del trabajo y control de versiones](#control-versiones)
18. [Limitaciones, mejoras futuras y conclusión](#limitaciones)
19. [Licencia y créditos](#creditos)

<a id="descripcion-general"></a>

## Descripción general y objetivos

### Contexto

El caso académico aborda la dificultad de coordinar fichas de pacientes, especialidades, profesionales y turnos cuando la información se administra por separado. El riesgo principal es reservar intervalos incompatibles o perder seguimiento de cancelaciones y cambios. No se dispone de mediciones de una clínica real que permitan cuantificar ahorros o mejoras operativas.

### Objetivo general

Desarrollar y documentar un sistema web que organice la oferta médica y gestione el ciclo administrativo de las citas, con acceso por roles, integridad relacional y consulta de información propia para médicos y pacientes.

### Objetivos específicos

- Centralizar el registro y mantenimiento de pacientes, médicos y especialidades.
- Definir horarios semanales y calcular bloques de atención disponibles.
- Registrar, reprogramar y cancelar citas con validaciones en PHP y restricciones en PostgreSQL.
- Separar las operaciones administrativas de las consultas personales mediante cuentas vinculadas a fichas existentes.
- Consultar agenda, historial y reportes administrativos sin confundirlos con una historia clínica.
- Conservar trazabilidad entre requisitos, implementación, pruebas y entregas académicas.

### Alcance por ciclo

| Ciclo | Alcance respaldado por código e historial |
|---|---|
| **Ciclo 1** | Autenticación y roles; pacientes, médicos, especialidades y horarios; reserva y prevención de solapamientos; reprogramación, cancelación, asistencia, agenda administrativa, historial y reportes. Informe académico con requisitos RF01–RF13, modelos y plan de pruebas. Las pantallas personales y la administración web de usuarios aún estaban pendientes en esa entrega. |
| **Ciclo 2** | Administración web de usuarios y vínculos; acceso por usuario o correo; agenda propia del médico; citas y perfil básico del paciente; ajustes de filtros, sesiones e interfaz; configuración por entorno y preparación Docker/Render. Publicación y recorridos manuales en Render confirmados posteriormente, en noviembre de 2026. |

El sistema aporta una fuente compartida de reservas, conservación de citas canceladas y resultados de asistencia, consulta diferenciada por rol e información agregada para administración. Estos son beneficios funcionales del diseño, no resultados estadísticos medidos en una institución.

<a id="estado-actual"></a>

## Estado actual del proyecto

**Implementado** indica código presente e inspeccionado. **Probado manualmente** identifica únicamente recorridos comunicados por el equipo en Render, no una regresión completa ni pruebas repetidas durante esta revisión documental.

| Módulo | Descripción | Implementación | Evidencia de prueba en Render |
|---|---|---|---|
| Autenticación y roles | Sesiones, acceso por usuario/correo y autorización en servidor | Implementado | Acceso de administrador y perfiles confirmado; matriz completa pendiente |
| Usuarios y vínculos | Alta, edición, actividad, restablecimiento y asociación a fichas | Implementado | Creación y vinculación de médico/paciente confirmadas |
| Pacientes | Fichas, búsqueda, edición y activación/desactivación | Implementado | Registro confirmado; validaciones exhaustivas pendientes |
| Médicos | Fichas y asignación de especialidades | Implementado | Registro confirmado; mantenimiento completo pendiente de regresión |
| Especialidades | Catálogo con unicidad normalizada y actividad | Implementado | Sin evidencia específica de prueba integral |
| Horarios y disponibilidad | Intervalos semanales y bloques disponibles | Implementado | Uso en reserva confirmado; fronteras y concurrencia pendientes |
| Citas | Registro y listado administrativo; control de solapamientos | Implementado | Reserva confirmada; concurrencia formal pendiente |
| Reprogramación y cancelación | Cambio de turno o cierre de reserva, conservando la cita | Implementado | Pruebas manuales confirmadas |
| Asistencia | Registro de `atendida` o `inasistencia` al finalizar el horario | Implementado | Resultados históricos reportados en Ciclo 1; sin nueva prueba confirmada en Render |
| Agenda administrativa e historial | Consultas por médico/fecha y por paciente | Implementado | Regresión completa no acreditada |
| Agenda y perfil médico | Identificación del médico vinculado, citas propias y horarios | Implementado, de consulta | Visualización de cita confirmada |
| Citas y perfil del paciente | Datos básicos y citas propias por período | Implementado, de consulta | Visualización de cita confirmada |
| Reportes | Seis secciones administrativas con filtros e impresión | Implementado | Consulta confirmada; combinaciones de filtros pendientes |
| Despliegue | Docker, PHP/Apache y PostgreSQL en Render | Desplegado, según confirmación del equipo | Recorridos manuales confirmados; HTTP 200 público verificado |

El incremento ya está en `main`; no se presenta como una rama pendiente de integración. No se identificó un módulo adicional en desarrollo con evidencia suficiente para etiquetarlo como tal. Las mejoras no implementadas se enumeran en [Limitaciones](#limitaciones).

<a id="funcionalidades"></a>

## Funcionalidades principales

- **Autenticación y acceso (RF01):** inicio por nombre de usuario o correo de la cuenta, cierre por POST, expiración por 30 minutos de inactividad, CSRF y validación de rol/actividad en cada petición. Cinco fallos generan un bloqueo de 15 minutos **por sesión**, no por cuenta o IP.
- **Usuarios:** el administrador crea y edita cuentas, cambia su actividad y restablece contraseñas. Puede vincular una ficha médica o de paciente según el rol, sin duplicar personas. Se protege al último administrador activo y se impide cambiar el propio rol. El restablecimiento invalida sesiones anteriores en su siguiente petición.
- **Pacientes (RF02):** alta, edición, búsqueda, activación/desactivación y acceso al historial de citas. La ficha incluye identificación y contacto, además de campos opcionales; no crea automáticamente una cuenta.
- **Médicos y especialidades (RF03–RF04):** registro profesional único, mantenimiento de fichas y asignación de una o varias especialidades. Las especialidades se crean, editan y activan/desactivan sin eliminar el historial relacionado.
- **Horarios (RF05):** configuración por médico, día de semana, hora inicial/final y duración por cita; edición y cambio de actividad. La disponibilidad se calcula con horarios activos y reservas vigentes.
- **Citas (RF06–RF09):** selección de paciente, especialidad, médico, fecha y bloque; código de reserva y fin calculado en servidor. La reprogramación conserva ID, código, paciente, médico y especialidad. La cancelación exige confirmación y admite motivo opcional; no elimina la cita.
- **Asistencia e historial (RF10 y RF12):** administración/recepción registra `atendida` o `inasistencia` cuando el horario ya finalizó; consulta histórica con búsqueda, filtros y paginación. No registra diagnósticos, tratamientos ni hora real de llegada.
- **Agenda (RF11):** administración/recepción consulta por médico y fecha. El médico vinculado ve únicamente sus citas por fecha y sus horarios semanales activos, junto con su identificación básica; no dispone de edición autónoma de perfil ni gestión de asistencia desde este panel.
- **Paciente:** consulta su perfil básico y citas próximas/en curso, anteriores o todas, incluidas las canceladas. Es una vista de lectura: reservar, reprogramar, cancelar y corregir fichas corresponde a administración/recepción.
- **Reportes (RF13):** citas por fecha, por médico, por especialidad, por estado, pacientes registrados y agenda diaria por médico. Incluye indicadores, gráficos CSS, filtros aplicables, tablas e impresión del navegador; no genera archivos CSV/PDF desde el servidor.
- **Panel de inicio:** indicadores administrativos, calendario mensual, disponibilidad del día y actividad reciente de citas. La actividad mostrada no constituye una auditoría de cambios.

Los estados admitidos son `programada`, `confirmada`, `atendida`, `cancelada` e `inasistencia`. **`confirmada` existe en el esquema y las consultas, pero no se encontró una operación específica para confirmar citas.**

<a id="roles-permisos"></a>

## Roles y permisos

Los cuatro roles están definidos tanto en [el SQL](database/clinica_vida_salud.sql) como en [`includes/auth.php`](includes/auth.php). No existe un rol de superadministrador.

| Rol real | Responsabilidad | Funcionalidades accesibles |
|---|---|---|
| `administrador` | Mantener cuentas, oferta médica y gestión administrativa | Usuarios y vínculos; pacientes; médicos; especialidades; horarios; citas y seguimiento; agenda administrativa; historial; reportes; panel de inicio |
| `recepcionista` | Coordinar pacientes y citas | Pacientes; registro, reprogramación, cancelación y asistencia de citas; historial; agenda administrativa y panel de inicio. Sin administración de usuarios, médicos, especialidades, horarios ni reportes |
| `medico` | Consultar su programación | `mi_agenda.php`: identificación del perfil vinculado, citas propias por fecha y horarios semanales activos |
| `paciente` | Consultar su información administrativa | `mis_citas.php`: perfil básico y citas propias por período, con paginación |

La separación se aplica en el servidor, no solo ocultando enlaces. Las vistas personales obtienen la identidad desde la sesión y la relación `id_usuario`, no desde un ID de otra persona enviado por URL. Requieren cuenta y ficha activas; sin vínculo válido muestran un aviso. Un rol no autorizado recibe 403; una página protegida sin sesión redirige al login.

<a id="tecnologias"></a>

## Tecnologías utilizadas

| Tecnología | Versión o evidencia confirmada | Función |
|---|---|---|
| PHP | Rama **8.2** en Docker; **8.2.12** en CLI local y revisión histórica | Procesamiento de páginas, sesiones y reglas de negocio |
| PDO / PDO_PGSQL | Extensiones PHP; `pdo_pgsql` se instala en el Dockerfile | Conexión y consultas preparadas a PostgreSQL |
| PostgreSQL | **18** indicado por el SQL; motor local **18.6** documentado; cliente `psql` **18.6** verificado | Persistencia, transacciones, claves e integridad de intervalos |
| HTML, CSS y JavaScript | HTML5, CSS propio y JavaScript nativo; sin librerías de frontend identificadas | Formularios, navegación, presentación y consultas `fetch` |
| Apache HTTP Server | **2.4.58 Win64** local; Apache incluido en la imagen PHP | Servir PHP y recursos estáticos |
| XAMPP | Uso local documentado; sin versión de paquete acreditada | Entorno Apache/PHP en Windows; PostgreSQL se instala por separado |
| Docker | [`php:8.2-apache-bookworm`](Dockerfile); versión del motor Docker no acreditada | Empaquetado de la aplicación para Render |
| Render | Servicio administrado, sin versión de software aplicable | Publicación HTTPS y PostgreSQL separado del contenedor |
| Git / GitHub | Git local **2.56.0.windows.4**; no es una versión mínima exigida | Historial, ramas y repositorio remoto |
| PowerShell / PHP CLI / psql | Procedimiento de alta compatible con PowerShell 5.1 | Instalación y herramientas de consola existentes |

La etiqueta Docker no fija un parche ni un digest. **No se verificaron las versiones exactas de PHP/Apache/PostgreSQL en la instancia desplegada**; las versiones locales no se atribuyen a Render. No hay manifiestos de Composer o npm ni un framework de aplicación identificado.

<a id="arquitectura"></a>

## Arquitectura y estructura real del proyecto

Aplicación cliente-servidor con **PHP procedimental y puntos de entrada directos**. Cada página puede combinar validación, consultas y HTML; `includes/` reúne funciones compartidas. `index.php` es la portada/panel, no un router frontal. No se implementa una arquitectura MVC estricta ni existen carpetas `public/`, `app/`, `core/`, `controllers/`, `services/` o `routes/`.

```text
Navegador — HTTP local / HTTPS en Render → Apache + PHP → PDO_PGSQL → PostgreSQL
```

Árbol principal de la copia revisada, incluidos los documentos de esta actualización; se omiten `.git/` y archivos auxiliares menores:

```text
clinica-vida-salud/
├── index.php
├── login.php
├── logout.php
├── usuarios.php
├── pacientes.php
├── medicos.php
├── especialidades.php
├── horarios.php
├── citas.php
├── agenda_medica.php
├── mi_agenda.php
├── mis_citas.php
├── seleccionar_reprogramacion.php
├── reprogramar_cita.php
├── cancelar_cita.php
├── asistencia_citas.php
├── historial_citas.php
├── reportes.php
├── probar_conexion.php
├── verificar.php
├── includes/
│   ├── auth.php
│   ├── conexion.php
│   ├── dashboard.php
│   ├── personal.php
│   ├── reportes.php
│   ├── sesion.php
│   ├── ui.php
│   └── usuarios.php
├── assets/
│   ├── estilos.css
│   └── img/
│       └── banner-medico.png
├── database/
│   └── clinica_vida_salud.sql
├── scripts/
│   ├── crear_administrador.php
│   └── crear_usuario_prueba.php
├── deploy/
│   ├── DESPLIEGUE.md
│   ├── REVISION.md
│   ├── apache-start.sh
│   ├── apache.conf
│   └── php.ini
├── documentacion/
│   ├── Ciclo_1_Grupo_17/
│   │   ├── Informe_Clinica_Vida_Salud_Ciclo_1.docx
│   │   └── Informe_Clinica_Vida_Salud_Ciclo_1.pdf
│   └── bitacoras/
│       └── ciclos-1-y-2.md
├── .dockerignore
├── .env.example
├── .gitattributes
├── .gitignore
├── Dockerfile
└── README.md
```

| Ubicación | Contenido |
|---|---|
| PHP de la raíz | Pantallas funcionales, autenticación y diagnósticos CLI |
| `includes/` | Sesión, autorización, conexión diferida, marco visual y funciones de usuarios/consultas |
| `assets/` | CSS e imagen de interfaz; el banner no es una captura de pruebas |
| `database/` | Único SQL versionado de creación de esquema y catálogos iniciales |
| `scripts/` | Alta inicial del administrador y creación de cuentas de prueba, solo por consola |
| `deploy/` | Configuración y documentación del contenedor y su despliegue |
| `documentacion/` | Informe académico original y bitácora documental complementaria |

No existe `tests/` ni la carpeta `documentacion/Ciclo_2_Grupo_17/` mencionada en el README anterior. No se conservan sus comandos ni enlaces como si fueran ejecutables o evidencia disponible.

<a id="base-datos"></a>

## Base de datos

El archivo [`database/clinica_vida_salud.sql`](database/clinica_vida_salud.sql) crea **ocho tablas**, índices y la extensión `btree_gist` dentro de una transacción. Es un script inicial para una base vacía, no una migración repetible ni un respaldo de datos.

| Tabla | Contenido y relaciones principales |
|---|---|
| `roles` | Cuatro roles con nombre único; relación 1:N con `usuarios` |
| `usuarios` | Cuenta con rol obligatorio, usuario/correo únicos y estado de actividad |
| `pacientes` | Ficha del paciente; vínculo opcional y único con `usuarios`; relación 1:N con `citas` |
| `medicos` | Ficha profesional y registro único; vínculo opcional y único con `usuarios`; relación 1:N con `horarios` |
| `especialidades` | Catálogo con índice único sobre `LOWER(TRIM(nombre))` |
| `medico_especialidad` | Relación N:M médico–especialidad mediante clave primaria compuesta |
| `horarios` | Día 1–7, intervalo, duración positiva y actividad por médico |
| `citas` | Paciente, asignación médico–especialidad, fecha, horas, código único, estado, motivos y marcas temporales |

### Integridad y reglas de gestión

- La FK compuesta de `citas` exige que el médico esté asignado a la especialidad. No existe `id_horario` en `citas`: la pertenencia y alineación al bloque se validan en PHP.
- Los horarios activos del mismo médico/día no pueden superponerse. Las citas `programada`/`confirmada` del mismo médico tampoco, mediante restricciones `EXCLUDE USING gist` y rangos `tsrange` semiabiertos `[inicio, fin)`; los bloques consecutivos pueden compartir el límite.
- La protección contra solapamientos es **por médico**, no por paciente. No se garantiza impedir dos citas simultáneas de un paciente con médicos distintos.
- PHP calcula la hora final según la duración del horario y rechaza bloques pasados, desalineados o fuera de la oferta. La disponibilidad mostrada puede cambiar: el guardado vuelve a validar y la base resuelve el conflicto de intervalo.
- Reprogramar usa transacción y bloqueo de fila. Cancelar conserva la reserva y su motivo opcional; asistencia conserva el resultado final. No hay una tabla de auditoría con los turnos anteriores.
- La gestión web valida que el vínculo corresponda al rol; las FK únicas de cada tabla, por sí solas, no impiden vincular una cuenta a ambas tablas de perfiles mediante SQL externo.
- Los módulos de reservas y seguimiento usan `America/La_Paz`; el esquema almacena fechas/horas sin zona explícita. No se presume soporte multizona.

El SQL solo inserta **cuatro roles y tres especialidades genéricas**: Pediatría, Medicina general y Geriatría. No incluye usuarios, médicos, pacientes ni citas de demostración. Los datos de XAMPP y Render son independientes: crear una ficha local no la publica ni la sincroniza. No se inspeccionaron filas privadas de ninguna instalación en esta actualización.

<a id="usuarios-demostracion"></a>

## Usuarios de prueba y demostración

**No se publican contraseñas, hashes ni credenciales de PostgreSQL.** Ninguna cuenta viene creada por el SQL inicial.

| Rol | Usuario de demostración | Finalidad | Forma de obtener acceso |
|---|---|---|---|
| Administrador | `admin_clinica`, utilizado en Render según confirmación del equipo; no es un seed | Administración y preparación de la demostración | Solicitar autorización y contraseña privada al equipo; no restablecerla para probar |
| Médico | Nombre de usuario no verificado en los archivos | Consultar agenda de una ficha ficticia vinculada | El administrador crea/identifica la cuenta y comunica el acceso en privado |
| Paciente | Nombre de usuario no verificado en los archivos | Consultar perfil básico y citas propias | El administrador crea/identifica la cuenta y comunica el acceso en privado |
| Recepcionista | No se acredita una cuenta concreta | Demostrar gestión administrativa permitida | Crear una cuenta de ensayo desde Usuarios si se necesita; el rol sí está implementado |

**Gabriel Pérez y Carla Vargas** fueron perfiles ficticios empleados en pruebas, según la confirmación recibida; sus nombres no permiten deducir sus usuarios ni credenciales. No existe un seed que los cree en todas las instalaciones.

Para preparar una demostración, usar el [alta inicial por CLI](#administrador-inicial) y después **Usuarios** para crear cuentas y vincularlas a fichas ficticias activas. Las contraseñas nuevas requieren mínimo 12 caracteres UTF-8 y máximo 72 bytes; deben entregarse por un canal privado.

La herramienta [`scripts/crear_usuario_prueba.php`](scripts/crear_usuario_prueba.php) admite únicamente `recepcionista`, `medico` o `paciente`, con `--entrada-powershell` y un objeto JSON por tubería con los campos `rol`, `nombre_usuario`, `contrasena` y `confirmacion`. Se aplica la misma entrada oculta del procedimiento de administrador, añadiendo `rol` al objeto y cambiando el script de destino. **Solo crea la cuenta, no la ficha ni el vínculo**; estos se gestionan desde la web. No acepta credenciales por argumentos o archivos ni sobrescribe cuentas existentes.

<a id="requisitos"></a>

## Requisitos previos

| Requisito | Necesidad y alcance |
|---|---|
| PHP 8.2 con CLI | Obligatorio; línea de ejecución verificada, sin afirmar compatibilidad probada con otras ramas |
| Extensiones `PDO`, `pdo_pgsql`, `mbstring`, `ctype`, `session` | Obligatorias para conexión, texto, validación y sesiones; comprobadas en PHP CLI local. Revisar también el PHP cargado por Apache |
| PostgreSQL y cliente `psql` | Obligatorios para el procedimiento local; referencia documentada: motor 18.6. Se necesitan permisos para crear la base y habilitar `btree_gist` |
| Apache con PHP | Obligatorio en el procedimiento descrito; XAMPP es la opción local documentada |
| Git | Necesario para clonar y revisar el historial; sin versión mínima fijada por el proyecto |
| PowerShell | Necesario para el procedimiento seguro de alta CLI incluido; compatible con 5.1 |
| Navegador actualizado | Necesario para formularios y JavaScript; no se acredita una matriz de versiones compatibles |
| Docker | Opcional para XAMPP; necesario solo si se desea construir/probar el contenedor localmente |

PostgreSQL **no es el servicio MySQL/MariaDB de XAMPP**. No se requieren instalaciones de Composer, npm, Python o Playwright para usar esta copia del proyecto.

<a id="instalacion-local"></a>
<a id="-instrucciones-para-ejecutar-localmente"></a>

## Instalación y configuración local

Procedimiento para **Windows, PowerShell y XAMPP en `C:\xampp`**, desde una instalación nueva. Ajustar la ubicación de herramientas si se instalaron en otro directorio; para los comandos siguientes, `psql` debe estar en `PATH`. Los comandos de provisión se documentan, **no se ejecutaron durante esta revisión**.

### 1. Clonar

```powershell
cd C:\xampp\htdocs
git clone --branch main https://github.com/yllbbtjqmss/clinica-vida-salud.git
cd clinica-vida-salud
```

Si el repositorio ya existe, no clonar encima ni reemplazar cambios locales: revisar primero su estado y la versión deseada.

### 2. Preparar PostgreSQL local

Iniciar el servicio de PostgreSQL. **Solo con destino local verificado y una instalación nueva**, crear la base e importar el esquema:

```powershell
psql -X -h localhost -p 5432 -U postgres -d postgres -W -v ON_ERROR_STOP=1 -c "CREATE DATABASE clinica_vida_salud;"
psql -X -h localhost -p 5432 -U postgres -d clinica_vida_salud -W -v ON_ERROR_STOP=1 -f .\database\clinica_vida_salud.sql
```

Los parámetros suponen un PostgreSQL local en el puerto habitual y un usuario instalador `postgres`; ajustar puerto/usuario a la instalación propia. `-W` solicita la contraseña sin incluirla en el comando. Usar para la aplicación una cuenta con los permisos adecuados; el usuario instalador no tiene que ser el usuario de ejecución.

**Si ya existe la base con tablas/datos, omitir ambos comandos.** No borrar ni recrear el esquema para actualizar el README o incorporar el Ciclo 2: entre la línea base del Ciclo 1 y `3415f3e` no cambió el SQL. El archivo ya incluye `BEGIN`/`COMMIT`; no añadir `--single-transaction`.

### 3. Configurar la conexión privada

[`includes/conexion.php`](includes/conexion.php) aplica esta prioridad:

1. Si existe `CVS_DB_HOST` en el entorno del proceso, usa las seis variables `CVS_DB_*` de la [tabla de Render](#variables-entorno).
2. Si no existe, carga **`C:/xampp/config_clinica/database.php`**, fuera del directorio público y del repositorio. Este archivo debe definir `$conexion` como instancia de `PDO`.

Para XAMPP, crear manualmente ese archivo privado con esta estructura; los textos en mayúsculas son **marcadores**, no credenciales utilizables:

```php
<?php
$conexion = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=clinica_vida_salud',
    'TU_USUARIO_LOCAL',
    'TU_CLAVE_LOCAL_PRIVADA'
);
```

Sustituir los marcadores solo en el archivo privado y limitar su acceso. `.gitignore` excluye `database.php` y `.env`, pero eso no reemplaza revisar lo que se incorpora a Git. **No mover este archivo a `htdocs`, no subirlo y no publicar capturas de su contenido.**

También puede configurarse el entorno del proceso PHP/Apache en un servidor compatible, sin cambiar el código. [`.env.example`](.env.example) es una referencia: **PHP no carga `.env` automáticamente**. Para una base local sin TLS, `CVS_DB_SSLMODE=disable` solo corresponde a un entorno local controlado; la conexión por entorno usa `require` por defecto. No establecer `CVS_HTTPS=1` sobre HTTP local, porque fuerza cookies Secure. Las variables de una consola no configuran automáticamente el Apache iniciado desde otro proceso.

### 4. Iniciar Apache y comprobar el entorno

Habilitar `pdo_pgsql` y las extensiones requeridas en el PHP correspondiente a XAMPP; reiniciar Apache desde su panel después de cambiar `php.ini`.

```powershell
& "C:\xampp\php\php.exe" -v
& "C:\xampp\php\php.exe" -m
& "C:\xampp\php\php.exe" verificar.php
& "C:\xampp\php\php.exe" probar_conexion.php
```

Ambos diagnósticos son **solo CLI**. `verificar.php` revisa el controlador PDO PostgreSQL; `probar_conexion.php` comprueba **exclusivamente el archivo privado de XAMPP**, no las variables `CVS_DB_*`. No usarlo para acreditar la conexión de Render o una configuración por entorno.

<a id="administrador-inicial"></a>

### 5. Crear el administrador inicial

Solo si aún no existe ninguna cuenta administradora, ejecutar desde la raíz esta entrada por tubería a [`scripts/crear_administrador.php`](scripts/crear_administrador.php). No activar transcripciones ni pegar contraseñas en comandos o archivos:

```powershell
$OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$nombre = Read-Host 'Nombre del administrador inicial'
$secreto = Read-Host 'Contraseña (mínimo 12 caracteres; máximo 72 bytes)' -AsSecureString
$repetir = Read-Host 'Confirmación' -AsSecureString
$b1 = [IntPtr]::Zero
$b2 = [IntPtr]::Zero
try {
    $b1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secreto)
    $b2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($repetir)
    $datos = @{
        nombre_usuario = $nombre
        contrasena = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b1)
        confirmacion = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b2)
    }
    $datos | ConvertTo-Json -Compress | & "C:\xampp\php\php.exe" scripts\crear_administrador.php --entrada-powershell
} finally {
    if ($b1 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b1) }
    if ($b2 -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b2) }
    $datos = $null
    $secreto = $null
    $repetir = $null
}
```

El secreto se oculta al escribirlo y se transmite en memoria por la tubería; la limpieza no garantiza borrar todas las copias de memoria. El script rechaza una segunda cuenta administradora inicial, incluso si la existente está inactiva; no reemplaza usuarios. Para las siguientes cuentas, usar **Usuarios** con un administrador autorizado.

### 6. Iniciar sesión

Abrir <http://localhost/clinica-vida-salud/> o <http://localhost/clinica-vida-salud/login.php>. Ingresar con la cuenta creada. El médico se dirige a **Mi agenda** y el paciente a **Mis citas y perfil**; inicialmente no habrá fichas ni citas hasta registrarlas y vincularlas.

<a id="render"></a>

## Despliegue en Render

**URL pública:** <https://clinica-vida-salud-p56z.onrender.com/>

El [`Dockerfile`](Dockerfile) parte de PHP 8.2 con Apache sobre Debian Bookworm, instala `pdo_pgsql`, copia únicamente los PHP y recursos necesarios y arranca Apache mediante [`deploy/apache-start.sh`](deploy/apache-start.sh). Usa `PORT` dinámico, con 10000 como valor predeterminado. El build/inicio **no importa SQL ni crea cuentas**.

Render proporciona HTTPS público; `CVS_HTTPS=1` configura cookies Secure detrás del proxy. PostgreSQL se ejecuta como servicio separado y se configura por entorno. Apache bloquea el acceso HTTP a internos, scripts, diagnósticos y archivos de configuración según [`deploy/apache.conf`](deploy/apache.conf).

<a id="variables-entorno"></a>

### Variables de entorno

Definir los secretos únicamente en el entorno privado del servicio, nunca en Git, Dockerfile o documentación:

| Variable | Uso / condición |
|---|---|
| `CVS_DB_HOST` | Host PostgreSQL sin prefijo de URL; su presencia activa la conexión por entorno |
| `CVS_DB_PORT` | Puerto PostgreSQL; predeterminado `5432` |
| `CVS_DB_NAME` | Nombre de la base de destino |
| `CVS_DB_USER` | Usuario de ejecución de la base |
| `CVS_DB_PASSWORD` | Secreto de PostgreSQL; sin valor publicado |
| `CVS_DB_SSLMODE` | En Render, `require` según la guía; es también el valor predeterminado del código |
| `CVS_HTTPS` | `1` para cookies Secure en el acceso HTTPS público |
| `PORT` | Puerto de escucha HTTP interno del contenedor; predeterminado `10000` |

`sslmode=require` cifra la conexión, pero no equivale a verificar la identidad del servidor con `verify-full` y una CA configurada.

### Procedimiento y documentación histórica

1. Configurar un Web Service **Docker**, con contexto raíz y `Dockerfile` en la raíz, y una base PostgreSQL separada; para host interno, usar una región compatible entre ambos servicios.
2. Definir las variables anteriores en Render. No exportar la base local ni copiar fichas personales.
3. Solo para una base remota nueva y vacía, seguir la importación dirigida del esquema en [la guía de despliegue](deploy/DESPLIEGUE.md). No repetirla sobre la instancia ya utilizada.
4. Crear la primera cuenta únicamente si falta, con la herramienta CLI y destino remoto comprobado. Si el plan no ofrece Shell, la guía describe el procedimiento desde una consola local temporal por conexión externa; no crear un instalador web público.
5. Verificar login y operación con datos ficticios. Un health check sobre `/login.php` acredita respuesta web, **no conexión a PostgreSQL** sin autenticar.

**La rama conectada actualmente en el panel de Render no pudo verificarse.** Que ambas ramas apunten al mismo commit en GitHub no demuestra la selección del servicio ni el commit realmente desplegado.

[DESPLIEGUE.md](deploy/DESPLIEGUE.md) y [REVISION.md](deploy/REVISION.md) se conservan como documentos de preparación del **29/10/2026**: todavía dicen «futuro», «no publicado» o «pendiente». Esas afirmaciones describen aquel momento, no sustituyen la confirmación posterior de despliegue y pruebas manuales recogida aquí. Sus referencias a `ciclo2-desarrollo` tampoco certifican la configuración actual de Render.

La guía histórica remite al «paso 6» del README anterior para crear el administrador. Ese procedimiento está ahora en [Crear el administrador inicial, paso 5](#administrador-inicial); se conserva el ancla antigua de instalación para no romper su enlace.

### Consideraciones del plan gratuito

Según [la documentación oficial de Render](https://render.com/docs/free), consultada el 02/11/2026, los Web Services Free se suspenden tras 15 minutos sin tráfico y el arranque posterior puede demorar; el sistema de archivos es efímero y las sesiones PHP en archivos pueden perderse al reiniciar, desplegar o suspenderse. No ofrecen Shell/SSH ni one-off jobs.

PostgreSQL Free tiene 1 GB, expira a los 30 días y no incluye respaldos administrados. **Estas condiciones solo aplican si la instancia usa ese plan**: no se verificaron el plan contratado, su fecha de creación/expiración ni las cuotas de esta cuenta. Revisar el panel antes de la demostración y no tratar el plan Free como infraestructura clínica productiva.

<a id="manual-uso"></a>

## Manual breve de uso

### Administración

1. Iniciar sesión y comprobar el rol visible.
2. Crear o revisar **Especialidades**; registrar el médico con al menos una especialidad activa. Registrar la ficha del paciente con sus datos obligatorios, siempre ficticios en la demostración.
3. En **Usuarios**, crear la cuenta, seleccionar `medico` o `paciente` y elegir su ficha existente en el selector correspondiente; dejar el otro vínculo vacío. Verificar que cuenta y ficha estén activas. El correo de acceso pertenece a la cuenta, no se copia automáticamente de la ficha.
4. Configurar **Horarios médicos** por día, intervalo y duración; mantenerlos activos para ofrecer bloques.
5. En **Registrar citas**, seleccionar paciente, especialidad, médico, fecha y bloque disponible; guardar y conservar el código mostrado.
6. Consultar **Agenda médica** e **Historial de citas**. Para cambiar el turno, usar **Reprogramar citas** y seleccionar la reserva vigente futura; no cambia el médico ni la especialidad.
7. Para cancelar una reserva no iniciada, confirmar la operación y, si corresponde, indicar motivo. Para una cita cuyo horario ya terminó, registrar **Atendida** o **Inasistencia** en asistencia; el resultado final no se rectifica desde ese módulo.
8. En **Reportes**, consultar la sección y filtros aplicables o imprimir desde el navegador. Usar **Usuarios → Administrar** para actividad y restablecimientos, sin divulgar contraseñas.

Recepción realiza solo los pasos permitidos por [su rol](#roles-permisos); no configura usuarios ni oferta médica.

### Médico

1. Iniciar sesión con una cuenta `medico` vinculada a una ficha activa.
2. En **Mi agenda**, elegir fecha y consultar citas, estados y horarios semanales.
3. Si no aparece el perfil o falta un horario, solicitar revisión al administrador; el panel no permite editarlo ni gestionar citas de terceros.

### Paciente

1. Iniciar sesión con una cuenta `paciente` vinculada a una ficha activa.
2. En **Mis citas y perfil**, revisar los datos básicos y filtrar próximas/en curso, anteriores o todas; avanzar páginas si corresponde.
3. Para corregir datos, reservar, reprogramar o cancelar, contactar con recepción/administración. No hay autoservicio de reserva ni registro público de cuentas.

<a id="pruebas"></a>

## Pruebas funcionales y resultados

La tabla resume las **pruebas manuales confirmadas por el equipo en Render en noviembre de 2026**, sin fecha exacta de ejecución aportada. Los IDs `RM-*` organizan esta actualización: no sustituyen los PF01–PF22 del informe del Ciclo 1 ni representan una suite automática. Resultado confirmado significa éxito comunicado del recorrido básico, no cobertura total de entradas y variantes.

| ID | Módulo | Acción | Resultado esperado | Estado / procedencia |
|---|---|---|---|---|
| RM-01 | Autenticación | Acceder como administrador | Sesión y panel administrativo | Confirmado manualmente en Render |
| RM-02 | Médicos/usuarios | Registrar ficha médica y vincular cuenta | Cuenta asociada al perfil correcto | Confirmado manualmente en Render |
| RM-03 | Pacientes/usuarios | Registrar ficha de paciente y vincular cuenta | Cuenta asociada al perfil correcto | Confirmado manualmente en Render |
| RM-04 | Citas | Reservar un bloque disponible | Cita registrada con paciente, médico, fecha y horario | Confirmado manualmente en Render |
| RM-05 | Paciente | Iniciar sesión y consultar la reserva | Cita visible en sus propias citas | Confirmado manualmente en Render |
| RM-06 | Médico | Iniciar sesión y consultar la fecha | Misma cita visible en su agenda | Confirmado manualmente en Render |
| RM-07 | Reprogramación | Cambiar fecha/bloque de una cita vigente | Nuevo turno, misma identidad de reserva | Prueba manual confirmada; sin registro paso a paso adjunto |
| RM-08 | Cancelación | Confirmar cancelación de cita admisible | Estado cancelado, sin eliminar la reserva | Prueba manual confirmada; sin registro paso a paso adjunto |
| RM-09 | Reportes | Consultar reportes como administrador | Datos administrativos visibles | Prueba manual confirmada; no acredita todos los filtros |
| RV-01 | Acceso público | GET de portada y `login.php`, sin credenciales | Respuesta HTTP 200 | Observado en esta revisión, 02/11/2026; no valida la base |
| PV-01 | Integridad | Dos reservas simultáneas para el mismo intervalo/médico | A lo sumo una reserva vigente | Pendiente de evidencia funcional reproducible; restricción SQL inspeccionada |
| PV-02 | Autorización | Recorrer todas las URL con los cuatro roles | Acceso limitado según permisos | Matriz completa pendiente; controles de código inspeccionados |
| PV-03 | Validaciones | Probar duplicados, límites temporales y combinaciones de filtros | Rechazo consistente sin cambios indebidos | Regresión exhaustiva pendiente |
| PV-04 | Asistencia y accesibilidad | Repetir asistencia en Render y navegación en dispositivos/teclado | Reglas temporales y uso accesible comprobados | Sin evidencia nueva suficiente; pendiente |

**Evidencia conservada:**

- [Informe del Ciclo 1 en PDF](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.pdf): plan y matriz de pruebas; contiene imágenes de pantallas en pp. 40–44 y 99–103. Son material histórico local, no capturas del despliegue en Render. El texto del informe mantiene sus propias discrepancias sobre pruebas pendientes y capturas; no se reclasifica toda la matriz como aprobada por esas imágenes.
- [Revisión de preparación de Render](deploy/REVISION.md): documenta sintaxis, configuración y comprobaciones aisladas del 29/10; no acredita por sí sola las pruebas posteriores del entorno publicado.
- [Bitácora complementaria](documentacion/bitacoras/ciclos-1-y-2.md): distingue evidencia Git, documentos y confirmación manual.

No se encontró una batería automática versionada, resultados JSON del Ciclo 2 ni capturas móviles independientes. Las referencias internas del informe a otros artefactos no garantizan que estén disponibles en este repositorio. No se ejecutaron altas, importaciones, migraciones ni cambios de datos para actualizar esta documentación.

<a id="bitacoras"></a>

## Bitácoras del proyecto

La [bitácora de los Ciclos 1 y 2](documentacion/bitacoras/ciclos-1-y-2.md) reconstruye actividades, propósito, resultado y evidencia sin inventar reuniones, horas o asignaciones individuales. No se encontró una bitácora independiente anterior en los archivos ni en los nombres de rutas del historial disponible; se conservan los informes originales y los documentos de despliegue, sin sobrescribirlos.

| Fecha / fuente temporal | Ciclo o fase | Actividad realizada | Resultado | Referencia verificable |
|---|---|---|---|---|
| 20/10/2026, UTC−04:00 | Ciclo 1 | Primer avance y esquema inicial | Base funcional y SQL versionados | [85c6afe](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/85c6afe) |
| 23–24/10/2026, UTC−04:00 | Ciclo 1 | Módulos RF, integración y ajustes de disponibilidad | Núcleo administrativo integrado | [54725f5](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/54725f5), [20c4892](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/20c4892) |
| 24/10/2026, UTC−04:00 | Ciclo 1 | Interfaz compartida, dashboard y reportes | Panel y RF13 incorporados | [4da053c](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4da053c), [578ccbc](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/578ccbc) |
| 24/10/2026 23:48:32, UTC−04:00; **25/10 en UTC** | Cierre documental del Ciclo 1 | Incorporar informe final | DOCX y PDF preservados | [6e3e96a](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/6e3e96a) |
| 28/10/2026, UTC−04:00 | Ciclo 2 | Usuarios web, vínculos y paneles personales | Incremento funcional y ajustes de acceso/filtros | [4dcd1a7](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4dcd1a7) |
| 29/10/2026, UTC−04:00 | Ciclo 2 / despliegue | Preparar contenedor y guía de Render | Configuración versionada; publicación aún pendiente en ese documento | [3415f3e](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/3415f3e), [revisión](deploy/REVISION.md) |
| 02/11/2026 11:22:48, UTC−04:00; reflog local | Integración del Ciclo 2 | Adelantar `main` a `origin/ciclo2-desarrollo` | Fast-forward hasta `3415f3e`, sin conflictos | `git reflog show main --date=iso-strict`; detalle en la bitácora |
| Noviembre de 2026; día no aportado | Validación de despliegue | Publicar y recorrer acceso, vínculos, citas y reportes | Pruebas manuales confirmadas por el equipo | [Resumen de pruebas](#pruebas); confirmación manual, no commit de ejecución |

Las fechas de commits se expresan con el desfase conservado por Git. El **25/10/2026** asociado a `6e3e96a` corresponde a UTC (`03:48:32Z`); no se cambia su fecha local para forzar coincidencia. El reflog acredita una operación local y no se distribuye al clonar.

### Documentación académica preservada

- [Informe final del Ciclo 1 — PDF](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.pdf).
- [Informe final del Ciclo 1 — DOCX](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.docx).
- [Guía de preparación de despliegue](deploy/DESPLIEGUE.md) y [registro de revisión](deploy/REVISION.md).

Los pendientes del informe del Ciclo 1 deben interpretarse en su fecha: usuarios web y paneles personales se incorporaron después. No se altera ese documento para simular que ya existían en la primera entrega.

<a id="equipo"></a>

## Integrantes del equipo

**Grupo 17**, confirmado en la portada del informe académico y en el historial del README.

| Integrante, según la portada | Participación documentada |
|---|---|
| Durán Pérez Melissa | Integrante del Grupo 17 |
| Vásquez Cordero Angélica Yandira | Integrante del Grupo 17 |

Fuente: portada de los informes [PDF](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.pdf) y [DOCX](documentacion/Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.docx), y commit [0efb54c](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/0efb54c). Se conserva el orden de nombres y apellidos de la fuente y se omiten identificadores estudiantiles. No hay una asignación nominal verificable de funciones formales como líder, analista o responsable de pruebas; no se inventa.

<a id="control-versiones"></a>

## Organización del trabajo y control de versiones

- **Ciclo 1:** el historial conserva avances funcionales en ramas de desarrollo e integración, seguidos del rediseño y cierre documental. El informe constituye una línea base académica, no la descripción de todas las capacidades actuales.
- **`main`:** rama principal con el incremento integrado hasta `3415f3e`.
- **`ciclo2-desarrollo`:** rama del segundo incremento; las referencias locales y las dos ramas consultadas directamente en GitHub apuntan al mismo commit al 02/11/2026. La revisión documental se inició sobre esta rama, con el árbol de trabajo limpio.
- **Integración:** el reflog de `main` registra `merge origin/ciclo2-desarrollo: Fast-forward`. No hay commit de merge nuevo: se adelantó el puntero de `main`, sin conflictos, conservando los commits [4dcd1a7](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4dcd1a7) y [3415f3e](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/3415f3e).

El historial permite distinguir cuándo se implementó una función, cuándo se documentó y qué pruebas están realmente respaldadas. No se deducen responsabilidades individuales a partir del nombre de una rama ni se atribuyen pruebas a un commit que solo prepara el despliegue.

Comandos de consulta, sin modificar archivos ni datos:

```powershell
git status --short --branch
git branch -avv
git log --all --date=iso-strict --format="%h %aI %s"
git show -s --format="%H %aI %cI %s" 6e3e96a 4dcd1a7 3415f3e
git reflog show main --date=iso-strict
git ls-remote --heads https://github.com/yllbbtjqmss/clinica-vida-salud.git main ciclo2-desarrollo
```

<a id="limitaciones"></a>

## Limitaciones, mejoras futuras y conclusión

### Limitaciones verificadas

- Los paneles médico y paciente son de consulta; no hay edición autónoma del perfil, autorregistro ni reserva directa del paciente.
- No hay historia clínica completa, recetas, facturación, pagos o notificaciones automáticas implementadas.
- La exclusión de intervalos protege por médico, no por paciente; no existe auditoría de cada reprogramación ni operación específica de confirmación de cita.
- La pertenencia al horario se valida en PHP, no mediante una FK cita–horario. Cambiar la oferta no equivale a reprogramar automáticamente reservas existentes.
- El bloqueo de login es por sesión. No se acredita limitación centralizada por IP/cuenta, recuperación de contraseña por correo ni auditoría de seguridad integral.
- Reportes dispone de impresión, no exportación CSV/PDF propia. Pacientes no tiene fecha de alta en el esquema: el reporte no debe interpretarse como altas por período.
- Algunos listados son acotados: pacientes muestra hasta 100 registros y el listado general de citas hasta 200; otros módulos sí paginan. No se acredita rendimiento bajo carga ni uso clínico real.
- Faltan evidencias reproducibles de regresión completa, concurrencia, límites temporales, accesibilidad y dispositivos físicos. No hay suite automática disponible en esta copia.
- No se verificaron el plan, la rama conectada, el commit desplegado ni las versiones exactas del entorno remoto. Las guías previas conservan afirmaciones históricas que no describen el despliegue posterior.

### Mejoras propuestas, no implementadas ni comprometidas

1. Conservar evidencia anonimizada y reproducible de pruebas por rol, filtros, concurrencia y fronteras temporales.
2. Incorporar limitación centralizada de intentos, auditoría de cambios y un procedimiento de respaldo/restauración probado.
3. Evaluar exclusión de citas simultáneas por paciente y políticas de cambios de horarios/especialidades, antes de implementarlas.
4. Unificar generación de disponibilidad y manejo temporal entre módulos; ampliar paginación y evaluar rendimiento.
5. Validar accesibilidad y navegación en dispositivos reales; estudiar exportaciones CSV/PDF según necesidad académica.

### Conclusión

CLÍNICA VIDA SALUD integra gestión administrativa de citas y consultas personales con separación de permisos y restricciones relacionales. El Ciclo 2 completa usuarios y vínculos, y el equipo confirma su publicación y recorridos manuales en Render. El historial y la documentación preservada permiten demostrar ese avance sin equiparar implementación, pruebas parciales y preparación para producción.

<a id="creditos"></a>

## Licencia y créditos

No se encontró un archivo `LICENSE` ni términos verificables de una licencia de software. La mención anterior «uso académico» describe el contexto, **no constituye una licencia formal**; no se asignan permisos de reutilización inexistentes. Su definición queda pendiente de las autoras y de las condiciones académicas aplicables.

Créditos confirmados en la portada del informe del Ciclo 1:

- **Universidad Autónoma Gabriel René Moreno**.
- **Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones**.
- **Materia:** Sistemas de Información I.
- **Docente:** MSc. Ing. Angélica Gazón Cuéllar.
- **Grupo 17 · Santa Cruz, Bolivia · 2-2026**.

La portada es la referencia académica; se corrige la atribución anterior a «Ingeniería de Requisitos». Los modelos PUDS-UML y la bibliografía permanecen en el informe original. No se acredita autoría o licencia del recurso gráfico de interfaz más allá de su presencia en el repositorio.
