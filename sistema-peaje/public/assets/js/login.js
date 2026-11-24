window.onGoogleCredential = async function (resp) {
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  const err = document.getElementById('login-error');
  const loading = document.getElementById('login-loading');
  err.classList.add('d-none');
  loading.classList.remove('d-none');
  try {
    const r = await fetch(base + '/api/v1/auth/google', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ credential: resp.credential }),
    });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.message || 'No se pudo iniciar sesion.');
    location.href = base + '/dashboard.php';
  } catch (e) {
    err.textContent = e.message || 'Error de red.';
    err.classList.remove('d-none');
  } finally {
    loading.classList.add('d-none');
  }
};
