<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;
use App\Models\Categoria;
use App\Services\CatalogoService;

$user = AuthMiddleware::page();
$casetas = (new CatalogoService())->casetas(false);
View::header('Historial y Registro de Pasos', $user, 'pasos.php');
?>
<!-- Formulario para registrar un paso enviando frame/imagen a /api/v1/pasos/frame -->
<div class="card mb-3 shadow-sm border-0"><div class="card-body">
  <h2 class="h6 mb-2"><i class="bi bi-camera-video text-primary me-1"></i>Registrar paso por caseta (envio de frame a inferencia)</h2>
  <form id="form-frame" class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
      <label class="form-label small mb-1">Caseta</label>
      <select class="form-select" name="caseta_id" required>
        <?php foreach ($casetas as $c): if ($c['activa']): ?>
          <option value="<?= (int)$c['id'] ?>"><?= View::e($c['nombre']) ?></option>
        <?php endif; endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-5">
      <label class="form-label small mb-1">Imagen / Frame del vehiculo (JPG, PNG o WebP)</label>
      <input type="file" class="form-control" name="imagen" accept=".jpg,.jpeg,.png,.webp" required>
    </div>
    <div class="col-12 col-md-4 d-flex gap-2">
      <button class="btn btn-primary flex-grow-1" type="submit" id="btn-frame"><i class="bi bi-cloud-upload"></i> Procesar frame y registrar</button>
    </div>
  </form>
  <div id="frame-res" class="alert d-none mt-2 mb-0 small"></div>
</div></div>

<!-- Filtros del historial -->
<div class="card mb-3"><div class="card-body">
  <form id="filtros-p" class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Placa</label><input class="form-control" id="fp-placa" placeholder="1001DEM"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Categoria</label>
      <select class="form-select" id="fp-cat"><option value="">Todas</option>
        <?php foreach (Categoria::ETIQUETAS as $k => $l): ?><option value="<?= View::e($k) ?>"><?= View::e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Caseta</label>
      <select class="form-select" id="fp-caseta"><option value="">Todas</option>
        <?php foreach ($casetas as $c): ?><option value="<?= (int)$c['id'] ?>"><?= View::e($c['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Estado</label>
      <select class="form-select" id="fp-estado"><option value="">Todos</option><option value="REGISTRADO">REGISTRADO</option><option value="OBSERVADO">OBSERVADO</option><option value="ERROR_INFERENCIA">ERROR_INFERENCIA</option></select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Desde</label><input type="date" class="form-control" id="fp-desde"></div>
    <div class="col-6 col-md-2 d-flex gap-1"><div class="flex-grow-1"><label class="form-label small mb-1">Hasta</label><input type="date" class="form-control" id="fp-hasta"></div>
      <button type="button" class="btn btn-outline-secondary align-self-end" id="fp-reset" title="Limpiar"><i class="bi bi-x-circle"></i></button>
    </div>
  </form>
</div></div>

<div class="card"><div class="table-responsive">
  <table class="table table-hover table-sm align-middle mb-0">
    <thead class="table-light"><tr>
      <th>#</th><th>Placa leida</th><th>Normalizada</th><th class="text-center">Conf. OCR</th><th>Categoria</th><th class="text-center">Ejes</th><th class="text-center">Pasaj.</th><th class="text-end">Monto</th><th>Caseta</th><th>Operador</th><th>Evidencia</th><th>Estado</th><th>Fecha y hora</th>
    </tr></thead>
    <tbody id="p-body"><tr><td colspan="13" class="text-center py-4 text-muted">Cargando...</td></tr></tbody>
  </table>
</div>
<div class="card-footer d-flex justify-content-between align-items-center small">
  <span id="p-info" class="text-muted"></span>
  <div class="btn-group"><button class="btn btn-sm btn-outline-secondary" id="pp-prev">&laquo;</button><button class="btn btn-sm btn-outline-secondary" id="pp-next">&raquo;</button></div>
</div></div>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/pasos.js"></script>'); ?>
