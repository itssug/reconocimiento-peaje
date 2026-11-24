<?php
declare(strict_types=1);

namespace App\Helpers;

/** Plantilla comun (sidebar + navbar) para todas las paginas privadas. */
final class View
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Ruta base de la aplicacion si esta en un subdirectorio (p. ej. /peaje). */
    public static function basePath(): string
    {
        if (PHP_SAPI === 'cli-server') {
            return '';
        }
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function nav(string $rol): array
    {
        $items = [
            ['dashboard.php', 'Panel', 'speedometer2', ['ADMIN', 'OPERADOR']],
            ['pasos.php', 'Pasos', 'list-check', ['ADMIN', 'OPERADOR']],
            ['vehiculos.php', 'Vehiculos', 'car-front', ['ADMIN', 'OPERADOR']],
            ['alertas.php', 'Alertas', 'exclamation-triangle', ['ADMIN', 'OPERADOR']],
            ['chat.php', 'Agente', 'chat-dots', ['ADMIN', 'OPERADOR']],
            ['estado.php', 'Estado de servicios', 'activity', ['ADMIN']],
            ['administracion.php', 'Administracion', 'gear', ['ADMIN']],
        ];
        return array_values(array_filter($items, static fn($i) => in_array($rol, $i[3], true)));
    }

    public static function header(string $title, array $user, string $active, string $extraHead = ''): void
    {
        $base = self::basePath();
        $e = [self::class, 'e'];
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="csrf-token" content="' . $e($user['csrf']) . '">'
            . '<meta name="app-base" content="' . $e($base) . '">'
            . '<meta name="user-rol" content="' . $e($user['rol']) . '">'
            . '<title>' . $e($title) . ' - Control de Peaje</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
            . '<link href="' . $e($base) . '/assets/css/app.css" rel="stylesheet">' . $extraHead . '</head><body>'
            . '<div class="d-flex" id="layout"><nav id="sidebar" class="bg-dark text-white p-3 collapse d-md-block">'
            . '<div class="fs-5 fw-semibold mb-4"><i class="bi bi-signpost-2 me-2"></i>Peaje</div><ul class="nav nav-pills flex-column gap-1">';
        foreach (self::nav($user['rol']) as [$href, $label, $icon]) {
            echo '<li class="nav-item"><a class="nav-link text-white' . ($active === $href ? ' active' : '') . '" href="'
                . $e($base . '/' . $href) . '"><i class="bi bi-' . $icon . ' me-2"></i>' . $e($label) . '</a></li>';
        }
        echo '</ul></nav><div class="flex-grow-1 min-vh-100 bg-light">'
            . '<header class="navbar bg-white border-bottom px-3"><button class="btn btn-outline-secondary d-md-none" data-bs-toggle="collapse" data-bs-target="#sidebar"><i class="bi bi-list"></i></button>'
            . '<span class="fw-semibold ms-2">' . $e($title) . '</span><div class="ms-auto d-flex align-items-center gap-2">'
            . '<span class="badge text-bg-' . ($user['rol'] === 'ADMIN' ? 'danger' : 'primary') . '">' . $e($user['rol']) . '</span>'
            . '<span class="small text-muted d-none d-sm-inline">' . $e($user['nombre']) . '</span>'
            . '<button class="btn btn-sm btn-outline-secondary" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Salir</button>'
            . '</div></header><main class="p-3 p-md-4">';
    }

    public static function footer(string $extraScripts = ''): void
    {
        $base = self::e(self::basePath());
        echo '</main></div></div><div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>'
            . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
            . '<script src="' . $base . '/assets/js/app.js"></script>' . $extraScripts . '</body></html>';
    }
}
