<?php
declare(strict_types=1);
/** Catálogo público autenticado: tres tablas, proyecciones mínimas y SELECT preparados.
 * No reutilizar endpoints de reservas: pueden escribir vencimientos incluso en GET.
 * Proyecciones públicas: nombre de especialidad y nombre legal del profesional.
 * Nunca se leen id_usuario, registro_profesional, contacto, usuarios ni computa SQL del cliente.
 */
const LUMI_CATALOGO_MAX_FILAS = 10;

function lumi_catalogo_permitido(string $rol): bool {
    return in_array($rol, ['paciente', 'medico', 'recepcionista', 'administrador'], true);
}

function lumi_catalogo_exigir_rol(string $rol): void {
    if (!lumi_catalogo_permitido($rol)) throw new DomainException('Catálogo no autorizado para tu cuenta.');
}

function lumi_catalogo_normalizar(string $texto): string {
    return strtr(mb_strtolower(trim($texto), 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function lumi_catalogo_especialidades(PDO $pdo, string $rol): array {
    lumi_catalogo_exigir_rol($rol);
    $q = $pdo->prepare('SELECT e.id_especialidad, e.nombre FROM public.especialidades e
        WHERE e.activo = TRUE ORDER BY e.nombre, e.id_especialidad LIMIT :limite');
    $q->bindValue(':limite', LUMI_CATALOGO_MAX_FILAS + 1, PDO::PARAM_INT);
    $q->execute();
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Coincidencia exacta primero; tildes opcionales solo si no hay coincidencia exacta.
 * Nunca LIKE ni nombres de tabla/SQL aportados por el cliente. Dos filas detectan ambigüedad.
 */
function lumi_catalogo_buscar_especialidad(PDO $pdo, string $rol, string $nombre): array {
    lumi_catalogo_exigir_rol($rol);
    if ($nombre === '' || mb_strlen($nombre) > 100) return [];
    $q = $pdo->prepare('SELECT e.id_especialidad, e.nombre FROM public.especialidades e
        WHERE e.activo = TRUE AND lower(btrim(e.nombre)) = :nombre ORDER BY e.id_especialidad LIMIT 2');
    $q->execute([':nombre'=>mb_strtolower(trim($nombre), 'UTF-8')]);
    $filas = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($filas) return $filas;
    $q = $pdo->prepare("SELECT e.id_especialidad, e.nombre FROM public.especialidades e
        WHERE e.activo = TRUE AND translate(lower(btrim(e.nombre)), 'áéíóúüñ', 'aeiouun') = :nombre
        ORDER BY e.id_especialidad LIMIT 2");
    $q->execute([':nombre'=>lumi_catalogo_normalizar($nombre)]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function lumi_catalogo_medicos(PDO $pdo, string $rol, int $especialidad): array {
    lumi_catalogo_exigir_rol($rol);
    if ($especialidad < 1) return [];
    $q = $pdo->prepare("SELECT btrim(m.nombres || ' ' || m.apellidos) AS nombre
        FROM public.medicos m JOIN public.medico_especialidad me ON me.id_medico=m.id_medico
        JOIN public.especialidades e ON e.id_especialidad=me.id_especialidad
        WHERE m.activo = TRUE AND e.activo = TRUE AND me.id_especialidad = :especialidad
        ORDER BY m.apellidos, m.nombres, m.id_medico LIMIT :limite");
    $q->bindValue(':especialidad', $especialidad, PDO::PARAM_INT);
    $q->bindValue(':limite', LUMI_CATALOGO_MAX_FILAS + 1, PDO::PARAM_INT);
    $q->execute();
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Solamente el nombre: descarta un conector intermedio («atienden en Pediatría»,
 * «hay en la Pediatría»). Un solo retiro de conectores consecutivos; nunca se repite
 * para no partir nombres que de por sí comiencen con artículo o preposición.
 */
function lumi_catalogo_limpiar_conectores(string $nombre): string {
    $nombre = trim($nombre, " \t\n\r\0\x0B¿?¡!.\"'");
    $sinConector = preg_replace('/^(?:(?:la|el|los|las|en|de|para|a|al|del)\s+)+/iu', '', $nombre, 1);
    if (is_string($sinConector) && $sinConector !== '') $nombre = trim($sinConector, " \t\n\r\0\x0B¿?¡!.\"'");
    return $nombre;
}

/** Intenciones de catálogo, distintas de abrir/administrar pantallas y de completar formularios. */
function lumi_catalogo_intencion(string $mensaje): ?array {
    $texto = lumi_catalogo_normalizar($mensaje);
    if (preg_match('/\b(abrir|abre|llevame|ir a|gestionar|administrar|editar|eliminar|registrar|crear)\b/', $texto)
        || preg_match('/\b(como (?:elegir|seleccionar|reservar)|ya elegi|que hago (?:ahora|despues)|siguiente paso|que sigue|que viene|despues de (?:elegir|seleccionar|escoger))\b/', $texto)) return null;
    $medicos = preg_match('/\b(medicos?|doctores?|profesionales?|especialistas?)\b/', $texto) === 1
        || preg_match('/\bquien(?:es)?\s+atiend\w*\b/', $texto) === 1;
    $especialidades = preg_match('/\bespecialidad(?:es)?\b/', $texto) === 1;
    if (!$medicos && !$especialidades) return null;
    if (preg_match('/\b(disponibilidad|disponibles?|libres?|hoy|manana|citas?|turnos?|horarios?)\b/', $texto)
        && !preg_match('/\b(?:catalogo|especialidades)\b/', $texto)) return ['tipo'=>'disponibilidad'];
    if (!preg_match('/\b(que|cuales|quienes|hay|existe\w*|tienen|ofrecen|atiend\w*|lista\w*|muestra\w*|ver|consultar|catalogo|asociad\w*|vinculad\w*)\b/', $texto)) return null;
    if ($medicos) {
        $nombre = '';
        if (preg_match('/\b(?:en|de|para|a|atiend\w*|dirig\w*)\s+(?:la\s+|el\s+|los\s+|las\s+)?(?:especialidad\s+(?:de\s+)?)?(.+?)\s*[?!.]*$/iu', $mensaje, $m)) {
            $nombre = lumi_catalogo_limpiar_conectores($m[1]);
        }
        return ['tipo'=>'medicos', 'nombre'=>$nombre];
    }
    if (preg_match('/\b(?:existe|tienen|hay|ofrecen)\s+(?:(?:la|una)\s+)?especialidad\s+(?:de\s+)?(.+?)\s*[?!.]*$/iu', $mensaje, $m)) {
        return ['tipo'=>'especialidad', 'nombre'=>lumi_catalogo_limpiar_conectores($m[1])];
    }
    return ['tipo'=>'especialidades'];
}

function lumi_catalogo_nombre(mixed $nombre): string {
    if (!is_string($nombre) || trim($nombre) === '' || !mb_check_encoding($nombre, 'UTF-8')
        || mb_strlen($nombre) > 201 || preg_match('/[\x00-\x1F\x7F]/', $nombre)) throw new UnexpectedValueException();
    lumi_validar_privacidad($nombre); // Ni contactos ni claves introducidos por error en un nombre.
    return trim($nombre);
}

function lumi_catalogo_listar_texto(string $cabecera, array $filas, string $nota): string {
    $texto = $cabecera;
    $mostradas = 0;
    foreach (array_slice($filas, 0, LUMI_CATALOGO_MAX_FILAS) as $fila) {
        $linea = "\n• " . lumi_catalogo_nombre($fila['nombre'] ?? null);
        if (mb_strlen($texto . $linea . $nota) > LUMI_MAX_RESPUESTA - 150) break;
        $texto .= $linea; $mostradas++;
    }
    if ($mostradas < count($filas)) $texto .= "\nHay más resultados; esta lista es parcial. Revisa el selector de la página para ver el catálogo completo.";
    return $texto . $nota;
}

/** Respuesta local completa: nunca devuelve filas/IDs de registros al cliente o al proveedor. */
function lumi_resolver_catalogo(string $mensaje, string $rol, PDO $pdo): ?array {
    $intencion = lumi_catalogo_intencion($mensaje);
    if ($intencion === null) return null;
    $respuesta = ['estado'=>'ok', 'origen'=>'guia', 'fuente'=>'catalogo_local', 'situacion'=>'normal',
        'navegacion'=>lumi_enlaces_permitidos($rol, $rol === 'paciente' ? ['reservar'] : (in_array($rol, ['administrador','recepcionista'], true) ? ['registrar'] : ['inicio']))];
    if (!lumi_catalogo_permitido($rol)) return array_replace($respuesta, ['situacion'=>'acceso_denegado', 'texto'=>'Catálogo no autorizado para tu cuenta.']);
    try {
        lumi_validar_privacidad($mensaje);
        if ($intencion['tipo'] === 'disponibilidad') {
            $respuesta['texto'] = 'No consulto horarios, precios ni citas libres. Puedo mostrarte las especialidades y los médicos de la clínica; los bloques con horario los ves en el formulario de reservas.';
        } elseif ($intencion['tipo'] === 'especialidades') {
            $filas = lumi_catalogo_especialidades($pdo, $rol);
            $respuesta['texto'] = $filas ? lumi_catalogo_listar_texto('Estas son las especialidades de la clínica:', $filas,
                "\nPuedes preguntar: «Qué médicos atienden en [nombre de la especialidad]».")
                : 'No hay especialidades registradas en este momento. La recepción puede darte más información.';
            if (!$filas) $respuesta['situacion'] = 'sin_datos';
        } elseif (($intencion['nombre'] ?? '') === '') {
            $respuesta['texto'] = '¿De qué especialidad quieres consultar los médicos? Dime «Qué médicos atienden en [nombre de la especialidad]».';
        } else {
            $filas = lumi_catalogo_buscar_especialidad($pdo, $rol, $intencion['nombre']);
            if (!$filas) {
                $respuesta['situacion'] = 'sin_datos';
                $respuesta['texto'] = 'No encontré una especialidad activa con ese nombre en el catálogo actual. Puedes preguntar «Qué especialidades existen» para revisar los nombres registrados.';
            } elseif (count($filas) !== 1) {
                $respuesta['texto'] = 'Hay más de una especialidad que coincide al omitir tildes. Escribe el nombre exacto como aparece en el selector; no elegiré una por ti.';
            } else {
                $especialidad = lumi_catalogo_nombre($filas[0]['nombre'] ?? null);
                if ($intencion['tipo'] === 'especialidad') {
                    $respuesta['texto'] = 'La especialidad «' . $especialidad . '» está disponible. Puedes preguntar qué médicos la atienden.';
                } else {
                    $medicos = lumi_catalogo_medicos($pdo, $rol, (int)$filas[0]['id_especialidad']);
                    $respuesta['texto'] = $medicos ? lumi_catalogo_listar_texto('Médicos de la especialidad «' . $especialidad . '»:', $medicos,
                        "\nPara elegir fecha y horario, usa el formulario de reservas; que un médico aparezca aquí no confirma citas libres.")
                        : 'La especialidad «' . $especialidad . '» está registrada, pero no hay médicos asociados en este momento. La recepción puede darte más información.';
                    if (!$medicos) $respuesta['situacion'] = 'sin_datos';
                }
            }
        }
    } catch (DomainException $ex) {
        // El filtro de privacidad conserva su mensaje: la API responde 400 y nada sale hacia Gemini.
        throw $ex;
    } catch (Throwable) {
        $respuesta['situacion'] = 'no_verificado';
        $respuesta['texto'] = 'No pude consultar el catálogo en este momento. Intenta nuevamente más tarde o consulta con recepción. No se confirmó disponibilidad ni se realizó ninguna operación.';
    }
    return $respuesta;
}
