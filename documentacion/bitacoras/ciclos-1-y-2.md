# Bitácora del proyecto — Ciclos 1 y 2

[Volver al README](../../README.md#bitacoras)

**Proyecto:** CLÍNICA VIDA SALUD · **Grupo:** 17 · **Revisión documental:** 02/11/2026.

## Criterio de registro

Esta bitácora se reconstruye a partir del historial Git, archivos actuales, informes académicos y confirmación manual del equipo. No es un registro contemporáneo de reuniones ni de horas trabajadas. Las fechas de commits corresponden a autor y confirmación, coincidentes en los hitos principales, con el desfase **UTC−04:00** almacenado por Git. Un commit acredita incorporación de código/documentos, no ejecución de todos sus casos de prueba.

Se distinguen cuatro fuentes:

| Fuente | Qué permite afirmar | Límite |
|---|---|---|
| Commit y archivos | Actividad versionada y resultado incorporado | No prueba el día de ejecución de un recorrido manual |
| Informe o revisión existente | Resultado y alcance declarados en ese documento | No equivale a repetir la comprobación; puede contener referencias a anexos ausentes |
| Reflog local | Movimiento de una referencia en esta copia | Es local, puede expirar y no se distribuye al clonar |
| Confirmación del equipo | Despliegue y recorridos manuales comunicados | No aporta por sí sola un log reproducible ni fecha exacta |

No se encontraron bitácoras independientes anteriores en el árbol ni rutas de bitácoras en el historial disponible de todas las referencias. **No se reemplazaron ni borraron documentos.** Se mantienen como fuentes:

- [Informe final del Ciclo 1 — PDF](../Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.pdf).
- [Informe final del Ciclo 1 — DOCX](../Ciclo_1_Grupo_17/Informe_Clinica_Vida_Salud_Ciclo_1.docx).
- [Preparación del despliegue](../../deploy/DESPLIEGUE.md) y [revisión del 29/10/2026](../../deploy/REVISION.md).

## Ciclo 1 — Construcción del núcleo y cierre documental

| Fecha Git, UTC−04:00 | Actividad y propósito | Resultado respaldado | Evidencia |
|---|---|---|---|
| 20/10/2026 | Iniciar el repositorio y registrar la primera base para continuar el desarrollo | Historial inicial y primer avance, incluido el esquema PostgreSQL | [950460e](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/950460e), [85c6afe](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/85c6afe) |
| 23/10/2026 | Incorporar pacientes y especialidades para disponer de identificación y catálogo de atención | RF02 y RF04 versionados | [6c5e56f](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/6c5e56f), [aa2a5e2](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/aa2a5e2) |
| 23/10/2026 | Incorporar autenticación para restringir las operaciones según rol | Inicio de sesión y control de acceso RF01 | [e457ded](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/e457ded) |
| 23/10/2026 | Implementar oferta profesional, horarios y reserva para construir el flujo principal de citas | RF03, RF05 y RF06; avance de RF07 según el mensaje del commit | [7074c09](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/7074c09) |
| 23/10/2026 | Incorporar cambios y seguimiento para gestionar reservas ya registradas | Cancelación RF09, reprogramación RF08 y agenda RF11 | [f568c6c](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/f568c6c), [3ead3bf](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/3ead3bf) |
| 24/10/2026 | Registrar resultados y consulta histórica para conservar el seguimiento administrativo | Asistencia RF10 e historial RF12, integrado con pacientes | [d4d1e11](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/d4d1e11), [4ad2b79](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4ad2b79) |
| 24/10/2026 | Integrar módulos y aplicar autenticación común para evitar pantallas aisladas | Integración inicial, acceso en médicos/horarios y reservas/reprogramación; agenda y menú común | [54725f5](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/54725f5), [2c13eac](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/2c13eac), [cde8f2e](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/cde8f2e), [e03c636](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/e03c636) |
| 24/10/2026 | Corregir disponibilidad y duración para respetar bloques de atención | Ajustes en las operaciones de citas y disponibilidad | [20c4892](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/20c4892) |
| 24/10/2026 | Unificar presentación y añadir información de gestión para facilitar navegación y consulta | Interfaz Dark Accent, barra lateral/topbar compartidas, dashboard y reportes RF13 | [4da053c](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4da053c), [1c279c7](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/1c279c7), [578ccbc](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/578ccbc) |
| 24/10/2026 | Documentar el sistema y corregir integrantes para preparar la entrega | README e identificación del Grupo 17 actualizados | [0adb71f](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/0adb71f), [0efb54c](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/0efb54c) |
| 24/10/2026, 23:48:32 | Incorporar documentación final para preservar la línea base académica | Informes DOCX y PDF del Ciclo 1, con requisitos, modelos, datos y pruebas diferenciadas de pendientes | [6e3e96a](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/6e3e96a), [carpeta original](../Ciclo_1_Grupo_17/) |

### Precisión sobre el hito 25/10/2026

El commit `6e3e96a` conserva `2026-10-24T23:48:32-04:00` tanto como fecha de autor como de confirmación. El mismo instante es **2026-10-25T03:48:32Z**. Por eso el hito conocido como «25/10, documentación final del Ciclo 1» es compatible con la fecha UTC, sin inventar una fecha local distinta.

### Resultado y evidencia del primer ciclo

El informe documenta el núcleo administrativo y la metodología PUDS-UML. Reporta comprobación sintáctica de 25 fuentes PHP y revisión de catálogo/agregados locales en solo lectura; **estas verificaciones históricas no se repitieron contra la base privada** para esta actualización. Diferencia operaciones comunicadas como realizadas y pruebas aún pendientes, especialmente concurrencia y validaciones exhaustivas.

El PDF contiene imágenes de pantallas en pp. 40–44 y 99–103, aunque algunos pasajes afirman que no había capturas incorporadas o mantienen pruebas pendientes. Se conserva el documento íntegro, sin usar esas imágenes para aprobar retroactivamente toda su matriz ni presentarlas como evidencia de Render.

Sus referencias a matrices Excel, JSON de evidencias, fuentes PlantUML y control documental no corresponden a archivos independientes presentes en esta copia. No se crean enlaces a esos anexos ausentes ni se reconstruyen como si fueran originales recuperados. Usuarios web, agenda propia del médico y citas propias del paciente estaban pendientes en la descripción del Ciclo 1 y se incorporaron después.

## Ciclo 2 — Usuarios, paneles personales y despliegue

| Fecha / fuente | Actividad y propósito | Resultado respaldado | Evidencia |
|---|---|---|---|
| 28/10/2026, 14:02:24, UTC−04:00; Git | Completar administración web y asociación de cuentas para habilitar consultas personales | `usuarios.php`, `includes/usuarios.php`, `mi_agenda.php`, `mis_citas.php` e `includes/personal.php`; acceso por usuario/correo, revalidación de sesiones y ajustes de agenda/reportes/interfaz | [4dcd1a7](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/4dcd1a7); 16 archivos modificados, sin cambio del esquema SQL |
| 29/10/2026, 13:31:34, UTC−04:00; Git | Preparar una ejecución Docker con configuración privada para publicar sin trasladar la base local | Dockerfile, configuración Apache/PHP, script de inicio, exclusiones, referencia de entorno y guías versionadas | [3415f3e](https://github.com/yllbbtjqmss/clinica-vida-salud/commit/3415f3e), [Dockerfile](../../Dockerfile), [guía](../../deploy/DESPLIEGUE.md) |
| 29/10/2026; documento | Revisar configuración y esquema de forma aislada antes de publicar | La revisión reporta 30/30 fuentes PHP con sintaxis válida, comprobaciones Apache/HTTP y esquema/PDO en clúster temporal. No acredita construcción Docker ni conexión/importación real a Render en esa fecha | [deploy/REVISION.md](../../deploy/REVISION.md) |
| 02/11/2026, 11:22:48, UTC−04:00; reflog local | Integrar el segundo incremento en la rama principal conservando su secuencia de commits | `main` pasó de `6e3e96a` a `3415f3e` mediante fast-forward, sin conflictos. No se creó commit de merge | `git reflog show main --date=iso-strict`; entrada `merge origin/ciclo2-desarrollo: Fast-forward` |
| Noviembre de 2026; confirmación del equipo, sin día exacto | Publicar y verificar el flujo compartido entre administración y perfiles | Despliegue en Render; acceso de administrador; creación/vinculación de médico y paciente; reserva y visualización por ambos perfiles; pruebas de reprogramación, cancelación y reportes confirmadas manualmente | [Aplicación publicada](https://clinica-vida-salud-p56z.onrender.com/), [tabla RM del README](../../README.md#pruebas). No hay un commit o registro adjunto que feche la ejecución |
| 02/11/2026; revisión documental actual | Contrastar Git local/remoto y acceso público para actualizar el estado sin usar credenciales | `main` y `ciclo2-desarrollo` locales, referencias remotas y ramas consultadas con `ls-remote` coinciden en `3415f3e`; portada y login devolvieron HTTP 200 en la comprobación posterior | Comandos de consulta al final; [repositorio](https://github.com/yllbbtjqmss/clinica-vida-salud). La primera consulta web devolvió 503; no se atribuye una causa sin evidencia |
| 02/11/2026; revisión documental actual | Sustituir referencias obsoletas sin alterar el sistema ni documentos anteriores | README reorganizado y esta bitácora añadida; sin ejecutar importaciones, altas, migraciones ni cambios de datos | Diferencias documentales del árbol de trabajo; esta actualización queda sin commit hasta revisión del equipo |

### Confirmaciones manuales y límites de evidencia

- La cuenta administradora usada en Render se identifica como `admin_clinica`; su contraseña fue definida privadamente y no se publica.
- Gabriel Pérez y Carla Vargas se identifican como perfiles ficticios de las pruebas por confirmación recibida. No hay un seed ni nombres de usuario verificados que permitan ofrecer sus accesos como cuentas universales.
- Los recorridos manuales confirmados no prueban todas las combinaciones de filtros, las cuatro matrices completas de permisos, concurrencia, accesibilidad ni rendimiento. Esas verificaciones permanecen pendientes de evidencia reproducible.
- No existe `tests/`, `documentacion/Ciclo_2_Grupo_17/`, `resultados.json` del Ciclo 2 ni las capturas móviles independientes que citaba el README anterior. El historial disponible tampoco aporta esos archivos: se retiran sus referencias activas, sin afirmar que las pruebas automáticas fueron ejecutadas.
- El documento del 29/10 conserva su estado de «preparación, sin publicación». La confirmación manual posterior actualiza el estado del proyecto, pero no reescribe ese registro histórico.
- La selección de rama, el commit efectivamente desplegado, el plan contratado, su vigencia y las versiones exactas de los servicios de Render no se pueden deducir de la igualdad de ramas ni del HTTP 200. No se accedió al panel privado ni a datos de PostgreSQL.

## Reproducción de la consulta histórica

Desde la raíz del repositorio, estos comandos son de lectura y no crean cuentas ni modifican la base:

```powershell
git status --short --branch
git log --all --date=iso-strict --format="%h %aI %s"
git show -s --format="%H %aI %cI %s" 6e3e96a 4dcd1a7 3415f3e
git show --stat 4dcd1a7
git show --stat 3415f3e
git reflog show main --date=iso-strict
git diff 6e3e96a 3415f3e -- database/clinica_vida_salud.sql
git ls-remote --heads https://github.com/yllbbtjqmss/clinica-vida-salud.git main ciclo2-desarrollo
```

El `diff` del esquema entre ambos hitos no muestra cambios. El reflog puede no estar disponible en otra copia; en ese caso la igualdad de referencias y la ascendencia de commits corroboran el estado integrado, pero no reconstruyen por sí solas el instante o comando local de integración.

## Equipo y continuidad

La portada confirma **Durán Pérez Melissa** y **Vásquez Cordero Angélica Yandira**, Grupo 17, Sistemas de Información I, Universidad Autónoma Gabriel René Moreno. No se asignan cargos ni actividades por persona: los nombres de ramas o autores Git no bastan para establecer un reparto formal del trabajo académico. Se omiten identificadores estudiantiles y cualquier dato de acceso.

Las futuras entradas deben conservar actividad, propósito, resultado y fuente. Para nuevas pruebas, registrar entorno y fecha realmente observados, datos ficticios y resultado esperado/obtenido sin contraseñas, tokens, cadenas privadas ni información clínica identificable.
