<?php
declare(strict_types=1);

namespace App\Helpers;

/** Logger a archivo. Redacta valores que parezcan tokens o credenciales. */
final class Logger
{
    public static function info(string $msg): void    { self::write('INFO', $msg); }
    public static function warning(string $msg): void { self::write('WARN', $msg); }
    public static function error(string $msg): void   { self::write('ERROR', $msg); }

    private static function write(string $level, string $msg): void
    {
        $msg = self::redact($msg);
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $level, str_replace(["\r", "\n"], ' ', $msg));
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function redact(string $s): string
    {
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $s) ?? $s;
        $s = preg_replace('/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]*/', '[JWT-REDACTED]', $s) ?? $s;
        $s = preg_replace('/((?:token|key|secret|password|authorization)["\']?\s*[:=]\s*["\']?)[^\s"\',&]+/i', '$1[REDACTED]', $s) ?? $s;
        return $s;
    }
}
