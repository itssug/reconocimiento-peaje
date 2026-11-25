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

    /** Ruta base de la aplicacion si esta en un subdirectorio (p. ej. /reconocimiento-peaje). */
    public static function basePath(): string
    {
        if (PHP_SAPI === 'cli-server') {
            return '';
        }
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($dir === '/' || $dir === '.') {
            return '';
        }
        $dir = rtrim($dir, '/');
        // Si Apache reescribe /subcarpeta/... -> /subcarpeta/public/..., ocultar /public en las URLs
        $reqUri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_ends_with($dir, '/public')) {
            $parent = substr($dir, 0, -7);
            if ($parent !== '' && str_starts_with($reqUri, $parent) && !str_starts_with($reqUri, $dir)) {
                return $parent;
            }
        }
        return $dir;
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
            . '<div class="d-flex" id="layout"><nav id="sidebar" class="p-3 collapse d-md-block">'
            . '<div class="sidebar-brand mb-4"><span class="brand-dot"><i class="bi bi-camera-fill"></i></span><span>Peaje AI</span></div><ul class="nav nav-pills flex-column gap-1">';
        foreach (self::nav($user['rol']) as [$href, $label, $icon]) {
            echo '<li class="nav-item"><a class="nav-link' . ($active === $href ? ' active' : '') . '" href="'
                . $e($base . '/' . $href) . '"><i class="bi bi-' . $icon . ' me-2"></i>' . $e($label) . '</a></li>';
        }
        echo '</ul></nav><div class="flex-grow-1 min-vh-100 app-surface">'
            . '<header class="navbar apple-topbar px-3 px-md-4"><button class="btn btn-sm btn-light border d-md-none" data-bs-toggle="collapse" data-bs-target="#sidebar"><i class="bi bi-list"></i></button>'
            . '<span class="topbar-title ms-2 ms-md-0">' . $e($title) . '</span><div class="ms-auto d-flex align-items-center gap-2">'
            . '<span class="apple-pill ' . ($user['rol'] === 'ADMIN' ? 'pill-admin' : 'pill-op') . '">' . $e($user['rol']) . '</span>'
            . '<span class="small text-muted d-none d-sm-inline">' . $e($user['nombre']) . '</span>'
            . '<button class="btn btn-sm btn-apple-ghost" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Salir</button>'
            . '</div></header><main class="p-3 p-md-4 container-xxl">';
    }

    public static function footer(string $extraScripts = ''): void
    {
        $base = self::e(self::basePath());
        echo '</main></div></div>'
            . '<div class="modal fade" id="evidenciaModal" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content apple-modal border-0">'
            . '<div class="modal-header border-0 pb-1"><div class="d-flex align-items-center gap-2">'
            . '<span class="brand-dot brand-dot-sm"><i class="bi bi-eye-fill"></i></span>'
            . '<h5 class="modal-title fw-semibold mb-0" id="evidenciaModalTitle">Evidencia del Vehículo</h5></div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>'
            . '<div class="modal-body pt-2">'
            . '<div id="evidenciaModalMeta" class="d-flex flex-wrap gap-2 mb-3"></div>'
            . '<div class="apple-img-frame text-center"><img id="evidenciaModalImg" src="" alt="Evidencia" class="img-fluid rounded-4"></div>'
            . '</div></div></div></div>'
            . '<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>'
            . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
            . '<script src="' . $base . '/assets/js/app.js"></script>' . $extraScripts . '</body></html>';
    }
}
