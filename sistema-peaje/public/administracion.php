<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;
use App\Models\Categoria;

$user = AuthMiddleware::page(['ADMIN']);
View::header('Administracion', $user, 'administracion.php');
?>
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-casetas"><i class="bi bi-geo-alt"></i> Casetas</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tarifas"><i class="bi bi-cash-coin"></i> Tarifas</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-usuarios"><i class="bi bi-people"></i> Usuarios autorizados</button></li>
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-casetas">
    <div class="d-flex justify-content-between mb-2"><h2 class="h6 text-muted mb-0">Casetas de peaje</h2><button class="btn btn-sm btn-primary" id="c-new"><i class="bi bi-plus-lg"></i> Nueva caseta</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Nombre</th><th>Ubicacion</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody id="c-body"></tbody></table></div></div>
  </div>
  <div class="tab-pane fade" id="tab-tarifas">
    <div class="d-flex justify-content-between mb-2"><h2 class="h6 text-muted mb-0">Tarifas por categoria y ejes (Bs)</h2><button class="btn btn-sm btn-primary" id="t-new"><i class="bi bi-plus-lg"></i> Nueva tarifa</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Categoria</th><th class="text-center">Ejes</th><th class="text-end">Monto</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody id="t-body"></tbody></table></div></div>
  </div>
  <div class="tab-pane fade" id="tab-usuarios">
    <div class="d-flex justify-content-between mb-2"><h2 class="h6 text-muted mb-0">Cuentas de Google autorizadas (lista blanca)</h2><button class="btn btn-sm btn-primary" id="u-new"><i class="bi bi-plus-lg"></i> Autorizar cuenta</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Correo</th><th>Nombre</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody id="u-body"></tbody></table></div></div>
  </div>
</div>

<!-- Modal generico -->
<div class="modal fade" id="modal-a" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="form-a" novalidate>
  <div class="modal-header"><h5 class="modal-title" id="modal-a-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="modal-a-fields"></div>
  <div class="modal-footer"><div id="form-a-error" class="text-danger small me-auto"></div><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="a-save">Guardar</button></div>
</form></div></div>
<script>window.CATEGORIAS = <?= json_encode(Categoria::ETIQUETAS, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/admin.js"></script>'); ?>
