<?php
declare(strict_types=1);

namespace App\Helpers;

final class Request
{
    public ?array $user = null;      // usuario autenticado (lo fija el middleware)
    public string $authVia = '';     // 'bearer' | 'cookie'
    public array $params = [];       // parametros de ruta

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly array $server,
        private readonly array $cookies,
        private readonly array $files,
        private readonly array $post,
        private readonly ?string $rawBody = null
    ) {}

    public static function fromGlobals(): self
    {
        $uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = View::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = preg_replace('#^/index\.php#', '', $uri) ?: '/';
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($uri, '/'),
            $_GET, $_SERVER, $_COOKIE, $_FILES, $_POST
        );
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? ($name === 'Authorization' ? ($this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null) : null);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization');
        if ($h !== null && preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{20,512})$/i', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) ? $v : null;
    }

    public function ip(): string
    {
        // REMOTE_ADDR es la unica fuente confiable en hosting compartido sin proxy conocido
        return substr((string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    public function userAgent(): string
    {
        return substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    /** Cuerpo JSON (maximo 1 MB) o, si no es JSON, los campos de formulario. */
    public function body(): array
    {
        $type = (string)($this->server['CONTENT_TYPE'] ?? '');
        if (stripos($type, 'application/json') !== false) {
            $raw = $this->rawBody ?? (string)file_get_contents('php://input', false, null, 0, 1048577);
            if (strlen($raw) > 1048576) {
                throw new ApiException(413, 'Cuerpo de la solicitud demasiado grande.');
            }
            $d = json_decode($raw, true);
            if (!is_array($d)) {
                throw new ApiException(400, 'JSON invalido.');
            }
            return $d;
        }
        return $this->post;
    }

    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }
}
