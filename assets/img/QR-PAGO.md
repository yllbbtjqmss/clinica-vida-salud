# Imagen del pago por QR

Guardar aquí la imagen PNG original proporcionada en el chat con el nombre
`qr-pago-demo.png`, sin recortes, filtros ni regeneración del código.

El adjunto del chat es visible como referencia, pero no está disponible como archivo
en el entorno de edición. No se incluye una imagen sustituta.

`pago.php` y la ficha de una reserva QR activa usan ese mismo archivo. La descarga
HTML incorpora sus bytes como imagen base64 para que funcione sin conexión.
Si falta el archivo, se muestra un aviso en vez de un QR inventado o una imagen rota.

El QR es solo un recurso visual de la presentación académica. Su contenido no se
vincula dinámicamente al importe ni a la referencia del pago. No realizar operaciones
bancarias reales para probarlo. La aprobación sigue a cargo de administración.
