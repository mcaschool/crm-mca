/*
 * Widget embebible de Celia (bot de Microcredenciales MCA).
 * - Aislado con Shadow DOM (no choca con los estilos de la web anfitriona).
 * - Sin secretos ni logica de negocio: solo habla con nuestra API.
 * - Deduce la institucion/bot desde la public_key; nunca envia institution_id.
 * Config via atributos del <script>: data-bot-key, data-api-base.
 */
(function () {
  var script = document.currentScript;
  var BOT_KEY = script.getAttribute('data-bot-key');
  var API = (script.getAttribute('data-api-base') || '').replace(/\/$/, '') + '/api/v1/widget';
  var LS_KEY = 'celia_session_' + BOT_KEY;

  var state = { lang: 'es', sessionId: localStorage.getItem(LS_KEY) || '', captured: false, assistant: 'Celia', answers: {}, options: null };

  var COLORS = { blue: '#0C447C', dark: '#0b2545', gold: '#F5B500', cream: '#F7F5EF' };

  var T = {
    es: { open: 'Habla con Celia', name: 'Tu nombre', email: 'Tu correo', consent: 'Acepto ser contactado y el tratamiento de mis datos.', start: 'Empezar', next: 'Siguiente', see: 'Ver ficha', celia: 'La conversacion con Celia estara disponible pronto.', none: 'No encontramos coincidencia exacta. Habla con Celia o mira el catalogo completo.', results: 'Programas recomendados para ti' },
    en: { open: 'Talk to Celia', name: 'Your name', email: 'Your email', consent: 'I agree to be contacted and to the processing of my data.', start: 'Start', next: 'Next', see: 'View', celia: 'The conversation with Celia will be available soon.', none: 'No exact match. Talk to Celia or browse the full catalog.', results: 'Programs recommended for you' }
  };
  function t(k) { return (T[state.lang] || T.es)[k]; }

  // --- API helper ---
  function api(path, method, body) {
    return fetch(API + path + (path.indexOf('?') > -1 ? '&' : '?') + 'lang=' + state.lang, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-Bot-Key': BOT_KEY, 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  // --- Shadow DOM shell ---
  var host = document.createElement('div');
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:"DM Sans",system-ui,Arial,sans-serif}' +
    '.launcher{position:fixed;bottom:20px;right:20px;background:' + COLORS.blue + ';color:#fff;border:none;border-radius:999px;padding:14px 20px;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);z-index:2147483000}' +
    '.panel{position:fixed;bottom:20px;right:20px;width:360px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 40px);background:' + COLORS.cream + ';border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;z-index:2147483000}' +
    '.panel.open{display:flex}' +
    '.head{background:' + COLORS.blue + ';color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}' +
    '.head b{font-size:16px}' +
    '.head .lang{background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;padding:4px 6px;cursor:pointer;font-size:12px}' +
    '.head .x{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1}' +
    '.body{flex:1;overflow-y:auto;padding:16px}' +
    '.msg{background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:12px;color:' + COLORS.dark + ';font-size:14px;line-height:1.4}' +
    '.btn{display:block;width:100%;text-align:left;background:#fff;border:1px solid #e2ddd2;border-radius:10px;padding:11px 14px;margin-bottom:8px;cursor:pointer;font-size:14px;color:' + COLORS.blue + ';font-weight:600}' +
    '.btn:hover{border-color:' + COLORS.blue + '}' +
    '.field{margin-bottom:10px}' +
    '.field label{display:block;font-size:12px;color:' + COLORS.dark + ';margin-bottom:4px}' +
    '.field input[type=text],.field input[type=email]{width:100%;padding:9px;border:1px solid #d9d3c7;border-radius:8px;font-size:14px}' +
    '.chk{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:' + COLORS.dark + ';margin:10px 0}' +
    '.primary{background:' + COLORS.gold + ';color:' + COLORS.dark + ';border:none;border-radius:999px;padding:11px 18px;font-weight:700;cursor:pointer;width:100%;font-size:14px}' +
    '.card{background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:10px;border-left:4px solid ' + COLORS.gold + '}' +
    '.card b{color:' + COLORS.dark + ';font-size:14px;display:block;margin-bottom:6px}' +
    '.card a{color:' + COLORS.blue + ';font-size:13px;font-weight:600;text-decoration:none}' +
    '.progress{height:6px;background:#e2ddd2;border-radius:999px;margin-bottom:14px;overflow:hidden}' +
    '.progress i{display:block;height:100%;background:' + COLORS.gold + ';transition:width .3s}' +
    '.hp{position:absolute;left:-9999px}' +
    '.typing{color:#8a8577;font-size:12px;padding:4px 2px}' +
    '</style>' +
    '<button class="launcher"></button>' +
    '<div class="panel"><div class="head"><b></b><div><button class="lang">EN</button> <button class="x">&times;</button></div></div><div class="body"></div></div>';

  var launcher = root.querySelector('.launcher');
  var panel = root.querySelector('.panel');
  var body = root.querySelector('.body');
  var headName = root.querySelector('.head b');
  var langBtn = root.querySelector('.lang');
  launcher.textContent = t('open');

  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; }
  function clear() { body.innerHTML = ''; }
  function typing() { clear(); body.appendChild(el('<div class="typing">…</div>')); }

  // --- Screens ---
  function open() {
    panel.classList.add('open'); launcher.style.display = 'none';
    typing();
    api('/session', 'POST', { session_id: state.sessionId }).then(function (r) {
      state.sessionId = r.session_id; localStorage.setItem(LS_KEY, r.session_id);
      state.captured = !!r.contact_captured;
      if (r.bot && r.bot.assistant_name) { state.assistant = r.bot.assistant_name; }
      headName.textContent = state.assistant;
      if (!state.captured) { return showCapture(r.node); }
      renderNode(r.node);
    });
  }

  function showCapture(nextNode) {
    clear();
    if (nextNode && nextNode.content) { body.appendChild(el('<div class="msg">' + esc(nextNode.content) + '</div>')); }
    var form = el('<div></div>');
    form.innerHTML =
      '<div class="field"><label>' + t('name') + '</label><input type="text" id="c_name"></div>' +
      '<div class="field"><label>' + t('email') + '</label><input type="email" id="c_email"></div>' +
      '<label class="chk"><input type="checkbox" id="c_consent"><span>' + t('consent') + '</span></label>' +
      '<input class="hp" id="c_hp" tabindex="-1" autocomplete="off">' +
      '<button class="primary" id="c_go">' + t('start') + '</button>' +
      '<div class="typing" id="c_err"></div>';
    body.appendChild(form);
    form.querySelector('#c_go').addEventListener('click', function () {
      var name = form.querySelector('#c_name').value.trim();
      var email = form.querySelector('#c_email').value.trim();
      var consent = form.querySelector('#c_consent').checked;
      var hp = form.querySelector('#c_hp').value;
      if (!name || !email || !consent) { form.querySelector('#c_err').textContent = '*'; return; }
      typing();
      api('/lead', 'POST', { session_id: state.sessionId, name: name, email: email, consent: consent, website: hp }).then(function (r) {
        if (r.contact_captured) { state.captured = true; loadWelcome(); }
        else { showCapture(nextNode); }
      });
    });
  }

  function loadWelcome() {
    typing();
    api('/session', 'POST', { session_id: state.sessionId }).then(function (r) { renderNode(r.node); });
  }

  function renderNode(node) {
    clear();
    if (!node) { return; }
    if (node.content) { body.appendChild(el('<div class="msg">' + esc(node.content) + '</div>')); }
    if (node.type === 'external_link' && node.config && node.config.url) {
      var link = el('<a class="btn" target="_blank" rel="noopener"></a>');
      link.textContent = state.lang === 'es' ? 'Abrir en la web ↗' : 'Open website ↗';
      link.href = node.config.url; body.appendChild(link);
    }
    (node.options || []).forEach(function (opt) {
      var b = el('<button class="btn"></button>');
      b.textContent = opt.label;
      b.addEventListener('click', function () { onOption(opt); });
      body.appendChild(b);
    });
  }

  function onOption(opt) {
    typing();
    api('/navigate', 'POST', { session_id: state.sessionId, option_id: opt.id }).then(function (r) {
      if (r.action === 'start_matcher') { return startMatcher(); }
      if (r.action === 'start_celia') { return startCelia(); }
      renderNode(r.node);
    });
  }

  function startCelia() {
    clear();
    body.appendChild(el('<div class="msg">' + esc(t('celia')) + '</div>'));
    var back = el('<button class="btn"></button>'); back.textContent = state.lang === 'es' ? '← Volver' : '← Back';
    back.addEventListener('click', loadWelcome); body.appendChild(back);
  }

  // --- Matcher ---
  var Q = ['motivacion', 'meta', 'area', 'seniority', 'educacion'];
  function startMatcher() {
    state.answers = {};
    typing();
    api('/matcher-options', 'GET').then(function (o) { state.options = o; askQuestion(0); });
  }
  function askQuestion(i) {
    if (i >= Q.length) { return submitMatcher(); }
    var key = Q[i];
    clear();
    var pct = Math.round((i / Q.length) * 100);
    body.appendChild(el('<div class="progress"><i style="width:' + pct + '%"></i></div>'));
    var qlabel = { motivacion: state.lang === 'es' ? '¿Que te motiva?' : 'What motivates you?', meta: state.lang === 'es' ? '¿Tu meta?' : 'Your goal?', area: state.lang === 'es' ? '¿Que area?' : 'Which area?', seniority: state.lang === 'es' ? '¿Tu momento profesional?' : 'Your career stage?', educacion: state.lang === 'es' ? '¿Tu nivel de estudios?' : 'Your education?' }[key];
    body.appendChild(el('<div class="msg">' + esc(qlabel) + '</div>'));
    (state.options[key] || []).forEach(function (opt) {
      var b = el('<button class="btn"></button>');
      b.textContent = opt.label;
      b.addEventListener('click', function () { state.answers[key] = opt.value; askQuestion(i + 1); });
      body.appendChild(b);
    });
  }
  function submitMatcher() {
    typing();
    api('/match', 'POST', { session_id: state.sessionId, answers: state.answers, website: '' }).then(function (r) {
      clear();
      body.appendChild(el('<div class="progress"><i style="width:100%"></i></div>'));
      if (!r.programs || !r.programs.length) {
        body.appendChild(el('<div class="msg">' + esc(t('none')) + '</div>'));
        var c = el('<button class="btn"></button>'); c.textContent = t('open'); c.addEventListener('click', startCelia); body.appendChild(c);
        return;
      }
      body.appendChild(el('<div class="msg">' + esc(t('results')) + '</div>'));
      r.programs.forEach(function (p) {
        var card = el('<div class="card"></div>');
        card.innerHTML = '<b>' + esc(p.name) + '</b><a target="_blank" rel="noopener" href="' + esc(p.url) + '">' + t('see') + ' ↗</a>';
        body.appendChild(card);
      });
      var back = el('<button class="btn"></button>'); back.textContent = state.lang === 'es' ? '← Volver al inicio' : '← Back to start';
      back.addEventListener('click', loadWelcome); body.appendChild(back);
    });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // --- Events ---
  launcher.addEventListener('click', open);
  root.querySelector('.x').addEventListener('click', function () { panel.classList.remove('open'); launcher.style.display = 'block'; });
  langBtn.addEventListener('click', function () {
    state.lang = state.lang === 'es' ? 'en' : 'es';
    langBtn.textContent = state.lang === 'es' ? 'EN' : 'ES';
    launcher.textContent = t('open');
    loadWelcome();
  });
})();
