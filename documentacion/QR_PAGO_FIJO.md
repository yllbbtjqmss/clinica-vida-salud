# Pago QR con imagen fija — 06/11/2026

## Estado de la entrega

La interfaz está preparada, pero **falta el archivo binario original**. La imagen del
chat es visible como referencia, no está expuesta como archivo a las herramientas de
edición. Guardarla como `assets/img/qr-pago-demo.png` (PNG real, no renombrar un JPEG).
No se recorta, filtra, decodifica ni regenera. No se añadió un QR sustituto.

Mientras no esté disponible se muestra «El código QR no está disponible. Consulta con
recepción», sin enlace roto ni retorno al generador anterior.

## Cambios

- `pago.php`: panel centrado, imagen fija, instrucciones, importe `Bs 100,00`,
  referencia seleccionable, estado real y contador existente. Nota académica discreta
  en el resumen en lugar de la advertencia destacada del QR.
- `includes/pagos_ui.php`: renderizado compartido del PNG; la descarga HTML incorpora
  sus bytes en base64 para funcionar fuera de localhost.
- `assets/pagos.js`: retirado solo el generador visual. Selección de métodos, tarjeta y
  contador conservados. La librería y su licencia siguen en `assets/vendor/`.
- `assets/estilos.css`: estilos locales del panel QR con la paleta existente y ancho
  fluido, sin deformar la imagen.
- `ficha_reserva.php`: mismo PNG solo en fichas de reservas QR activas; no se ofrece
  para volver a pagar desde un comprobante ni una reserva ya finalizada.
- `reservar_cita.php`: nota breve en la selección de pago en línea.
- Pruebas HTTP/navegador actualizadas: verifican recurso fijo o ausencia controlada,
  datos, contador, impresión, responsive y aprobación. Ya no intentan decodificar el
  antiguo QR dinámico.

No se modificaron `includes/reservas_pagos.php`, `includes/pasarela_demo.php`, SQL,
credenciales, permisos ni transacciones. Se conservan reserva temporal, referencia,
caducidad, movimientos, revisión administrativa y creación de la cita confirmada.
La imagen no contiene el importe ni la referencia específicos de cada reserva.

## Probar en localhost

Requiere XAMPP y el módulo de reservas/pagos ya instalado en la base de prueba elegida.
Este cambio no ejecuta migraciones ni cambia la conexión. No usar datos clínicos reales.

1. Guardar el PNG original en la ruta indicada. Comprobar que abre en
   `http://localhost/clinica-vida-salud/assets/img/qr-pago-demo.png`.
2. Iniciar sesión como paciente en `http://localhost/clinica-vida-salud/login.php`.
   Abrir **Reservar cita**, seleccionar consulta y revisar el resumen.
3. Elegir **Pago en línea → Código QR** y confirmar la reserva. Revisar imagen,
   importe, referencia completa, estado y cuenta atrás. Si el navegador conserva
   estilos anteriores, recargar con `Ctrl+F5`.
4. Abrir **Ver / imprimir ficha** y **Descargar ficha HTML**: ambas deben usar el
   mismo PNG. La descarga debe mostrarlo también sin conexión.
5. En otra sesión de administrador, abrir el pago desde `pagos.php` y seleccionar
   **Verificación de pagos → Pago aprobado → Registrar resultado** antes del vencimiento.
6. En la sesión del paciente, pulsar **Actualizar estado**. Comprobar **Pago: Pagado**,
   **Cita: Confirmada**, historial y comprobante; el panel para pagar desaparece.
7. Revisar también móvil, abandono y vencimiento con otras reservas ficticias.

El recurso es visual para la presentación académica. No hacer transferencias reales
con la imagen ni introducir datos bancarios para probarla. No existe integración
bancaria; un escaneo no acredita el pago ni confirma una cita.

## Verificación ejecutada

51 comprobaciones correctas en un clúster PostgreSQL nuevo y efímero, sin acceso a
bases reales. Incluye Edge/Chromium a 360, 390, 768 y 1440 px, contador, renderizado
sin JavaScript, impresión y aprobación QR desde administración. Sintaxis de los PHP
y JavaScript modificados comprobada.

Evidencia: [`pruebas-qr-fijo.json`](pruebas-qr-fijo.json). **La carga y fidelidad del
PNG adjunto siguen pendientes**: las pruebas ejecutadas verificaron el caso de recurso
ausente. Al incorporar el PNG, repetir la suite; comprueba carga de la imagen y su
inclusión exacta en la descarga HTML.

```powershell
python tests\ejecutar_reservas_pagos.py --evidencia documentacion\pruebas-qr-fijo-con-imagen.json --browser-tools "$env:LOCALAPPDATA\Temp\opencode\pagos-browser-tools"
```

Las herramientas de navegador son externas al proyecto; si no están instaladas,
omitir `--browser-tools` ejecuta solo las pruebas de lógica y HTTP.
