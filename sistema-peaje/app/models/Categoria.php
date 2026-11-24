<?php
declare(strict_types=1);

namespace App\Models;

/** Categorias de vehiculo soportadas y reglas de coherencia con los ejes. */
final class Categoria
{
    public const LISTA = ['motocicleta', 'automovil', 'minibus', 'camion', 'bus'];

    public const ETIQUETAS = [
        'motocicleta' => 'Motocicleta', 'automovil' => 'Automovil', 'minibus' => 'Minibus',
        'camion' => 'Camion', 'bus' => 'Bus',
    ];

    /** Maximo de ejes plausible por categoria (el minimo es 2). */
    public const MAX_EJES = ['motocicleta' => 2, 'automovil' => 2, 'minibus' => 2, 'bus' => 3, 'camion' => 9];

    public static function esValida(?string $c): bool
    {
        return $c !== null && in_array($c, self::LISTA, true);
    }

    public static function ejesCoherentes(string $categoria, int $ejes): bool
    {
        return $ejes >= 2 && $ejes <= (self::MAX_EJES[$categoria] ?? 9);
    }
}
