<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ApiException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Services\CatalogoService;
use App\Services\VehiculoService;

final class VehiculoController
{
    private static function id(Request $r): int
    {
        $id = $r->params['id'] ?? '';
        if (!ctype_digit($id) || strlen($id) > 12) {
            throw new ApiException(422, 'Identificador invalido.');
        }
        return (int)$id;
    }

    public static function index(Request $r): void
    {
        Response::ok((new VehiculoService())->list($r->query));
    }

    public static function show(Request $r): void
    {
        $v = (new VehiculoService())->find(self::id($r)) ?? throw new ApiException(404, 'Vehiculo no encontrado.');
        Response::ok($v);
    }

    public static function store(Request $r): void
    {
        $svc = new VehiculoService();
        $d = $svc->parse($r->body(), false);
        Response::ok($svc->create($d, (int)$r->user['id'], $r->ip()), 'Vehiculo registrado.', 201);
    }

    public static function update(Request $r): void
    {
        $svc = new VehiculoService();
        $id = self::id($r);
        $cur = $svc->find($id) ?? throw new ApiException(404, 'Vehiculo no encontrado.');
        $d = $svc->parse($r->body(), true, $cur);
        Response::ok($svc->update($id, $d, (int)$r->user['id'], $r->ip()), 'Vehiculo actualizado.');
    }

    public static function destroy(Request $r): void
    {
        Response::ok((new VehiculoService())->deactivate(self::id($r), (int)$r->user['id'], $r->ip()), 'Vehiculo desactivado.');
    }
}
