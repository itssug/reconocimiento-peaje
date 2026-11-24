<?php
declare(strict_types=1);

/**
 * Diagnostico del entorno de hosting. No revela secretos, rutas privadas ni valores de configuracion.
 * Desactivar con DIAG_ENABLED=false una vez validado el hosting.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Config;
use App\Helpers\Database;
use App\Services\Installer;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!Config::get('app.diag_enabled', false)) {
    http_response_code(404);
    exit('No disponible.');
}

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$items = [];
foreach (Installer::checks() as $c) {
    // Sin detalle de rutas: solo nombre y estado
    $items[] = [$c['nombre'], $c['ok']];
}

$sqlite = ['ok' => false, 'msg' => ''];
try {
    $pdo = Database::connection();
    $fk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
    $jm = strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
    $sqlite = ['ok' => true, 'msg' => "foreign_keys=$fk, journal_mode=$jm, tablas=$tables"];
} catch (Throwable $ex) {
    $sqlite['msg'] = 'No se pudo abrir la base de datos.';
}

$items[] = ['Conexion SQLite (' . $sqlite['msg'] . ')', $sqlite['ok']];
$items[] = ['Instalador bloqueado', Installer::isInstalled()];
$items[] = ['Conexion HTTPS', !empty($_SERVER['HTTPS'])];
$items[] = ['Endpoint de inferencia configurado', (string)Config::get('app.inference.url') !== ''];
$items[] = ['Google Client ID configurado', (string)Config::get('app.google.client_id') !== ''];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Diagnostico', 'data' => array_map(
        static fn($i) => ['check' => $i[0], 'ok' => $i[1]], $items), 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostico del entorno</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-5" style="max-width:720px">
<h1 class="h4">Diagnostico del entorno</h1>
<p class="text-muted">PHP <?= $e(PHP_VERSION) ?> &middot; <?= $e(date('Y-m-d H:i:s')) ?></p>
<ul class="list-group">
<?php foreach ($items as [$name, $ok]): ?>
<li class="list-group-item d-flex justify-content-between"><span><?= $e($name) ?></span>
<span class="badge <?= $ok ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $ok ? 'OK' : 'FALLA' ?></span></li>
<?php endforeach; ?>
</ul></div></body></html>
