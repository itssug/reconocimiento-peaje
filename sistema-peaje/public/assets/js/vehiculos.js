(function () {
  const state = { page: 1, per: 15, total: 0 };
  const body = document.getElementById('v-body');
  const errBox = document.getElementById('v-error');
  const catLabel = { motocicleta: 'Motocicleta', automovil: 'Automovil', minibus: 'Minibus', camion: 'Camion', bus: 'Bus' };
  const cols = window.IS_ADMIN ? 7 : 6;

  async function load() {
    errBox.classList.add('d-none');
    const p = new URLSearchParams({ page: state.page, per_page: state.per });
    const q = document.getElementById('f-q').value.trim();
    if (q) p.set('q', q);
    if (document.getElementById('f-cat').value) p.set('categoria', document.getElementById('f-cat').value);
    if (document.getElementById('f-act').value !== '') p.set('activo', document.getElementById('f-act').value);
    body.innerHTML = `<tr><td colspan="${cols}" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm"></span> Cargando...</td></tr>`;
    try {
      const { data } = await api('/api/v1/vehiculos?' + p);
      state.total = data.total;
      render(data.items);
      const from = data.total ? (data.page - 1) * data.per_page + 1 : 0;
      document.getElementById('v-info').textContent = `${from}-${Math.min(data.page * data.per_page, data.total)} de ${data.total}`;
      document.getElementById('p-prev').disabled = data.page <= 1;
      document.getElementById('p-next').disabled = data.page * data.per_page >= data.total;
    } catch (e) {
      body.innerHTML = '';
      errBox.textContent = e.message;
      errBox.classList.remove('d-none');
    }
  }

  function render(items) {
    if (!items.length) {
      body.innerHTML = `<tr><td colspan="${cols}" class="text-center py-4 text-muted">Sin resultados.</td></tr>`;
      return;
    }
    body.innerHTML = items.map((v) => `
      <tr class="${v.activo ? '' : 'table-secondary'}">
        <td><code class="fs-6">${esc(v.placa)}</code>${v.es_demo ? ' <span class="badge text-bg-warning">DEMO</span>' : ''}</td>
        <td>${esc(v.propietario || '-')}</td>
        <td>${esc(catLabel[v.categoria] || v.categoria)}</td>
        <td class="text-center">${v.ejes}</td>
        <td class="text-end">${fmtMoney(v.saldo)}</td>
        <td><span class="badge text-bg-${v.activo ? 'success' : 'secondary'}">${v.activo ? 'Activo' : 'Inactivo'}</span></td>
        ${window.IS_ADMIN ? `<td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-primary" data-act="edit" data-id="${v.id}" title="Editar"><i class="bi bi-pencil"></i></button>
          ${v.activo ? `<button class="btn btn-sm btn-outline-danger" data-act="del" data-id="${v.id}" data-placa="${esc(v.placa)}" title="Desactivar"><i class="bi bi-slash-circle"></i></button>` : ''}
        </td>` : ''}
      </tr>`).join('');
    window._items = Object.fromEntries(items.map((v) => [v.id, v]));
  }

  let timer;
  document.getElementById('f-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.page = 1; load(); }, 300); });
  ['f-cat', 'f-act'].forEach((id) => document.getElementById(id).addEventListener('change', () => { state.page = 1; load(); }));
  document.getElementById('f-reset').addEventListener('click', () => {
    document.getElementById('f-q').value = ''; document.getElementById('f-cat').value = ''; document.getElementById('f-act').value = '1';
    state.page = 1; load();
  });
  document.getElementById('p-prev').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
  document.getElementById('p-next').addEventListener('click', () => { state.page++; load(); });

  if (window.IS_ADMIN) {
    const modal = new bootstrap.Modal(document.getElementById('modal-v'));
    const delModal = new bootstrap.Modal(document.getElementById('modal-del'));
    const form = document.getElementById('form-v');
    const formErr = document.getElementById('form-v-error');
    let delId = null;

    document.getElementById('btn-nuevo').addEventListener('click', () => {
      form.reset(); form.id.value = ''; form.placa.disabled = false;
      showFormErrors(form, {}); formErr.classList.add('d-none');
      document.getElementById('modal-v-title').textContent = 'Nuevo vehiculo';
      modal.show();
    });

    body.addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-act]');
      if (!b) return;
      const id = b.dataset.id;
      if (b.dataset.act === 'edit') {
        const v = window._items[id];
        form.reset(); showFormErrors(form, {}); formErr.classList.add('d-none');
        form.id.value = v.id; form.placa.value = v.placa; form.propietario.value = v.propietario || '';
        form.categoria.value = v.categoria; form.ejes.value = v.ejes; form.saldo.value = v.saldo; form.activo.checked = v.activo;
        document.getElementById('modal-v-title').textContent = 'Editar vehiculo';
        modal.show();
      } else {
        delId = id; document.getElementById('del-placa').textContent = b.dataset.placa; delModal.show();
      }
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const save = document.getElementById('v-save');
      save.disabled = true; formErr.classList.add('d-none'); showFormErrors(form, {});
      const payload = {
        placa: form.placa.value, propietario: form.propietario.value || null,
        categoria: form.categoria.value, ejes: Number(form.ejes.value),
        saldo: Number(form.saldo.value || 0), activo: form.activo.checked,
      };
      try {
        const id = form.id.value;
        await api(id ? `/api/v1/vehiculos/${id}` : '/api/v1/vehiculos', { method: id ? 'PUT' : 'POST', body: payload });
        modal.hide(); toast(id ? 'Vehiculo actualizado.' : 'Vehiculo registrado.'); load();
      } catch (e) {
        showFormErrors(form, e.errors);
        if (!Object.keys(e.errors || {}).length) { formErr.textContent = e.message; formErr.classList.remove('d-none'); }
      } finally { save.disabled = false; }
    });

    document.getElementById('del-ok').addEventListener('click', async () => {
      try { await api(`/api/v1/vehiculos/${delId}`, { method: 'DELETE' }); delModal.hide(); toast('Vehiculo desactivado.'); load(); }
      catch (e) { delModal.hide(); toast(e.message, 'danger'); }
    });
  }
  load();
})();
