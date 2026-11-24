<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;

$user = AuthMiddleware::page();
View::header('Alertas del Sistema', $user, 'alertas.php');
?>
<div class="card mb-3"><div class="card-body">
  <form class="row g-2 align-items-end">
    <div class="col-6 col-md-4"><label class="form-label small mb-1">Tipo de alerta</label>
      <select class="form-select" id="fa-tipo"><option value="">Todos los tipos</option>
        <option value="PLACA_NO_REGISTRADA">PLACA_NO_REGISTRADA</option>
        <option value="SIN_SALDO">SIN_SALDO</option>
        <option value="OCR_BAJA_CONFIANZA">OCR_BAJA_CONFIANZA</option>
        <option value="VEHICULO_NO_IDENTIFICADO">VEHICULO_NO_IDENTIFICADO</option>
        <option value="DATOS_INCOMPLETOS">DATOS_INCOMPLETOS</option>
        <option value="ERROR_INFERENCIA">ERROR_INFERENCIA</option>
        <option value="INCONSISTENCIA_CATEGORIA_EJES">INCONSISTENCIA_CATEGORIA_EJES</option>
      </select>
    </div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Estado</label>
      <select class="form-select" id="fa-atendida"><option value="">Todas</option><option value="0" selected>Pendientes</option><option value="1">Atendidas</option></select>
    </div>
  </form>
</div></div>

<div class="card"><div class="table-responsive">
  <table class="table table-hover table-sm align-middle mb-0">
    <thead class="table-light"><tr>
      <th>#</th><th>Tipo</th><th>Placa</th><th>Caseta</th><th>Descripcion</th><th>Estado</th><th>Fecha</th><th class="text-end">Accion</th>
    </tr></thead>
    <tbody id="a-body"><tr><td colspan="8" class="text-center py-4 text-muted">Cargando...</td></tr></tbody>
  </table>
</div>
<div class="card-footer d-flex justify-content-between align-items-center small">
  <span id="a-info" class="text-muted"></span>
  <div class="btn-group"><button class="btn btn-sm btn-outline-secondary" id="ap-prev">&laquo;</button><button class="btn btn-sm btn-outline-secondary" id="ap-next">&raquo;</button></div>
</div></div>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/alertas.js"></script>'); ?>
