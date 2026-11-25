<?php
declare(strict_types=1);

/**
 * Instalador seguro.
 *  - CLI (desarrollo):   php install.php [--demo]
 *  - Navegador (hosting): requiere .env con INSTALL_TOKEN; se bloquea al terminar.
 * Tras instalar, elimine este archivo del servidor.
 */

// Funciona tanto en la raiz del proyecto como copiado temporalmente dentro de public/ (HostGator)
require is_file(__DIR__ . '/app/bootstrap.php')
    ? __DIR__ . '/app/bootstrap.php'
    : dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Config;
use App\Services\Installer;

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

if (PHP_SAPI === 'cli') {
    if (Installer::isInstalled()) {
        fwrite(STDERR, "Ya instalado (storage/installed.lock). Elimine el archivo para reinstalar.\n");
        exit(1);
    }
    if (Installer::ensureEnv()) {
        echo "Se creo .env desde .env.example (revise sus valores).\n";
        // recargar configuracion con el nuevo .env
        App\Helpers\Env::load(BASE_PATH . '/.env');
    }
    foreach (Installer::checks() as $c) {
        printf("[%s] %s %s\n", $c['ok'] ? 'OK' : 'FALLA', $c['nombre'], $c['detalle']);
    }
    if (!Installer::checksPassed()) {
        fwrite(STDERR, "Corrija las comprobaciones fallidas.\n");
        exit(2);
    }
    foreach (Installer::run(in_array('--demo', $argv, true)) as $m) {
        echo "- $m\n";
    }
    echo "Instalacion completada.\n";
    exit(0);
}

// ---- Modo web ----
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

$page = static function (string $body) use ($e): void {
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Instalador</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light"><div class="container py-5" style="max-width:720px"><h1 class="h4 mb-4">Instalador - Sistema de Control de Peaje</h1>'
        . $body . '</div></body></html>';
};

if (Installer::isInstalled()) {
    http_response_code(403);
    $page('<div class="alert alert-secondary">El sistema ya esta instalado y el instalador esta bloqueado. Elimine <code>install.php</code> del servidor.</div>');
    exit;
}

$token = (string)Config::get('app.install_token', '');
if (!is_file(BASE_PATH . '/.env') || $token === '') {
    http_response_code(403);
    $page('<div class="alert alert-warning">Cree el archivo <code>.env</code> (copia de <code>.env.example</code>) fuera de <code>public/</code> y defina un <code>INSTALL_TOKEN</code> largo y aleatorio. Luego recargue esta pagina.</div>');
    exit;
}

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$checks = Installer::checks();
$rows = '';
foreach ($checks as $c) {
    $rows .= '<li class="list-group-item d-flex justify-content-between"><span>' . $e($c['nombre']) . ' <small class="text-muted">' . $e($c['detalle']) . '</small></span>'
        . '<span class="badge ' . ($c['ok'] ? 'text-bg-success">OK' : 'text-bg-danger">FALLA') . '</span></li>';
}
$list = '<ul class="list-group mb-4">' . $rows . '</ul>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $okCsrf  = hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
    $okToken = hash_equals($token, (string)($_POST['install_token'] ?? ''));
    if (!$okCsrf || !$okToken) {
        usleep(500000);
        http_response_code(403);
        $page('<div class="alert alert-danger">Solicitud o token de instalacion invalido.</div>' . $list);
        exit;
    }
    if (!Installer::checksPassed()) {
        $page('<div class="alert alert-danger">Hay comprobaciones fallidas.</div>' . $list);
        exit;
    }
    try {
        $log = Installer::run(!empty($_POST['demo']));
    } catch (Throwable $ex) {
        App\Helpers\Logger::error('Instalacion fallida: ' . $ex->getMessage());
        $page('<div class="alert alert-danger">La instalacion fallo. Revise storage/logs.</div>');
        exit;
    }
    $li = '';
    foreach ($log as $m) {
        $li .= '<li>' . $e($m) . '</li>';
    }
    $page('<div class="alert alert-success">Instalacion completada y bloqueada.</div><ul>' . $li . '</ul>'
        . '<p class="text-danger">Elimine <code>install.php</code> del servidor.</p>');
    exit;
}

$page($list . '<form method="post" class="card card-body">'
    . '<input type="hidden" name="csrf" value="' . $e($_SESSION['csrf']) . '">'
    . '<label class="form-label">Token de instalacion (INSTALL_TOKEN de su .env)</label>'
    . '<input type="password" name="install_token" class="form-control mb-3" required autocomplete="off">'
    . '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="demo" id="demo" value="1">'
    . '<label class="form-check-label" for="demo">Insertar datos de DEMOSTRACION (identificados como DEMO)</label></div>'
    . '<button class="btn btn-primary">Instalar</button></form>');
