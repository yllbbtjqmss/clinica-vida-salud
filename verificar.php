<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este diagnóstico solo está disponible mediante PHP CLI.');
}

$controladores = PDO::getAvailableDrivers();

if (in_array('pgsql', $controladores, true)) {
    fwrite(STDOUT, 'El controlador PDO PostgreSQL está habilitado en PHP CLI.' . PHP_EOL);
} else {
    fwrite(STDERR, 'El controlador PDO PostgreSQL no está habilitado en PHP CLI.' . PHP_EOL);
    exit(1);
}
