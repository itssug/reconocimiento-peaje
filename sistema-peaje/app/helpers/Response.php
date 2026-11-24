<?php
declare(strict_types=1);

namespace App\Helpers;

final class Response
{
    public static function json(bool $success, string $message, mixed $data = null, array $errors = [], int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            ['success' => $success, 'message' => $message, 'data' => $data, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    public static function ok(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        self::json(true, $message, $data, [], $status);
    }

    public static function fail(string $message, int $status = 400, array $errors = []): never
    {
        self::json(false, $message, null, $errors, $status);
    }
}
