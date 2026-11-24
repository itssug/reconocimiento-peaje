<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Config;
use App\Helpers\Request;
use App\Helpers\View;
use App\Services\AuthService;

$req = Request::fromGlobals();
$tok = $req->cookie(AuthService::COOKIE);
if ($tok && (new AuthService())->authenticate($tok)) {
    header('Location: ' . View::basePath() . '/dashboard.php');
    exit;
}
$clientId = (string)Config::get('app.google.client_id', '');
$base = View::basePath();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="app-base" content="<?= View::e($base) ?>">
<title>Ingresar - Control de Peaje</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= View::e($base) ?>/assets/css/app.css" rel="stylesheet">
<script src="https://accounts.google.com/gsi/client" async defer></script></head>
<body class="bg-dark d-flex align-items-center min-vh-100">
<div class="container" style="max-width:420px">
  <div class="card shadow border-0"><div class="card-body p-4 text-center">
    <i class="bi bi-signpost-2 display-4 text-primary"></i>
    <h1 class="h4 mt-2">Sistema Inteligente de Control de Peaje</h1>
    <p class="text-muted small">Acceso exclusivo con cuentas Google autorizadas.</p>
    <div id="login-error" class="alert alert-danger d-none text-start small" role="alert"></div>
    <div id="login-loading" class="text-muted small d-none"><span class="spinner-border spinner-border-sm"></span> Verificando...</div>
    <?php if ($clientId === ''): ?>
      <div class="alert alert-warning small text-start">Google Sign-In no esta configurado (<code>GOOGLE_CLIENT_ID</code> en <code>.env</code>).</div>
    <?php else: ?>
      <div id="g_id_onload" data-client_id="<?= View::e($clientId) ?>" data-callback="onGoogleCredential" data-auto_prompt="false"></div>
      <div class="g_id_signin d-flex justify-content-center" data-type="standard" data-size="large" data-theme="outline" data-text="signin_with" data-shape="rectangular"></div>
    <?php endif; ?>
  </div></div>
</div>
<script src="<?= View::e($base) ?>/assets/js/login.js"></script>
</body></html>
