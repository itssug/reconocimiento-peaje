<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;

$user = AuthMiddleware::page(['ADMIN']);
View::header('Estado de Servicios', $user, 'estado.php');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted small mb-0">Monitoreo de PHP, SQLite y endpoint externo de inferencia. No expone secretos, tokens ni rutas privadas.</p>
  <button class="btn btn-sm btn-primary" id="btn-ping"><i class="bi bi-arrow-repeat"></i> Comprobar conexion ahora</button>
</div>

<div class="row g-3" id="estado-cards">
  <div class="col-12 text-center py-4 text-muted">Cargando estado...</div>
</div>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/estado.js"></script>'); ?>
