# Resultado de preparación para Render

Fecha: **2026-10-29**. Rama: **`ciclo2-desarrollo`**.
Estado: archivos preparados para revisión/subida manual; **sin commit ni push**,
sin publicación y sin acceso a la instancia `clinica-vida-salud-db`.

## Archivos preparados

Los archivos de despliegue mencionados anteriormente por el README no existían
en esta copia de la rama. Se crearon:

- `Dockerfile`: PHP 8.2/Apache, `pdo_pgsql`, comprobación de extensiones y Apache.
- `.dockerignore`: lista permitida y exclusión de secretos/configuración privada.
- `deploy/apache.conf`: VirtualHost con `PORT`, bloqueo de internos y logs mínimos.
- `deploy/php.ini`: configuración de producción exclusiva del contenedor.
- `deploy/apache-start.sh`: validación de `PORT` e inicio de Apache en primer plano.
- `.gitattributes`: finales LF para el script Linux.
- `.env.example`: solo marcadores, no credenciales; no se carga automáticamente.
- `deploy/DESPLIEGUE.md`: variables, importación dirigida a Render y pruebas Docker.
- Este informe; se actualizó únicamente la sección de despliegue del `README.md`.

**No se modificó ningún PHP, el esquema SQL ni la configuración privada de XAMPP.**
Sin `CVS_DB_HOST` se mantiene la conexión privada local; con ella se usan
`CVS_DB_HOST`, `CVS_DB_PORT`, `CVS_DB_NAME`, `CVS_DB_USER`, `CVS_DB_PASSWORD`
y `CVS_DB_SSLMODE` existentes.

## Comprobaciones ejecutadas

Entorno disponible: **PHP 8.2.12**, **Apache 2.4.58 Win64** y
**PostgreSQL 18.6**. Estas versiones locales no acreditan las del futuro contenedor.

| Comprobación | Resultado y alcance |
| --- | --- |
| Sintaxis PHP | **30/30 OK**, mediante `php -n -l`; no se ejecutaron los módulos funcionales |
| `deploy/php.ini` | **OK**: errores ocultos, log activo, `expose_php` apagado y sesiones estrictas/HttpOnly |
| Sintaxis Apache | **OK** con `PORT=10000` y `PORT=8080`, incluyendo `Listen 0.0.0.0:${PORT}` en configuración temporal |
| Acceso HTTP Apache | **OK**: 2 rutas públicas con 200 y 8 rutas internas/restringidas con 403; servidor temporal ligado solo a loopback |
| Script de inicio | **OK**: sintaxis con Git `sh`, 4 casos válidos/default y 8 inválidos/reservados; comandos Apache simulados |
| Login HTTP PHP | **200**, formulario CSRF y cookie Secure/HttpOnly/SameSite=Lax con `CVS_HTTPS=1`, sin conectar a ninguna base |
| Importación SQL aislada | **OK** en un clúster PostgreSQL nuevo, directorio temporal y puerto loopback independiente |
| Esquema inicial | **8 tablas**, extensión `btree_gist`, **4 roles**, **3 especialidades**; 0 usuarios/pacientes/médicos/citas |
| PDO por `CVS_DB_*` | **OK** contra ese clúster temporal, con `sslmode=disable` solo por tratarse de la prueba local sin TLS |
| Guía PowerShell | Sintaxis de **3 bloques OK**; no se ejecutaron los comandos de importación o publicación de la guía |
| Secretos | Revisión de los archivos preparados: sin credenciales reales, tokens, URLs autenticadas ni claves privadas detectados; los valores de ejemplo son marcadores |
| Código existente | `git diff --exit-code -- '*.php' database/clinica_vida_salud.sql` sin diferencias |

Para probar Apache se adaptaron exclusivamente las rutas del DocumentRoot y los
logs a una carpeta temporal con archivos ficticios. Se usó su configuración propia,
sin alterar ni reiniciar el Apache de XAMPP. El listener de las pruebas HTTP fue
`127.0.0.1`, no un servicio público. Esto **no** valida el MPM prefork de Linux.

Los clústeres PostgreSQL de prueba quedaron detenidos. No se leyó el archivo
privado de conexión, no se consultó ni modificó `clinica_vida_salud` y no se copiaron
datos personales. La revisión de secretos no es una auditoría del historial Git
ni de los documentos académicos; estos documentos no entran en la imagen.

## Pendiente, no aprobado por estas pruebas

1. **Docker no está instalado**: no se pudo construir ni ejecutar la imagen.
   Falta validar la descarga de la imagen base, compilación de `pdo_pgsql`, inicio
   real de Apache Linux y cambio real de puerto dentro del contenedor. La imagen
   incluye comprobaciones durante el build, pero todavía no se han ejecutado.
2. Conexión/TLS/credenciales y permisos reales de **Render**; el soporte de
   `btree_gist` se verificó en la documentación oficial, no en esa instancia.
3. Importación del esquema, creación de cuenta ficticia inicial y creación manual
   del Web Service tras autorizar la subida. No hay un despliegue acreditado.
4. Regresión funcional completa RF01–RF13 en Render. No se repitió esa batería
   durante esta preparación; tampoco existe `tests/` en esta copia de la rama.

La configuración corresponde al modelo Docker de Render, pero no se debe afirmar
que el contenedor o el despliegue funcionaron hasta completar esas pruebas.
Seguir [DESPLIEGUE.md](DESPLIEGUE.md) y revisar la expiración del PostgreSQL gratuito
antes de la defensa.
