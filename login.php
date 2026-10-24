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
<link rel="stylesheet" href="assets/estilos.css">
</head>
<body class="ui-pagina-login">
<main class="ui-login">
<section class="ui-login-marca">
<div class="cvs-marca">
<span class="cvs-logo"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></span>
<span class="cvs-marca-texto">
<span class="cvs-nombre">Clínica Vida Salud</span>
<span class="cvs-lema">Cuidamos tu vida, siempre</span>
</span>
</div>
<h1>Bienvenido a tu espacio de salud.</h1>
<p>Accede con tu cuenta para identificarte en el sistema de gestión de citas médicas.</p>
<span class="acciones"><a class="boton secundario" href="index.php">← Volver al inicio</a></span>
<p class="ui-login-frase">«Una comunidad más saludable — atención humana, tecnología que cuida.»</p>
</section>
<section class="ui-login-tarjeta">
<div class="tarjeta" aria-labelledby="titulo-login">
<h2 class="ui-login-titulo" id="titulo-login">Iniciar sesión</h2><p class="ayuda">Introduce los datos de tu cuenta. Todos los campos son obligatorios.</p>
<?php if ($aviso['error'] !== ''): ?><div class="error" role="alert"><?= cvs_escapar($aviso['error']) ?></div><?php endif; ?>
<form method="post" action="login.php">
<input type="hidden" name="csrf" value="<?= cvs_escapar(cvs_csrf_token()) ?>">
<div class="campo"><label for="nombre_usuario">Nombre de usuario</label><input id="nombre_usuario" name="nombre_usuario" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= cvs_escapar($aviso['nombre']) ?>"></div>
<div class="campo"><label for="contrasena">Contraseña</label><input id="contrasena" name="contrasena" type="password" maxlength="4096" autocomplete="current-password" required></div>
<button type="submit">Iniciar sesión</button>
</form>
<p class="nota">Entorno local de desarrollo · RF01.<br>Gestión de pacientes: administrador y recepcionista. Gestión de especialidades: únicamente administrador. Médico y paciente tendrán pantallas específicas posteriormente.</p>
</div>
</section>
</main>
</body>
</html>
