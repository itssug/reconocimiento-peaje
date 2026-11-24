(function () {
  const modal = new bootstrap.Modal(document.getElementById('modal-a'));
  const form = document.getElementById('form-a');
  const fields = document.getElementById('modal-a-fields');
  const err = document.getElementById('form-a-error');
  let current = null; // { title, html, onOpen, submit }

  const field = (label, name, html, help = '') =>
    `<div class="mb-3"><label class="form-label">${label}</label>${html}<div class="invalid-feedback"></div>${help ? `<div class="form-text">${help}</div>` : ''}</div>`;
  const input = (name, attrs = '') => `<input class="form-control" name="${name}" ${attrs}>`;
  const select = (name, opts) => `<select class="form-select" name="${name}">${opts.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select>`;
  const check = (name, label) => `<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="${name}" id="chk-${name}" checked><label class="form-check-label" for="chk-${name}">${label}</label></div>`;

  function open(cfg) {
    current = cfg; err.textContent = '';
    document.getElementById('modal-a-title').textContent = cfg.title;
    fields.innerHTML = cfg.html;
    showFormErrors(form, {});
    cfg.onOpen && cfg.onOpen();
    modal.show();
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = document.getElementById('a-save');
    btn.disabled = true; err.textContent = ''; showFormErrors(form, {});
    try { await current.submit(); modal.hide(); }
    catch (e) {
      showFormErrors(form, e.errors);
      if (!Object.keys(e.errors || {}).length) err.textContent = e.message;
    } finally { btn.disabled = false; }
  });

  const badge = (ok) => `<span class="badge text-bg-${ok ? 'success' : 'secondary'}">${ok ? 'Activo' : 'Inactivo'}</span>`;
  const empty = (n) => `<tr><td colspan="${n}" class="text-center text-muted py-3">Sin registros.</td></tr>`;

  // ---------- Casetas ----------
  const cBody = document.getElementById('c-body');
  async function loadCasetas() {
    try {
      const { data } = await api('/api/v1/casetas');
      window._casetas = Object.fromEntries(data.map((c) => [c.id, c]));
      cBody.innerHTML = data.length ? data.map((c) => `<tr><td>${esc(c.nombre)}${c.es_demo ? ' <span class="badge text-bg-warning">DEMO</span>' : ''}</td><td>${esc(c.ubicacion || '-')}</td><td>${badge(c.activa)}</td>
        <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" data-id="${c.id}" data-a="edit"><i class="bi bi-pencil"></i></button>
        ${c.activa ? `<button class="btn btn-sm btn-outline-danger" data-id="${c.id}" data-a="off"><i class="bi bi-slash-circle"></i></button>` : ''}</td></tr>`).join('') : empty(4);
    } catch (e) { toast(e.message, 'danger'); }
  }
  function casetaForm(c) {
    open({
      title: c ? 'Editar caseta' : 'Nueva caseta',
      html: field('Nombre', 'nombre', input('nombre', 'maxlength="80" required')) + field('Ubicacion', 'ubicacion', input('ubicacion', 'maxlength="160"')) + (c ? check('activa', 'Activa') : ''),
      onOpen() { if (c) { form.nombre.value = c.nombre; form.ubicacion.value = c.ubicacion || ''; form.activa.checked = c.activa; } },
      async submit() {
        const body = { nombre: form.nombre.value, ubicacion: form.ubicacion.value || null };
        if (c) body.activa = form.activa.checked;
        await api(c ? `/api/v1/casetas/${c.id}` : '/api/v1/casetas', { method: c ? 'PUT' : 'POST', body });
        toast('Caseta guardada.'); loadCasetas();
      },
    });
  }
  document.getElementById('c-new').addEventListener('click', () => casetaForm(null));
  cBody.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button[data-a]'); if (!b) return;
    const c = window._casetas[b.dataset.id];
    if (b.dataset.a === 'edit') return casetaForm(c);
    if (confirm(`Desactivar la caseta "${c.nombre}"?`)) {
      try { await api(`/api/v1/casetas/${c.id}`, { method: 'DELETE' }); toast('Caseta desactivada.'); loadCasetas(); } catch (e) { toast(e.message, 'danger'); }
    }
  });

  // ---------- Tarifas ----------
  const tBody = document.getElementById('t-body');
  async function loadTarifas() {
    try {
      const { data } = await api('/api/v1/tarifas');
      window._tarifas = Object.fromEntries(data.map((t) => [t.id, t]));
      tBody.innerHTML = data.length ? data.map((t) => `<tr><td>${esc(CATEGORIAS[t.categoria] || t.categoria)}</td><td class="text-center">${t.ejes}</td><td class="text-end">${fmtMoney(t.monto)}</td><td>${badge(t.activa)}</td>
        <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" data-id="${t.id}" data-a="edit"><i class="bi bi-pencil"></i></button>
        ${t.activa ? `<button class="btn btn-sm btn-outline-danger" data-id="${t.id}" data-a="off"><i class="bi bi-slash-circle"></i></button>` : ''}</td></tr>`).join('') : empty(5);
    } catch (e) { toast(e.message, 'danger'); }
  }
  function tarifaForm(t) {
    open({
      title: t ? 'Editar tarifa' : 'Nueva tarifa',
      html: field('Categoria', 'categoria', select('categoria', Object.entries(CATEGORIAS))) + field('Ejes', 'ejes', input('ejes', 'type="number" min="2" max="9" value="2"')) +
        field('Monto (Bs)', 'monto', input('monto', 'type="number" min="0" step="0.01" value="0"')) + (t ? check('activa', 'Activa') : ''),
      onOpen() { if (t) { form.categoria.value = t.categoria; form.ejes.value = t.ejes; form.monto.value = t.monto; form.activa.checked = t.activa; form.categoria.disabled = true; form.ejes.disabled = true; } },
      async submit() {
        const body = t ? { monto: Number(form.monto.value), activa: form.activa.checked }
          : { categoria: form.categoria.value, ejes: Number(form.ejes.value), monto: Number(form.monto.value) };
        await api(t ? `/api/v1/tarifas/${t.id}` : '/api/v1/tarifas', { method: t ? 'PUT' : 'POST', body });
        toast('Tarifa guardada.'); loadTarifas();
      },
    });
  }
  document.getElementById('t-new').addEventListener('click', () => tarifaForm(null));
  tBody.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button[data-a]'); if (!b) return;
    const t = window._tarifas[b.dataset.id];
    if (b.dataset.a === 'edit') return tarifaForm(t);
    if (confirm('Desactivar esta tarifa?')) {
      try { await api(`/api/v1/tarifas/${t.id}`, { method: 'DELETE' }); toast('Tarifa desactivada.'); loadTarifas(); } catch (e) { toast(e.message, 'danger'); }
    }
  });

  // ---------- Usuarios autorizados ----------
  const uBody = document.getElementById('u-body');
  async function loadUsuarios() {
    try {
      const { data } = await api('/api/v1/usuarios');
      window._usuarios = Object.fromEntries(data.map((u) => [u.id, u]));
      uBody.innerHTML = data.length ? data.map((u) => `<tr><td>${esc(u.email)}</td><td>${esc(u.nombre || (u.ha_ingresado ? '-' : 'Aun no ha ingresado'))}</td>
        <td><span class="badge text-bg-${u.rol === 'ADMIN' ? 'danger' : 'primary'}">${esc(u.rol)}</span></td><td>${badge(u.activo)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-id="${u.id}" data-a="edit"><i class="bi bi-pencil"></i></button></td></tr>`).join('') : empty(5);
    } catch (e) { toast(e.message, 'danger'); }
  }
  function usuarioForm(u) {
    open({
      title: u ? 'Editar acceso' : 'Autorizar cuenta de Google',
      html: field('Correo de Google', 'email', input('email', 'type="email" maxlength="190" required' + (u ? ' disabled' : ''))) +
        field('Rol', 'rol', select('rol', [['OPERADOR', 'Operador'], ['ADMIN', 'Administrador']])) + (u ? check('activo', 'Acceso activo') : ''),
      onOpen() { if (u) { form.email.value = u.email; form.rol.value = u.rol; form.activo.checked = u.activo; } },
      async submit() {
        if (u) await api(`/api/v1/usuarios/${u.id}`, { method: 'PUT', body: { rol: form.rol.value, activo: form.activo.checked } });
        else await api('/api/v1/usuarios', { method: 'POST', body: { email: form.email.value, rol: form.rol.value } });
        toast('Usuario guardado.'); loadUsuarios();
      },
    });
  }
  document.getElementById('u-new').addEventListener('click', () => usuarioForm(null));
  uBody.addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-a]'); if (b) usuarioForm(window._usuarios[b.dataset.id]);
  });

  loadCasetas(); loadTarifas(); loadUsuarios();
})();
