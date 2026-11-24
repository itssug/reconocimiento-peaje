<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Dates;
use App\Helpers\Logger;
use PDO;

/** Historial de actividad (sin credenciales ni tokens). */
final class ActivityService
{
    public static function log(PDO $db, ?int $userId, string $accion, ?string $entidad, ?int $entidadId, string $detalle, string $ip = ''): void
    {
        $db->prepare('INSERT INTO historial_actividad (usuario_id, accion, entidad, entidad_id, detalle, ip, created_at)
                      VALUES (:u,:a,:e,:i,:d,:ip,:t)')
            ->execute([':u' => $userId, ':a' => $accion, ':e' => $entidad, ':i' => $entidadId,
                       ':d' => substr(Logger::redact($detalle), 0, 1000), ':ip' => $ip, ':t' => Dates::now()]);
    }
}
