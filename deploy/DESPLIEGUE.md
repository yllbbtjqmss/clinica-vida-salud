# Render: Clínica Vida Salud (defensa universitaria)

Preparación para la rama **`ciclo2-desarrollo`** y la instancia existente
**`clinica-vida-salud-db`**. Esta guía no publica ni importa nada automáticamente.
Usar exclusivamente cuentas y fichas ficticias. No exportar la base local.

## 1. Qué contiene la imagen

- `php:8.2-apache-bookworm`: PHP 8.2, Apache y `mbstring`; instala `pdo_pgsql`
  con `libpq`. La construcción comprueba las extensiones y la sintaxis Apache.
- Solo copia los PHP, `includes`, `assets` y las herramientas CLI de `scripts`.
  No copia Git, documentación académica, SQL, `.env` ni el archivo privado local.
- `deploy/php.ini` complementa la configuración de producción **del contenedor**;
  no modifica XAMPP. Los errores no se muestran al navegador.
- Apache prohíbe listar directorios, acceder por HTTP a `includes`/`scripts`,
  diagnósticos, archivos ocultos y archivos de configuración.
- El inicio valida `PORT`, y Apache usa `Listen 0.0.0.0:${PORT}` y
  `<VirtualHost *:${PORT}>`. El valor por defecto es **10000**. `EXPOSE` no
  reemplaza la escucha real. No se mantiene un segundo listener en el puerto 80.
- No se ejecuta SQL, se crean cuentas ni se conectan bases durante el build/inicio.

## 2. Variables del futuro Web Service

Definirlas manualmente en **Environment** de Render, nunca en GitHub ni Dockerfile:

| Variable | Valor a configurar |
| --- | --- |
| `CVS_DB_HOST` | Host **interno** de la instancia PostgreSQL, sin `postgresql://` |
| `CVS_DB_PORT` | Puerto mostrado por Render, normalmente `5432` |
| `CVS_DB_NAME` | **Database** mostrado por Render; no confundir con el nombre del servicio |
| `CVS_DB_USER` | Usuario de PostgreSQL mostrado por Render |
| `CVS_DB_PASSWORD` | Contraseña de PostgreSQL, solo en el panel de Render |
| `CVS_DB_SSLMODE` | `require` |
| `CVS_HTTPS` | `1` (cookies Secure tras el proxy HTTPS de Render) |
| `PORT` | `10000` (Render también lo define por defecto) |

El Web Service y PostgreSQL deben estar en la **misma región y espacio de trabajo**
para usar el host interno. Desde tu computadora se usa el **host externo**.
`sslmode=require` cifra la conexión; no equivale a verificar la identidad del servidor
con `verify-full` y una CA configurada.

`includes/conexion.php` permanece sin cambios: cuando existe `CVS_DB_HOST`, usa las
seis variables `CVS_DB_*`; sin ella carga `C:/xampp/config_clinica/database.php`.
No establecer esas variables globalmente en Windows ni modificar el archivo privado.
`.env.example` solo contiene marcadores; la aplicación **no carga archivos `.env`**.

## 3. Importar SOLO el esquema a la nueva base de Render

**No se ha ejecutado esta importación.** No usar `pg_dump`, respaldos locales,
`DROP`, `TRUNCATE`, `CREATE DATABASE` ni restauraciones de datos personales.

`database/clinica_vida_salud.sql` crea 8 tablas, índices, restricciones,
`btree_gist`, cuatro roles y tres especialidades genéricas. No contiene usuarios,
contraseñas, pacientes, médicos ni citas. Render admite la extensión `btree_gist`.
El SQL tiene su propia transacción `BEGIN`/`COMMIT`; no añadir `--single-transaction`.

### Procedimiento recomendado (PowerShell y psql local)

1. En Render abre **`clinica-vida-salud-db` → Info / Connections**. Identifica
   **External Hostname**, puerto, **Database** y usuario. No pegar ni compartir la
   URL de conexión completa: contiene la contraseña. Si necesitas acceso externo,
   autoriza temporalmente solo tu IP en la configuración de acceso de PostgreSQL.
2. Desde la raíz del proyecto, abre una **consola PowerShell nueva**, sin transcripción,
   y ejecuta este bloque. Los valores se preguntan; la contraseña se introduce en
   el prompt oculto de `psql`, nunca en argumentos, variables o archivos:

   ```powershell
   & {
       $psql = 'C:\Program Files\PostgreSQL\18\bin\psql.exe'
       $hostRender = (Read-Host 'External Hostname de Render').Trim()
       $puertoRender = (Read-Host 'Puerto de Render (normalmente 5432)').Trim()
       $baseRender = (Read-Host 'Database exacta de Render').Trim()
       $usuarioRender = (Read-Host 'Usuario de Render').Trim()
       if ($hostRender -notmatch '^dpg-[a-zA-Z0-9-]+\.[a-zA-Z0-9.-]+\.render\.com$') {
           throw 'Se exige el hostname EXTERNO dpg-...render.com; no localhost ni una URL.'
       }
       if ($puertoRender -notmatch '^\d{1,5}$' -or [int]$puertoRender -lt 1 -or [int]$puertoRender -gt 65535) {
           throw 'Puerto invalido.'
       }
       if ($baseRender -notmatch '^[a-zA-Z0-9_]+$' -or $usuarioRender -notmatch '^[a-zA-Z0-9_]+$') {
           throw 'Introducir solo el nombre Database y usuario de Render, no URLs ni cadenas de conexion.'
       }
       $sslAnterior = [Environment]::GetEnvironmentVariable('PGSSLMODE', 'Process')
       $timeoutAnterior = [Environment]::GetEnvironmentVariable('PGCONNECT_TIMEOUT', 'Process')
       try {
           $env:PGSSLMODE = 'require'
           $env:PGCONNECT_TIMEOUT = '10'
           # -X ignora psqlrc; todos los parametros de destino son EXPLICITOS.
           $destino = @('-X', '-h', $hostRender, '-p', $puertoRender, '-U', $usuarioRender, '-d', $baseRender, '-W', '-v', 'ON_ERROR_STOP=1')
           & $psql @destino -c 'SELECT current_database(), current_user, inet_server_addr(), inet_server_port(); SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid(); SELECT tablename FROM pg_tables WHERE schemaname = ''public'' ORDER BY tablename;'
           if ($LASTEXITCODE -ne 0) { throw 'No se pudo verificar el destino; no importar.' }
           # Debes ver la base/usuario correctos, SSL=t y NINGUNA tabla de aplicacion.
           $confirmacion = Read-Host 'Solo si la base es la nueva de Render y esta vacia, escribe IMPORTAR-RENDER'
           if ($confirmacion -cne 'IMPORTAR-RENDER') { throw 'Importacion cancelada.' }
           & $psql @destino -f '.\database\clinica_vida_salud.sql'
           if ($LASTEXITCODE -ne 0) { throw 'Importacion fallida. Revisar antes de reintentar; no borrar tablas.' }
           & $psql @destino -c 'SELECT tablename FROM pg_tables WHERE schemaname = ''public'' ORDER BY tablename; SELECT extname FROM pg_extension WHERE extname = ''btree_gist''; SELECT count(*) AS roles FROM roles; SELECT count(*) AS especialidades FROM especialidades; SELECT count(*) AS usuarios FROM usuarios; SELECT count(*) AS pacientes FROM pacientes; SELECT count(*) AS medicos FROM medicos; SELECT count(*) AS citas FROM citas;'
           if ($LASTEXITCODE -ne 0) { throw 'No se pudo completar la verificacion final.' }
       } finally {
           [Environment]::SetEnvironmentVariable('PGSSLMODE', $sslAnterior, 'Process')
           [Environment]::SetEnvironmentVariable('PGCONNECT_TIMEOUT', $timeoutAnterior, 'Process')
       }
   }
   ```

3. Resultado esperado: 8 tablas, extensión `btree_gist`, 4 roles,
   3 especialidades y **0** usuarios/pacientes/médicos/citas. No repetir el SQL si ya
   existen tablas. Ante un error de importación, `ON_ERROR_STOP` detiene `psql` y la
   conexión cerrada revierte la transacción incompleta.
4. Restringe nuevamente el acceso externo cuando termines. No publicar capturas
   de credenciales, comandos de conexión completos ni direcciones privadas.

Este procedimiento no lee ni cambia la base local: siempre pasa el host externo,
puerto, usuario y base de Render explícitamente. No usa `CVS_DB_*` de XAMPP.

## 4. Primera cuenta administradora (sin Shell de pago)

Los Web Services gratuitos **no tienen Shell/SSH ni one-off jobs**. No depender
de `Render Shell` para ejecutar la herramienta ni crear un instalador web público.

Después de importar, en una **consola PowerShell temporal**, configura las seis
variables `CVS_DB_*` del proceso con el **host externo** de Render. Introduce la
contraseña de PostgreSQL con `Read-Host -AsSecureString`, convirtiéndola a texto
solo en memoria para `$env:CVS_DB_PASSWORD`; no escribirla en el historial. Usa el
procedimiento seguro del [README, paso 6](../README.md#-instrucciones-para-ejecutar-localmente)
para enviar las credenciales de la nueva cuenta ficticia a
`scripts/crear_administrador.php --entrada-powershell` por la tubería estándar.
**Comprueba primero el destino Render**, nunca reutilices una consola configurada
para la base local. Cierra la consola al terminar para descartar sus variables.
Esto crea únicamente la primera cuenta en Render, no copia ninguna cuenta local.

La herramienta existente rechaza una segunda cuenta administradora inicial. Si
ya existe, utiliza la administración web; nunca borrar ni reemplazar usuarios.

## 5. Crear el Web Service MANUALMENTE cuando lo autoricen

No pulsar **Create Web Service** hasta revisar los archivos y autorizar su subida.

| Campo de Render | Selección |
| --- | --- |
| Source | Repositorio GitHub del proyecto |
| Branch | **`ciclo2-desarrollo`** |
| Language / Runtime | **Docker**, no Static Site |
| Region | La misma que `clinica-vida-salud-db` |
| Root Directory | Vacío (raíz del repositorio) |
| Dockerfile Path | `./Dockerfile` |
| Docker Build Context | `.` (si aparece el campo) |
| Docker Command | Vacío: respetar el `CMD` de la imagen |
| Build / Start Command | No añadir comandos PHP/XAMPP; usar Dockerfile y CMD |
| Plan | Free para la demostración |
| Health Check Path | `/login.php` |
| Auto-Deploy | Desactivado / Off para controlar la publicación |

Agregar las variables de la sección 2. No crear otra base, ni usar una URL privada
como valor de `CVS_DB_HOST`, ni subir `.env` o credenciales a GitHub.

El health check comprueba Apache/PHP y sesiones, **no** la disponibilidad de la
base; verificar la conexión con el login después de importar/crear la cuenta.
Render termina TLS y reenvía HTTP al contenedor; `CVS_HTTPS=1` mantiene Secure
sin confiar en una cabecera enviada por el visitante ni generar redirecciones en bucle.

## 6. Prueba del contenedor (pendiente si no hay Docker)

En un equipo con Docker, desde la raíz (sin variables ni credenciales reales):

```powershell
docker build --pull -t cvs-render:prueba .
docker run --rm cvs-render:prueba php -v
docker run --rm cvs-render:prueba php -m
docker run --rm cvs-render:prueba apache2ctl -t
docker run --rm -d --name cvs-render-prueba -p 127.0.0.1:18080:10000 -e CVS_HTTPS=0 cvs-render:prueba
curl.exe -I http://127.0.0.1:18080/login.php
curl.exe -I http://127.0.0.1:18080/assets/estilos.css
curl.exe -I http://127.0.0.1:18080/includes/conexion.php
curl.exe -I http://127.0.0.1:18080/.env
curl.exe -I http://127.0.0.1:18080/scripts/crear_administrador.php
curl.exe -I http://127.0.0.1:18080/verificar.php
docker logs cvs-render-prueba
docker stop cvs-render-prueba
```

Esperado: PHP 8.2, `pdo_pgsql` y `mbstring`, `Syntax OK`, login/CSS **200** e
internos/ocultos/diagnósticos **403**. No probar credenciales locales. Se usa
`CVS_HTTPS=0` solo porque la prueba es HTTP local; en Render debe ser `1`.
Para comprobar Secure en local, iniciar con `CVS_HTTPS=1` y revisar `Set-Cookie`
del login (el navegador no conservará esa cookie sobre HTTP).

Comprobar además que `PORT` es dinámico y que un valor inválido impide arrancar:

```powershell
docker run --rm -d --name cvs-render-puerto -p 127.0.0.1:18081:8080 -e PORT=8080 -e CVS_HTTPS=0 cvs-render:prueba
curl.exe -I http://127.0.0.1:18081/login.php
docker stop cvs-render-puerto
docker run --rm -e PORT=invalido cvs-render:prueba
```

Esperado: **200** en 18081; con `PORT=invalido`, salida no cero sin iniciar Apache.
Estas instrucciones no sustituyen una construcción/ejecución real. Consultar el
[resultado de revisión](REVISION.md) para distinguir lo ejecutado de lo pendiente.

## 7. Comprobaciones para el día de la defensa

- Revisar la fecha de expiración de PostgreSQL en el panel: el plan gratuito
  **expira a los 30 días**, no dispone de respaldos administrados y tiene 1 GB.
- El Web Service gratuito se duerme tras 15 minutos sin tráfico; abrirlo con
  antelación. Los reinicios/suspensiones/despliegues eliminan las sesiones PHP
  guardadas en archivos: volver a iniciar sesión. La base está separada.
- Probar HTTPS, login/logout, roles y los módulos ya validados, solo con datos
  ficticios. El código funcional no se modifica en esta preparación.
- El bloqueo de login actual es por sesión, no una defensa centralizada por
  IP/cuenta. Esto es una demostración académica, **no un sistema clínico productivo**.
- No acreditar despliegue, importación ni regresión completa antes de realizarlos.

Referencias oficiales consultadas durante la preparación:
[puerto y TLS](https://render.com/docs/web-services#port-binding),
[extensiones PostgreSQL](https://render.com/docs/postgresql-extensions),
[limitaciones Free](https://render.com/docs/free).
