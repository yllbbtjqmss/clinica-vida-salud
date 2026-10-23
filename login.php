<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (cvs_usuario_actual() !== null) {
    header('Location: index.php', true, 303);
    exit;
}

// Límite básico POR SESIÓN: se elude borrando cookies o usando otra sesión.
// No reemplaza una limitación centralizada por cuenta/IP antes de publicar.
const CVS_LOGIN_MAX_INTENTOS = 5;
const CVS_LOGIN_VENTANA = 900;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = cvs_texto($_POST, 'nombre_usuario');
    // No recortar ni transformar la contraseña.
    $contrasena = is_string($_POST['contrasena'] ?? null) ? $_POST['contrasena'] : '';
    $error = '';
    $ahora = time();
    $limite = $_SESSION['cvs_intentos'] ?? ['inicio'=>$ahora, 'fallos'=>0, 'bloqueado_hasta'=>0];
    if ($ahora >= $limite['bloqueado_hasta']
        && ($limite['bloqueado_hasta'] > 0 || $ahora - $limite['inicio'] >= CVS_LOGIN_VENTANA)) {
        $limite = ['inicio'=>$ahora, 'fallos'=>0, 'bloqueado_hasta'=>0];
    }

    if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Solicitud no válida. Vuelve a intentarlo desde este formulario.';
    } elseif ($ahora < $limite['bloqueado_hasta']) {
        $error = 'Demasiados intentos fallidos. Espera 15 minutos desde el bloqueo antes de intentarlo nuevamente.';
    } elseif ($nombre === '' || $contrasena === '') {
        $error = 'Completa el nombre de usuario y la contraseña.';
    } elseif (preg_match('//u', $nombre) !== 1 || preg_match_all('/./us', $nombre) > 60 || strlen($contrasena) > 4096) {
        $error = 'Usuario o contraseña incorrectos.';
    } else {
        try {
            $consulta = cvs_conexion()->prepare('SELECT u.id_usuario, u.id_rol, u.nombre_usuario, u.contrasena_hash, u.activo, r.nombre AS rol
                FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.nombre_usuario = :nombre');
            $consulta->execute([':nombre'=>$nombre]);
            $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            cvs_error_base_datos($ex);
        }
        // Ejecutar una verificación también si no existe la cuenta, sin un hash fijo en el código.
        $hashAlternativo = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $coincide = password_verify($contrasena, $usuario ? $usuario['contrasena_hash'] : $hashAlternativo);
        unset($contrasena, $hashAlternativo);
        if ($usuario && $coincide && cvs_activo($usuario['activo']) && cvs_rol_valido($usuario['rol'])) {
            cvs_olvidar_autenticacion(); // Regenera el ID y el token CSRF.
            unset($_SESSION['cvs_intentos']);
            cvs_guardar_identidad($usuario);
            unset($usuario);
            header('Location: index.php', true, 303);
            exit;
        }
        unset($usuario);
        $limite['fallos']++;
        if ($limite['fallos'] >= CVS_LOGIN_MAX_INTENTOS) {
            $limite['bloqueado_hasta'] = $ahora + CVS_LOGIN_VENTANA;
            $error = 'Demasiados intentos fallidos. Espera 15 minutos antes de intentarlo nuevamente.';
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
    $_SESSION['cvs_intentos'] = $limite;
    // PRG: nunca conservar contraseñas ni hashes en sesión o en la URL.
    $_SESSION['cvs_login_aviso'] = ['error'=>$error, 'nombre'=>$nombre];
    header('Location: login.php', true, 303);
    exit;
}

$aviso = $_SESSION['cvs_login_aviso'] ?? ['error'=>'', 'nombre'=>''];
unset($_SESSION['cvs_login_aviso']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión | Clínica Vida Salud</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#f2f7f8;color:#17333b;font-family:Arial,sans-serif}main{max-width:1040px;margin:0 auto;padding:60px 22px;display:grid;grid-template-columns:1fr 1fr;gap:45px;align-items:center;min-height:100vh}.marca{display:inline-block;color:#146d73;background:#dfedef;padding:9px 13px;border-radius:25px;font-weight:bold;font-size:14px}h1{font-size:42px;line-height:1.15;margin:23px 0 18px}.presentacion p{color:#49646c;line-height:1.8;max-width:430px}.tarjeta{background:#fff;padding:34px;border-radius:18px;box-shadow:0 10px 35px #143b4212;border:1px solid #e1eeee}h2{margin:0 0 10px;font-size:25px}.ayuda{font-size:14px;color:#49646c;line-height:1.6}.campo{margin-top:22px}label{display:block;font-size:14px;font-weight:bold;margin-bottom:8px}input{width:100%;border:1px solid #c7d6d9;border-radius:8px;padding:13px;font:inherit}button{width:100%;border:0;border-radius:8px;background:#146d73;color:#fff;padding:14px;font:inherit;font-weight:bold;cursor:pointer;margin-top:25px}a{color:#146d73}a:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #db9c22;outline-offset:3px}.error{background:#ffe7e7;border-radius:8px;padding:13px;font-size:14px;line-height:1.5}.nota{border-top:1px solid #e1eeee;margin-top:24px;padding-top:16px;font-size:12px;color:#587079;line-height:1.6}@media(max-width:720px){main{display:block;padding:28px 18px}.presentacion{margin-bottom:28px}h1{font-size:31px}.tarjeta{padding:24px}.presentacion p{margin-bottom:0}}
</style>
</head>
<body>
<main>
<section class="presentacion"><span class="marca">Clínica Vida Salud</span><h1>Bienvenido a tu espacio de salud.</h1><p>Accede con tu cuenta para identificarte en el sistema de gestión de citas médicas.</p><p><a href="index.php">← Volver al inicio</a></p></section>
<section class="tarjeta" aria-labelledby="titulo-login">
<h2 id="titulo-login">Iniciar sesión</h2><p class="ayuda">Introduce los datos de tu cuenta. Todos los campos son obligatorios.</p>
<?php if ($aviso['error'] !== ''): ?><div class="error" role="alert"><?= cvs_escapar($aviso['error']) ?></div><?php endif; ?>
<form method="post" action="login.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>">
<div class="campo"><label for="nombre_usuario">Nombre de usuario</label><input id="nombre_usuario" name="nombre_usuario" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= cvs_escapar($aviso['nombre']) ?>"></div>
<div class="campo"><label for="contrasena">Contraseña</label><input id="contrasena" name="contrasena" type="password" maxlength="4096" autocomplete="current-password" required></div>
<button type="submit">Iniciar sesión</button>
</form>
<p class="nota">Entorno local de desarrollo · RF01.<br>Gestión de pacientes: administrador y recepcionista. Gestión de especialidades: únicamente administrador. Médico y paciente tendrán pantallas específicas posteriormente.</p>
</section>
</main>
</body>
</html>
