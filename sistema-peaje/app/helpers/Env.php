<?php
declare(strict_types=1);

namespace App\Helpers;

/** Cargador minimo de archivos .env (sin dependencias). */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                $q = $v[0];
                $end = strrpos($v, $q);
                $v = $end > 0 ? substr($v, 1, $end - 1) : substr($v, 1);
            } else {
                $v = trim(preg_replace('/\s+#.*$/', '', $v) ?? $v);
            }
            self::$vars[$k] = $v;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // Las variables reales del entorno tienen prioridad sobre el archivo .env
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key] === '' ? $default : self::$vars[$key];
        }
        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v !== null && is_numeric($v) ? (int)$v : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $v = self::get($key);
        return $v !== null && is_numeric($v) ? (float)$v : $default;
    }
}
