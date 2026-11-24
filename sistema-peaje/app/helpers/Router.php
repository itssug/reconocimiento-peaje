<?php
declare(strict_types=1);

namespace App\Helpers;

/** Router minimo: patrones con {param}, autenticacion y roles declarativos. */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $opts += ['auth' => true, 'roles' => []];
        $this->routes[] = [strtoupper($method), $pattern, $handler, $opts];
    }

    public function dispatch(Request $req): never
    {
        try {
            $pathMatched = false;
            foreach ($this->routes as [$method, $pattern, $handler, $opts]) {
                $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
                if (!preg_match($regex, $req->path, $m)) {
                    continue;
                }
                $pathMatched = true;
                if ($method !== $req->method) {
                    continue;
                }
                $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                if ($opts['auth']) {
                    \App\Middleware\AuthMiddleware::authenticate($req);
                    if ($opts['roles']) {
                        \App\Middleware\AuthMiddleware::requireRole($req, $opts['roles']);
                    }
                }
                $handler($req);
                Response::fail('El controlador no devolvio respuesta.', 500);
            }
            if ($pathMatched) {
                Response::fail('Metodo no permitido.', 405);
            }
            Response::fail('Ruta no encontrada.', 404);
        } catch (ApiException $e) {
            Response::fail($e->getMessage(), $e->status, $e->errors);
        } catch (\Throwable $e) {
            Logger::error(get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
            Response::fail('Error interno del servidor.', 500);
        }
    }
}
