<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ApiException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Services\AuthService;

final class AuthController
{
    public static function google(Request $req): void
    {
        $b = $req->body();
        $cred = $b['credential'] ?? $b['id_token'] ?? '';
        if (!is_string($cred) || $cred === '') {
            throw new ApiException(422, 'Datos invalidos.', ['credential' => 'El token de Google es obligatorio.']);
        }
        $svc = new AuthService();
        $r = $svc->loginWithGoogle($cred, $req->ip(), $req->userAgent());

        self::setCookie($r['token'], $r['expires_at'], $req->isHttps());
        Response::ok([
            'token' => $r['token'],            // para clientes API (Authorization: Bearer)
            'token_type' => 'Bearer',
            'expires_at' => $r['expires_at'] . 'Z',
            'csrf_token' => AuthService::csrfToken(AuthService::hash($r['token'])),
            'user' => $r['user'],
        ], 'Inicio de sesion correcto.');
    }

    public static function me(Request $req): void
    {
        $u = $req->user;
        Response::ok([
            'user' => ['id' => (int)$u['id'], 'email' => $u['email'], 'nombre' => $u['nombre'], 'foto_url' => $u['foto_url'], 'rol' => $u['rol']],
            'csrf_token' => $u['csrf'],
            'expires_at' => $u['expira_en'] . 'Z',
        ]);
    }

    public static function logout(Request $req): void
    {
        $svc = new AuthService();
        $svc->revoke($req->user['_token']);
        self::setCookie('', gmdate('Y-m-d H:i:s', time() - 3600), $req->isHttps());
        Response::ok(null, 'Sesion cerrada.');
    }

    private static function setCookie(string $value, string $expiresUtc, bool $https): void
    {
        setcookie(AuthService::COOKIE, $value, [
            'expires' => strtotime($expiresUtc . ' UTC'),
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
