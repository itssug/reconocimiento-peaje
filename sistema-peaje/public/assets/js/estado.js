(function () {
  const box = document.getElementById('estado-cards');
  const btn = document.getElementById('btn-ping');

  async function load(ping = false) {
    btn.disabled = true;
    try {
      const { data } = await api('/api/v1/estado/servicios' + (ping ? '?ping=1' : ''));
      const inf = data.inferencia;
      box.innerHTML = `
        <div class="col-12 col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="h6 mb-0">Aplicacion PHP</h3>
            <span class="badge text-bg-success">OK</span>
          </div>
          <ul class="list-unstyled small mb-0">
            <li><strong>Version PHP:</strong> ${esc(data.php.version)}</li>
            <li><strong>pdo_sqlite:</strong> ${data.php.extensiones.pdo_sqlite ? 'Cargada' : 'Falta'}</li>
            <li><strong>cURL / OpenSSL:</strong> ${data.php.extensiones.curl && data.php.extensiones.openssl ? 'Cargadas' : 'Falta'}</li>
            <li><strong>Hora servidor:</strong> ${fmtDate(data.servidor_hora)}</li>
          </ul>
        </div></div></div>

        <div class="col-12 col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="h6 mb-0">Base de datos SQLite</h3>
            <span class="badge text-bg-${data.sqlite.ok ? 'success' : 'danger'}">${data.sqlite.ok ? 'OK' : 'ERROR'}</span>
          </div>
          <ul class="list-unstyled small mb-0">
            <li><strong>foreign_keys:</strong> ${data.sqlite.foreign_keys ? 'ON' : 'OFF'}</li>
            <li><strong>journal_mode:</strong> ${esc(data.sqlite.journal_mode)}</li>
            <li><strong>Tablas activas:</strong> ${data.sqlite.tablas}</li>
          </ul>
        </div></div></div>

        <div class="col-12 col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="h6 mb-0">API de Inferencia (${esc(inf.modo)})</h3>
            <span class="badge text-bg-${inf.configurado ? (inf.ok ? 'success' : 'danger') : 'warning'}">
              ${inf.configurado ? (inf.ok ? 'CONECTADO' : 'SIN RESPUESTA') : 'MODO DEMO'}
            </span>
          </div>
          <ul class="list-unstyled small mb-0">
            <li><strong>URL configurada:</strong> <code>${esc(inf.endpoint_url)}</code></li>
            <li><strong>Latencia:</strong> ${inf.latencia_ms !== null ? inf.latencia_ms + ' ms' : '-'}</li>
            <li><strong>Ultima comprobacion:</strong> ${fmtDate(inf.comprobado_en)}</li>
            <li><strong>Ultimo error conocido:</strong> <span class="text-danger">${esc(inf.ultimo_error || 'Ninguno')}</span></li>
          </ul>
        </div></div></div>`;
      if (ping) toast('Comprobacion completada.');
    } catch (e) {
      toast(e.message, 'danger');
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener('click', () => load(true));
  load(false);
})();
