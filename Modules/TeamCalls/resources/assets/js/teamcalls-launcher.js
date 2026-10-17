/*
 * TeamCalls · lanzador de llamadas para otras aplicaciones de MCA Colaboración (fase 4E, solo desarrollo).
 *
 * TeamChat, el widget universal o cualquier vista del panel lo cargan (teamcalls::partials.launcher) y
 * abren la interfaz de llamadas con una INTENCIÓN: llamada de voz o video con una persona, desde una
 * conversación privada, con invitación explícita desde un grupo o canal, o abrir una llamada existente.
 *
 * - No llama a ninguna API de comandos ni toca medios: abre (o reutiliza) la ventana «mca-teamcalls», que
 *   ejecuta la intención con la API existente. La autorización es SIEMPRE del servidor (CallsGateway).
 * - Si la ventana de llamadas ya está abierta, le pasa la intención sin recargarla (recargar colgaría la
 *   llamada en curso); si no, la abre en la URL de la intención (CallLaunch en el servidor).
 * - Sin secretos, sin almacenamiento del navegador y sin datos de la persona.
 *
 * API (window.MCACalls):
 *   available            true cuando el lanzador está cargado (y la interfaz habilitada en el servidor).
 *   url(intent)          URL de la interfaz con la intención validada.
 *   open(intent)         abre o reutiliza la ventana; llamarlo DENTRO del clic (bloqueo de ventanas emergentes).
 *   active(conversation) Promise con la llamada viva de una conversación ({id, media, …}) o null.
 *   normalize(intent)    validación (mismas reglas que el servidor); lanza TypeError si no es válida.
 * intent: {media:'audio'|'video', user:42} | {media, conversation:'direct:<id>'|{type:'direct', id}}
 *       | {media, conversation:'group:<id>'|'channel:<id>', invitees:[3, 4]} | {call:'<ulid>'}; key opcional.
 *
 * Botones declarativos (sin JS propio): data-mca-call="audio|video|open" y data-mca-call-user,
 * data-mca-call-conversation, data-mca-call-invitees ("3,4") o data-mca-call-id. Eventos que burbujean:
 * mca-call:before (cancelable; detail.intent se puede completar, p. ej. con la selección de invitados),
 * mca-call:opened y mca-call:error. Al cargar: <html data-mca-calls="on"> y el evento mca-calls:ready.
 */
(function (root) {
    'use strict';

    const WINDOW_NAME = 'mca-teamcalls';
    const MAX_INVITEES = 24;
    const RE = {
        id: /^[1-9]\d{0,18}$/,
        key: /^[A-Za-z0-9._:-]{8,48}$/,
        call: /^[0-9A-HJKMNP-TV-Z]{26}$/i,
        conversation: /^(direct|group|channel):([A-Za-z0-9_-]{1,64})$/,
    };

    function fail(what) {
        throw new TypeError('MCACalls: ' + what);
    }

    function newKey(cryptoApi) {
        const raw = cryptoApi && cryptoApi.randomUUID ? cryptoApi.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
        return ('l-' + raw).replace(/[^A-Za-z0-9._:-]/g, '').slice(0, 48);
    }

    /** Valida y normaliza una intención con las MISMAS reglas que CallLaunch (servidor). */
    function normalize(intent, cryptoApi) {
        if (!intent || typeof intent !== 'object') fail('intent');
        const given = (k) => intent[k] !== undefined && intent[k] !== null && intent[k] !== '';
        if (given('call')) {
            if (['media', 'user', 'conversation', 'invitees', 'key'].some(given)) fail('call excludes other fields');
            const call = String(intent.call);
            if (!RE.call.test(call)) fail('call');
            // answer (fase 4I): contestar al abrir, p. ej. desde el aviso de llamada entrante de cualquier pantalla.
            return intent.answer ? { call, answer: '1' } : { call }; // tal cual: ULID en minúsculas
        }
        if (given('answer')) fail('answer only opens an existing call');
        if (intent.media !== 'audio' && intent.media !== 'video') fail('media');
        if (given('user') === given('conversation')) fail('user xor conversation');
        const out = { media: intent.media };
        if (given('user')) {
            if (given('invitees')) fail('invitees only for groups and channels');
            out.user = String(intent.user);
            if (!RE.id.test(out.user)) fail('user');
        } else {
            const c = intent.conversation;
            const ref = typeof c === 'string' ? c : String(c.type) + ':' + String(c.id);
            const m = RE.conversation.exec(ref);
            if (!m) fail('conversation');
            out.conversation = ref;
            if (m[1] === 'direct') {
                if (given('invitees')) fail('direct conversations resolve the peer on the server');
            } else {
                const raw = Array.isArray(intent.invitees) ? intent.invitees : String(intent.invitees || '').split(',');
                const list = [...new Set(raw.map((x) => String(x).trim()).filter(Boolean))];
                if (!list.length) fail('invitees required for groups and channels');
                if (list.length > MAX_INVITEES || !list.every((x) => RE.id.test(x))) fail('invitees');
                out.invitees = list.join(',');
            }
        }
        out.key = given('key') ? String(intent.key) : newKey(cryptoApi);
        if (!RE.key.test(out.key)) fail('key');
        return out;
    }

    /** Intención desde los atributos data-mca-call-* de un elemento. */
    function intentFromElement(el) {
        const d = el.dataset || {};
        const kind = d.mcaCall;
        if (kind === 'open') return { call: d.mcaCallId };
        const intent = { media: kind };
        if (d.mcaCallUser) intent.user = d.mcaCallUser;
        if (d.mcaCallConversation) intent.conversation = d.mcaCallConversation;
        if (d.mcaCallInvitees) intent.invitees = d.mcaCallInvitees;
        return intent;
    }

    function create(env) {
        const win = env.window;
        const doc = env.document;
        const pageUrl = env.page;
        const activeUrl = env.active;

        function url(intent) {
            const q = normalize(intent, win.crypto);
            return pageUrl + (pageUrl.includes('?') ? '&' : '?') + new URLSearchParams(q).toString();
        }

        function open(intent) {
            const q = normalize(intent, win.crypto);
            const target = pageUrl + (pageUrl.includes('?') ? '&' : '?') + new URLSearchParams(q).toString();
            let w = null;
            try { w = win.open('', WINDOW_NAME); } catch (e) { w = null; }
            if (w) {
                let reused = false;
                try {
                    // Solo una página de llamadas del MISMO origen expone launch(); cualquier otra cosa se navega.
                    if (w.teamcallsUi && typeof w.teamcallsUi.launch === 'function' && w.location.origin === win.location.origin) {
                        w.teamcallsUi.launch(q);
                        reused = true;
                    }
                } catch (e) { reused = false; }
                if (!reused) w.location.href = target;
                try { w.focus(); } catch (e) { /* el navegador decide */ }
                return { opened: true, reused, url: target };
            }
            // Ventanas emergentes bloqueadas: en una página normal se navega; dentro de un marco (widget) no.
            if (win.top === win.self) {
                win.location.assign(target);
                return { opened: true, reused: false, navigated: true, url: target };
            }
            return { opened: false, reused: false, url: target };
        }

        /** Llamada viva de una conversación (API definitiva). null si no hay, o sin acceso (p. ej. en el widget). */
        async function active(conversation) {
            const ref = typeof conversation === 'string' ? conversation : String(conversation.type) + ':' + String(conversation.id);
            const m = RE.conversation.exec(ref);
            if (!m) fail('conversation');
            try {
                const res = await win.fetch(activeUrl + '?' + new URLSearchParams({ type: m[1], id: m[2] }).toString(), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) return null;
                const json = await res.json();
                return (json && json.data) || null;
            } catch (e) {
                return null;
            }
        }

        function emit(el, name, detail, cancelable) {
            const ev = new win.CustomEvent(name, { bubbles: true, cancelable: Boolean(cancelable), detail });
            return el.dispatchEvent(ev) ? ev : null;
        }

        function onClick(ev) {
            const el = ev.target && ev.target.closest ? ev.target.closest('[data-mca-call]') : null;
            if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
            ev.preventDefault();
            const before = emit(el, 'mca-call:before', { intent: intentFromElement(el) }, true);
            if (!before) return; // la aplicación lo canceló (p. ej. para pedir antes la selección de invitados)
            try {
                emit(el, 'mca-call:opened', open(before.detail.intent));
            } catch (e) {
                emit(el, 'mca-call:error', { error: e });
            }
        }

        const api = { available: true, url, open, active, normalize: (i) => normalize(i, win.crypto) };
        if (doc) {
            doc.addEventListener('click', onClick);
            if (doc.documentElement) doc.documentElement.setAttribute('data-mca-calls', 'on');
        }
        return api;
    }

    root.MCACallsLauncher = { create, normalize, intentFromElement };

    // En el navegador: configuración del propio <script> (rutas que publica el servidor).
    const script = root.document && root.document.currentScript;
    if (script && !root.MCACalls) {
        const page = script.dataset.page || new URL('./', script.src).href.replace(/\/$/, '');
        const activeUrl = script.dataset.active || new URL('/api/v1/collaboration/calls/active', script.src).href;
        root.MCACalls = create({ window: root, document: root.document, page, active: activeUrl });
        root.document.dispatchEvent(new root.CustomEvent('mca-calls:ready', { detail: root.MCACalls }));
    }
})(typeof window !== 'undefined' ? window : globalThis);
