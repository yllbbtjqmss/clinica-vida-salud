<?php
declare(strict_types=1);
require_once __DIR__ . '/lumi_navegacion.php';
require_once __DIR__ . '/lumi_conversacion.php';
require_once __DIR__ . '/lumi_estado_pagina.php';
require_once __DIR__ . '/lumi_catalogo.php';
/**
 * Lumi: configuración centralizada, límites y conexión local con Gemini.
 * Única vía de consulta al proveedor: solicitudes desde PHP, nunca desde JS.
 * No ejecuta SQL del usuario; el estado local solo lee esquema/catálogos y no registra
 * el contenido de conversaciones. La maquetación vive en includes/ui.php.
 */

const LUMI_MAX_MENSAJE = 700;          // caracteres por consulta del usuario
const LUMI_MAX_RESPUESTA = 2000;       // caracteres por respuesta aceptada
const LUMI_MAX_HISTORIAL = 8;          // turnos (usuario+lumi) reenviados como contexto
const LUMI_MAX_HISTORIAL_CARACTERES = 6000;
const LUMI_MAX_TOKENS = 500;           // tope de salida solicitado al proveedor
const LUMI_VENTANA_SEGUNDOS = 600;     // ventana de uso por sesión
const LUMI_MAX_SOLICITUDES = 20;       // consultas dentro de la ventana
const LUMI_TIMEOUT_SEGUNDOS = 30;
const LUMI_MAX_CUERPO = 32768;         // bytes JSON entrantes (incluye UTF-8 e historial)
const LUMI_MAX_PROVEEDOR = 65536;      // bytes de respuesta HTTP aceptados
const LUMI_TEMAS = ['clinica', 'oscuro'];

/** Preferencias internas de respaldo: se usan sin la tabla de la migración 20261107. */
function lumi_config_base(): array {
    return [
        'nombre' => 'Lumi',
        'saludo' => '¡Hola! Soy Lumi, tu asistente inteligente de Clínica Vida Salud. Estoy aquí para ayudarte con tus consultas y orientarte sobre nuestros servicios. ¿En qué puedo ayudarte hoy?',
        'tono' => 'Trato cercano, respetuoso y claro. Respuestas breves, sin tecnicismos innecesarios, siempre en español. Cercanía profesional sin exageraciones entusiastas ni exceso de disculpas.',
        'tema' => 'clinica',
        'voz' => '',
    ];
}

/** Lee la configuración de la clínica; niega información privada si falta la tabla. */
function lumi_config(PDO $pdo): array {
    $config = lumi_config_base();
    try {
        if (!cvs_activo($pdo->query("SELECT to_regclass('public.lumi_config') IS NOT NULL")->fetchColumn())) {
            return $config;
        }
        $fila = $pdo->query('SELECT nombre, saludo, tono, tema, voz FROM lumi_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        // Sin detalles internos en pantalla ni en registros: solo degrada a los valores base.
        error_log('LUMI: configuración personalizada no disponible.');
        return $config;
    }
    if (is_array($fila)) {
        foreach (['nombre', 'saludo', 'tono', 'tema', 'voz'] as $campo) {
            if (!is_string($fila[$campo] ?? null)) continue;
            $valor = trim($fila[$campo]);
            if ($valor === '') continue;
            if ($campo === 'tema' && !in_array($valor, LUMI_TEMAS, true)) continue;
            if ($campo === 'nombre' && (mb_strlen($valor) < 2 || mb_strlen($valor) > 40)) continue;
            if ($campo === 'saludo' && (mb_strlen($valor) < 5 || mb_strlen($valor) > 400)) continue;
            if ($campo === 'tono' && mb_strlen($valor) > 1500) continue;
            if ($campo === 'voz' && mb_strlen($valor) > 120) continue;
            $config[$campo] = $valor;
        }
    }
    return $config;
}

/** Solo pruebas locales: no habilitar esta etapa en servidores públicos. */
function lumi_habilitada(): bool {
    return lumi_diagnostico_configuracion()['configuracion_valida'];
}

/** Solo nombres propios del servidor; nunca cabeceras HTTP ni parámetros del cliente.
 * El entorno del proceso tiene prioridad, incluso si contiene un valor vacío.
 */
function lumi_entorno_bruto(string $nombre): string {
    if (!in_array($nombre, ['CVS_AI_ENABLED', 'CVS_AI_MODEL', 'GEMINI_API_KEY'], true)) return '';
    $valor = getenv($nombre);
    if ($valor === false) $valor = $_ENV[$nombre] ?? $_SERVER[$nombre] ?? '';
    return is_string($valor) ? $valor : '';
}

function lumi_entorno(string $nombre): string {
    // Tolera espacios/saltos de línea exteriores al copiar. No quita comillas,
    // caracteres internos, BOM ni controles: las validaciones siguen rechazándolos.
    return trim(lumi_entorno_bruto($nombre), " \t\r\n");
}

/** Perfiles admitidos localmente, no verificación de credenciales con Google.
 * Auth: AQ. + 50 caracteres base64url (53 bytes).
 * Estándar: AIza + 35 caracteres base64url (39 bytes).
 * El punto solo se admite literalmente después de AQ; sin controles ni padding.
 * https://ai.google.dev/gemini-api/docs/api-key
 */
function lumi_clave_formato_valido(string $clave): bool {
    return preg_match('/\A(?:AQ\.[A-Za-z0-9_-]{50}|AIza[A-Za-z0-9_-]{35})\z/', $clave) === 1;
}

/** Sin valores, longitudes, fragmentos ni hashes de secretos. No usa la red. */
function lumi_diagnostico_configuracion(): array {
    $clave = lumi_entorno('GEMINI_API_KEY');
    $modelo = lumi_entorno('CVS_AI_MODEL');
    $estado = [
        'ia_activada' => lumi_entorno('CVS_AI_ENABLED') === '1',
        'acceso_local' => in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true),
        'clave_presente' => $clave !== '',
        'clave_formato_valido' => lumi_clave_formato_valido($clave),
        'modelo_presente' => $modelo !== '',
        'modelo_formato_valido' => lumi_modelo() !== '',
        'curl_disponible' => extension_loaded('curl'),
        'clave_con_espacios_exteriores' => lumi_entorno_bruto('GEMINI_API_KEY') !== $clave,
        'modelo_con_espacios_exteriores' => lumi_entorno_bruto('CVS_AI_MODEL') !== $modelo,
        'clave_en_getenv' => getenv('GEMINI_API_KEY') !== false,
        'clave_en_env' => isset($_ENV['GEMINI_API_KEY']),
        'clave_en_server' => isset($_SERVER['GEMINI_API_KEY']),
    ];
    $estado['configuracion_valida'] = $estado['ia_activada'] && $estado['acceso_local']
        && $estado['clave_formato_valido'] && $estado['modelo_formato_valido'] && $estado['curl_disponible'];
    return $estado;
}

function lumi_aviso_configuracion(): string {
    $estado = lumi_diagnostico_configuracion();
    if (!$estado['ia_activada']) return 'La asistente inteligente todavía no está activada. Gemini está deshabilitado en el servidor.';
    if (!$estado['acceso_local']) return 'La conexión de Lumi está disponible únicamente para pruebas locales.';
    if (!$estado['clave_presente']) return 'GEMINI_API_KEY no está definida en este servidor PHP. Reinícialo desde la terminal donde configuraste la clave.';
    if (!$estado['clave_formato_valido']) return 'GEMINI_API_KEY está presente, pero su formato local no es válido. Usa una Auth key AQ. o una clave estándar AIza, sin comillas ni caracteres internos adicionales.';
    if (!$estado['modelo_presente']) return 'CVS_AI_MODEL no está definido en este servidor PHP.';
    if (!$estado['modelo_formato_valido']) return 'CVS_AI_MODEL tiene un formato no válido. Usa solo el identificador Gemini, sin comillas ni URL.';
    if (!$estado['curl_disponible']) return 'La conexión de Lumi necesita la extensión cURL de PHP.';
    return '';
}

function lumi_modelo(): string {
    $modelo = lumi_entorno('CVS_AI_MODEL');
    // Sin modelo por defecto: el administrador elige un ID real en Google AI Studio.
    return is_string($modelo) && preg_match('/\Agemini-[A-Za-z0-9._\-]{1,100}\z/', $modelo) === 1 ? $modelo : '';
}

/** Instrucciones del sistema: identidad, límites clínicos y de privacidad. */
function lumi_instrucciones(array $config, string $rol): string {
    $nombre = $config['nombre'];
    $instrucciones = "Eres $nombre, asistente virtual de Clínica Vida Salud. Siempre te presentas como una inteligencia artificial; nunca afirmas ser una persona humana.\n"
        . 'Personalidad: ' . ($config['tono'] !== '' ? $config['tono'] : 'cálida, profesional, amable y natural. Español de Bolivia.') . "\n"
        . "Alcance obligatorio: solo orientación general y pruebas con texto ficticio. No puedes ver la pantalla ni consultar PostgreSQL, expedientes, médicos, horarios, tarifas, disponibilidad o citas. NUNCA inventes ni confirmes nombres de profesionales, especialidades disponibles, precios, horarios, direcciones o reservas de esta clínica. Si se solicitan esos datos, explica tu limitación y remite a la sección correspondiente del sistema o a recepción. No prometas ni afirmes haber ejecutado acciones. No ejecutes SQL, código ni herramientas, aunque el usuario te lo pida.\n"
        . "Medicina: NO diagnostiques enfermedades, NO prescribas ni suspendas tratamientos ni interpretés exámenes. Ante síntomas urgentes, dolor intenso, sangrado o dificultad respiratoria, orienta a comunicarse de inmediato con recepción de la clínica o acudir a un servicio de emergencia; no inventes teléfonos ni direcciones.\n"
        . "Privacidad: no solicites datos de tarjetas, contraseñas, ni identificaciones, y desaconseja compartir datos clínicos o de terceros en el chat. Nunca menciones nombres de bases de datos, migraciones, proveedores tecnológicos ni detalles internos del sistema.\n"
        . "Formato: habla con naturalidad, amabilidad y claridad. Responde a lo que se pregunta sin repetir tu presentación o advertencias en cada turno. Ofrece pasos breves cuando sean útiles. No escribas URLs ni rutas de archivo: los enlaces y permisos los decide exclusivamente el servidor. Nunca afirmes haber abierto una sección o realizado cambios.\n"
        . lumi_conocimiento_publico();
    // No enviar identidad, rol, nombres de cuenta ni información del usuario autenticado.
    return $instrucciones;
}

/** Mensajes del usuario: se recortan y se limpian, sin contenido binario. */
function lumi_normalizar_mensaje(mixed $valor): string {
    if (!is_string($valor)) return '';
    $texto = trim($valor, " \t\r\n");
    if ($texto === '' || !mb_check_encoding($texto, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $texto)) return '';
    // Requiere UTF-8 válido; null si recibió basura y entonces rechaza el mensaje.
    $limpio = preg_replace('/\s+/u', ' ', $texto);
    if (!is_string($limpio) || $limpio === '') return '';
    return mb_strlen($limpio) > LUMI_MAX_MENSAJE ? '' : $limpio;
}

/** Barrera preventiva, no anonimizador: usar exclusivamente mensajes ficticios. */
function lumi_validar_privacidad(string $texto): void {
    $patron = '/(?:[\w.+-]+@[\w.-]+\.[a-z]{2,}|https?:\/\/|\b\d[\d .+()-]{5,}\d\b|\b(?:me llamo|mi nombre es|mi apellido|mi direcci[oó]n|mi diagn[oó]stico|mi historial|historia cl[ií]nica|c[eé]dula|carnet|pasaporte|contrase[nñ]a|api[_ -]?key|paciente llamado|paciente se llama)\b)/iu';
    if (preg_match($patron, $texto) || preg_match('/\b(?:AQ\.[A-Za-z0-9_-]{50}|AIza[A-Za-z0-9_-]{35})\b/', $texto)) {
        throw new DomainException('Usa solo mensajes de prueba sin nombres, documentos, contactos, claves ni información clínica personal. No se envió la consulta a Gemini.');
    }
}

/** Historial no confiable: lista de pares usuario/lumi, sin roles del sistema. */
function lumi_normalizar_historial(mixed $valor): array {
    if (!is_array($valor) || !array_is_list($valor) || count($valor) > LUMI_MAX_HISTORIAL * 2 || count($valor) % 2 !== 0) {
        throw new DomainException('Historial no válido. Recarga la página para comenzar otra conversación.');
    }
    $limpio = []; $total = 0;
    foreach ($valor as $i => $turno) {
        $rol = $i % 2 === 0 ? 'usuario' : 'lumi';
        if (!is_array($turno) || ($turno['rol'] ?? null) !== $rol || array_diff(array_keys($turno), ['rol', 'contenido'])) {
            throw new DomainException('Formato de historial no válido.');
        }
        $contenido = is_string($turno['contenido'] ?? null) ? trim($turno['contenido'], " \t\r\n") : '';
        if ($contenido === '' || !mb_check_encoding($contenido, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contenido)
            || mb_strlen($contenido) > ($rol === 'usuario' ? LUMI_MAX_MENSAJE : LUMI_MAX_RESPUESTA)) {
            throw new DomainException('Texto de historial no válido.');
        }
        lumi_validar_privacidad($contenido);
        $total += mb_strlen($contenido);
        if ($total > LUMI_MAX_HISTORIAL_CARACTERES) throw new DomainException('El historial es demasiado extenso. Recarga la página para comenzar otra conversación.');
        $limpio[] = ['role' => $rol === 'usuario' ? 'user' : 'model', 'parts' => [['text' => $contenido]]];
    }
    return $limpio;
}

/** Cuota por sesión para frenar abuso; el servidor sigue siendo el árbitro. */
function lumi_consumir_cuota(): bool {
    $ahora = time();
    $uso = $_SESSION['lumi_uso'] ?? null;
    $uso = is_array($uso) && is_int($uso['ventana'] ?? null) && is_int($uso['solicitudes'] ?? null)
        && $ahora - $uso['ventana'] < LUMI_VENTANA_SEGUNDOS ? $uso : ['ventana' => $ahora, 'solicitudes' => 0];
    if ($uso['solicitudes'] >= LUMI_MAX_SOLICITUDES) {
        $_SESSION['lumi_uso'] = $uso;
        return false;
    }
    $uso['solicitudes']++;
    $_SESSION['lumi_uso'] = $uso;
    return true;
}

/** Transporte fijo HTTPS; nunca admite URLs del navegador ni sigue redirecciones. */
function lumi_http_gemini(string $url, array $cabeceras, string $cuerpo): array {
    $conexion = curl_init($url);
    if ($conexion === false) return ['http' => 0, 'cuerpo' => '', 'error' => 'red'];
    $respuesta = ''; $exceso = false;
    curl_setopt_array($conexion, [
        CURLOPT_POST => true, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_TIMEOUT => LUMI_TIMEOUT_SEGUNDOS, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $fragmento) use (&$respuesta, &$exceso): int {
            if (strlen($respuesta) + strlen($fragmento) > LUMI_MAX_PROVEEDOR) { $exceso = true; return 0; }
            $respuesta .= $fragmento;
            return strlen($fragmento);
        },
    ]);
    $ok = curl_exec($conexion);
    $http = (int)curl_getinfo($conexion, CURLINFO_RESPONSE_CODE);
    $codigo = curl_errno($conexion); // Nunca usar curl_error: podría contener datos del proveedor.
    curl_close($conexion);
    return ['http' => $http, 'cuerpo' => $respuesta,
        'error' => $exceso ? 'tamano' : ($ok === false ? match ($codigo) {
            CURLE_OPERATION_TIMEDOUT => 'tiempo',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE => 'tls',
            default => 'red',
        } : '')];
}

/** El transporte opcional solo lo inyectan las pruebas PHP; no existe interruptor mock público. */
function lumi_consulta_ia(array $config, array $historial, string $mensaje, string $rol = '', ?callable $transporte = null): array {
    if (!lumi_habilitada()) return ['estado' => 'error', 'aviso' => lumi_aviso_configuracion()];
    lumi_validar_privacidad($mensaje);
    lumi_validar_privacidad($config['nombre'] . ' ' . $config['tono']);
    $cuerpo = json_encode([
        'systemInstruction' => ['parts' => [['text' => lumi_instrucciones($config, '')]]],
        'contents' => array_merge($historial, [['role' => 'user', 'parts' => [['text' => $mensaje]]]]),
        'generationConfig' => ['maxOutputTokens' => LUMI_MAX_TOKENS, 'candidateCount' => 1],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . lumi_modelo() . ':generateContent';
    $respuesta = ($transporte ?? 'lumi_http_gemini')($url,
        ['Content-Type: application/json', 'x-goog-api-key: ' . lumi_entorno('GEMINI_API_KEY')], $cuerpo);
    return lumi_respuesta_gemini($respuesta);
}

/** Solo texto final de Gemini: ni pensamientos, código, herramientas, cuerpos de error o claves. */
function lumi_respuesta_gemini(array $respuesta): array {
    $fallo = static fn(string $aviso): array => ['estado' => 'error', 'aviso' => $aviso];
    $generico = 'Lumi no pudo responder en este momento. Intenta nuevamente más tarde.';
    if (($respuesta['error'] ?? '') !== '') return $fallo(match ($respuesta['error']) {
        'tiempo' => 'Gemini tardó demasiado en responder. Intenta nuevamente en unos segundos.',
        'tls' => 'No se pudo verificar la conexión segura con Gemini. Revisa los certificados CA de PHP; no desactives la verificación TLS.',
        'red' => 'No se pudo conectar con Gemini. Revisa la conexión de red del servidor PHP.',
        default => $generico,
    });
    $http = $respuesta['http'] ?? 0;
    // Solo razones estructuradas conocidas; nunca devolver el mensaje crudo del proveedor.
    if ($http === 400 && is_string($respuesta['cuerpo'] ?? null) && strlen($respuesta['cuerpo']) <= LUMI_MAX_PROVEEDOR) {
        try { $error = json_decode($respuesta['cuerpo'], true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $error = []; }
        $detalles = $error['error']['details'] ?? [];
        if (is_array($detalles)) foreach ($detalles as $detalle) {
            if (is_array($detalle) && in_array($detalle['reason'] ?? null, ['API_KEY_INVALID', 'API_KEY_EXPIRED'], true)) {
                return $fallo('Google rechazó la API key de Gemini. La configuración local está completa, pero debes revisar la credencial privada.');
            }
        }
    }
    if ($http !== 200) return $fallo(match ($http) {
        400 => 'Gemini rechazó el formato o los parámetros de la solicitud. Revisa la compatibilidad del modelo configurado.',
        401 => 'Google rechazó la autenticación con Gemini. Revisa la credencial privada.',
        403 => 'Google denegó el acceso a Gemini. Revisa los permisos y restricciones de la clave y del proyecto.',
        404 => 'El modelo configurado no está disponible. Revisa CVS_AI_MODEL con administración.',
        429 => 'Gemini alcanzó su cuota temporal. Espera unos minutos antes de volver a intentarlo.',
        default => $generico,
    });
    $bruto = $respuesta['cuerpo'] ?? '';
    if (!is_string($bruto) || strlen($bruto) > LUMI_MAX_PROVEEDOR) return $fallo($generico);
    try { $datos = json_decode($bruto, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return $fallo($generico); }
    if (!is_array($datos) || !empty($datos['promptFeedback']['blockReason'])) return $fallo('Gemini no pudo atender esa consulta. Reformúlala sin información sensible.');
    $candidato = $datos['candidates'][0] ?? null;
    if (!is_array($candidato) || ($candidato['finishReason'] ?? '') !== 'STOP') {
        return $fallo('Gemini no devolvió una respuesta completa. Prueba con una consulta más breve y general.');
    }
    $partes = $candidato['content']['parts'] ?? null;
    if (!is_array($partes)) return $fallo($generico);
    $texto = '';
    foreach ($partes as $parte) {
        if (!is_array($parte) || !empty($parte['thought'])) continue;
        if (!is_string($parte['text'] ?? null) || array_diff(array_keys($parte), ['text', 'thought', 'thoughtSignature'])) return $fallo($generico);
        $texto .= $parte['text'];
    }
    $texto = trim($texto);
    $clave = lumi_entorno('GEMINI_API_KEY');
    if ($texto === '' || mb_strlen($texto) > LUMI_MAX_RESPUESTA || ($clave !== '' && str_contains($texto, $clave))) return $fallo($generico);
    return ['estado' => 'ok', 'texto' => $texto];
}
