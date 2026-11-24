<?php
declare(strict_types=1);

namespace App\Helpers;

/** Acceso a la configuracion: Config::get('app.inference.url'). */
final class Config
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!isset(self::$cache[$file])) {
            $path = BASE_PATH . '/app/config/' . $file . '.php';
            self::$cache[$file] = is_file($path) ? require $path : [];
        }
        $value = self::$cache[$file];
        foreach ($parts as $p) {
            if (!is_array($value) || !array_key_exists($p, $value)) {
                return $default;
            }
            $value = $value[$p];
        }
        return $value;
    }
}
