/*
 * Widget embebible del asesor academico de MCA (Microcredenciales).
 * - Aislado con Shadow DOM (no choca con los estilos de la web anfitriona).
 * - Sin secretos ni logica de negocio: solo habla con nuestra API.
 * - Deduce la institucion/bot desde la public_key; nunca envia institution_id.
 * Config via atributos del <script>: data-bot-key, data-api-base, data-hero-image.
 *
 * Esta es SOLO la capa visual (rediseno aprobado). No cambia la conversacion, el
 * enrutamiento, el emparejador ni las barreras: las llamadas a la API y las
 * decisiones de flujo se conservan intactas. Bilingue es/en. Iconos Lucide.
 */
(function () {
  // document.currentScript es null cuando el widget se carga de forma dinamica
  // (p. ej. document.createElement('script') en el "footer scripts" de WordPress).
  // En ese caso se localiza el <script> por su marcador data-bot-key.
  var script = document.currentScript
    || document.querySelector('script[data-bot-key][src*="celia"]')
    || (function () { var s = document.querySelectorAll('script[data-bot-key]'); return s[s.length - 1]; })();
  if (!script) { return; } // sin script identificable no se arranca (evita romper la web)
  var BOT_KEY = script.getAttribute('data-bot-key');
  var API = (script.getAttribute('data-api-base') || '').replace(/\/$/, '') + '/api/v1/widget';
  var HERO = script.getAttribute('data-hero-image') || ''; // foto institucional opcional
  // Separacion del lanzador desde el borde inferior (px). Configurable por atributo
  // para no chocar con botones flotantes de la web (p. ej. "subir arriba"). Se aplica
  // DENTRO del shadow-root al lanzador; por defecto 24. Se acota a un rango sano.
  var OFFSET_BOTTOM = parseInt(script.getAttribute('data-offset-bottom'), 10);
  if (isNaN(OFFSET_BOTTOM) || OFFSET_BOTTOM < 0) { OFFSET_BOTTOM = 24; }
  if (OFFSET_BOTTOM > 400) { OFFSET_BOTTOM = 400; }
  var LS_KEY = 'celia_session_' + BOT_KEY;

  var state = {
    lang: 'es', sessionId: localStorage.getItem(LS_KEY) || '', captured: false, sessionReady: false,
    assistant: 'Celia', avatarUrl: null, answers: {}, options: null,
    screen: 'welcome', node: null, userName: null, celia: false, identity: 'institution',
    clog: null, cfoot: null, cin: null, csend: null, lastRole: null, pendingTopic: null
  };

  var REDUCE = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  // --- Iconos Lucide (trazo ~1.85, color por currentColor) ---
  var ICONS = {
    minus: '<path d="M5 12h14"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    send: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    chevronRight: '<path d="m9 18 6-6-6-6"/>',
    chevronLeft: '<path d="m15 18-6-6 6-6"/>',
    home: '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
    messageCircle: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    paperclip: '<path d="M13.234 20.252 21 12.3"/><path d="m16 6-8.414 8.586a2 2 0 0 0 2.829 2.829l8.414-8.586a4 4 0 0 0-5.657-5.657l-8.379 8.551a6 6 0 0 0 8.485 8.485l7.914-8.086"/>',
    gradCap: '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
    badgeCheck: '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
    bookOpen: '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    award: '<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>',
    clipboardList: '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
    trendingUp: '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
    layoutGrid: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    compass: '<path d="m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z"/><circle cx="12" cy="12" r="10"/>',
    sparkles: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
    building2: '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'
  };
  function icon(name, cls) {
    var p = ICONS[name] || ICONS.messageCircle;
    return '<svg class="ic ' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>';
  }

  // Mapa CONFIGURABLE icono -> opcion del menu del bot. Se mapea por la CLAVE del
  // nodo destino (estable, no por la etiqueta bilingue) o por la accion.
  var ICON_NODE = {
    NODE_QUE_ES: 'badgeCheck', NODE_METODOLOGIA: 'bookOpen', NODE_CERTIFICACION: 'award',
    NODE_INSCRIPCION: 'clipboardList', NODE_PROYECCION: 'trendingUp', NODE_INCOMPANY: 'building2'
  };
  var ICON_ACTION = { external_link: 'layoutGrid', start_matcher: 'compass', start_celia: 'sparkles' };
  function optionIcon(opt) {
    if (opt.target_key && ICON_NODE[opt.target_key]) { return ICON_NODE[opt.target_key]; }
    // Correo (mailto) -> icono de sobre; formulario corporativo -> edificio.
    if (opt.action === 'external_link' && /^mailto:/i.test(String(opt.url || ''))) { return 'mail'; }
    if (opt.action === 'external_link' && /formacion-corporativa/i.test(String(opt.url || ''))) { return 'building2'; }
    if (opt.action && ICON_ACTION[opt.action]) { return ICON_ACTION[opt.action]; }
    return null; // submenus sin icono de tema: solo texto + chevron discreto
  }

  // --- Textos (bilingue) ---
  var T = {
    es: {
      launcher: '¡Conversemos!', org: 'MCA School of Business', orgShort: 'MCA School',
      role: 'Asesoría académica', instRole: 'Asistente de Microcredenciales',
      wgreet: 'Hola, ¿qué tal? 👋', wsub: 'Resuelve tus dudas sobre las Microcredenciales de MCA School.',
      cardTitle: 'Pregúntanos lo que quieras', cardSub: 'Te respondemos en segundos',
      faqTitle: 'Preguntas frecuentes', tabHome: 'Inicio', tabChat: 'Chat', footer: 'PROUDLY MADE IN THE USA',
      name: 'Tu nombre', email: 'Tu correo', consent: 'Acepto ser contactado y el tratamiento de mis datos.',
      start: 'Empezar', see: 'Ver ficha', ask: 'Escribe tu pregunta…', you: 'Tú',
      checking: 'Verificando disponibilidad…', available: 'está disponible.',
      none: 'No encontramos coincidencia exacta. Habla con el asesor o mira el catálogo completo.',
      results: 'Programas recomendados para ti',
      online: 'En línea', close: 'Cerrar',
      teaser: 'Hola 👋 Soy Celia. ¿Te ayudo a elegir tu microcredencial?'
    },
    en: {
      launcher: "Let's talk!", org: 'MCA School of Business', orgShort: 'MCA School',
      role: 'Academic advising', instRole: 'Microcredentials Assistant',
      wgreet: 'Hi there! 👋', wsub: "Get answers about MCA School's Microcredentials.",
      cardTitle: 'Ask us anything', cardSub: 'We reply in seconds',
      faqTitle: 'Frequently asked', tabHome: 'Home', tabChat: 'Chat', footer: 'PROUDLY MADE IN THE USA',
      name: 'Your name', email: 'Your email', consent: 'I agree to be contacted and to the processing of my data.',
      start: 'Start', see: 'View', ask: 'Type your question…', you: 'You',
      checking: 'Checking availability…', available: 'is available.',
      none: 'No exact match. Talk to the advisor or browse the full catalog.',
      results: 'Programs recommended for you',
      online: 'Online', close: 'Close',
      teaser: "Hi 👋 I'm Celia. Shall I help you choose your microcredential?"
    }
  };
  function t(k) { return (T[state.lang] || T.es)[k]; }

  // Preguntas frecuentes de la bienvenida -> enlazan a un nodo del arbol (deep-link).
  var FAQ = [
    { node: 'NODE_QUE_ES', ic: 'badgeCheck', es: '¿Qué es una Microcredencial?', en: 'What is a Microcredential?' },
    { node: 'NODE_CERTIFICACION', ic: 'award', es: '¿Qué certificación entregan?', en: 'What certification do you give?' },
    { node: 'NODE_INSCRIPCION', ic: 'clipboardList', es: '¿Cómo me inscribo?', en: 'How do I enroll?' },
    { node: 'NODE_METODOLOGIA', ic: 'bookOpen', es: '¿Cómo es la metodología?', en: 'How does it work?' }
  ];

  // --- API helper (sin cambios de logica) ---
  function api(path, method, body) {
    return fetch(API + path + (path.indexOf('?') > -1 ? '&' : '?') + 'lang=' + state.lang, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-Bot-Key': BOT_KEY, 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  // --- Ritmo humano (retardo visual, NO aditivo) ---
  function wait(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }
  function humanPause() { return REDUCE ? 0 : (1500 + Math.random() * 1000); }
  function paced(promise) {
    return Promise.all([Promise.resolve(promise), wait(humanPause())]).then(function (a) { return a[0]; });
  }

  function avatarInner() { return state.avatarUrl ? '<img src="' + esc(state.avatarUrl) + '" alt="">' : icon('gradCap'); }

  // --- Shadow DOM shell ---
  var host = document.createElement('div');
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });

  var heroBg = HERO
    ? 'linear-gradient(150deg,rgba(20,59,107,.92),rgba(30,90,168,.82) 45%,rgba(59,130,214,.72)),url("' + HERO + '") center/cover no-repeat'
    : 'linear-gradient(150deg,var(--deep) 0%,var(--mca) 45%,var(--bright) 100%)';

  root.innerHTML =
    '<style>' +
    '@import url("https://fonts.bunny.net/css?family=dm-sans:400,500,600,700");' +
    ':host{all:initial;' +
    '--deep:#143B6B;--mca:#1E5AA8;--mid:#2E6DB4;--bright:#3B82D6;--yellow:#FFC531;--yellow2:#FFD34D;' +
    '--ink:#13253D;--paper:#F4F7FB;--white:#FFF;--line:#E7EDF5;--muted:#61748F;--botbg:#EFF4FA}' +
    '*{box-sizing:border-box;font-family:"DM Sans",system-ui,-apple-system,Arial,sans-serif}' +
    '.ic{width:20px;height:20px;flex-shrink:0}' +
    // Launcher (estado cerrado): dock esquina inferior derecha con teaser + botón glass
    '.cw-dock{position:fixed;right:20px;bottom:20px;z-index:2147483000;display:flex;flex-direction:column;align-items:flex-end;gap:12px;max-width:calc(100vw - 32px)}' +
    // Burbuja de invitación (teaser)
    '.cw-teaser{position:relative;background:var(--white);border:1px solid #EAEEF4;border-radius:16px 16px 4px 16px;padding:13px 32px 13px 15px;max-width:250px;box-shadow:0 10px 26px rgba(19,37,61,.14);font-size:13px;color:var(--ink);line-height:1.5;animation:cwRise .5s ease both}' +
    '.cw-teaser[hidden]{display:none}' +
    '.cw-teaser .cw-x{position:absolute;top:6px;right:8px;width:20px;height:20px;border:none;background:none;color:var(--muted);font-size:16px;line-height:1;cursor:pointer;padding:0;border-radius:6px;display:grid;place-items:center}' +
    '.cw-teaser .cw-x:hover{background:var(--line);color:var(--ink)}' +
    '@keyframes cwRise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}' +
    // Botón glass (translúcido, borde azul fino, texto azul profundo; nada de azul sólido)
    '.launcher{position:relative;display:inline-flex;align-items:center;gap:11px;border:1.5px solid rgba(30,90,168,.32);cursor:pointer;font-family:inherit;border-radius:40px;padding:10px 20px 10px 12px;color:var(--ink);background:rgba(255,255,255,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);box-shadow:0 8px 22px rgba(19,37,61,.12);transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s,background .18s}' +
    '.launcher:hover{transform:translateY(-2px);background:rgba(255,255,255,.78);box-shadow:0 14px 32px rgba(19,37,61,.18)}' +
    '.launcher .l-ico{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;background:rgba(30,90,168,.12);color:var(--mca)}' +
    '.launcher .l-ico .ic{width:19px;height:19px}' +
    '.launcher .l-tx{display:flex;flex-direction:column;line-height:1.2;text-align:left}' +
    '.launcher .l-main{font-size:14.5px;font-weight:600;letter-spacing:-.005em;color:var(--ink)}' +
    '.launcher .l-sub{font-size:11px;font-weight:500;color:#22935A;display:flex;align-items:center;gap:5px;margin-top:1px}' +
    '.launcher .l-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#2ECC71;box-shadow:0 0 0 0 rgba(46,204,113,.5);animation:cwLive 2s ease-out infinite}' +
    '@keyframes cwLive{0%{box-shadow:0 0 0 0 rgba(46,204,113,.5)}70%{box-shadow:0 0 0 6px rgba(46,204,113,0)}100%{box-shadow:0 0 0 0 rgba(46,204,113,0)}}' +
    // Panel
    '.panel{position:fixed;bottom:20px;right:20px;width:384px;max-width:calc(100vw - 24px);height:634px;max-height:calc(100vh - 40px);background:var(--paper);border-radius:22px;box-shadow:0 20px 60px rgba(11,37,69,.4);display:none;flex-direction:column;overflow:hidden;z-index:2147483000}' +
    '.panel.open{display:flex}' +
    '.iconbtn{background:rgba(255,255,255,.16);border:none;color:#fff;width:32px;height:32px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}' +
    '.iconbtn:hover{background:rgba(255,255,255,.28)}' +
    '.iconbtn.lang{font-size:12px;font-weight:700;width:auto;padding:0 10px;color:#fff}' +
    // ---- Welcome ----
    '.hero{position:relative;background:' + heroBg + ';color:#fff;padding:18px 20px 64px;flex-shrink:0}' +
    '.hrow{display:flex;align-items:center;justify-content:space-between;gap:10px}' +
    '.org{display:flex;align-items:center;gap:10px;min-width:0}' +
    '.org .av{width:40px;height:40px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0}' +
    '.org .av img{width:100%;height:100%;object-fit:cover}.org .av svg{width:22px;height:22px;color:#fff}' +
    '.org b{font-size:14px;font-weight:600;letter-spacing:.2px}' +
    '.hactions{display:flex;gap:8px}' +
    '.hgreet{font-size:27px;font-weight:700;letter-spacing:-.3px;margin:22px 0 6px}' +
    '.hsub{font-size:14px;line-height:1.5;color:rgba(255,255,255,.9);max-width:19rem}' +
    // Liquid glass card (SOLO aqui)
    '.glass{position:absolute;left:20px;right:20px;bottom:-34px;' +
    'background:linear-gradient(135deg,rgba(255,255,255,.78),rgba(255,255,255,.52));' +
    'backdrop-filter:blur(30px) saturate(200%);-webkit-backdrop-filter:blur(30px) saturate(200%);' +
    'border:1px solid rgba(255,255,255,.9);border-radius:18px;' +
    'box-shadow:0 10px 34px rgba(11,37,69,.22),inset 0 1px 0 rgba(255,255,255,.95),inset 0 0 22px rgba(255,255,255,.35);' +
    'padding:15px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}' +
    '.glass .gt{color:var(--ink);font-weight:700;font-size:15px}' +
    '.glass .gs{color:var(--muted);font-size:12.5px;margin-top:2px}' +
    '.glass .gbtn{background:linear-gradient(135deg,var(--bright),var(--mca));color:#fff;border:none;width:46px;height:46px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 6px 16px rgba(30,90,168,.4)}' +
    '.glass .gbtn:active{transform:scale(.94)}' +
    '.wbody{flex:1;overflow-y:auto;padding:48px 18px 12px}' +
    '.faqh{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin:4px 4px 10px}' +
    '.faq{display:flex;align-items:center;gap:12px;width:100%;text-align:left;height:auto;background:var(--white);border:1px solid var(--line);border-radius:14px;padding:13px 14px;margin-bottom:9px;cursor:pointer;color:var(--ink);font-size:14px;font-weight:500;line-height:1.4;transition:border-color .15s,transform .12s,box-shadow .15s}' +
    '.faq:hover{border-color:var(--bright);box-shadow:0 4px 14px rgba(30,90,168,.1)}' +
    '.faq .lead{color:var(--mca);display:flex;align-items:center;flex-shrink:0}' +
    '.faq .lead .ic{width:20px;height:20px}' +
    '.faq .lbl{flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word}.faq .ch{color:var(--muted);flex-shrink:0;display:flex}.faq .ch .ic{width:18px;height:18px}' +
    '.tabs{display:flex;border-top:1px solid var(--line);background:var(--white);flex-shrink:0}' +
    '.tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 0 7px;background:none;border:none;cursor:pointer;color:var(--muted);font-size:11px;font-weight:600}' +
    '.tab.active{color:var(--mca)}' +
    '.foot{text-align:center;text-transform:uppercase;letter-spacing:1.5px;font-size:9.5px;font-weight:600;color:var(--muted);padding:8px 0 10px;background:var(--white)}' +
    // ---- Chat ----
    '.chead{background:linear-gradient(135deg,var(--mca),var(--deep));color:#fff;padding:14px 14px;display:flex;align-items:center;gap:11px;flex-shrink:0}' +
    '.chead .av{width:40px;height:40px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0}' +
    '.chead .av img{width:100%;height:100%;object-fit:cover}.chead .av svg{width:22px;height:22px;color:#fff}' +
    '.chead .nm{flex:1;min-width:0}.chead .nm b{font-size:15px;font-weight:700;display:block;line-height:1.2}' +
    '.chead .nm span{font-size:12px;color:rgba(255,255,255,.82)}' +
    '.clog{flex:1;overflow-y:auto;padding:16px;scroll-behavior:smooth}' +
    '.turn{display:flex;flex-direction:column;margin-bottom:14px}' +
    '.turn.user{align-items:flex-end}' +
    '.who{font-size:11px;font-weight:600;letter-spacing:.2px;color:var(--muted);margin:0 8px 4px}' +
    '.turn.user .who{color:var(--mca)}.turn.celia .who{margin-left:42px}' +
    '.crow{display:flex;align-items:flex-end;gap:8px}' +
    '.av-s{width:26px;height:26px;border-radius:50%;overflow:hidden;background:#e3ebf6;color:var(--mca);display:flex;align-items:center;justify-content:center;flex-shrink:0}' +
    '.av-s img{width:100%;height:100%;object-fit:cover}.av-s svg{width:15px;height:15px}.av-s.ph{background:transparent}' +
    '.msg{border-radius:15px;padding:12px 14px;font-size:14px;line-height:1.5;white-space:pre-line;overflow-wrap:anywhere;word-break:break-word;max-width:100%;animation:msgIn .3s ease both}' +
    '.turn.celia .msg{background:var(--botbg);color:var(--ink);border-bottom-left-radius:5px}' +
    '.turn.user .msg{background:linear-gradient(135deg,var(--bright),var(--mca));color:#fff;border-bottom-right-radius:5px;max-width:86%}' +
    '.msg a{color:var(--mca);font-weight:600;text-decoration:underline;overflow-wrap:anywhere;word-break:break-word}' +
    '.turn.user .msg a{color:#fff}' +
    '.q{font-weight:600;color:var(--ink)}' +
    // Menu / opciones
    '.opt{display:flex;align-items:center;gap:11px;width:100%;text-align:left;height:auto;background:var(--white);border:1px solid var(--line);border-radius:14px;padding:13px 15px;margin-bottom:8px;cursor:pointer;font-size:14px;color:var(--ink);font-weight:500;line-height:1.4;transition:border-color .15s,transform .12s,background .15s}' +
    '.opt:hover{border-color:var(--bright);background:#fbfdff}' +
    '.opt.pressed{transform:scale(.98);background:#eef4fb;border-color:var(--mca)}' +
    '.opt .lead{color:var(--mca);display:flex;align-items:center;flex-shrink:0}' +
    '.opt .lead .ic{width:20px;height:20px}' +
    '.opt .lbl{flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word}' +
    '.opt .ch{color:var(--muted);flex-shrink:0;display:flex}.opt .ch .ic{width:18px;height:18px}' +
    // Captura
    '.field{margin-bottom:11px}.field label{display:block;font-size:12px;color:var(--ink);margin-bottom:5px;font-weight:600}' +
    '.field input{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:14px;background:#fff}' +
    '.field input:focus{outline:none;border-color:var(--bright)}' +
    '.chk{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--ink);margin:12px 2px}' +
    '.primary{background:linear-gradient(135deg,var(--bright),var(--mca));color:#fff;border:none;border-radius:12px;padding:12px 18px;font-weight:700;cursor:pointer;width:100%;font-size:14px}' +
    '.primary:active{transform:scale(.99)}.err{color:#c0392b;font-size:12px;min-height:14px;margin-top:4px;font-weight:600}' +
    // Tarjetas de programa
    '.card{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:10px;border-left:4px solid var(--yellow);box-shadow:0 1px 4px rgba(11,37,69,.08);animation:msgIn .3s ease both}' +
    '.card b{color:var(--ink);font-size:15px;display:block;margin-bottom:8px;line-height:1.3}' +
    '.card a{display:inline-flex;align-items:center;gap:5px;color:var(--mca);font-size:13px;font-weight:700;text-decoration:none}' +
    '.card a:hover{text-decoration:underline}' +
    '.progress{height:6px;background:var(--line);border-radius:999px;margin-bottom:12px;overflow:hidden}' +
    '.progress i{display:block;height:100%;background:linear-gradient(90deg,var(--bright),var(--mca));transition:width .35s}' +
    '.step{font-size:11px;color:var(--muted);font-weight:600;letter-spacing:.4px;margin-bottom:8px}' +
    // Typing + status
    '.typing{display:flex;gap:5px;align-items:center;padding:8px 2px;animation:msgIn .25s ease both}' +
    '.typing span{width:7px;height:7px;border-radius:50%;background:#b9c6d8;display:inline-block;animation:dot 1s infinite ease-in-out}' +
    '.typing span:nth-child(2){animation-delay:.15s}.typing span:nth-child(3){animation-delay:.3s}' +
    '.status{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;padding:6px 2px;animation:msgIn .25s ease both}' +
    '.status.ok{color:#2e7d32;font-weight:600}' +
    // Input
    '.cfoot{flex-shrink:0}' +
    '.inbar{display:flex;gap:8px;padding:11px 12px;border-top:1px solid var(--line);background:#fff;align-items:center}' +
    '.inbar .clip{background:none;border:none;color:var(--muted);cursor:pointer;padding:6px;display:flex;flex-shrink:0}' +
    '.inbar input{flex:1;padding:11px 15px;border:1px solid var(--line);border-radius:999px;font-size:14px;background:var(--paper)}' +
    '.inbar input:focus{outline:none;border-color:var(--bright)}' +
    '.inbar .snd{background:linear-gradient(135deg,var(--yellow),var(--yellow2));color:var(--ink);border:none;border-radius:50%;width:44px;height:44px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(255,197,49,.45)}' +
    '.inbar .snd:active{transform:scale(.94)}.inbar .snd:disabled{opacity:.5;cursor:default;box-shadow:none}' +
    '.hp{position:absolute;left:-9999px}' +
    '@keyframes msgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}' +
    '@keyframes dot{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-5px);opacity:1}}' +
    '@media (prefers-reduced-motion: reduce){' +
    '.msg,.card,.typing,.status,.cw-teaser,.launcher .l-dot{animation:none}.typing span{animation:none}' +
    '.opt,.faq,.primary,.gbtn,.snd,.launcher{transition:none}.clog{scroll-behavior:auto}}' +
    '</style>' +
    '<div class="cw-dock">' +
    '<div class="cw-teaser" hidden><button class="cw-x" type="button" aria-label="' + esc(t('close')) + '">×</button><span class="cw-teaser-tx"></span></div>' +
    '<button class="launcher" type="button"></button>' +
    '</div>' +
    '<div class="panel"></div>';

  var dock = root.querySelector('.cw-dock');
  var teaser = root.querySelector('.cw-teaser');
  var launcher = root.querySelector('.launcher');
  var panel = root.querySelector('.panel');

  // Separacion inferior configurable del lanzador (dentro del shadow-root).
  // Se aplica con translateY sobre .cw-dock (el elemento con position:fixed que ancla
  // el lanzador en la esquina), NO con 'bottom': asi funciona aunque un ancestro de la
  // web anfitriona (p. ej. un tema de WordPress con transform/filter) altere el bloque
  // contenedor del fixed y 'bottom' deje de surtir efecto. La base CSS del dock es
  // bottom:20px; se sube (OFFSET_BOTTOM - 20) px para quedar a OFFSET_BOTTOM del borde.
  // La ventana abierta (.panel) es HERMANA del dock, no un hijo: no se ve afectada.
  var DOCK_BASE_BOTTOM = 20;
  dock.style.transform = 'translateY(' + (DOCK_BASE_BOTTOM - OFFSET_BOTTOM) + 'px)';
  launcher.setAttribute('aria-label', t('launcher'));
  launcher.innerHTML =
    '<span class="l-ico">' + icon('messageCircle') + '</span>' +
    '<span class="l-tx"><span class="l-main">' + esc(t('launcher')) + '</span>' +
    '<span class="l-sub"><span class="l-dot"></span><span class="l-online">' + esc(t('online')) + '</span></span></span>';
  teaser.querySelector('.cw-teaser-tx').textContent = t('teaser');

  // Teaser: aparece a los ~4s (si no se abrió el chat ni se descartó); no reaparece
  // en la sesión (estado en memoria del widget, siguiendo el patrón sin persistencia).
  teaser.querySelector('.cw-x').addEventListener('click', function () { state.teaserDone = true; teaser.setAttribute('hidden', ''); });
  setTimeout(function () {
    if (!state.teaserDone && panel && !panel.classList.contains('open')) { teaser.removeAttribute('hidden'); }
  }, 4000);

  // Refresca los textos del lanzador/teaser al cambiar de idioma (ES/EN).
  function refreshLauncherTexts() {
    var lm = launcher.querySelector('.l-main'); if (lm) { lm.textContent = t('launcher'); }
    var lo = launcher.querySelector('.l-online'); if (lo) { lo.textContent = t('online'); }
    launcher.setAttribute('aria-label', t('launcher'));
    var tt = teaser.querySelector('.cw-teaser-tx'); if (tt) { tt.textContent = t('teaser'); }
    var tx = teaser.querySelector('.cw-x'); if (tx) { tx.setAttribute('aria-label', t('close')); }
  }

  function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; }
  function smoothScroll(c) { if (!c) { return; } try { c.scrollTo({ top: c.scrollHeight, behavior: REDUCE ? 'auto' : 'smooth' }); } catch (e) { c.scrollTop = c.scrollHeight; } }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // Enlaces del propio sitio de MCA y correos (mailto) -> misma pestana (_self);
  // externos -> nueva (_blank).
  function isMcaUrl(u) { return /mcaschool\.education/i.test(String(u || '')); }
  function sameTab(u) { u = String(u || ''); return u.indexOf('mailto:') === 0 || isMcaUrl(u); }
  function linkTarget(u) { return sameTab(u) ? '_self' : '_blank'; }
  function bubbleHTML(text) {
    return esc(text).replace(/(https?:\/\/[^\s<]+)/g, function (url) {
      var trail = ''; var m = url.match(/[).,;!?]+$/);
      if (m) { trail = m[0]; url = url.slice(0, -trail.length); }
      return '<a href="' + url + '" target="' + linkTarget(url) + '" rel="noopener">' + url + '</a>' + trail;
    });
  }
  function pressThen(btn, cb) { if (REDUCE) { cb(); return; } btn.classList.add('pressed'); setTimeout(cb, 140); }

  // --- Apertura / cierre ---
  function open() {
    panel.classList.add('open'); dock.style.display = 'none'; state.teaserDone = true;
    loadSession();
    showWelcome();
  }
  // Sesion en segundo plano: captura, avatar y nombre del asesor. El idioma lo elige
  // el usuario (selector ES/EN); las peticiones ya viajan con ?lang.
  function loadSession() {
    api('/session', 'POST', { session_id: state.sessionId }).then(function (r) {
      state.sessionId = r.session_id; localStorage.setItem(LS_KEY, r.session_id);
      state.captured = !!r.contact_captured;
      if (r.bot && r.bot.assistant_name) { state.assistant = r.bot.assistant_name; }
      state.avatarUrl = (r.bot && r.bot.avatar_url) ? r.bot.avatar_url : null;
      state.node = r.node || null;
      state.sessionReady = true;
      if (state.screen === 'chat') { renderChatEntry(); }
    });
  }
  function closePanel() { panel.classList.remove('open'); dock.style.display = 'flex'; }

  // --- Pantalla de bienvenida ---
  function showWelcome() {
    state.screen = 'welcome'; state.celia = false;
    var faqRows = FAQ.map(function (f) {
      return '<button class="faq" data-node="' + f.node + '"><span class="lead">' + icon(f.ic) + '</span><span class="lbl">' + esc(f[state.lang] || f.es) + '</span><span class="ch">' + icon('chevronRight') + '</span></button>';
    }).join('');
    panel.innerHTML =
      '<div class="hero">' +
      '<div class="hrow"><div class="org"><span class="av">' + icon('gradCap') + '</span><b>' + esc(t('org')) + '</b></div>' +
      '<div class="hactions"><button class="iconbtn lang" data-a="lang" title="ES / EN">' + (state.lang === 'es' ? 'EN' : 'ES') + '</button>' +
      '<button class="iconbtn" data-a="min">' + icon('minus') + '</button><button class="iconbtn" data-a="close">' + icon('x') + '</button></div></div>' +
      '<div class="hgreet">' + esc(t('wgreet')) + '</div><div class="hsub">' + esc(t('wsub')) + '</div>' +
      '<div class="glass"><div><div class="gt">' + esc(t('cardTitle')) + '</div><div class="gs">' + esc(t('cardSub')) + '</div></div>' +
      '<button class="gbtn" data-a="chat">' + icon('send') + '</button></div>' +
      '</div>' +
      '<div class="wbody"><div class="faqh">' + esc(t('faqTitle')) + '</div>' + faqRows + '</div>' +
      '<div class="tabs"><button class="tab active" data-a="home">' + icon('home') + '<span>' + esc(t('tabHome')) + '</span></button>' +
      '<button class="tab" data-a="chat">' + icon('messageCircle') + '<span>' + esc(t('tabChat')) + '</span></button></div>' +
      '<div class="foot">' + esc(t('footer')) + '</div>';

    panel.querySelectorAll('[data-a]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-a');
        if (a === 'close' || a === 'min') { closePanel(); }
        else if (a === 'lang') { state.lang = state.lang === 'es' ? 'en' : 'es'; refreshLauncherTexts(); showWelcome(); }
        else if (a === 'chat') { enterChat(null); }
        else if (a === 'home') { /* ya estamos en inicio */ }
      });
    });
    panel.querySelectorAll('.faq').forEach(function (f) {
      f.addEventListener('click', function () { pressThen(f, function () { enterChat(f.getAttribute('data-node')); }); });
    });
  }

  // --- Pantalla de conversacion ---
  function enterChat(pendingTopic) {
    state.screen = 'chat'; state.pendingTopic = pendingTopic || null;
    buildChatShell();
    // Si la sesion aun no llego, se pintara al resolver /session (ver loadSession()).
    if (state.sessionReady) { renderChatEntry(); } else { typing(); }
  }

  function buildChatShell() {
    panel.innerHTML =
      '<div class="chead"><button class="iconbtn" data-a="back">' + icon('chevronLeft') + '</button>' +
      '<span class="av"></span>' +
      '<div class="nm"><b class="cname"></b><span class="crole"></span></div>' +
      '<button class="iconbtn" data-a="close">' + icon('x') + '</button></div>' +
      '<div class="clog"></div><div class="cfoot"></div>';
    state.clog = panel.querySelector('.clog');
    state.cfoot = panel.querySelector('.cfoot');
    state.lastRole = null; state.celia = false; state.identity = 'institution'; state.cin = null; state.csend = null;
    panel.querySelector('[data-a="back"]').addEventListener('click', showWelcome);
    panel.querySelector('[data-a="close"]').addEventListener('click', closePanel);
    setChatIdentity();
  }

  // Identidad del encabezado y del emisor. En modo botones (bot guiado, sin IA) es
  // la INSTITUCION; solo tras "Hablar con Celia" pasa a ser Celia.
  function setChatIdentity() {
    var isCelia = state.identity === 'celia';
    var av = panel.querySelector('.chead .av'); if (av) { av.innerHTML = isCelia ? avatarInner() : icon('gradCap'); }
    var nm = panel.querySelector('.chead .cname'); if (nm) { nm.textContent = isCelia ? state.assistant : t('org'); }
    var role = panel.querySelector('.chead .crole'); if (role) { role.textContent = isCelia ? t('role') : t('instRole'); }
  }
  function chatAvatar() { return state.identity === 'celia' ? avatarInner() : icon('gradCap'); }
  function botName() { return state.identity === 'celia' ? state.assistant : t('orgShort'); }

  function renderChatEntry() {
    setChatIdentity();
    if (!state.captured) { showCapture(state.node); }
    else { renderNode(state.node); }
  }

  function clearLog() { if (state.clog) { state.clog.innerHTML = ''; } state.lastRole = null; if (state.cfoot) { state.cfoot.innerHTML = ''; } state.celia = false; state.cin = null; state.csend = null; }
  function typingEl() { return el('<div class="typing"><span></span><span></span><span></span></div>'); }
  function typing() { clearLog(); state.clog.appendChild(typingEl()); smoothScroll(state.clog); }
  function logTyping() { var tp = typingEl(); tp.classList.add('tp'); state.clog.appendChild(tp); smoothScroll(state.clog); }
  function clearTyping() { var tp = state.clog.querySelector('.tp'); if (tp) { tp.parentNode.removeChild(tp); } }

  // Burbuja con nombre del emisor y avatar (avatar solo en el 1er mensaje del bloque).
  function logBubble(text, who) {
    var isUser = who === 'user';
    var turn = el('<div class="turn ' + (isUser ? 'user' : 'celia') + '"></div>');
    var label = el('<div class="who"></div>');
    label.textContent = isUser ? (state.userName || t('you')) : botName();
    var bubble = el('<div class="msg"></div>');
    bubble.innerHTML = bubbleHTML(text);
    turn.appendChild(label);
    if (isUser) { turn.appendChild(bubble); }
    else {
      var row = el('<div class="crow"></div>');
      var firstOfBlock = state.lastRole !== 'celia';
      var av = el('<span class="av-s' + (firstOfBlock ? '' : ' ph') + '"></span>');
      av.innerHTML = firstOfBlock ? chatAvatar() : '';
      row.appendChild(av); row.appendChild(bubble); turn.appendChild(row);
    }
    state.clog.appendChild(turn); state.lastRole = isUser ? 'user' : 'celia'; smoothScroll(state.clog);
  }

  // label + onClick + index (para el escalonado) + iconName (izq, suelto, sin caja;
  // null = sin icono) + chev (chevron-right discreto a la derecha).
  function optionButton(label, onClick, index, iconName, chev) {
    var b = el('<button class="opt"></button>');
    var html = iconName ? '<span class="lead">' + icon(iconName) + '</span>' : '';
    html += '<span class="lbl"></span>';
    if (chev) { html += '<span class="ch">' + icon('chevronRight') + '</span>'; }
    b.innerHTML = html;
    b.querySelector('.lbl').textContent = label;
    if (!REDUCE) { b.style.animation = 'msgIn .3s ease both'; b.style.animationDelay = (70 + (index || 0) * 65) + 'ms'; b.addEventListener('animationend', function () { b.style.animation = ''; }); }
    b.addEventListener('click', function () { pressThen(b, onClick); });
    return b;
  }

  // --- Captura (nombre + correo + consentimiento). Logica intacta. ---
  function showCapture(nextNode) {
    clearLog();
    if (nextNode && nextNode.content) { logBubble(nextNode.content, 'celia'); }
    var form = el('<div style="padding:4px 2px"></div>');
    form.innerHTML =
      '<div class="field"><label>' + esc(t('name')) + '</label><input type="text" id="c_name"></div>' +
      '<div class="field"><label>' + esc(t('email')) + '</label><input type="email" id="c_email"></div>' +
      '<label class="chk"><input type="checkbox" id="c_consent"><span>' + esc(t('consent')) + '</span></label>' +
      '<input class="hp" id="c_hp" tabindex="-1" autocomplete="off">' +
      '<button class="primary" id="c_go">' + esc(t('start')) + '</button><div class="err" id="c_err"></div>';
    state.clog.appendChild(form); smoothScroll(state.clog);
    form.querySelector('#c_go').addEventListener('click', function () {
      var name = form.querySelector('#c_name').value.trim();
      var email = form.querySelector('#c_email').value.trim();
      var consent = form.querySelector('#c_consent').checked;
      var hp = form.querySelector('#c_hp').value;
      if (!name || !email || !consent) { form.querySelector('#c_err').textContent = '*'; return; }
      state.userName = name;
      typing();
      paced(api('/lead', 'POST', { session_id: state.sessionId, name: name, email: email, consent: consent, website: hp })).then(function (r) {
        if (r.contact_captured) { state.captured = true; loadMenu(); }
        else { showCapture(nextNode); }
      });
    });
  }

  function loadMenu() {
    typing();
    paced(api('/session', 'POST', { session_id: state.sessionId })).then(function (r) { renderNode(r.node); });
  }

  function renderNode(node) {
    clearLog();
    if (!node) { return; }
    if (node.content) { logBubble(node.content, 'celia'); }
    var opts = node.options || [];
    opts.forEach(function (opt, idx) {
      state.clog.appendChild(optionButton(opt.label, function () { onOption(opt); }, idx, optionIcon(opt), true));
    });
    smoothScroll(state.clog);
    // Deep-link desde una pregunta frecuente de la bienvenida.
    if (state.pendingTopic && opts.length) {
      var match = opts.filter(function (o) { return o.target_key === state.pendingTopic; })[0];
      state.pendingTopic = null;
      if (match) { onOption(match); }
    }
  }

  function onOption(opt) {
    // Enlace del propio sitio de MCA o correo (mailto): MISMA pestana. Se registra el
    // evento (navigate) y luego se navega, para no perder el rastro CRM.
    if (opt.action === 'external_link' && opt.url && sameTab(opt.url)) {
      api('/navigate', 'POST', { session_id: state.sessionId, option_id: opt.id }).then(function () { window.location.href = opt.url; });
      return;
    }
    if (opt.action === 'external_link' && opt.url) { window.open(opt.url, '_blank', 'noopener'); }
    typing();
    paced(api('/navigate', 'POST', { session_id: state.sessionId, option_id: opt.id })).then(function (r) {
      if (r.action === 'start_matcher') { return startMatcher(); }
      if (r.action === 'start_celia') { return startCelia(); }
      if (r.action === 'external_link') { return loadMenu(); }
      renderNode(r.node);
    });
  }

  // --- Modo Celia (chat con IA). Logica y enrutamiento intactos. ---
  function startCelia() {
    clearLog();
    state.celia = true; state.identity = 'celia'; setChatIdentity();
    state.cfoot.innerHTML =
      '<div class="inbar"><button class="clip" title="">' + icon('paperclip') + '</button>' +
      '<input type="text" id="c_in" placeholder="' + esc(t('ask')) + '"><button class="snd" id="c_send">' + icon('send') + '</button></div>';
    state.cin = state.cfoot.querySelector('#c_in'); state.csend = state.cfoot.querySelector('#c_send');
    var clip = state.cfoot.querySelector('.clip');
    if (clip) { clip.addEventListener('click', function () { if (state.cin) { state.cin.focus(); } }); }
    function doSend() { var m = state.cin.value.trim(); if (!m) { return; } state.cin.value = ''; sendCelia(m); }
    state.csend.addEventListener('click', doSend);
    state.cin.addEventListener('keydown', function (e) { if (e.key === 'Enter') { doSend(); } });

    var req = api('/celia/start', 'POST', { session_id: state.sessionId });
    inputDisabled(true);
    celiaIntro(req);
  }

  // Secuencia de disponibilidad (solo visual): verificando -> disponible -> saludo.
  function celiaIntro(req) {
    var status = el('<div class="status"><div class="typing"><span></span><span></span><span></span></div><span class="statustxt"></span></div>');
    status.querySelector('.statustxt').textContent = t('checking');
    state.clog.appendChild(status); smoothScroll(state.clog);
    var d1 = REDUCE ? 0 : 2000, d2 = REDUCE ? 0 : 1000;
    wait(d1).then(function () {
      var dots = status.querySelector('.typing'); if (dots) { dots.parentNode.removeChild(dots); }
      status.classList.add('ok');
      status.querySelector('.statustxt').textContent = state.assistant + ' ' + t('available');
      return wait(d2);
    }).then(function () { return req; }).then(function (r) {
      if (status.parentNode) { status.parentNode.removeChild(status); }
      celiaReply(r); inputDisabled(!!(r && r.limit_reached));
      if (state.cin) { state.cin.focus(); }
    });
  }

  function sendCelia(m) {
    logBubble(m, 'user'); inputDisabled(true); logTyping();
    paced(api('/celia', 'POST', { session_id: state.sessionId, message: m, website: '' })).then(function (r) {
      celiaReply(r); inputDisabled(!!r.limit_reached);
    });
  }

  function celiaReply(r) {
    clearTyping();
    if (r && r.reply) { logBubble(r.reply, 'celia'); }
    if (r && r.action === 'start_matcher') { return startMatcher(); }
    if (r && r.action === 'buttons' && r.node) { celiaButtons(r.node); }
  }

  function celiaButtons(node) {
    (node.options || []).forEach(function (opt, idx) {
      state.clog.appendChild(optionButton(opt.label, function () { celiaChoose(opt); }, idx, optionIcon(opt), true));
    });
    smoothScroll(state.clog);
  }

  function celiaChoose(opt) {
    if (opt.action === 'external_link' && opt.url && sameTab(opt.url)) {
      api('/navigate', 'POST', { session_id: state.sessionId, option_id: opt.id }).then(function () { window.location.href = opt.url; });
      return;
    }
    if (opt.action === 'external_link' && opt.url) { window.open(opt.url, '_blank', 'noopener'); }
    logTyping();
    paced(api('/navigate', 'POST', { session_id: state.sessionId, option_id: opt.id })).then(function (r) {
      clearTyping();
      if (r.action === 'start_matcher') { return startMatcher(); }
      if (r.node) { if (r.node.content) { logBubble(r.node.content, 'celia'); } celiaButtons(r.node); }
    });
  }

  function inputDisabled(v) { if (state.cin) { state.cin.disabled = v; } if (state.csend) { state.csend.disabled = v; } }

  // --- Emparejador (determinista). Orden y logica intactos. ---
  var Q = ['motivacion', 'meta', 'area', 'seniority', 'educacion'];
  function startMatcher() {
    state.answers = {}; typing();
    paced(api('/matcher-options', 'GET')).then(function (o) { state.options = o; paintQuestion(0); });
  }
  function advanceQuestion(i) { if (i >= Q.length) { return submitMatcher(); } typing(); paced(Promise.resolve()).then(function () { paintQuestion(i); }); }
  function paintQuestion(i) {
    var key = Q[i]; clearLog();
    var pct = Math.round((i / Q.length) * 100);
    state.clog.appendChild(el('<div class="progress"><i style="width:' + pct + '%"></i></div>'));
    state.clog.appendChild(el('<div class="step">' + (i + 1) + '/' + Q.length + '</div>'));
    var qs = (state.options && state.options.questions) || {};
    logBubble(qs[key] || key, 'celia');
    (state.options[key] || []).forEach(function (opt, idx) {
      state.clog.appendChild(optionButton(opt.label, function () { state.answers[key] = opt.value; advanceQuestion(i + 1); }, idx, null, false));
    });
    smoothScroll(state.clog);
  }
  function submitMatcher() {
    typing();
    paced(api('/match', 'POST', { session_id: state.sessionId, answers: state.answers, website: '' })).then(function (r) {
      clearLog();
      state.clog.appendChild(el('<div class="progress"><i style="width:100%"></i></div>'));
      if (!r.programs || !r.programs.length) {
        logBubble(t('none'), 'celia');
        state.clog.appendChild(optionButton(t('launcher'), startCelia, 0, 'sparkles', false));
        smoothScroll(state.clog); return;
      }
      logBubble(t('results'), 'celia');
      r.programs.forEach(function (p) {
        var card = el('<div class="card"></div>');
        card.innerHTML = '<b></b><a target="' + linkTarget(p.url) + '" rel="noopener" href="' + esc(p.url) + '">' + esc(t('see')) + ' ' + icon('chevronRight') + '</a>';
        card.querySelector('b').textContent = p.name;
        state.clog.appendChild(card);
      });
      state.clog.appendChild(optionButton(state.lang === 'es' ? '← Volver al inicio' : '← Back to start', showWelcome, 0, 'home', false));
      smoothScroll(state.clog);
    });
  }

  // --- Eventos globales ---
  launcher.addEventListener('click', open);
})();
