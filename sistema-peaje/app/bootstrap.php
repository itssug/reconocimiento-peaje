<?php
declare(strict_types=1);

if (defined('BASE_PATH')) {
    return; // ya inicializado (p. ej. router del servidor embebido + pagina)
}

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Se requiere PHP 8.3 o superior.');
}

define('BASE_PATH', dirname(__DIR__));

// Autoload PSR-4 minimo (sin Composer): App\Helpers\X => app/helpers/X.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $file = array_pop($parts);
    $dir = strtolower(implode('/', $parts));
    $path = BASE_PATH . '/app/' . $dir . '/' . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Helpers\Config;
use App\Helpers\Env;
use App\Helpers\Logger;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string)Config::get('app.timezone', 'America/La_Paz'));

$debug = (bool)Config::get('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    Logger::error("PHP[$no] $str en " . basename($file) . ":$line");
    return true;
});
set_exception_handler(static function (\Throwable $e) use ($debug): void {
    Logger::error(get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $debug ? htmlspecialchars($e->getMessage(), ENT_QUOTES) : 'Error interno del servidor.';
});
