<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;
use App\Models\Categoria;

$user = AuthMiddleware::page();
View::header('Vehiculos', $user, 'vehiculos.php');
$isAdmin = $user['rol'] === 'ADMIN';
?>
<div class="card mb-3"><div class="card-body">
  <form id="filtros" class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small mb-1">Buscar por placa o propietario</label>
      <input type="search" class="form-control" id="f-q" placeholder="1234ABC" maxlength="40" autocomplete="off">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1">Categoria</label>
      <select class="form-select" id="f-cat"><option value="">Todas</option>
        <?php foreach (Categoria::ETIQUETAS as $k => $l): ?><option value="<?= View::e($k) ?>"><?= View::e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">Estado</label>
      <select class="form-select" id="f-act"><option value="">Todos</option><option value="1" selected>Activos</option><option value="0">Inactivos</option></select>
    </div>
    <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
      <button class="btn btn-outline-secondary" type="button" id="f-reset"><i class="bi bi-x-circle"></i> Limpiar</button>
      <?php if ($isAdmin): ?><button class="btn btn-primary" type="button" id="btn-nuevo"><i class="bi bi-plus-lg"></i> Nuevo vehiculo</button><?php endif; ?>
    </div>
  </form>
</div></div>

<div id="v-error" class="alert alert-danger d-none"></div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover table-sm align-middle mb-0">
    <thead class="table-light"><tr>
      <th>Placa</th><th>Propietario</th><th>Categoria</th><th class="text-center">Ejes</th><th class="text-end">Saldo</th><th>Estado</th><?php if ($isAdmin): ?><th class="text-end">Acciones</th><?php endif; ?>
    </tr></thead>
    <tbody id="v-body"><tr><td colspan="7" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm"></span> Cargando...</td></tr></tbody>
  </table>
</div>
<div class="card-footer d-flex justify-content-between align-items-center small">
  <span id="v-info" class="text-muted"></span>
  <div class="btn-group"><button class="btn btn-sm btn-outline-secondary" id="p-prev">&laquo;</button><button class="btn btn-sm btn-outline-secondary" id="p-next">&raquo;</button></div>
</div></div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="modal-v" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="form-v" novalidate>
  <div class="modal-header"><h5 class="modal-title" id="modal-v-title">Vehiculo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="id">
    <div class="mb-3"><label class="form-label">Placa</label><input class="form-control" name="placa" maxlength="20" required placeholder="1234ABC" style="text-transform:uppercase"><div class="invalid-feedback"></div><div class="form-text">Se normaliza (mayusculas, sin guiones ni espacios).</div></div>
    <div class="mb-3"><label class="form-label">Propietario</label><input class="form-control" name="propietario" maxlength="120"><div class="invalid-feedback"></div></div>
    <div class="row g-3">
      <div class="col-6"><label class="form-label">Categoria</label><select class="form-select" name="categoria" required>
        <?php foreach (Categoria::ETIQUETAS as $k => $l): ?><option value="<?= View::e($k) ?>"><?= View::e($l) ?></option><?php endforeach; ?>
      </select><div class="invalid-feedback"></div></div>
      <div class="col-6"><label class="form-label">Ejes</label><input class="form-control" type="number" name="ejes" min="2" max="9" value="2" required><div class="invalid-feedback"></div></div>
      <div class="col-6"><label class="form-label">Saldo (Bs)</label><input class="form-control" type="number" name="saldo" min="0" step="0.01" value="0"><div class="invalid-feedback"></div></div>
      <div class="col-6 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" id="v-activo" checked><label class="form-check-label" for="v-activo">Activo</label></div></div>
    </div>
    <div id="form-v-error" class="alert alert-danger d-none mt-3 mb-0"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit" id="v-save">Guardar</button></div>
</form></div></div>

<div class="modal fade" id="modal-del" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Desactivar vehiculo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">Se desactivara el vehiculo <strong id="del-placa"></strong>. Su historial se conserva.</div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger" id="del-ok">Desactivar</button></div>
</div></div></div>
<?php endif; ?>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/vehiculos.js"></script>'); ?>
