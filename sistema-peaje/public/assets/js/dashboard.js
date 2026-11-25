(function () {
  let maxPasoId = 0;
  let maxAlertaId = 0;
  const seenPasos = new Set();
  const seenAlertas = new Set();
  let pasosList = [];
  let alertasList = [];
  let inFlight = false;

  const tbPasos = document.getElementById('tb-pasos');
  const lstAlertas = document.getElementById('lst-alertas');
  const liveBadge = document.getElementById('live-badge');
  const infBadge = document.getElementById('inf-badge');
  const base = document.querySelector('meta[name="app-base"]')?.content || '';

  const estadoBadge = (es) => {
    const map = { REGISTRADO: 'success', OBSERVADO: 'warning', ERROR_INFERENCIA: 'danger' };
    return `<span class="badge text-bg-${map[es] || 'secondary'}">${esc(es)}</span>`;
  };

  function renderPasos() {
    if (!pasosList.length) {
      tbPasos.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Sin pasos registrados.</td></tr>';
      return;
    }
    tbPasos.innerHTML = pasosList.slice(0, 15).map((p) => `
      <tr data-paso-id="${p.id}">
        <td class="text-muted small">#${p.id}</td>
        <td>
          <code class="fw-bold">${esc(p.placa_normalizada || p.placa_leida || 'N/A')}</code>
          ${p.origen === 'DEMO' ? '<span class="badge text-bg-warning ms-1">DEMO</span>' : ''}
          ${p.imagen_url ? `<button type="button" class="btn btn-link p-0 ms-1 align-middle border-0" title="Ver evidencia segmentada" data-evidencia-url="${esc(base + p.imagen_url)}" data-placa="${esc(p.placa_normalizada || p.placa_leida || 'N/A')}" data-categoria="${esc(p.categoria_detectada || '-')}" data-ejes="${esc(p.ejes_detectados ?? '-')}" data-pasajeros="${esc(p.pasajeros ?? '-')}" data-monto="${esc(fmtMoney(p.monto))}" data-estado="${esc(p.estado)}" data-fecha="${esc(fmtDate(p.created_at))}"><img src="${esc(base + p.imagen_url)}" alt="Evidencia" class="evidencia-thumb"></button>` : ''}
        </td>
        <td>${esc(p.categoria_detectada || '-')}</td>
        <td class="text-center">${p.ejes_detectados ?? '-'}</td>
        <td class="text-center">${p.pasajeros ?? '-'}</td>
        <td class="text-end fw-semibold">${fmtMoney(p.monto)}</td>
        <td class="small">${esc(p.caseta_nombre || ('#' + p.caseta_id))}</td>
        <td>${estadoBadge(p.estado)}</td>
        <td class="small text-muted">${fmtDate(p.created_at)}</td>
      </tr>`).join('');
  }

  function renderAlertas() {
    if (!alertasList.length) {
      lstAlertas.innerHTML = '<div class="list-group-item text-muted small py-3 text-center">No hay alertas pendientes.</div>';
      return;
    }
    lstAlertas.innerHTML = alertasList.slice(0, 8).map((a) => `
      <div class="list-group-item d-flex justify-content-between align-items-start gap-2" data-alerta-id="${a.id}">
        <div class="small">
          <div><span class="badge text-bg-danger">${esc(a.tipo)}</span> ${a.es_demo ? '<span class="badge text-bg-warning">DEMO</span>' : ''}
            ${a.placa_normalizada ? `<code class="ms-1">${esc(a.placa_normalizada)}</code>` : ''}</div>
          <div class="text-muted mt-1">${esc(a.descripcion)}</div>
        </div>
        <button class="btn btn-sm btn-outline-success flex-shrink-0" data-atender="${a.id}" title="Marcar atendida"><i class="bi bi-check2"></i></button>
      </div>`).join('');
  }

  lstAlertas.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button[data-atender]');
    if (!b) return;
    const id = Number(b.dataset.atender);
    b.disabled = true;
    try {
      await api(`/api/v1/alertas/${id}/atender`, { method: 'PUT' });
      alertasList = alertasList.filter((a) => a.id !== id);
      seenAlertas.delete(id);
      renderAlertas();
      toast('Alerta atendida.');
    } catch (e) {
      toast(e.message, 'danger');
      b.disabled = false;
    }
  });

  function renderCharts(casetas, categorias, vehiculos) {
    const maxRec = Math.max(1, ...casetas.map((c) => Number(c.recaudacion || 0)));
    document.getElementById('chart-casetas').innerHTML = casetas.length ? casetas.map((c) => {
      const pct = Math.round((Number(c.recaudacion || 0) / maxRec) * 100);
      return `<div class="mb-3">
        <div class="d-flex justify-content-between small mb-1"><span>${esc(c.nombre)} (${c.pasos} pasos)</span><strong>${fmtMoney(c.recaudacion)}</strong></div>
        <div class="progress" style="height:10px"><div class="progress-bar bg-success" style="width:${pct}%"></div></div>
      </div>`;
    }).join('') : '<div class="text-muted small">Sin datos.</div>';

    const totCat = Math.max(1, categorias.reduce((a, x) => a + Number(x.cantidad || 0), 0));
    document.getElementById('chart-categorias').innerHTML = categorias.length ? categorias.map((c) => {
      const pct = Math.round((Number(c.cantidad || 0) / totCat) * 100);
      return `<div class="mb-2">
        <div class="d-flex justify-content-between small"><span>${esc(c.categoria)}</span><span>${c.cantidad} (${pct}%) &middot; ${fmtMoney(c.recaudacion)}</span></div>
        <div class="progress" style="height:8px"><div class="progress-bar bg-info" style="width:${pct}%"></div></div>
      </div>`;
    }).join('') : '<div class="text-muted small">Sin datos.</div>';

    document.getElementById('lst-vehiculos').innerHTML = vehiculos.map((v) => `
      <li class="list-group-item d-flex justify-content-between align-items-center small">
        <span><code>${esc(v.placa)}</code> &middot; ${esc(v.categoria)} (${v.ejes} ejes)${v.es_demo ? ' <span class="badge text-bg-warning">DEMO</span>' : ''}</span>
        <span class="fw-semibold">${fmtMoney(v.saldo)}</span>
      </li>`).join('');
  }

  async function poll() {
    if (inFlight) return;
    inFlight = true;
    try {
      const { data } = await api(`/api/v1/dashboard/poll?since_paso_id=${maxPasoId}&since_alerta_id=${maxAlertaId}`);
      document.getElementById('st-vehiculos').textContent = data.contadores.total_vehiculos;
      document.getElementById('st-pasos-hoy').textContent = data.contadores.pasos_hoy;
      document.getElementById('st-recaudacion-hoy').textContent = fmtMoney(data.contadores.recaudacion_hoy);
      document.getElementById('st-alertas').textContent = data.contadores.alertas_pendientes;

      // Evitar duplicados usando Set de IDs y marca since_paso_id
      const nuevosPasos = (data.pasos_nuevos || []).filter((p) => !seenPasos.has(p.id));
      if (nuevosPasos.length) {
        nuevosPasos.forEach((p) => seenPasos.add(p.id));
        pasosList = [...nuevosPasos, ...pasosList].sort((a, b) => b.id - a.id).slice(0, 25);
        renderPasos();
      } else if (maxPasoId === 0) {
        renderPasos();
      }

      const nuevasAlertas = (data.alertas_nuevas || []).filter((a) => !seenAlertas.has(a.id));
      if (nuevasAlertas.length) {
        nuevasAlertas.forEach((a) => seenAlertas.add(a.id));
        alertasList = [...nuevasAlertas, ...alertasList].sort((a, b) => b.id - a.id).slice(0, 15);
        renderAlertas();
      } else if (maxAlertaId === 0) {
        renderAlertas();
      }

      maxPasoId = Math.max(maxPasoId, data.max_paso_id || 0);
      maxAlertaId = Math.max(maxAlertaId, data.max_alerta_id || 0);

      renderCharts(data.recaudacion_casetas || [], data.distribucion_categorias || [], data.ultimos_vehiculos || []);

      const inf = data.inferencia || {};
      infBadge.className = 'badge ms-1 text-bg-' + (inf.configurado ? (inf.ok ? 'success' : 'danger') : 'warning');
      infBadge.textContent = inf.configurado ? (inf.ok ? `Inferencia REAL (${inf.latencia_ms ?? '-'} ms)` : 'Inferencia: sin respuesta') : 'Inferencia: Modo DEMO';

      liveBadge.className = 'badge text-bg-success';
      liveBadge.innerHTML = '<i class="bi bi-broadcast"></i> Tiempo real (2s)';
      document.getElementById('last-sync').textContent = 'Actualizado: ' + fmtDate(data.timestamp);
    } catch (e) {
      liveBadge.className = 'badge text-bg-danger';
      liveBadge.textContent = 'Reintentando conexion...';
    } finally {
      inFlight = false;
    }
  }

  poll();
  setInterval(poll, 2000);
})();
