<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;

$user = AuthMiddleware::page();
View::header('Panel en tiempo real', $user, 'dashboard.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <span class="badge text-bg-success" id="live-badge"><i class="bi bi-broadcast"></i> Tiempo real (2s)</span>
    <span class="badge text-bg-secondary ms-1" id="inf-badge">Inferencia: verificando...</span>
    <span class="small text-muted ms-2" id="last-sync"></span>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= View::e(View::basePath()) ?>/pasos.php" class="btn btn-sm btn-primary"><i class="bi bi-camera"></i> Registrar / Consultar pasos</a>
    <a href="<?= View::e(View::basePath()) ?>/chat.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-chat-dots"></i> Agente</a>
  </div>
</div>

<!-- Tarjetas estadisticas -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="card stat-card border-0 shadow-sm"><div class="card-body">
    <div class="text-muted small">Vehiculos activos</div>
    <div class="display-6" id="st-vehiculos">-</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card border-0 shadow-sm"><div class="card-body">
    <div class="text-muted small">Pasos del dia</div>
    <div class="display-6 text-primary" id="st-pasos-hoy">-</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card border-0 shadow-sm"><div class="card-body">
    <div class="text-muted small">Recaudacion del dia</div>
    <div class="display-6 text-success" id="st-recaudacion-hoy">-</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card stat-card border-0 shadow-sm"><div class="card-body">
    <div class="text-muted small">Alertas pendientes</div>
    <div class="display-6 text-danger" id="st-alertas">-</div>
  </div></div></div>
</div>

<div class="row g-3 mb-4">
  <!-- Tabla de pasos recientes en tiempo real -->
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-car-front-fill text-primary me-1"></i>Pasos recientes</span>
        <a href="<?= View::e(View::basePath()) ?>/pasos.php" class="small">Ver todo</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead class="table-light"><tr>
            <th>#</th><th>Placa</th><th>Categoria</th><th class="text-center">Ejes</th><th class="text-center">Pasaj.</th><th class="text-end">Monto</th><th>Caseta</th><th>Estado</th><th>Hora</th>
          </tr></thead>
          <tbody id="tb-pasos"><tr><td colspan="9" class="text-center py-4 text-muted">Cargando...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Alertas pendientes -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Alertas pendientes</span>
        <a href="<?= View::e(View::basePath()) ?>/alertas.php" class="small">Gestionar</a>
      </div>
      <div class="list-group list-group-flush" id="lst-alertas">
        <div class="list-group-item text-muted small py-3 text-center">Cargando...</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Grafico de recaudacion por caseta -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart-fill text-success me-1"></i>Recaudacion por caseta</div>
      <div class="card-body" id="chart-casetas"><div class="text-muted small">Cargando...</div></div>
    </div>
  </div>

  <!-- Distribucion por categoria -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-pie-chart-fill text-info me-1"></i>Distribucion por categoria</div>
      <div class="card-body" id="chart-categorias"><div class="text-muted small">Cargando...</div></div>
    </div>
  </div>

  <!-- Ultimos vehiculos registrados -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-clock-history me-1"></i>Ultimos vehiculos registrados</span>
        <a href="<?= View::e(View::basePath()) ?>/vehiculos.php" class="small">Ver padron</a>
      </div>
      <ul class="list-group list-group-flush" id="lst-vehiculos"></ul>
    </div>
  </div>
</div>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/dashboard.js"></script>'); ?>
