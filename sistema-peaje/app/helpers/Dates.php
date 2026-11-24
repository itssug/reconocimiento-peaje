<?php
declare(strict_types=1);

namespace App\Helpers;

final class Dates
{
    /** 'Y-m-d H:i:s' (UTC) => ISO-8601 con Z. */
    public static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Rango UTC [inicio, fin) del dia actual en la zona horaria de la app. */
    public static function todayRangeUtc(): array
    {
        $tz = new \DateTimeZone((string)Config::get('app.timezone', 'America/La_Paz'));
        $start = new \DateTimeImmutable('today', $tz);
        $utc = new \DateTimeZone('UTC');
        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
