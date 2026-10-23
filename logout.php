<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/sesion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido. Utiliza el formulario de cierre de sesión.');
}
if (!cvs_csrf_valido($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Solicitud no válida. Vuelve al inicio y utiliza el formulario de cierre de sesión.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $parametros = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'=>time() - 42000,
        'path'=>$parametros['path'], 'domain'=>$parametros['domain'],
        'secure'=>$parametros['secure'], 'httponly'=>$parametros['httponly'],
        'samesite'=>$parametros['samesite'] ?? 'Lax'
    ]);
}
session_destroy();
header('Location: login.php', true, 303);
exit;
