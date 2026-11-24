<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\ApiException;
use App\Helpers\Request;
use App\Services\AuthService;

final class AuthMiddleware
{
    /** Autentica por Bearer (API) o cookie HttpOnly (navegador). Cookie + metodo mutante exige CSRF. */
    public static function authenticate(Request $req): array
    {
        $svc = new AuthService();
        $token = $req->bearerToken();
        $via = 'bearer';
        if ($token === null) {
            $token = $req->cookie(AuthService::COOKIE);
            $via = 'cookie';
        }
        if ($token === null) {
            throw new ApiException(401, 'Autenticacion requerida.');
        }
        $user = $svc->authenticate($token);
        if ($user === null) {
            throw new ApiException(401, 'Sesion invalida o expirada.');
        }
        if ($via === 'cookie' && !in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = (string)$req->header('X-CSRF-Token');
            if (!hash_equals(AuthService::csrfToken($user['token_hash']), $sent)) {
                throw new ApiException(403, 'Token CSRF invalido.');
            }
        }
        $user['csrf'] = AuthService::csrfToken($user['token_hash']);
        $user['_token'] = $token;
        unset($user['token_hash']);
        $req->user = $user;
        $req->authVia = $via;
        return $user;
    }

    /** El rol siempre proviene de la base de datos (lista blanca), nunca del cliente. */
    public static function requireRole(Request $req, array $roles): void
    {
        if ($req->user === null || !in_array($req->user['rol'], $roles, true)) {
            throw new ApiException(403, 'No tiene permisos para esta operacion.');
        }
    }

    /** Guardia para paginas PHP: redirige a login si no hay sesion valida. */
    public static function page(?array $roles = null): array
    {
        $req = Request::fromGlobals();
        $token = $req->cookie(AuthService::COOKIE);
        $user = $token ? (new AuthService())->authenticate($token) : null;
        if ($user === null) {
            header('Location: ' . \App\Helpers\View::basePath() . '/login.php');
            exit;
        }
        if ($roles !== null && !in_array($user['rol'], $roles, true)) {
            http_response_code(403);
            exit('Acceso denegado.');
        }
        $user['csrf'] = AuthService::csrfToken($user['token_hash']);
        unset($user['token_hash']);
        return $user;
    }
}
