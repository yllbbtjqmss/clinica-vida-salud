# 🏥 Clínica Vida Salud

Sistema web de gestión de citas médicas desarrollado con **PHP 8.2**, **PostgreSQL**, **HTML**, **CSS** y **JavaScript**. Proyecto académico de la materia **Ingeniería de Requisitos**.

| | |
|---|---|
| **Rama activa** | `diseno-dashboard` |
| **Estado** | Avance funcional de RF01–RF13 (ver [Estado actual](#-estado-actual-del-desarrollo)) |
| **Licencia** | Uso académico |

---

## 📋 Descripción del sistema

Clínica Vida Salud es un sistema de escritorio web para la administración del ciclo completo de una cita médica: registro de pacientes, médicos, especialidades y horarios; reserva de citas con validación de disponibilidad de turnos; reprogramación, cancelación, control de asistencia e historial por paciente; y un módulo de reportes administrativos con estadísticas y gráficos reales obtenidos de PostgreSQL.

La interfaz sigue un diseño propio **Dark Accent + Analytics & Timeline**: barra lateral azul petróleo compartida en todas las pantallas, tarjetas estadísticas modernas, calendario interactivo y línea de tiempo de actividad.

## 🎯 Objetivo principal

Digitalizar y centralizar la gestión de citas de la clínica, garantizando seguridad de acceso por roles, integridad de los datos en la base relacional y consultas sin solapamientos de horarios por médico.

---

## ✨ Funcionalidades (requerimientos funcionales)

| Requisito | Funcionalidad | Pantallas / módulos |
|---|---|---|
| **RF01** | Seguridad y autenticación: inicio de sesión, hashing de contraseñas, control por roles, token CSRF y bloqueo temporal tras intentos fallidos | `login.php`, `includes/auth.php`, `includes/sesion.php` |
| **RF02** | Registro y administración de pacientes | `pacientes.php` |
| **RF03** | Registro y administración de médicos | `medicos.php` |
| **RF04** | Administración de especialidades | `especialidades.php` |
| **RF05** | Administración de horarios médicos por día y duración de turno | `horarios.php` |
| **RF06** | Registro de citas con validación de disponibilidad de turnos en vivo | `citas.php` |
| **RF07** | Prevención de citas duplicadas: el sistema impide registrar reservas superpuestas para un mismo médico, utilizando validaciones en el servidor y restricciones de PostgreSQL | `citas.php`, `reprogramar_cita.php`, `database/clinica_vida_salud.sql` |
| **RF08** | Reprogramación de citas en dos pasos, con transacción anti-solapamiento | `seleccionar_reprogramacion.php`, `reprogramar_cita.php` |
| **RF09** | Cancelación de citas con registro del motivo | `cancelar_cita.php` |
| **RF10** | Control de asistencia: marcar **atendida** o **inasistencia** | `asistencia_citas.php` |
| **RF12** | Historial de citas por paciente con búsqueda y paginación | `historial_citas.php` |
| **RF13** | Reportes administrativos: citas por fecha, por médico, por especialidad, por estado, pacientes registrados y agenda diaria, con KPIs, gráficos e impresión *(solo administrador)* | `reportes.php`, `includes/reportes.php` |
| **RF11** | Consulta de agenda médica: el administrador y la recepcionista pueden consultar la agenda de los médicos por fecha. La consulta personal del médico está pendiente | `agenda_medica.php` |

### Estados válidos de una cita

`programada` · `confirmada` · `atendida` · `cancelada` · `inasistencia`

---

## 🛠 Tecnologías utilizadas

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 (PDO con consultas preparadas) |
| Base de datos | PostgreSQL 18 (extensión `btree_gist`, exclusión de intervalos con `PERIOD`/`EXCLUDE` para evitar solapamientos) |
| Frontend | HTML5, CSS3 (hoja única `assets/estilos.css`), JavaScript nativo |
| Servidor local | XAMPP (Apache) + PostgreSQL |
| Seguridad | `password_hash`, tokens CSRF, consultas preparadas, control de acceso por rol en servidor |

---

## 👥 Roles de usuario y permisos

| Rol | Acceso |
|---|---|
| **Administrador** | Todos los módulos, incluido el panel administrativo de la portada y el módulo de reportes |
| **Recepcionista** | Pacientes, citas, reprogramación, cancelación, asistencia, historial y consulta de la agenda médica |
| **Médico** | Acceso restringido (pantalla personal pendiente de desarrollo) |
| **Paciente** | Acceso restringido (pantalla personal pendiente de desarrollo) |

La autorización se aplica **en el servidor** (`cvs_exigir_roles()`), aunque un enlace se oculte en el menú; los accesos indebidos responden con 403 y la sesión inexistente redirige al login.

---

## 📁 Estructura principal de carpetas

```
clinica-vida-salud/
├── index.php                        # Portada + panel administrativo (admin/recepción)
├── login.php / logout.php           # Sesión (con bloqueo tras intentos fallidos)
├── pacientes.php / medicos.php      # CRUD de personas
├── especialidades.php / horarios.php
├── citas.php                        # RF06 · registro de citas
├── agenda_medica.php                # RF07 · agenda por médico y fecha
├── seleccionar_reprogramacion.php   # RF08 · paso 1 (listar candidatas)
├── reprogramar_cita.php             # RF08 · paso 2 (nuevo turno)
├── cancelar_cita.php                # RF09
├── asistencia_citas.php             # RF10
├── historial_citas.php              # RF12
├── reportes.php                     # RF13 · dashboard de reportes
├── probar_conexion.php / verificar.php  # Diagnósticos de entorno (solo CLI)
├── assets/
│   ├── estilos.css                  # Design system Dark Accent + dashboard
│   └── img/                         # Imágenes de la interfaz
├── includes/
│   ├── auth.php                     # RF01 · exigencia de roles
│   ├── sesion.php                   # Sesión, CSRF y escape de salida
│   ├── conexion.php                 # PDO (carga una configuración privada externa)
│   ├── ui.php                       # Marco visual, menú por rol e íconos SVG
│   ├── dashboard.php                # Consultas de solo lectura del panel
│   └── reportes.php                 # RF13 · capa de consultas de reportes
├── database/
│   └── clinica_vida_salud.sql       # Esquema completo de la base
├── scripts/
│   ├── crear_administrador.php      # Alta inicial del administrador (solo consola)
│   └── crear_usuario_prueba.php     # Cuenta de prueba (solo consola)
└── documentacion/                   # (vacía por ahora)
```

---

## ⚙️ Requisitos de instalación

- PHP **8.2** con las extensiones `pdo_pgsql` y `mbstring` habilitadas (XAMPP).
- **PostgreSQL** 18 o una versión moderna compatible.
- Apache (incluido en XAMPP) apuntando a la carpeta del proyecto.
- Git para clonar el repositorio.

> ℹ️ El archivo `C:\xampp\php\php.ini` debe tener descomentada la línea `extension=pdo_pgsql`. Puedes comprobarlo con el diagnóstico incluido (ver más abajo).

---

## 🚀 Instrucciones para ejecutar localmente

1. **Clonar el proyecto** en la carpeta pública de Apache:

   ```powershell
   cd C:\xampp\htdocs
   git clone --branch diseno-dashboard --single-branch https://github.com/yllbbtjqmss/clinica-vida-salud.git
   cd clinica-vida-salud
   ```

2. **Iniciar servicios**: desde el panel de XAMPP, iniciar **Apache**; iniciar el servicio de **PostgreSQL**.

3. **Crear la base de datos y su estructura** (una sola vez, dentro de la base `clinica_vida_salud`):

   ```powershell
   psql -U postgres -c "CREATE DATABASE clinica_vida_salud"
   psql -U postgres -d clinica_vida_salud -f database\clinica_vida_salud.sql
   ```

   El script crea las tablas (`roles`, `usuarios`, `pacientes`, `medicos`, `especialidades`, `medico_especialidad`, `horarios`, `citas`), la extensión `btree_gist` y los roles iniciales del sistema.

4. **Configurar la conexión** (ver [Configuración de PostgreSQL](#-configuración-de-postgresql)).

5. **Diagnosticar el entorno** (todos los diagnósticos son solo CLI; desde el navegador responden 403):

   ```powershell
   & "C:\xampp\php\php.exe" verificar.php       # controlador PDO pgsql habilitado
   & "C:\xampp\php\php.exe" probar_conexion.php # disponibilidad de la base
   ```

6. **Crear la primera cuenta de administrador**. La herramienta `scripts/crear_administrador.php` **solo funciona por consola** y recibe las credenciales por la **tubería estándar desde PowerShell** (nunca por argumentos, archivos ni comandos):

   ```powershell
   # Solicita usuario y contraseña oculta con Read-Host -AsSecureString
   # y envía la salida como objeto JSON a la tubería de PHP.
   $credenciales = [ordered]@{
       nombre_usuario = Read-Host 'Nombre de usuario'
       contrasena     = (Read-Host -AsSecureString 'Contrasena') | ConvertFrom-SecureString -AsPlainText
       confirmacion   = (Read-Host -AsSecureString 'Confirmacion') | ConvertFrom-SecureString -AsPlainText
   }
   $entrada = $credenciales | ConvertTo-Json -Compress
   & "C:\xampp\php\php.exe" scripts\crear_administrador.php --entrada-powershell <<< $entrada
   ```

   La herramienta valida el formato del nombre, la coincidencia de la confirmación y crea **únicamente la primera cuenta** de administrador existente (rechaza duplicados). De forma análoga, `scripts/crear_usuario_prueba.php` crea una cuenta de prueba por ejecución.

7. **Abrir la aplicación**: <http://localhost/clinica-vida-salud/> e iniciar sesión con la cuenta creada en el paso 6.

---

## 🗄 Configuración de PostgreSQL

La aplicación **no guarda credenciales en el repositorio**. El archivo `includes/conexion.php` carga en tiempo de ejecución una configuración privada externa (excluida por `.gitignore`):

```php
// includes/conexion.php — línea relevante
@require 'C:/xampp/config_clinica/database.php';
```

Para instalar en otra máquina, crea manualmente la carpeta `C:/xampp/config_clinica/` y dentro un archivo `database.php` que construya y publique una variable `$conexion` de tipo **PDO** apuntando a la base `clinica_vida_salud`. El formato es el siguiente (con **marcadores de posición**, sustitúyelos por tus propios valores locales y no los publiques):

```php
<?php
$conexion = new PDO(
    'pgsql:host=TU_HOST;port=TU_PUERTO;dbname=clinica_vida_salud',
    'TU_USUARIO',
    'TU_CONTRASENA'
);
```

- Nunca confirmes ese archivo en Git (la regla `database.php` de `.gitignore` y la ubicación fuera del proyecto lo impiden).
- Ante un fallo de conexión, la aplicación responde con un mensaje genérico y registra solo el código SQLSTATE en el log del servidor; **no expone** detalles de la conexión ni trazas.

---

## 📸 Capturas de pantalla

_Aún no se han agregado capturas del sistema. Se incorporarán a `documentacion/` antes de la presentación final._

---

## 🔄 Estado actual del desarrollo

- Rama de diseño `diseno-dashboard` con interfaz **Dark Accent** unificada (barra lateral, topbar, tarjetas y badges por estado).
- Módulos RF01–RF10 y RF12 operativos sobre PostgreSQL con transacciones y validaciones de horario (bloqueo de intervalos solapados por médico).
- Portada con **panel administrativo** interactivo: banner, KPIs de pacientes y citas del día, calendario mensual con conteo de citas, disponibilidad de turnos del día y línea de tiempo de actividad.
- RF13 (**reportes administrativos**) integrado: KPIs, gráficos CSS con datos reales, filtros por período/médico/especialidad/estado, paginación, agenda diaria e impresión (vista `@media print`).
- Los detalles de pruebas para la primera presentación se listan en la sección siguiente.

## ⏳ Funcionalidades pendientes

- [ ] Pantalla personal de **médico**.
- [ ] Pantalla personal de **paciente**.
- [ ] **Administración completa de usuarios** desde la web (hoy solo existe el alta inicial del administrador y cuentas de prueba mediante scripts de consola).
- [ ] Definición/integración del requisito **RF11** (según documentación del curso).
- [ ] Pruebas completas documentadas de RF01–RF13 (varios módulos ya probados funcionalmente, falta la batería formal registrada).
- [ ] Exportación CSV/PDF del módulo de reportes (opcional según RF13).
- [ ] Capturas de pantalla para este README.

---

## 🧑‍💻 Integrantes del proyecto

- **Integrante 1:** [NOMBRE COMPLETO]
- **Integrante 2:** [NOMBRE COMPLETO]
- **Grupo:** [NÚMERO DE GRUPO]

---

> 📌 Documento mantenido para la primera presentación del avance de Ingeniería de Requisitos. Última actualización: rama `diseno-dashboard`.
