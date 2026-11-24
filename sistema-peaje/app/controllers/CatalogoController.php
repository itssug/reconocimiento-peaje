<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ApiException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Services\CatalogoService;

final class CatalogoController
{
    private static function id(Request $r): int
    {
        $id = $r->params['id'] ?? '';
        if (!ctype_digit($id) || strlen($id) > 12) {
            throw new ApiException(422, 'Identificador invalido.');
        }
        return (int)$id;
    }

    // Casetas
    public static function casetas(Request $r): void
    {
        Response::ok((new CatalogoService())->casetas(($r->query['activas'] ?? '') === '1'));
    }
    public static function casetaCrear(Request $r): void
    {
        Response::ok((new CatalogoService())->saveCaseta(null, $r->body(), (int)$r->user['id'], $r->ip()), 'Caseta creada.', 201);
    }
    public static function casetaEditar(Request $r): void
    {
        Response::ok((new CatalogoService())->saveCaseta(self::id($r), $r->body(), (int)$r->user['id'], $r->ip()), 'Caseta actualizada.');
    }
    public static function casetaDesactivar(Request $r): void
    {
        Response::ok((new CatalogoService())->saveCaseta(self::id($r), ['activa' => false], (int)$r->user['id'], $r->ip()), 'Caseta desactivada.');
    }

    // Tarifas
    public static function tarifas(Request $r): void
    {
        Response::ok((new CatalogoService())->tarifas());
    }
    public static function tarifaGuardar(Request $r): void
    {
        [$t, $creada] = (new CatalogoService())->saveTarifa(null, $r->body(), (int)$r->user['id'], $r->ip());
        Response::ok($t, $creada ? 'Tarifa creada.' : 'Tarifa actualizada.', $creada ? 201 : 200);
    }
    public static function tarifaEditar(Request $r): void
    {
        [$t] = (new CatalogoService())->saveTarifa(self::id($r), $r->body(), (int)$r->user['id'], $r->ip());
        Response::ok($t, 'Tarifa actualizada.');
    }
    public static function tarifaDesactivar(Request $r): void
    {
        [$t] = (new CatalogoService())->saveTarifa(self::id($r), ['activa' => false], (int)$r->user['id'], $r->ip());
        Response::ok($t, 'Tarifa desactivada.');
    }

    // Usuarios autorizados
    public static function usuarios(Request $r): void
    {
        Response::ok((new CatalogoService())->usuarios());
    }
    public static function usuarioAgregar(Request $r): void
    {
        Response::ok((new CatalogoService())->addUsuario($r->body(), (int)$r->user['id'], $r->ip()), 'Usuario autorizado.', 201);
    }
    public static function usuarioEditar(Request $r): void
    {
        Response::ok((new CatalogoService())->updateUsuario(self::id($r), $r->body(), $r->user, $r->ip()), 'Usuario actualizado.');
    }
}
