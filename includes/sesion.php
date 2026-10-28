<?php
declare(strict_types=1);

const CVS_INACTIVIDAD_SEGUNDOS = 1800;

function cvs_escapar(mixed $valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cvs_texto(array $origen, string $campo): string {
    return is_string($origen[$campo] ?? null) ? trim($origen[$campo]) : '';
}

function cvs_csrf_token(): string {
    if (!isset($_SESSION['cvs_csrf']) || !is_string($_SESSION['cvs_csrf'])) {
        $_SESSION['cvs_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cvs_csrf'];
}

function cvs_csrf_valido(mixed $token): bool {
    return is_string($token) && isset($_SESSION['cvs_csrf'])
        && is_string($_SESSION['cvs_csrf']) && hash_equals($_SESSION['cvs_csrf'], $token);
}

function cvs_olvidar_autenticacion(): void {
    // Mantener el límite de intentos de esta sesión, pero eliminar datos del usuario.
    $intentos = $_SESSION['cvs_intentos'] ?? null;
    $_SESSION = [];
    if (is_array($intentos)) $_SESSION['cvs_intentos'] = $intentos;
    session_regenerate_id(true);
    $_SESSION['cvs_csrf'] = bin2hex(random_bytes(32));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    // HTTPS público detrás de proxy: configuración del servidor, nunca una cabecera del cliente.
    $https = $https || getenv('CVS_HTTPS') === '1';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime'=>0, 'path'=>'/', 'domain'=>'',
        'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax'
    ]);
    if (!@session_start()) {
        error_log('RF01: no fue posible iniciar la sesion PHP.');
        http_response_code(500);
        exit('No fue posible iniciar una sesión segura.');
    }
}

$cvsAhora = time();
if (isset($_SESSION['cvs_auth'])) {
    $cvsUltimaActividad = $_SESSION['cvs_ultima_actividad'] ?? null;
    if (!is_int($cvsUltimaActividad) || $cvsUltimaActividad > $cvsAhora
        || $cvsAhora - $cvsUltimaActividad >= CVS_INACTIVIDAD_SEGUNDOS) {
        cvs_olvidar_autenticacion();
    } else {
        $_SESSION['cvs_ultima_actividad'] = $cvsAhora;
    }
}
unset($cvsAhora, $cvsUltimaActividad);

header('Cache-Control: no-store');
