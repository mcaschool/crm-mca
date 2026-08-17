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
    '@import url("https://fonts.bunny.net/css?family=cormorant-garamond:600|dm-sans:400,500,600,700|raleway:600");' +
    ':host{all:initial}' +
    '*{box-sizing:border-box;font-family:"DM Sans",system-ui,Arial,sans-serif}' +
    '.launcher{position:fixed;bottom:20px;right:20px;background:' + COLORS.blue + ';color:#fff;border:none;border-radius:999px;padding:14px 22px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 8px 24px rgba(12,68,124,.35);z-index:2147483000}' +
    '.launcher:hover{background:' + COLORS.dark + '}' +
    '.panel{position:fixed;bottom:20px;right:20px;width:370px;max-width:calc(100vw - 24px);height:580px;max-height:calc(100vh - 40px);background:' + COLORS.cream + ';border-radius:18px;box-shadow:0 16px 50px rgba(11,37,69,.35);display:none;flex-direction:column;overflow:hidden;z-index:2147483000}' +
    '.panel.open{display:flex}' +
    '.head{background:linear-gradient(135deg,' + COLORS.blue + ',' + COLORS.dark + ');color:#fff;padding:16px 18px;display:flex;align-items:center;justify-content:space-between}' +
    '.head b{font-family:"Cormorant Garamond",serif;font-size:22px;font-weight:600;letter-spacing:.3px}' +
    '.head .lang{background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:6px;padding:5px 8px;cursor:pointer;font-size:12px;font-weight:600}' +
    '.head .x{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;margin-left:6px}' +
    '.body{flex:1;overflow-y:auto;padding:18px}' +
    '.msg{background:#fff;border-radius:14px;padding:13px 15px;margin-bottom:12px;color:' + COLORS.dark + ';font-size:14px;line-height:1.5;box-shadow:0 1px 3px rgba(11,37,69,.06);white-space:pre-line}' +
    '.msg.q{font-family:"Raleway",sans-serif;font-weight:600;font-size:15px;color:' + COLORS.blue + '}' +
    '.step{font-size:11px;color:#a89f8c;font-weight:600;letter-spacing:.5px;margin-bottom:6px}' +
    '.btn{display:block;width:100%;text-align:left;background:#fff;border:1px solid #e6e0d4;border-radius:12px;padding:12px 15px;margin-bottom:8px;cursor:pointer;font-size:14px;color:' + COLORS.blue + ';font-weight:600;transition:all .15s}' +
    '.btn:hover{border-color:' + COLORS.blue + ';background:#fbfaf6;transform:translateX(2px)}' +
    '.field{margin-bottom:12px}' +
    '.field label{display:block;font-size:12px;color:' + COLORS.dark + ';margin-bottom:5px;font-weight:600}' +
    '.field input[type=text],.field input[type=email]{width:100%;padding:10px 11px;border:1px solid #d9d3c7;border-radius:9px;font-size:14px}' +
    '.field input:focus{outline:none;border-color:' + COLORS.blue + '}' +
    '.chk{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:' + COLORS.dark + ';margin:12px 0}' +
    '.primary{background:' + COLORS.gold + ';color:' + COLORS.dark + ';border:none;border-radius:999px;padding:12px 18px;font-weight:700;cursor:pointer;width:100%;font-size:14px}' +
    '.primary:hover{filter:brightness(.96)}' +
    '.card{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:10px;border-left:5px solid ' + COLORS.gold + ';box-shadow:0 1px 4px rgba(11,37,69,.08)}' +
    '.card b{font-family:"Raleway",sans-serif;color:' + COLORS.dark + ';font-size:15px;display:block;margin-bottom:8px;line-height:1.3}' +
    '.card a{display:inline-block;color:' + COLORS.blue + ';font-size:13px;font-weight:700;text-decoration:none}' +
    '.card a:hover{text-decoration:underline}' +
    '.progress{height:7px;background:#e6e0d4;border-radius:999px;margin-bottom:12px;overflow:hidden}' +
    '.progress i{display:block;height:100%;background:linear-gradient(90deg,' + COLORS.gold + ',#f0a500);transition:width .35s}' +
    '.hp{position:absolute;left:-9999px}' +
    '.typing{color:#a89f8c;font-size:13px;padding:6px 2px}' +
    '</style>' +
    '<button class="launcher"></button>' +
    '<div class="panel"><div class="head"><b></b><div><button class="lang">EN</button><button class="x">&times;</button></div></div><div class="body"></div></div>';

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
    body.appendChild(el('<div class="step">' + (i + 1) + '/' + Q.length + '</div>'));
    var qs = (state.options && state.options.questions) || {};
    var qlabel = qs[key] || key;
    body.appendChild(el('<div class="msg q">' + esc(qlabel) + '</div>'));
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
