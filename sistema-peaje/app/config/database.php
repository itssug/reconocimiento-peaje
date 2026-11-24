<?php
declare(strict_types=1);

use App\Helpers\Env;

$path = Env::get('DB_PATH', 'storage/database/peaje.sqlite');
// Ruta absoluta (Windows "C:\" o Unix "/") o relativa a la raiz del proyecto
if (!preg_match('~^([A-Za-z]:[\\\\/]|/)~', $path)) {
    $path = BASE_PATH . '/' . $path;
}

return [
    'driver'          => 'sqlite',
    'path'            => $path,
    'busy_timeout_ms' => Env::int('DB_BUSY_TIMEOUT_MS', 5000),
    'wal'             => true,
];
