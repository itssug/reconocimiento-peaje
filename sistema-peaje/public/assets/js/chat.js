(function () {
  const log = document.getElementById('chat-log');
  const form = document.getElementById('form-chat');
  const msgInput = document.getElementById('chat-msg');
  const imgInput = document.getElementById('chat-img');
  const sendBtn = document.getElementById('chat-send');

  function addBubble(who, text, extraHtml = '') {
    const wrap = document.createElement('div');
    wrap.className = `d-flex mb-3 ${who === 'user' ? 'justify-content-end' : 'justify-content-start'}`;
    const card = document.createElement('div');
    card.className = `p-3 rounded-3 shadow-sm ${who === 'user' ? 'bg-primary text-white' : 'bg-white border'}`;
    card.style.maxWidth = '85%';
    card.innerHTML = `<div style="white-space:pre-wrap">${esc(text)}</div>${extraHtml}`;
    wrap.appendChild(card);
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
  }

  addBubble('agent', 'Bienvenido al Agente de Control de Peaje. Escriba una placa (ej. 1001DEM), adjunte una foto de placa para reconocerla con M1, o consulte la recaudacion y alertas.');

  async function sendQuery() {
    const txt = msgInput.value.trim();
    const hasImg = imgInput.files && imgInput.files.length > 0;
    if (!txt && !hasImg) return;

    addBubble('user', txt || '[Imagen adjunta]' + (hasImg && txt ? ' + [Imagen]' : ''));
    sendBtn.disabled = true;
    try {
      const fd = new FormData();
      if (txt) fd.append('mensaje', txt);
      if (hasImg) fd.append('imagen', imgInput.files[0]);
      msgInput.value = '';
      imgInput.value = '';

      const { data } = await api('/api/v1/agente/chat', { method: 'POST', formData: fd });
      let badge = '';
      if (data.estado_placa) {
        const colors = {
          REGISTRADA_CON_PASO: 'success',
          REGISTRADA_SIN_PASO: 'info',
          IDENTIFICADA_NO_REGISTRADA: 'danger',
          NO_EXISTE: 'secondary',
        };
        badge = `<div class="mt-2"><span class="badge text-bg-${colors[data.estado_placa] || 'secondary'}">${esc(data.estado_placa)}</span></div>`;
      }
      addBubble('agent', data.respuesta, badge);
    } catch (e) {
      addBubble('agent', 'Error: ' + e.message);
    } finally {
      sendBtn.disabled = false;
    }
  }

  form.addEventListener('submit', (ev) => { ev.preventDefault(); sendQuery(); });
  document.querySelectorAll('.q-quick').forEach((b) => {
    b.addEventListener('click', () => {
      msgInput.value = b.dataset.q;
      sendQuery();
    });
  });
})();
