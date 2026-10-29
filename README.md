# 🏥 Clínica Vida Salud

Sistema web de gestión de citas médicas desarrollado con **PHP 8.2**, **PostgreSQL**, **HTML**, **CSS** y **JavaScript**. Proyecto académico de la materia **Ingeniería de Requisitos**.

| | |
|---|---|
| **Rama oficial** | `main` |
| **Desarrollo Ciclo 2** | `ciclo2-desarrollo` |
| **Estado** | Incremento funcional con administración web y paneles personales; pruebas y pendientes documentados |
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
| **RF01** | Login por usuario o correo; administración web de usuarios, vínculos, activación y restablecimiento; hashing, roles, CSRF y bloqueo por sesión | `usuarios.php`, `login.php`, `includes/usuarios.php`, `includes/auth.php`, `includes/sesion.php` |
| **RF02** | Registro y administración de pacientes | `pacientes.php` |
| **RF03** | Registro y administración de médicos | `medicos.php` |
| **RF04** | Administración de especialidades | `especialidades.php` |
| **RF05** | Administración de horarios médicos por día y duración de turno | `horarios.php` |
| **RF06** | Registro de citas con validación de disponibilidad de turnos en vivo | `citas.php` |
| **RF07** | Prevención de citas duplicadas: el sistema impide registrar reservas superpuestas para un mismo médico, utilizando validaciones en el servidor y restricciones de PostgreSQL | `citas.php`, `reprogramar_cita.php`, `database/clinica_vida_salud.sql` |
| **RF08** | Reprogramación de citas en dos pasos, con transacción anti-solapamiento | `seleccionar_reprogramacion.php`, `reprogramar_cita.php` |
| **RF09** | Cancelación de citas con registro del motivo | `cancelar_cita.php` |
| **RF10** | Control de asistencia: marcar **atendida** o **inasistencia** | `asistencia_citas.php` |
| **RF12** | Historial administrativo con búsqueda y paginación; consulta personal de próximas/anteriores y perfil básico | `historial_citas.php`, `mis_citas.php` |
| **RF13** | Reportes administrativos: citas por fecha, por médico, por especialidad, por estado, pacientes registrados y agenda diaria, con KPIs, gráficos e impresión *(solo administrador)* | `reportes.php`, `includes/reportes.php` |
| **RF11** | Agenda por médico/fecha para administración y recepción; citas y horarios propios para el médico vinculado | `agenda_medica.php`, `mi_agenda.php` |

### Estados válidos de una cita

`programada` · `confirmada` · `atendida` · `cancelada` · `inasistencia`

---

## 🛠 Tecnologías utilizadas

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2 (PDO con consultas preparadas) |
| Base de datos | PostgreSQL 18 (extensión `btree_gist`, `EXCLUDE` y `tsrange` para evitar solapamientos) |
| Frontend | HTML5, CSS3 (hoja única `assets/estilos.css`), JavaScript nativo |
| Servidor local | XAMPP (Apache) + PostgreSQL |
| Seguridad | `password_hash`, tokens CSRF, consultas preparadas, control de acceso por rol en servidor |

---

## 👥 Roles de usuario y permisos

| Rol | Acceso |
|---|---|
| **Administrador** | Todos los módulos, incluido el panel administrativo de la portada y el módulo de reportes |
| **Recepcionista** | Pacientes, citas, reprogramación, cancelación, asistencia, historial y consulta de la agenda médica |
| **Médico** | `mi_agenda.php`: citas propias por fecha y horarios semanales activos |
| **Paciente** | `mis_citas.php`: próximas/anteriores/todas sus citas y perfil básico |

La autorización se aplica **en el servidor** (`cvs_exigir_roles()`), aunque un enlace se oculte en el menú; los accesos indebidos responden con 403 y la sesión inexistente redirige al login.

---

## 📁 Estructura principal de carpetas

```
clinica-vida-salud/
├── index.php                        # Portada + panel administrativo (admin/recepción)
├── login.php / logout.php           # Sesión (con bloqueo tras intentos fallidos)
├── usuarios.php                     # Administración web, solo administrador
├── mi_agenda.php / mis_citas.php     # Consultas personales por FK id_usuario
├── pacientes.php / medicos.php      # CRUD de personas
├── especialidades.php / horarios.php
├── citas.php                        # RF06 · registro de citas
├── agenda_medica.php                # RF11 · agenda administrativa por médico y fecha
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
├── tests/                           # Batería HTTP sobre clúster temporal
├── Dockerfile / deploy/             # Preparación de despliegue HTTPS
└── documentacion/                   # Ciclo 1 preservado; revisión/evidencia del Ciclo 2
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
    git clone --branch main --single-branch https://github.com/yllbbtjqmss/clinica-vida-salud.git
   cd clinica-vida-salud
   ```

2. **Iniciar servicios**: desde el panel de XAMPP, iniciar **Apache**; iniciar el servicio de **PostgreSQL**.

3. **Solo para una instalación nueva con base vacía**, crear la base de datos y su estructura. **Si ya tienes la base existente en XAMPP, omite este paso**; el Ciclo 2 no necesita migraciones:

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

    Compatible con PowerShell 5.1 (no utiliza `<<<` ni opciones exclusivas de PowerShell 7):

    ```powershell
    $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    $nombre = Read-Host 'Nombre de usuario'
    $secreto = Read-Host 'Contraseña (mínimo 12 caracteres)' -AsSecureString
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

    La herramienta crea **únicamente la primera cuenta** administradora. Si ya existe, inicia sesión y utiliza **Usuarios**. No ejecutes scripts de alta para reemplazar cuentas existentes.

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

También se admite configuración por variables del entorno del proceso: `CVS_DB_HOST`, `CVS_DB_PORT`, `CVS_DB_NAME`, `CVS_DB_USER`, `CVS_DB_PASSWORD`, `CVS_DB_SSLMODE`. Si existe `CVS_DB_HOST`, esta configuración tiene prioridad y no carga el archivo privado. Sin esa variable, XAMPP sigue usando el archivo externo. Ver `.env.example` (solo referencia, no se carga automáticamente) y [despliegue](deploy/DESPLIEGUE.md).

## 👤 Cómo comprobar los módulos del Ciclo 2 en XAMPP

1. Abre <http://localhost/clinica-vida-salud/login.php> e inicia sesión como administrador. Las sesiones previas a este incremento requieren volver a autenticarse.
2. En **Usuarios** registra una cuenta, selecciona el rol y asigna correo opcional. La contraseña debe tener mínimo 12 caracteres UTF-8 y máximo 72 bytes; confirma el mismo valor.
3. Para un médico: primero identifica su ficha en **Médicos**. En **Usuarios → Administrar**, elige rol `medico`, selecciona esa ficha y deja el selector de paciente vacío. No se crea otra ficha ni se copian sus datos. Los registros ya vinculados a otra cuenta no se ofrecen.
4. Para un paciente: identifica su ficha en **Pacientes**, luego elige rol `paciente` y su ficha desde **Usuarios**. Deja el selector médico vacío. El correo de acceso es el de `usuarios`, independiente del correo de la ficha.
5. Cierra sesión. Ingresa con usuario y después con correo. Los médicos van a **Mi agenda** y los pacientes a **Mis citas y perfil**. Los perfiles deben estar activos; sin vínculo aparece un aviso.
6. El médico puede filtrar por fecha y consultar sus horarios y los cinco estados. El paciente puede consultar próximas, anteriores y todas sus citas, con paginación y datos básicos.
7. Como administrador, busca y edita cuentas, activa/desactiva o restablece contraseñas desde **Administrar**. Nunca se muestra una clave existente; el restablecimiento invalida las sesiones anteriores en la próxima petición. Entrega la nueva clave de forma privada.
8. La cuenta activa no puede cambiar su propio rol. No es posible desactivar o degradar al último administrador activo. No se borran cuentas ni fichas. Al cambiar de rol elimina la selección del vínculo anterior.
9. Administración/recepción conserva **Agenda médica**. Fechas inexistentes o médicos inválidos muestran error; médico/paciente reciben 403 en la agenda administrativa. Los paneles propios rechazan IDs externos en URL.

Para pruebas de escritura sin afectar tus datos, utiliza la batería aislada de la siguiente sección.

## 🧪 Pruebas reproducibles y evidencia

Requiere Python 3, PHP 8.2 con `pdo_pgsql`/`mbstring` y binarios PostgreSQL (`initdb`, `pg_ctl`, `psql`). No utiliza la configuración privada: crea un **clúster nuevo** en una carpeta temporal y otro puerto de loopback, con base `cvs_ciclo2_test`, esquema y datos ficticios. Al terminar detiene y elimina ese clúster. No abre ni escribe en `clinica_vida_salud`.

```powershell
# La carpeta de evidencia ya existe en el proyecto.
# Puedes usar una carpeta temporal existente mediante CVS_TEST_TMP.
$env:CVS_TEST_TMP = $env:TEMP
python tests\funcionales.py --php "C:\xampp\php\php.exe" --pg-bin "C:\Program Files\PostgreSQL\18\bin" --evidencia documentacion\Ciclo_2_Grupo_17\resultados.json
```

La evidencia incluye fecha UTC, versiones, sintaxis, huella del SQL y resultado por escenario. El proceso devuelve código no cero si falla una comprobación. Se ejecutan PF01–PF10, login por ambos identificadores, usuarios, duplicados, vínculos, aislamiento, estado de cuentas, claves/sesiones, reprogramación, asistencia, inasistencia y seis secciones de reportes. No registra contraseñas, cookies ni tokens en la evidencia.

Opcional para navegación móvil automatizada: instalar `playwright` en el entorno de Python y Chromium mediante `python -m playwright install chromium`. Al estar disponible, la batería abre el navegador y guarda capturas `movil-*.png`. Sin Playwright, esa comprobación figura **PENDIENTE**, no aprobada. Son pruebas en navegador a tamaño móvil; no equivalen a dispositivos físicos.

Resultados ejecutados y alcance: [resultados JSON](documentacion/Ciclo_2_Grupo_17/resultados.json) y [revisión técnica](documentacion/Ciclo_2_Grupo_17/revision.md). La documentación del Ciclo 1 conserva su estado histórico.

## ☁️ Preparación de despliegue

Preparación para un **Web Service Docker de Render**, desde la rama **`ciclo2-desarrollo`**, conectado a la instancia existente **`clinica-vida-salud-db`**. Incluye PHP 8.2/Apache, `pdo_pgsql`, puerto dinámico `PORT` (10000 por defecto), conexión por `CVS_DB_*` y cookies Secure con `CVS_HTTPS=1`. XAMPP conserva su configuración privada externa sin cambios.

La [guía de despliegue](deploy/DESPLIEGUE.md) detalla las variables, la importación del esquema **solo a Render**, sin exportar datos locales, y los campos del futuro Web Service. Ver [resultados y comprobaciones pendientes](deploy/REVISION.md). **Docker no está instalado en este equipo: construir y ejecutar el contenedor sigue pendiente.** No se publicó el sistema ni se realizó una importación a Render; no se copiaron datos personales. El plan PostgreSQL gratuito expira a los 30 días: revisar su vigencia antes de la defensa.

---

## 📸 Capturas de pantalla

Capturas auténticas de navegación móvil con datos ficticios: `documentacion/Ciclo_2_Grupo_17/movil-*.png`. El informe del Ciclo 1 permanece en su carpeta académica.

---

## 🔄 Estado actual del desarrollo

- Rama oficial `main`; incremento del Ciclo 2 preparado en `ciclo2-desarrollo`, con interfaz **Dark Accent** compartida.
- Módulos RF01–RF10 y RF12 operativos sobre PostgreSQL con transacciones y validaciones de horario (bloqueo de intervalos solapados por médico).
- Portada con **panel administrativo** interactivo: banner, KPIs de pacientes y citas del día, calendario mensual con conteo de citas, disponibilidad de turnos del día y línea de tiempo de actividad.
- RF13 (**reportes administrativos**) integrado: KPIs, gráficos CSS con datos reales, filtros por período/médico/especialidad/estado, paginación, agenda diaria e impresión (vista `@media print`).
- Usuarios web, acceso por usuario/correo, paneles propios y RF11 integrados. Sin cambios de esquema ni recreación de la base existente.
- Corregidos filtros de fecha de agenda/reportes, ambigüedad SQL en reportes filtrados y selector del menú móvil.

## ⏳ Funcionalidades pendientes

- [x] Pantalla personal de **médico**.
- [x] Pantalla personal de **paciente**.
- [x] **Administración de usuarios** desde la web.
- [x] **RF11**: consulta administrativa y agenda médica propia según permisos.
- [x] Batería funcional aislada con resultados verificables del incremento y escenarios PF01–PF10.
- [ ] Regresión exhaustiva RF01–RF13: concurrencia entre trabajadores, fronteras temporales y combinaciones restantes de catálogos/filtros.
- [ ] Construir y verificar Docker; despliegue público HTTPS y limitación centralizada de intentos por IP/cuenta (el bloqueo PHP actual es por sesión).
- [ ] Accesibilidad y navegación en dispositivos físicos.
- [ ] Exportación CSV/PDF del módulo de reportes (opcional según RF13).
- [x] Capturas de navegador con datos ficticios para el Ciclo 2.

---

## 🧑‍💻 Integrantes del proyecto

- **Integrante 1:** DURAN PEREZ MELISSA
- **Integrante 2:** VASQUEZ CORDERO ANGELICA YANDIRA
- **Grupo:** 17

---

> 📌 README actualizado para el Ciclo 2. Grupo 17; documentación académica del Ciclo 1 preservada.
