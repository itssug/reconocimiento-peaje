(function () {
  const state = { page: 1, per: 20 };
  const body = document.getElementById('a-body');

  async function load() {
    const p = new URLSearchParams({ page: state.page, per_page: state.per });
    const t = document.getElementById('fa-tipo').value;
    const at = document.getElementById('fa-atendida').value;
    if (t) p.set('tipo', t);
    if (at !== '') p.set('atendida', at);
    body.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Cargando...</td></tr>';
    try {
      const { data } = await api('/api/v1/alertas?' + p);
      if (!data.items.length) {
        body.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Sin alertas.</td></tr>';
      } else {
        body.innerHTML = data.items.map((a) => `<tr>
          <td class="text-muted small">#${a.id}</td>
          <td><span class="badge text-bg-danger">${esc(a.tipo)}</span>${a.es_demo ? ' <span class="badge text-bg-warning">DEMO</span>' : ''}</td>
          <td><code>${esc(a.placa_normalizada || '-')}</code></td>
          <td class="small">${esc(a.caseta_nombre || '-')}</td>
          <td>${esc(a.descripcion)}</td>
          <td>${a.atendida
            ? `<span class="badge text-bg-success">Atendida</span><div class="small text-muted">${esc(a.atendida_por_nombre || '')}</div>`
            : '<span class="badge text-bg-warning">Pendiente</span>'}</td>
          <td class="small text-muted">${fmtDate(a.created_at)}</td>
          <td class="text-end">${!a.atendida ? `<button class="btn btn-sm btn-outline-success" data-id="${a.id}"><i class="bi bi-check2"></i> Atender</button>` : ''}</td>
        </tr>`).join('');
      }
      const from = data.total ? (data.page - 1) * data.per_page + 1 : 0;
      document.getElementById('a-info').textContent = `${from}-${Math.min(data.page * data.per_page, data.total)} de ${data.total}`;
      document.getElementById('ap-prev').disabled = data.page <= 1;
      document.getElementById('ap-next').disabled = data.page * data.per_page >= data.total;
    } catch (e) {
      body.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
    }
  }

  ['fa-tipo', 'fa-atendida'].forEach((id) => document.getElementById(id).addEventListener('change', () => { state.page = 1; load(); }));
  document.getElementById('ap-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
  document.getElementById('ap-next').addEventListener('click', () => { state.page++; load(); });

  body.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button[data-id]');
    if (!b) return;
    b.disabled = true;
    try {
      await api(`/api/v1/alertas/${b.dataset.id}/atender`, { method: 'PUT' });
      toast('Alerta marcada como atendida.');
      load();
    } catch (e) {
      toast(e.message, 'danger');
      b.disabled = false;
    }
  });

  load();
})();
