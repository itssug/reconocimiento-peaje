<?php
declare(strict_types=1);

/** Punto de entrada: router de la API REST y redireccion de la raiz. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\Router;
use App\Helpers\View;

$req = Request::fromGlobals();

if (str_starts_with($req->path, '/api/')) {
    $router = new Router();
    require BASE_PATH . '/routes/api.php';
    $router->dispatch($req);
}

// Servidor embebido de PHP (desarrollo): dejar pasar archivos estaticos y paginas .php
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $req->path;
    if ($req->path !== '/' && is_file($file) && !str_ends_with($file, '.htaccess') && !preg_match('/\.(sqlite|log|env|sql)$/', $file)) {
        return false;
    }
}

if ($req->path === '/') {
    header('Location: ' . View::basePath() . '/dashboard.php');
    exit;
}
http_response_code(404);
echo 'No encontrado.';
