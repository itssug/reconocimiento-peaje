(function () {
  const state = { page: 1, per: 20 };
  const body = document.getElementById('p-body');
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const estadoBadge = (es) => `<span class="badge text-bg-${({ REGISTRADO: 'success', OBSERVADO: 'warning', ERROR_INFERENCIA: 'danger' })[es] || 'secondary'}">${esc(es)}</span>`;

  async function load() {
    const p = new URLSearchParams({ page: state.page, per_page: state.per });
    const map = { placa: 'fp-placa', categoria: 'fp-cat', caseta_id: 'fp-caseta', estado: 'fp-estado', desde: 'fp-desde', hasta: 'fp-hasta' };
    Object.entries(map).forEach(([k, id]) => {
      const v = document.getElementById(id).value.trim();
      if (v) p.set(k, v);
    });
    body.innerHTML = '<tr><td colspan="13" class="text-center py-4 text-muted">Cargando...</td></tr>';
    try {
      const { data } = await api('/api/v1/pasos?' + p);
      if (!data.items.length) {
        body.innerHTML = '<tr><td colspan="13" class="text-center py-4 text-muted">Sin registros para los filtros seleccionados.</td></tr>';
      } else {
        body.innerHTML = data.items.map((r) => `<tr>
          <td class="text-muted small">#${r.id}</td>
          <td><code>${esc(r.placa_leida || '-')}</code></td>
          <td><code class="fw-bold">${esc(r.placa_normalizada || '-')}</code>${r.origen === 'DEMO' ? ' <span class="badge text-bg-warning">DEMO</span>' : ''}</td>
          <td class="text-center">${r.confianza_ocr !== null ? Math.round(r.confianza_ocr * 100) + '%' : '-'}</td>
          <td>${esc(r.categoria_detectada || '-')}</td>
          <td class="text-center">${r.ejes_detectados ?? '-'}</td>
          <td class="text-center">${r.pasajeros ?? '-'}</td>
          <td class="text-end fw-semibold">${fmtMoney(r.monto)}</td>
          <td class="small">${esc(r.caseta_nombre || ('#' + r.caseta_id))}</td>
          <td class="small">${esc(r.operador_nombre || '-')}</td>
          <td>${r.imagen_url ? `<button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" data-evidencia-url="${esc(base + r.imagen_url)}" data-placa="${esc(r.placa_normalizada || r.placa_leida || 'N/A')}" data-categoria="${esc(r.categoria_detectada || '-')}" data-ejes="${esc(r.ejes_detectados ?? '-')}" data-pasajeros="${esc(r.pasajeros ?? '-')}" data-monto="${esc(fmtMoney(r.monto))}" data-estado="${esc(r.estado)}" data-fecha="${esc(fmtDate(r.created_at))}"><img src="${esc(base + r.imagen_url)}" alt="Evidencia" class="evidencia-thumb" style="height:24px;width:38px"><span>Ver</span></button>` : '<span class="text-muted small">Sin img</span>'}</td>
          <td>${estadoBadge(r.estado)}</td>
          <td class="small text-muted">${fmtDate(r.created_at)}</td>
        </tr>`).join('');
      }
      const from = data.total ? (data.page - 1) * data.per_page + 1 : 0;
      document.getElementById('p-info').textContent = `${from}-${Math.min(data.page * data.per_page, data.total)} de ${data.total}`;
      document.getElementById('pp-prev').disabled = data.page <= 1;
      document.getElementById('pp-next').disabled = data.page * data.per_page >= data.total;
    } catch (e) {
      body.innerHTML = `<tr><td colspan="13" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
    }
  }

  ['fp-cat', 'fp-caseta', 'fp-estado', 'fp-desde', 'fp-hasta'].forEach((id) =>
    document.getElementById(id).addEventListener('change', () => { state.page = 1; load(); }));
  let t;
  document.getElementById('fp-placa').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { state.page = 1; load(); }, 300); });
  document.getElementById('fp-reset').addEventListener('click', () => {
    ['fp-placa', 'fp-cat', 'fp-caseta', 'fp-estado', 'fp-desde', 'fp-hasta'].forEach((id) => (document.getElementById(id).value = ''));
    state.page = 1; load();
  });
  document.getElementById('pp-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
  document.getElementById('pp-next').addEventListener('click', () => { state.page++; load(); });

  const formFrame = document.getElementById('form-frame');
  const resBox = document.getElementById('frame-res');
  formFrame.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = document.getElementById('btn-frame');
    btn.disabled = true;
    resBox.className = 'alert alert-info mt-2 mb-0 small';
    resBox.textContent = 'Enviando imagen a inferencia y registrando paso...';
    try {
      const fd = new FormData(formFrame);
      const { data } = await api('/api/v1/pasos/frame', { method: 'POST', formData: fd });
      const p = data.paso;
      resBox.className = 'alert alert-success mt-2 mb-0 small d-flex flex-wrap justify-content-between align-items-center gap-2';
      const btnModal = p.imagen_url
        ? `<button type="button" class="btn btn-sm btn-primary" data-evidencia-url="${esc(base + p.imagen_url)}" data-placa="${esc(p.placa_normalizada || p.placa_leida || 'N/A')}" data-categoria="${esc(p.categoria_detectada || '-')}" data-ejes="${esc(p.ejes_detectados ?? '-')}" data-pasajeros="${esc(p.pasajeros ?? '-')}" data-monto="${esc(fmtMoney(p.monto))}" data-estado="${esc(p.estado)}" data-fecha="${esc(fmtDate(p.created_at))}"><i class="bi bi-eye"></i> Ver foto segmentada</button>`
        : '';
      resBox.innerHTML = `<span>Paso <strong>#${p.id}</strong> registrado (${esc(p.origen)}): Placa <code>${esc(p.placa_normalizada || 'N/A')}</code> &middot; Categoría <strong>${esc(p.categoria_detectada || '-')}</strong> (${p.ejes_detectados ?? '-'} ejes) &middot; Monto <strong>${fmtMoney(p.monto)}</strong> &middot; Estado <strong>${esc(p.estado)}</strong>.</span>${btnModal}`;
      if (p.imagen_url && window.verEvidenciaModal) {
        window.verEvidenciaModal({
          url: base + p.imagen_url,
          placa: p.placa_normalizada || p.placa_leida || 'N/A',
          categoria: p.categoria_detectada || '-',
          ejes: p.ejes_detectados ?? '-',
          pasajeros: p.pasajeros ?? '-',
          monto: fmtMoney(p.monto),
          estado: p.estado,
          fecha: fmtDate(p.created_at),
        });
      }
      formFrame.imagen.value = '';
      state.page = 1;
      load();
    } catch (e) {
      resBox.className = 'alert alert-danger mt-2 mb-0 small';
      resBox.textContent = e.message;
    } finally {
      btn.disabled = false;
    }
  });

  load();
})();
