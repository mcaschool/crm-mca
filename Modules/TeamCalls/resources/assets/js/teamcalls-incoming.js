/*
 * TeamCalls · aviso de llamada entrante en CUALQUIER pantalla del CRM (fase 4I, solo donde la interfaz de
 * llamadas está habilitada: desarrollo o una preproducción nombrada).
 *
 * NO es otro sistema de notificaciones: consume el mismo canal que la página de llamadas (CollaborationRealtime
 * por el adaptador de TeamCalls; si no hay tiempo real, la lista «en curso» con sondeo de respaldo) y la misma
 * API. Contestar abre (o reutiliza) la ventana de TeamCalls con answer=1 (teamcalls-launcher.js); rechazar
 * llama a la API existente. La autorización es siempre del servidor.
 *
 *  - Una sola pestaña de la sesión consulta y comparte la lista con las demás (TabCoordinator, el mismo canal
 *    que la página de llamadas). Atender en una pestaña oculta el aviso en todas.
 *  - Si la página de TeamCalls está abierta en este navegador, avisa ella (este aviso se calla).
 *  - Si TeamChat/el widget universal asumen los avisos de llamada, marcan <html data-mca-call-alerts="widget">
 *    y este aviso se calla (sin avisos duplicados; ver docs/TEAMCALLS-TEAMCHAT-INTEGRATION-4I.md).
 *  - Sin credenciales de medios ni almacenamiento del navegador.
 */
(function () {
    'use strict';

    const cfgEl = document.getElementById('tc-incoming-config');
    const Core = window.TeamCallsCore;
    if (!cfgEl || !Core) return;
    const cfg = JSON.parse(cfgEl.textContent);
    const T = cfg.i18n || {};
    const t = (key, vars) => String(T[key] || key).replace(/:(\w+)/g, (m, v) => (vars && vars[v] != null ? vars[v] : m));
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const me = Number(cfg.me.id);
    const tracker = new Core.IncomingTracker();
    const names = new Map();
    const cards = new Map(); // callId → elemento
    let stopped = false;
    let hub = null;

    // ─── API ──────────────────────────────────────────────────────────────────────────────────────
    async function api(method, url) {
        const ctl = new AbortController();
        const timer = setTimeout(() => ctl.abort(), 15000);
        try {
            const res = await fetch(url, {
                method, credentials: 'same-origin', signal: ctl.signal,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            });
            let json = null;
            try { json = await res.json(); } catch (e) { /* sin cuerpo */ }
            if (!res.ok) {
                const err = new Error('http_' + res.status);
                err.code = (json && json.error && json.error.code) || 'http_' + res.status;
                // Sin sesión, o sin acceso a las llamadas: el aviso se detiene (la página se recargará con la sesión nueva).
                if (Core.endsSession(err.code) || (err.code === 'forbidden' && url === cfg.routes.live)) stop();
                throw err;
            }
            return json;
        } finally {
            clearTimeout(timer);
        }
    }
    const route = (name, id) => cfg.routes[name].replace('__CALL__', encodeURIComponent(id));

    async function nameOf(userId) {
        if (names.has(Number(userId))) return names.get(Number(userId));
        try {
            ((await api('GET', cfg.routes.contacts)).data.contacts || []).forEach((c) => names.set(Number(c.id), c.name));
        } catch (e) { /* sin nombres: se usa el genérico */ }
        return names.get(Number(userId)) || t('unknown_user', { id: userId });
    }

    // ─── Coordinación entre pestañas y con la página de llamadas ───────────────────────────────────
    const tabId = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()).slice(0, 18);
    const tabs = new Core.TabCoordinator({
        locks: (navigator.locks && navigator.locks.request) ? navigator.locks : null,
        channel: ('BroadcastChannel' in window) ? new BroadcastChannel('teamcalls:' + me) : null,
        tabId,
        scope: String(me),
    });

    /** ¿Hay una página de TeamCalls abierta en este navegador? Entonces avisa ella. */
    async function callsPageOpen() {
        if (!navigator.locks || !navigator.locks.query) return false;
        try {
            const q = await navigator.locks.query();
            return (q.held || []).some((l) => l.name === 'teamcalls-page:' + me);
        } catch (e) { return false; }
    }

    function deferredToWidget() {
        return document.documentElement.dataset.mcaCallAlerts === 'widget';
    }

    // ─── Lista de llamadas: tiempo real + sondeo de respaldo (solo la pestaña que lidera) ────────
    let leader = false;
    let timer = null;
    let realtime = false;
    const coalescer = new Core.Coalescer();

    function schedule() {
        clearTimeout(timer);
        if (stopped || !leader) return;
        const base = realtime ? cfg.poll.realtimeMs : (document.hidden ? cfg.poll.hiddenMs : cfg.poll.visibleMs);
        timer = setTimeout(() => refresh().finally(schedule), base);
    }

    async function refresh() {
        if (stopped) return;
        try {
            await coalescer.run('live', async () => {
                const calls = (await api('GET', cfg.routes.live)).data || [];
                tabs.send('snapshot', { calls });
                await onSnapshot(calls);
            });
        } catch (e) { /* el siguiente ciclo lo reintenta */ }
    }

    async function onSnapshot(calls) {
        const { show, hide } = tracker.update(calls, me);
        for (const id of hide) {
            removeCard(id);
            missedCheck(id);
        }
        if (!show.length) return;
        if (deferredToWidget() || await callsPageOpen()) return;
        for (const c of show) await showCard(c);
    }

    tabs.onMessage((m) => {
        if (m.type === 'snapshot' && !leader) onSnapshot(m.calls || []);
        if (m.type === 'incoming-handled' && m.callId) {
            tracker.handle(m.callId);
            removeCard(m.callId);
        }
    });

    // ─── Aviso ──────────────────────────────────────────────────────────────────────────────────
    const ICON = {
        phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
        video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
        hangup: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.7 13.3a16 16 0 0 0 3.4 2.6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 3.1 4.2 2 2 0 0 1 5.1 2h3a2 2 0 0 1 2 1.7"/><path d="m22 2-20 20"/></svg>',
    };

    function host() {
        let el = document.getElementById('tci-host');
        if (!el) {
            el = document.createElement('div');
            el.id = 'tci-host';
            el.className = 'tci-host';
            el.setAttribute('aria-live', 'assertive');
            document.body.appendChild(el);
        }
        return el;
    }

    const initials = (name) => String(name).split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';

    async function showCard(summary) {
        if (cards.has(summary.id)) return;
        const caller = await nameOf(summary.initiator_id);
        if (!tracker.shown.has(summary.id)) return; // dejó de sonar mientras se buscaba el nombre
        const video = summary.media === 'video';
        const el = document.createElement('div');
        el.className = 'tci-card';
        el.setAttribute('role', 'alertdialog');
        el.setAttribute('aria-labelledby', 'tci-title-' + summary.id);
        el.innerHTML = '<span class="tci-av" aria-hidden="true"></span>'
            + '<span class="tci-text"><strong class="tci-name"></strong><span class="tci-kind"></span></span>'
            + '<span class="tci-acts"><button type="button" class="tci-btn tci-btn--no"></button><button type="button" class="tci-btn tci-btn--ok"></button></span>';
        el.querySelector('.tci-av').textContent = initials(caller);
        el.querySelector('.tci-name').id = 'tci-title-' + summary.id;
        el.querySelector('.tci-name').textContent = t('incoming_from', { name: caller });
        el.querySelector('.tci-kind').textContent = t(video ? 'incoming_video' : 'incoming_audio');
        const no = el.querySelector('.tci-btn--no');
        const ok = el.querySelector('.tci-btn--ok');
        no.innerHTML = ICON.hangup + '<span>' + t('decline') + '</span>';
        ok.innerHTML = (video ? ICON.video : ICON.phone) + '<span>' + t('accept') + '</span>';
        no.addEventListener('click', () => decline(summary.id));
        ok.addEventListener('click', () => accept(summary.id));
        host().appendChild(el);
        cards.set(summary.id, el);
        ring(true);
        flashTitle(true);
        ok.focus({ preventScroll: true });
    }

    function removeCard(id) {
        const el = cards.get(id);
        if (el) el.remove();
        cards.delete(id);
        if (!cards.size) { ring(false); flashTitle(false); }
    }

    /** Aceptar: la ventana de TeamCalls contesta (answer=1). Debe ocurrir dentro del clic (ventanas emergentes). */
    function accept(id) {
        tracker.handle(id);
        tabs.send('incoming-handled', { callId: id, outcome: 'accepted' });
        removeCard(id);
        try {
            if (window.MCACalls) window.MCACalls.open({ call: id, answer: true });
            else window.location.assign(cfg.page + '?call=' + encodeURIComponent(id) + '&answer=1');
        } catch (e) {
            window.location.assign(cfg.page + '?call=' + encodeURIComponent(id) + '&answer=1');
        }
    }

    async function decline(id) {
        tracker.handle(id);
        tabs.send('incoming-handled', { callId: id, outcome: 'declined' });
        removeCard(id);
        try { await api('POST', route('decline', id)); } catch (e) { /* ya no sonaba */ }
    }

    /** Dejó de sonar sin atenderla aquí: si fue perdida o cancelada, aviso breve con «Devolver llamada». */
    async function missedCheck(id) {
        if (tracker.handled.has(id)) return;
        let s = null;
        try { s = (await api('GET', route('show', id))).data; } catch (e) { return; }
        const mine = (s.participants || []).find((p) => Number(p.user_id) === me);
        if (!mine || !['missed', 'cancelled'].includes(mine.state)) return;
        if (deferredToWidget() || await callsPageOpen()) return;
        const caller = await nameOf(s.initiator_id);
        const el = document.createElement('div');
        el.className = 'tci-card tci-card--missed';
        el.setAttribute('role', 'status');
        el.innerHTML = '<span class="tci-text"><strong class="tci-name"></strong><span class="tci-kind"></span></span>'
            + '<span class="tci-acts"><button type="button" class="tci-btn tci-btn--plain"></button><button type="button" class="tci-btn tci-btn--ok"></button></span>';
        el.querySelector('.tci-name').textContent = t('missed_from', { name: caller });
        el.querySelector('.tci-kind').textContent = t(s.media === 'video' ? 'incoming_video' : 'incoming_audio');
        const close = el.querySelector('.tci-btn--plain');
        close.textContent = t('dismiss');
        close.addEventListener('click', () => el.remove());
        const back = el.querySelector('.tci-btn--ok');
        back.innerHTML = (s.media === 'video' ? ICON.video : ICON.phone) + '<span>' + t('call_back') + '</span>';
        back.addEventListener('click', () => {
            el.remove();
            const intent = { media: s.media, user: Number(s.initiator_id) };
            if (window.MCACalls) window.MCACalls.open(intent);
        });
        host().appendChild(el);
        setTimeout(() => el.remove(), 30000);
    }

    // ─── Tono y título (discretos; el navegador puede impedir el sonido sin interacción previa) ───
    let ringTimer = null;
    let audio = null;
    function ring(on) {
        clearInterval(ringTimer);
        if (!on || !cfg.ringtone) return;
        const beep = () => {
            try {
                audio = audio || new (window.AudioContext || window.webkitAudioContext)();
                if (audio.state === 'suspended') audio.resume().catch(() => {});
                [0, 0.35].forEach((offset) => {
                    const o = audio.createOscillator();
                    const g = audio.createGain();
                    o.frequency.value = 660;
                    g.gain.setValueAtTime(0.0001, audio.currentTime + offset);
                    g.gain.exponentialRampToValueAtTime(0.08, audio.currentTime + offset + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + offset + 0.25);
                    o.connect(g).connect(audio.destination);
                    o.start(audio.currentTime + offset);
                    o.stop(audio.currentTime + offset + 0.3);
                });
            } catch (e) { clearInterval(ringTimer); }
        };
        beep();
        ringTimer = setInterval(beep, 3000);
    }

    const baseTitle = document.title;
    let titleTimer = null;
    function flashTitle(on) {
        clearInterval(titleTimer);
        document.title = baseTitle;
        if (!on) return;
        let alt = false;
        titleTimer = setInterval(() => { alt = !alt; document.title = alt ? '📞 ' + t('status_incoming') : baseTitle; }, 1500);
    }

    function stop() {
        stopped = true;
        clearTimeout(timer);
        cards.forEach((el, id) => removeCard(id));
        if (hub) hub.disconnect();
    }

    // ─── Arranque ───────────────────────────────────────────────────────────────────────────────
    hub = new Core.SignalHub({
        userId: me,
        handlers: {
            ringing: () => refresh(),
            answeredElsewhere: (callId) => { tracker.handle(callId); removeCard(callId); },
            refresh: () => refresh(),
            resync: () => refresh(),
            status: () => { realtime = Boolean(hub && hub.realtime); schedule(); },
        },
    });
    const rt = cfg.realtime && cfg.realtime.socket && cfg.realtime.socket.key && window.TeamCallsRealtimeAdapter
        ? window.TeamCallsRealtimeAdapter.createAdapter({ api: cfg.realtime.api, csrf, socket: cfg.realtime.socket }) : null;
    if (rt) hub.connect(rt);
    tabs.pollLeadership(() => { leader = true; refresh().finally(schedule); });
    document.addEventListener('visibilitychange', () => { if (leader && !document.hidden) refresh(); });

    // Solo lectura, para pruebas automatizadas y depuración.
    window.teamcallsIncoming = { state: () => ({ leader, realtime, stopped, shown: [...tracker.shown.keys()], cards: [...cards.keys()] }) };
})();
