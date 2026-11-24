<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\View;
use App\Middleware\AuthMiddleware;
use App\Services\AgenteService;

$user = AuthMiddleware::page();
$modo = (new AgenteService())->isProviderConfigured() ? 'IA_EXTERNA' : 'DEMOSTRACION';
View::header('Agente Conversacional', $user, 'chat.php');
?>
<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-robot text-primary me-1"></i><strong>Agente de Control de Peaje</strong></span>
        <span class="badge text-bg-<?= $modo === 'IA_EXTERNA' ? 'success' : 'warning' ?>" id="chat-modo-badge">
          Modo: <?= View::e($modo) ?>
        </span>
      </div>
      <?php if ($modo === 'DEMOSTRACION'): ?>
        <div class="alert alert-warning rounded-0 mb-0 py-2 small">
          <i class="bi bi-info-circle me-1"></i>El agente opera en <strong>modo de demostracion estructurado</strong> (sin proveedor LLM externo configurado). Todas las respuestas provienen directamente de la base de datos SQLite y del servicio M1.
        </div>
      <?php endif; ?>
      <div class="card-body bg-light" id="chat-log" style="height:440px;overflow-y:auto"></div>
      <div class="card-footer bg-white">
        <form id="form-chat" class="row g-2 align-items-center">
          <div class="col-12 col-md-6">
            <input type="text" class="form-control" name="mensaje" id="chat-msg" placeholder="Ej: 1001DEM, recaudacion del dia, ultimos pasos..." autocomplete="off">
          </div>
          <div class="col-8 col-md-4">
            <input type="file" class="form-control form-control-sm" name="imagen" id="chat-img" accept=".jpg,.jpeg,.png,.webp" title="Subir imagen de placa (M1)">
          </div>
          <div class="col-4 col-md-2 d-grid">
            <button class="btn btn-primary" type="submit" id="chat-send"><i class="bi bi-send"></i> Enviar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold">Consultas rapidas</div>
      <div class="card-body d-grid gap-2">
        <button class="btn btn-outline-primary btn-sm text-start q-quick" data-q="1001DEM"><i class="bi bi-search me-1"></i>Placa registrada con paso (1001DEM)</button>
        <button class="btn btn-outline-primary btn-sm text-start q-quick" data-q="7777DEM"><i class="bi bi-exclamation-circle me-1"></i>Placa identificada no registrada (7777DEM)</button>
        <button class="btn btn-outline-primary btn-sm text-start q-quick" data-q="9999ZZZ"><i class="bi bi-x-octagon me-1"></i>Placa inexistente (9999ZZZ)</button>
        <button class="btn btn-outline-primary btn-sm text-start q-quick" data-q="I001DEM"><i class="bi bi-magic me-1"></i>Confusion OCR I001DEM &rarr; 1001DEM</button>
        <button class="btn btn-outline-secondary btn-sm text-start q-quick" data-q="Recaudacion del dia"><i class="bi bi-cash-stack me-1"></i>Resumen de recaudacion</button>
        <button class="btn btn-outline-secondary btn-sm text-start q-quick" data-q="Ultimos pasos"><i class="bi bi-list-ul me-1"></i>Ultimos pasos registrados</button>
        <button class="btn btn-outline-secondary btn-sm text-start q-quick" data-q="Alertas pendientes"><i class="bi bi-bell me-1"></i>Alertas pendientes</button>
      </div>
    </div>
  </div>
</div>
<?php View::footer('<script src="' . View::e(View::basePath()) . '/assets/js/chat.js"></script>'); ?>
