/* Cliente API comun + utilidades de UI. Usa la cookie de sesion HttpOnly + cabecera CSRF. */
(function () {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  window.APP_ROL = document.querySelector('meta[name="user-rol"]')?.content || '';
  window.IS_ADMIN = window.APP_ROL === 'ADMIN';

  window.api = async function (path, { method = 'GET', body = null, formData = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrf();
    if (body !== null) headers['Content-Type'] = 'application/json';
    let res;
    try {
      res = await fetch(base + path, {
        method, headers, credentials: 'same-origin',
        body: formData ?? (body !== null ? JSON.stringify(body) : undefined),
      });
    } catch (e) {
      throw { status: 0, message: 'Error de red. Verifique su conexion.', errors: {} };
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* respuesta no JSON */ }
    if (res.status === 401 && !path.includes('/auth/')) { location.href = base + '/login.php'; }
    if (!res.ok || !json || json.success === false) {
      throw { status: res.status, message: json?.message || 'Error inesperado.', errors: json?.errors || {} };
    }
    return json;
  };

  // Escapa HTML para insertar datos en innerHTML
  window.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  window.fmtDate = (iso) => {
    if (!iso) return '-';
    return new Intl.DateTimeFormat('es-BO', {
      timeZone: 'America/La_Paz', dateStyle: 'short', timeStyle: 'medium',
    }).format(new Date(iso));
  };
  window.fmtMoney = (n) => 'Bs ' + Number(n ?? 0).toFixed(2);

  window.toast = (msg, type = 'success') => {
    const box = document.getElementById('toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.toast-body').textContent = msg;
    box.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 4000 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  };

  // Muestra errores de validacion del servidor junto a cada campo del formulario
  window.showFormErrors = (form, errors) => {
    form.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach((i) => (i.textContent = ''));
    Object.entries(errors || {}).forEach(([k, msg]) => {
      const input = form.querySelector(`[name="${k}"]`);
      if (!input) return;
      input.classList.add('is-invalid');
      const fb = input.parentElement.querySelector('.invalid-feedback');
      if (fb) fb.textContent = msg;
    });
  };

  document.getElementById('btn-logout')?.addEventListener('click', async () => {
    try { await window.api('/api/v1/auth/logout', { method: 'POST' }); } catch (e) { /* ignorar */ }
    location.href = base + '/login.php';
  });

  // Visor modal estilo Apple para fotos de evidencia (sin abrir pestaña aparte)
  window.verEvidenciaModal = function ({ url, placa, categoria, ejes, pasajeros, monto, estado, fecha }) {
    const modalEl = document.getElementById('evidenciaModal');
    if (!modalEl) return;
    document.getElementById('evidenciaModalTitle').textContent = placa ? `Vehículo ${placa}` : 'Evidencia del Vehículo';
    const imgEl = document.getElementById('evidenciaModalImg');
    imgEl.src = url;
    const chips = [];
    if (placa) chips.push(`<span class="meta-chip"><strong>Placa:</strong> <code>${window.esc(placa)}</code></span>`);
    if (categoria) chips.push(`<span class="meta-chip"><strong>Categoría:</strong> ${window.esc(categoria)}</span>`);
    if (ejes !== undefined && ejes !== null && ejes !== '-') chips.push(`<span class="meta-chip"><strong>Ejes:</strong> ${window.esc(ejes)}</span>`);
    if (pasajeros !== undefined && pasajeros !== null && pasajeros !== '-') chips.push(`<span class="meta-chip"><strong>Pasajeros:</strong> ${window.esc(pasajeros)}</span>`);
    if (monto !== undefined && monto !== null) chips.push(`<span class="meta-chip"><strong>Tarifa:</strong> ${window.esc(monto)}</span>`);
    if (estado) chips.push(`<span class="meta-chip"><strong>Estado:</strong> ${window.esc(estado)}</span>`);
    if (fecha) chips.push(`<span class="meta-chip text-muted">${window.esc(fecha)}</span>`);
    document.getElementById('evidenciaModalMeta').innerHTML = chips.join('');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  };

  document.addEventListener('click', (ev) => {
    const trigger = ev.target.closest('[data-evidencia-url]');
    if (!trigger) return;
    ev.preventDefault();
    window.verEvidenciaModal({
      url: trigger.dataset.evidenciaUrl,
      placa: trigger.dataset.placa || '',
      categoria: trigger.dataset.categoria || '',
      ejes: trigger.dataset.ejes || '',
      pasajeros: trigger.dataset.pasajeros || '',
      monto: trigger.dataset.monto || '',
      estado: trigger.dataset.estado || '',
      fecha: trigger.dataset.fecha || '',
    });
  });
})();
