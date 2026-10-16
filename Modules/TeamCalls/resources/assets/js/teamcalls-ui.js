/*
 * TeamCalls · interfaz web de llamadas (fase 3C, solo desarrollo).
 *
 * - Llamadas: SOLO la API existente de TeamCalls (sesión del panel, CSRF, 2FA e institución). La página
 *   nunca elige sala, identidad ni institución: pide credenciales para una llamada y el backend decide.
 * - Medios: SOLO el SDK web oficial de LiveKit (window.LivekitClient). Sin WebRTC ni señalización propios.
 * - Reglas de la fase 3B:
 *     · la credencial entra a la sala SIN medios; se publica solo cuando LiveKit confirma los permisos
 *       que concede el backend (ParticipantPermissionsChanged);
 *     · si la sala se elimina (rotación tras una expulsión) o se pierde la conexión: se consulta la
 *       llamada, se piden credenciales NUEVAS y se entra a la sala actual (nunca se reutiliza un token);
 *     · las interrupciones cortas las recupera el SDK (Reconnecting/Reconnected) y el motor respeta su
 *       margen de reconexión: la página no cuelga por un corte de red.
 * - Fase 3D: una sola pestaña de la sesión controla la llamada y una sola sondea (TabCoordinator); el
 *   sondeo es TEMPORAL y adaptativo (pestaña oculta, llamada, fallos) y queda como respaldo cuando
 *   CollaborationRealtime entregue eventos (window.teamcallsUi.useRealtime(adaptador), ver SignalHub en
 *   teamcalls-core.js). Al perder la sesión se liberan cámara y micrófono. Ninguna credencial sale de
 *   la conexión de LiveKit: ni almacenamiento del navegador ni mensajes entre pestañas.
 */
(function () {
    'use strict';

    const LK = window.LivekitClient;
    const cfg = JSON.parse(document.getElementById('tc-config').textContent);
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const T = cfg.i18n || {};
    const $ = (id) => document.getElementById(id);
    const t = (key, vars) => String(T[key] || key).replace(/:(\w+)/g, (m, v) => (vars && vars[v] != null ? vars[v] : m));
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const LIVE = ['initiating', 'ringing', 'connecting', 'active'];

    const ICON = {
        phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
        video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
        videoOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 16v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.7 0H14a2 2 0 0 1 2 2v3.3l1 1L22 7v10"/><path d="m2 2 20 20"/></svg>',
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/></svg>',
        micOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2 2 20 20M18.9 13.1A7 7 0 0 0 19 11v-1M5 10v1a7 7 0 0 0 11.4 5.4M15 9.3V5a3 3 0 0 0-5.7-1.3M9 9v2a3 3 0 0 0 5.1 2.1M12 18v4"/></svg>',
        hangup: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.7 13.3a16 16 0 0 0 3.4 2.6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 3.1 4.2 2 2 0 0 1 5.1 2h3a2 2 0 0 1 2 1.7"/><path d="m22 2-20 20"/></svg>',
        screen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4M12 7v6M9 10l3-3 3 3"/></svg>',
        people: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>',
        expand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>',
        grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        speaker: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="14" height="18" rx="2"/><rect x="18" y="3" width="4" height="5" rx="1"/><rect x="18" y="10" width="4" height="5" rx="1"/><rect x="18" y="17" width="4" height="4" rx="1"/></svg>',
        shrink: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3v3a2 2 0 0 1-2 2H3M21 8h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3M16 21v-3a2 2 0 0 1 2-2h3"/></svg>',
    };

    // ─── Estado ────────────────────────────────────────────────────────────────────────────────
    const state = {
        call: null,            // CallSummary de la llamada en curso (API)
        room: null,            // LiveKit Room vigente (se desecha al reconectar: nunca se reutiliza su token)
        admitted: false,       // LiveKit confirmó los permisos que concedió el backend
        recovering: false,
        connecting: false,
        wantMic: true,
        wantCam: true,
        contacts: new Map(),   // user_id → nombre
        dismissed: new Set(),  // llamadas entrantes ya rechazadas o ignoradas
        incoming: null,
        synthetic: null,       // pistas sintéticas (solo con ?sintetico=1 y la opción del servidor)
        sessionLost: false,
        tiles: new Map(),
        elsewhere: null,       // llamada en curso de esta persona controlada por otra pestaña
        booting: true,         // hasta procesar la primera lista: solo entonces se recupera sola tras recargar
        handingOver: false,    // soltando la llamada porque otra pestaña la pidió
        syntheticScreen: null, // pantalla sintética (solo pruebas automatizadas, ?sintetico=1)
        screenStopping: false, // la persona detuvo la pantalla desde la interfaz (no avisar como «terminada»)
        inviting: new Set(),   // selección del panel «Añadir personas»
        removing: null,        // participante pendiente de confirmar «Retirar»
        departures: new Map(), // identidad → temporizador: distingue «salió» de una reconexión corta
        // Fase 4G (solo en esta vista: nada se comparte con los demás).
        pinned: null,          // ficha fijada por quien mira
        view: 'auto',          // auto | grid | speaker (auto: orador con 3 o más personas)
        speaking: [],          // claves de quienes hablan, de más a menos fuerte (sin la propia)
        featured: { key: null, reason: null },
        meterTimer: null,
    };
    const speakerTracker = new window.TeamCallsCore.SpeakerTracker({ hold: 1500, minGap: 5000 });

    // ─── API de TeamCalls ───────────────────────────────────────────────────────────────────────
    function route(name, callId) {
        return cfg.routes[name].replace('__CALL__', encodeURIComponent(callId || ''));
    }

    async function api(method, url, body, extraHeaders) {
        let res;
        try {
            res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: Object.assign({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, extraHeaders || {}),
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            const err = new Error('network');
            err.code = 'network';
            throw err;
        }
        let json = null;
        try { json = await res.json(); } catch (e) { /* sin cuerpo */ }
        if (!res.ok) {
            const err = new Error((json && json.error && json.error.message) || res.statusText);
            err.code = (json && json.error && json.error.code) || 'http_' + res.status;
            err.status = res.status;
            err.retryAfter = Number(res.headers.get('Retry-After')) || 0;
            if (['unauthenticated', 'two_factor_required', 'csrf_token_mismatch'].includes(err.code)) {
                sessionLost();
            }
            throw err;
        }
        return json;
    }

    function errorKey(e) {
        const map = {
            busy: 'err_busy', answered_elsewhere: 'err_answered_elsewhere', media_unavailable: 'err_media_unavailable',
            forbidden: 'err_forbidden', unauthenticated: 'err_session', two_factor_required: 'err_session',
            csrf_token_mismatch: 'err_session', network: 'err_server', too_many_requests: 'err_server',
            invalid_transition: 'err_call_gone', not_found: 'err_call_gone', invalid: 'err_launch_invalid',
            too_many_participants: 'err_too_many_participants',
        };
        return map[e && e.code] || null;
    }

    function showError(e) {
        const key = errorKey(e);
        alertBox(key ? t(key) : t('err_generic', { code: (e && e.code) || '?' }));
    }

    function sessionLost() {
        if (state.sessionLost) return;
        state.sessionLost = true;
        // Sesión cerrada o caducada: se deja de consultar, se cuelga localmente y se liberan cámara y
        // micrófono (el servidor saca a la persona de la llamada al cerrar sesión o por el margen de reconexión).
        poller.stop();
        hub.disconnect();
        hideIncoming();
        if (state.call || state.room) {
            disposeRoom(true).then(() => { state.call = null; tabs.releaseControl(); render(); });
        }
        alertBox(t('err_session'));
    }

    // ─── Señales de llamadas: pestañas, sondeo de respaldo y tiempo real (fase 3D) ───────────────
    // Una sola pestaña de la sesión CONTROLA la llamada (Web Locks) y una sola SONDEA la lista de
    // llamadas; las demás reciben sus resultados por BroadcastChannel (sin credenciales). Cuando exista
    // CollaborationRealtime, sus eventos llegan por el SignalHub y el sondeo queda como respaldo lento.
    const Core = window.TeamCallsCore;
    const tabId = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()).slice(0, 18);
    const tabs = new Core.TabCoordinator({
        locks: (navigator.locks && navigator.locks.request) ? navigator.locks : null,
        channel: ('BroadcastChannel' in window) ? new BroadcastChannel('teamcalls:' + cfg.me.id) : null,
        tabId,
        scope: String(cfg.me.id),
    });
    const coalescer = new Core.Coalescer();

    const hub = new Core.SignalHub({
        userId: cfg.me.id,
        handlers: {
            ringing: () => coalescer.run('live', refreshLive).catch(() => {}),
            answeredElsewhere: (callId) => {
                if (state.incoming && state.incoming.id === callId && !(state.call && state.call.id === callId)) hideIncoming();
            },
            refresh: (callId) => {
                if (state.call && state.call.id === callId && tabs.controlling) coalescer.run('call', () => refreshCall(callId)).catch(() => {});
                else coalescer.run('live', refreshLive).catch(() => {});
            },
            resync: () => poller.poke(),
            status: () => render(),
        },
    });

    const poller = {
        timer: null,
        failures: 0,
        retryAfterMs: 0,
        leader: false,
        stopped: false,
        phase() {
            if (state.incoming) return 'incoming';
            if (state.call && tabs.controlling) return phase() === 'calling' ? 'calling' : 'in_call';
            return 'idle';
        },
        schedule(ms) {
            clearTimeout(this.timer);
            if (this.stopped) return;
            this.timer = setTimeout(() => this.tick(), ms);
        },
        async tick() {
            if (this.stopped) return;
            try {
                // La pestaña que controla la llamada la relee; la que sondea, la lista de llamadas vivas.
                if (state.call && tabs.controlling) await coalescer.run('call', () => refreshCall(state.call.id));
                if (this.leader) await coalescer.run('live', refreshLive);
                if (this.failures > 0 && $('tc-alert').dataset.kind === 'network') $('tc-alert').hidden = true;
                this.failures = 0;
                this.retryAfterMs = 0;
            } catch (e) {
                if (e.code === 'too_many_requests') this.retryAfterMs = (e.retryAfter || 30) * 1000;
                else if (e.code === 'network' || (e.status || 0) >= 500) {
                    this.failures++;
                    if (this.failures >= 2) alertBox(t('err_server_retry'), 'info', false, 'network');
                }
            }
            this.schedule(Core.pollDelay({
                phase: this.phase(), hidden: document.hidden, failures: this.failures,
                realtime: hub.realtime, retryAfterMs: this.retryAfterMs,
            }, cfg.poll));
        },
        poke() { if (!this.stopped) this.schedule(0); },
        stop() { this.stopped = true; clearTimeout(this.timer); },
    };

    async function refreshCall(callId) {
        let s;
        try {
            s = (await api('GET', route('show', callId))).data;
        } catch (e) {
            if (e.code === 'not_found' && state.call && state.call.id === callId) return onUpdate(null);
            throw e;
        }
        if (state.call && state.call.id === callId) {
            onUpdate(s);
            tabs.send('call-update', { call: s });
        }
    }

    async function refreshLive() {
        const calls = (await api('GET', cfg.routes.live)).data || [];
        tabs.send('snapshot', { calls });
        await onSnapshot(calls);
    }

    /** Lista de llamadas vivas (propia o recibida de la pestaña que sondea). */
    async function onSnapshot(calls) {
        const booting = state.booting;
        state.booting = false;
        const ids = new Set(calls.map((c) => c.id));
        if (state.incoming && (!ids.has(state.incoming.id) || (me(calls.find((c) => c.id === state.incoming.id)) || {}).state !== 'ringing')) {
            hideIncoming(); // dejó de sonar: cancelada, perdida o contestada en otra pestaña o dispositivo
        }
        if (state.elsewhere && !ids.has(state.elsewhere.id)) hideElsewhere();
        for (const c of calls) {
            const mine = me(c);
            if (!mine || (state.call && state.call.id === c.id)) continue;
            if (mine.state === 'ringing') {
                if (!state.call && !state.dismissed.has(c.id)) showIncoming(c);
            } else if ((mine.state === 'joined' || mine.state === 'accepted') && !state.call) {
                // Llamada en curso de esta persona sin pestaña que la controle aquí. Solo al CARGAR la página
                // (recarga) se recupera sola, y solo si ninguna otra pestaña la controla; en cualquier otro caso
                // se ofrece «Continuar aquí» (evita que dos pestañas se la quiten mutuamente).
                if (booting && !(await tabs.controlledElsewhere()) && await tabs.acquireControl(false)) {
                    state.call = c;
                    state.wantCam = c.media === 'video';
                    hideElsewhere();
                    render();
                    connectMedia().catch(() => recover());
                } else {
                    showElsewhere(c);
                }
            }
        }
    }

    function onTabMessage(m) {
        if (m.type === 'snapshot' && !poller.leader) onSnapshot(m.calls || []);
        if (m.type === 'call-update' && m.call && !(state.call && state.call.id === m.call.id)) {
            if (state.elsewhere && state.elsewhere.id === m.call.id) {
                if (LIVE.includes(m.call.status)) showElsewhere(m.call); else hideElsewhere();
            }
        }
        if (m.type === 'incoming-handled' && state.incoming && state.incoming.id === m.callId) {
            if (m.outcome === 'declined') state.dismissed.add(m.callId);
            hideIncoming();
        }
        if (m.type === 'takeover' && state.call && state.call.id === m.callId && tabs.controlling) {
            // Otra pestaña de la sesión pide la llamada: se suelta sin colgar y se liberan cámara y micrófono.
            const s = state.call;
            state.handingOver = true;
            disposeRoom(true).then(() => {
                state.call = null;
                state.handingOver = false;
                tabs.releaseControl();
                showElsewhere(s);
                render();
            });
        }
    }

    function showElsewhere(summary) {
        state.elsewhere = summary;
        $('tc-elsewhere').hidden = false;
        render();
    }

    function hideElsewhere() {
        state.elsewhere = null;
        $('tc-elsewhere').hidden = true;
        render();
    }

    /** «Continuar aquí»: se avisa a la pestaña que la tiene, se toma el control y se entra con credenciales nuevas. */
    async function takeOver() {
        const s = state.elsewhere;
        if (!s || state.call) return;
        tabs.send('takeover', { callId: s.id });
        await sleep(400); // margen para que la otra pestaña suelte la sala
        if (!(await tabs.acquireControl(true))) return alertBox(t('err_other_tab'));
        hideElsewhere();
        state.call = s;
        state.wantCam = s.media === 'video';
        render();
        try { await connectMedia(); } catch (e) { recover(); }
        poller.poke();
    }

    /** Colgar: quien inició la llamada la finaliza para todos; el resto solo sale (decisión funcional). */
    function hangUpRoute(summary) {
        return Number(summary.initiator_id) === Number(cfg.me.id) ? 'end' : 'leave';
    }

    async function hangUpElsewhere() {
        const s = state.elsewhere;
        if (!s) return;
        const action = hangUpRoute(s);
        try { await api('POST', route(action, s.id), action === 'leave' ? { all_devices: true } : undefined); } catch (e) { /* ya terminada */ }
        hideElsewhere();
        poller.poke();
        loadHistory();
    }

    // ─── Presentación ───────────────────────────────────────────────────────────────────────────
    function alertBox(message, tone, append, kind) {
        const box = $('tc-alert');
        const text = $('tc-alert-text');
        // Varios avisos seguidos (p. ej. micrófono y cámara) se muestran juntos en lugar de pisarse.
        text.textContent = append && !box.hidden && text.textContent && !text.textContent.includes(message) ? text.textContent + ' ' + message : message;
        box.dataset.tone = tone || 'error';
        box.dataset.kind = kind || '';
        box.hidden = false;
    }

    function nameOf(userId) {
        if (Number(userId) === Number(cfg.me.id)) return t('you');
        return state.contacts.get(Number(userId)) || t('unknown_user', { id: userId });
    }

    function initials(name) {
        return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';
    }

    function me(summary) {
        return summary ? (summary.participants || []).find((p) => Number(p.user_id) === Number(cfg.me.id)) : null;
    }

    function phase() {
        if (state.incoming && !state.call) return 'incoming';
        if (!state.call) return 'idle';
        if (state.recovering || (state.room && state.room.state === 'reconnecting')) return 'reconnecting';
        const s = state.call;
        if ((s.status === 'ringing' || s.status === 'initiating') && Number(s.initiator_id) === Number(cfg.me.id)) return 'calling';
        if (state.room && state.room.state === 'connected' && !state.admitted) return 'waiting_media';
        if (s.status === 'active' && state.admitted) return 'in_call';
        return 'connecting';
    }

    function render() {
        const p = phase();
        const status = $('tc-status');
        status.dataset.phase = p === 'waiting_media' ? 'connecting' : p;
        status.textContent = t('status_' + p);
        const inCall = Boolean(state.call);
        $('tc-root').dataset.incall = inCall ? '1' : '0'; // en llamada, la sala ocupa todo el ancho
        $('tc-empty').hidden = inCall;
        $('tc-room').hidden = !inCall;
        $('tc-controls').hidden = !inCall;
        $('tc-bottom').hidden = !inCall;
        $('tc-fullscreen').hidden = !inCall || !document.fullscreenEnabled;
        $('tc-view').hidden = !inCall;
        const others = inCall ? (state.call.participants || []).filter((x) => Number(x.user_id) !== Number(cfg.me.id) && !x.revoked && x.state !== 'declined' && x.state !== 'missed') : [];
        $('tc-peer').textContent = others.map((x) => nameOf(x.user_id)).join(', ');
        renderControls();
        renderParticipants();
        syncPending();
        layout();
        document.querySelectorAll('[data-call-btn]').forEach((b) => { b.disabled = inCall; });
        document.title = p === 'incoming' ? '📞 ' + t('status_incoming') : baseTitle;
    }

    function renderControls() {
        const lp = state.room && state.room.localParticipant;
        const micOn = state.synthetic ? !isSyntheticMuted('audio') : Boolean(lp && lp.isMicrophoneEnabled);
        const camOn = state.synthetic ? !isSyntheticMuted('video') : Boolean(lp && lp.isCameraEnabled);
        const canUse = Boolean(state.admitted);
        const mic = $('tc-mic');
        mic.innerHTML = (micOn ? ICON.mic : ICON.micOff) + '<span>' + t(micOn ? 'mic_mute' : 'mic_unmute') + '</span>';
        mic.setAttribute('aria-pressed', String(micOn));
        mic.disabled = !canUse;
        const cam = $('tc-cam');
        const video = state.call && state.call.media === 'video';
        cam.hidden = !video;
        cam.innerHTML = (camOn ? ICON.video : ICON.videoOff) + '<span>' + t(camOn ? 'cam_off' : 'cam_on') + '</span>';
        cam.setAttribute('aria-pressed', String(camOn));
        cam.disabled = !canUse;
        const ringingOut = state.call && phase() === 'calling';
        $('tc-hangup').innerHTML = ICON.hangup + '<span>' + t(ringingOut ? 'cancel_call' : 'hangup') + '</span>';
        // Compartir pantalla: solo en videollamadas y si LiveKit confirmó ese permiso (lo concede el backend).
        const screen = $('tc-screen');
        const sharing = isSharingScreen();
        screen.hidden = !(video && canUse && lp && Core.sourceAllowed(lp.permissions, 'SCREEN_SHARE'));
        screen.innerHTML = ICON.screen + '<span>' + t(sharing ? 'screen_stop' : 'screen_start') + '</span>';
        screen.setAttribute('aria-pressed', String(sharing));
        screen.dataset.active = sharing ? '1' : '0';
        const people = $('tc-people-btn');
        const count = activeParticipants().length;
        people.innerHTML = ICON.people + '<span>' + t('people') + '</span><span class="tc-count">' + count + '</span>';
        people.setAttribute('aria-expanded', String($('tc-people').open));
        const fs = Boolean(document.fullscreenElement);
        $('tc-fullscreen').innerHTML = fs ? ICON.shrink : ICON.expand;
        $('tc-fullscreen').title = t(fs ? 'fullscreen_exit' : 'fullscreen');
        $('tc-fullscreen').setAttribute('aria-label', $('tc-fullscreen').title);
        // En móviles los controles muestran solo el icono: el nombre accesible no depende del texto visible.
        document.querySelectorAll('#tc-controls .tc-ctl').forEach((b) => {
            const label = b.querySelector('span');
            if (label) { b.setAttribute('aria-label', label.textContent); b.title = label.textContent; }
        });
    }

    /** Participantes que siguen en la llamada (suenan, aceptaron o están dentro), sin retirados. */
    function activeParticipants() {
        return ((state.call && state.call.participants) || []).filter((p) => !p.revoked && ['ringing', 'accepted', 'joined'].includes(p.state));
    }

    function isHost() {
        return Boolean(state.call) && Number(state.call.initiator_id) === Number(cfg.me.id);
    }

    let peopleSignature = '';
    function renderParticipants() {
        const live = state.call && LIVE.includes(state.call.status);
        // Solo se reconstruye si algo cambió: así los botones («Retirar») no se sustituyen bajo el cursor.
        const signature = JSON.stringify([live, isHost(), state.removing, ((state.call && state.call.participants) || [])
            .map((p) => [p.user_id, p.state, p.role, p.revoked, p.media_state, nameOf(p.user_id)])]);
        if (signature === peopleSignature) return;
        peopleSignature = signature;
        const list = $('tc-participants');
        list.innerHTML = '';
        $('tc-people-count').textContent = state.call ? String(activeParticipants().length) : '';
        ((state.call && state.call.participants) || []).forEach((p) => {
            const li = document.createElement('li');
            li.className = 'tc-item';
            const stateKey = p.revoked ? 'p_removed' : 'p_' + p.state;
            const tone = p.revoked ? 'bad' : (p.media_state === 'connected' ? 'ok' : (p.state === 'ringing' || p.media_state === 'connecting' ? 'warn' : ''));
            li.innerHTML = '<span class="tc-avatar"></span><span class="tc-item-name"></span><span class="tc-chip"></span>';
            li.children[0].textContent = initials(nameOf(p.user_id));
            li.children[1].textContent = nameOf(p.user_id) + (p.role === 'host' ? ' · ' + t('host') : '');
            li.children[2].textContent = t(stateKey) + (p.media_state ? ' · ' + t('m_' + p.media_state) : '');
            if (tone) li.children[2].dataset.tone = tone;
            // Retirar: solo quien inicia la llamada (el servidor lo vuelve a comprobar), con confirmación.
            if (live && isHost() && Number(p.user_id) !== Number(cfg.me.id) && !p.revoked) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'tc-ctl tc-ctl--sm tc-ctl--ghost-danger';
                const confirming = state.removing === Number(p.user_id);
                b.textContent = t(confirming ? 'remove_confirm' : 'remove');
                b.dataset.confirm = confirming ? '1' : '0';
                b.addEventListener('click', () => removeParticipant(Number(p.user_id)));
                li.appendChild(b);
            }
            list.appendChild(li);
        });
        const me0 = me(state.call);
        $('tc-invite-open').hidden = !(live && me0 && ['accepted', 'joined'].includes(me0.state));
        if ($('tc-invite-open').hidden) $('tc-invite-panel').hidden = true;
    }

    // ─── Añadir personas y retirar (API existente; el servidor autoriza) ─────────────────────────
    function inviteCandidates(filter) {
        const q = (filter || '').trim().toLowerCase();
        const busy = new Set(activeParticipants().map((p) => Number(p.user_id)));
        busy.add(Number(cfg.me.id));
        return [...state.contacts.entries()].filter(([id, name]) => !busy.has(id) && (!q || name.toLowerCase().includes(q)));
    }

    function renderInvite() {
        const list = $('tc-invite-list');
        const rows = inviteCandidates($('tc-invite-search').value);
        list.innerHTML = '';
        if (!rows.length) {
            list.innerHTML = '<li class="tc-muted"></li>';
            list.firstChild.textContent = t('invite_none');
        }
        rows.forEach(([id, name]) => {
            const li = document.createElement('li');
            li.className = 'tc-item';
            li.innerHTML = '<label class="tc-check"><input type="checkbox"><span class="tc-avatar"></span><span class="tc-item-name"></span></label>';
            const box = li.querySelector('input');
            box.setAttribute('aria-label', name);
            box.checked = state.inviting.has(id);
            box.addEventListener('change', () => { if (box.checked) state.inviting.add(id); else state.inviting.delete(id); $('tc-invite-send').disabled = !state.inviting.size; });
            li.querySelector('.tc-avatar').textContent = initials(name);
            li.querySelector('.tc-item-name').textContent = name;
            list.appendChild(li);
        });
        $('tc-invite-send').disabled = !state.inviting.size;
    }

    function toggleInvite(open) {
        $('tc-invite-panel').hidden = !open;
        state.inviting.clear();
        if (open) { $('tc-invite-search').value = ''; renderInvite(); $('tc-invite-search').focus(); }
    }

    async function sendInvite() {
        const call = state.call;
        if (!call || !state.inviting.size) return;
        $('tc-invite-send').disabled = true;
        try {
            const s = (await api('POST', route('invite', call.id), { user_ids: [...state.inviting] })).data;
            toggleInvite(false);
            toast(t('invite_sent'));
            onUpdate(s);
        } catch (e) {
            showError(e);
            $('tc-invite-send').disabled = false;
        }
    }

    async function removeParticipant(userId) {
        const call = state.call;
        if (!call) return;
        if (state.removing !== userId) {
            state.removing = userId; // primer clic: pide confirmación
            renderParticipants();
            setTimeout(() => { if (state.removing === userId) { state.removing = null; renderParticipants(); } }, 4000);
            return;
        }
        state.removing = null;
        try {
            onUpdate((await api('POST', route('revoke', call.id), { user_id: userId })).data);
        } catch (e) {
            showError(e);
            renderParticipants();
        }
    }

    // ─── Avisos breves (entradas, salidas, reconexiones, invitaciones) ───────────────────────────
    const toastLog = [];
    function toast(message) {
        const box = $('tc-toasts');
        const el = document.createElement('div');
        el.className = 'tc-toast';
        el.textContent = message;
        box.appendChild(el);
        toastLog.push(message); // solo para la depuración (teamcallsUi.state): los avisos duran unos segundos
        if (toastLog.length > 20) toastLog.shift();
        while (box.children.length > 3) box.firstChild.remove();
        setTimeout(() => el.remove(), 4500);
    }

    function renderContacts(filter) {
        const list = $('tc-contacts');
        const q = (filter || '').trim().toLowerCase();
        const rows = [...state.contacts.entries()].filter(([, name]) => !q || name.toLowerCase().includes(q));
        list.innerHTML = '';
        if (!rows.length) {
            list.innerHTML = '<li class="tc-muted"></li>';
            list.firstChild.textContent = t('contacts_empty');
            return;
        }
        rows.forEach(([id, name]) => {
            const li = document.createElement('li');
            li.className = 'tc-item';
            li.innerHTML = '<span class="tc-avatar"></span><span class="tc-item-name"></span>'
                + '<button type="button" class="tc-ico-btn" data-call-btn data-media="audio">' + ICON.phone + '</button>'
                + '<button type="button" class="tc-ico-btn" data-call-btn data-media="video">' + ICON.video + '</button>';
            li.children[0].textContent = initials(name);
            li.children[1].textContent = name;
            const [audioBtn, videoBtn] = li.querySelectorAll('button');
            audioBtn.title = t('incoming_audio') + ' · ' + name;
            audioBtn.setAttribute('aria-label', audioBtn.title);
            videoBtn.title = t('incoming_video') + ' · ' + name;
            videoBtn.setAttribute('aria-label', videoBtn.title);
            audioBtn.addEventListener('click', () => startCall(id, 'audio'));
            videoBtn.addEventListener('click', () => startCall(id, 'video'));
            list.appendChild(li);
        });
        render();
    }

    async function loadHistory() {
        try {
            const calls = (await api('GET', cfg.routes.history + '?limit=10')).data || [];
            const list = $('tc-history');
            list.innerHTML = '';
            if (!calls.length) {
                list.innerHTML = '<li class="tc-muted"></li>';
                list.firstChild.textContent = t('h_empty');
                return;
            }
            calls.forEach((c) => {
                const li = document.createElement('li');
                li.className = 'tc-item';
                const others = (c.participants || []).filter((p) => Number(p.user_id) !== Number(cfg.me.id)).map((p) => nameOf(p.user_id));
                const when = new Date(c.created_at).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' });
                li.innerHTML = '<span class="tc-ico-btn" aria-hidden="true"></span><span class="tc-item-name"><span></span><span class="tc-item-sub"></span></span><span class="tc-chip"></span>';
                li.children[0].innerHTML = c.media === 'video' ? ICON.video : ICON.phone;
                li.children[1].children[0].textContent = t('h_with', { names: others.join(', ') });
                li.children[1].children[1].textContent = when;
                li.children[2].textContent = c.viewer_state === 'missed' ? t('c_missed') : t('c_' + c.status);
                if (c.viewer_state === 'missed' || c.status === 'failed') li.children[2].dataset.tone = 'bad';
                if (LIVE.includes(c.status)) li.children[2].dataset.tone = 'warn';
                list.appendChild(li);
            });
        } catch (e) { /* el historial no es crítico */ }
    }

    // ─── Mosaico de video ───────────────────────────────────────────────────────────────────────
    // Claves (fase 4F): «c:<identidad>» cámara o avatar de cada persona conectada, «s:<identidad>» su
    // pantalla compartida, «p:<usuario>» invitación que todavía suena; «local» es quien mira.
    const MIC_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2 2 20 20M18.9 13.1A7 7 0 0 0 19 11v-1M5 10v1a7 7 0 0 0 11.4 5.4M15 9.3V5a3 3 0 0 0-5.7-1.3M9 9v2a3 3 0 0 0 5.1 2.1M12 18v4"/></svg>';
    const CAM_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 16v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.7 0H14a2 2 0 0 1 2 2v3.3l1 1L22 7v10"/><path d="m2 2 20 20"/></svg>';
    const PIN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 17v5M9 10.8V4h6v6.8l3 3.2H6z"/></svg>';
    const SIGNAL_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="15" width="4" height="6" rx="1"/><rect x="10" y="10" width="4" height="11" rx="1"/><rect x="17" y="4" width="4" height="17" rx="1"/></svg>';

    function tileFor(key, opts) {
        let tile = state.tiles.get(key);
        if (!tile) {
            const kind = opts.kind || 'camera';
            const el = document.createElement('div');
            el.className = 'tc-tile';
            el.dataset.key = key;
            el.dataset.kind = kind;
            el.dataset.local = opts.local ? '1' : '0';
            el.innerHTML = '<span class="tc-tile-ph"><span class="tc-tile-av"></span></span>'
                + (kind === 'pending' ? '' : '<button type="button" class="tc-pin" aria-pressed="false">' + PIN_ICON + '</button>')
                + '<span class="tc-tile-bar">'
                + (kind === 'camera' ? '<span class="tc-lvl" aria-hidden="true"><i></i><i></i><i></i></span>' : '')
                + '<span class="tc-tile-name"></span><span class="tc-tile-icons">'
                + (kind === 'camera' ? '<span class="tc-q" role="img" hidden>' + SIGNAL_ICON + '</span>' : '')
                + '<span class="tc-ic" data-ic="mic" hidden>' + MIC_OFF_ICON + '</span><span class="tc-ic" data-ic="cam" hidden>' + CAM_OFF_ICON + '</span>'
                + '</span></span><span class="tc-tile-badge" hidden></span>';
            el.querySelector('[data-ic="mic"]').title = t('mic_is_off');
            el.querySelector('[data-ic="cam"]').title = t('cam_is_off');
            const pin = el.querySelector('.tc-pin');
            if (pin) pin.addEventListener('click', (e) => { e.stopPropagation(); togglePin(key); });
            ($(kind === 'screen' ? 'tc-focus' : 'tc-tiles')).appendChild(el);
            tile = { el, video: null };
            state.tiles.set(key, tile); // una sola por clave: reconectar no duplica
        }
        if (opts.label) {
            tile.el.querySelector('.tc-tile-av').textContent = initials(opts.avatar || opts.label);
            tile.el.querySelector('.tc-tile-name').textContent = opts.label;
        }
        return tile;
    }

    function userIdOf(participant) {
        const m = /^u(\d+)\./.exec((participant && participant.identity) || '');
        return m ? Number(m[1]) : null;
    }

    function participantLabel(participant) {
        const id = userIdOf(participant);
        return id ? nameOf(id) : t('unknown_user', { id: '?' });
    }

    function camKey(participant, local) { return local ? 'c:local' : 'c:' + participant.identity; }
    function screenKey(participant, local) { return local ? 's:local' : 's:' + participant.identity; }

    function attachVideo(key, track, opts) {
        const tile = tileFor(key, opts);
        if (tile.video) tile.video.remove();
        const v = track.attach();
        v.muted = true;
        v.playsInline = true;
        tile.el.insertBefore(v, tile.el.firstChild);
        tile.el.querySelector('.tc-tile-ph').hidden = true; // placeholder de iniciales
        tile.video = v;
        layout();
    }

    function detachVideo(key, track) {
        const tile = state.tiles.get(key);
        if (track) track.detach();
        if (tile && tile.video) {
            tile.video.remove();
            tile.video = null;
            tile.el.querySelector('.tc-tile-ph').hidden = false;
        }
    }

    function removeTile(key) {
        const tile = state.tiles.get(key);
        if (tile) tile.el.remove();
        state.tiles.delete(key);
        layout();
    }

    function clearTiles() {
        state.tiles.forEach((tile) => tile.el.remove());
        state.tiles.clear();
        state.departures.forEach((away) => clearTimeout(away.timer));
        state.departures.clear();
        document.querySelectorAll('audio[data-tc-audio]').forEach((a) => a.remove());
        layout();
    }

    /** Micrófono silenciado, cámara apagada y reconexión según LiveKit (local o remoto). */
    function refreshParticipant(participant, local) {
        const tile = state.tiles.get(camKey(participant, local));
        if (!tile || !participant) return;
        const micOff = state.synthetic && local ? isSyntheticMuted('audio') : !participant.isMicrophoneEnabled;
        const video = state.call && state.call.media === 'video';
        const camOn = state.synthetic && local ? !isSyntheticMuted('video') : participant.isCameraEnabled;
        tile.el.querySelector('[data-ic="mic"]').hidden = !micOff;
        tile.el.querySelector('[data-ic="cam"]').hidden = !(video && !camOn);
        const key = camKey(participant, local);
        if (!camOn && tile.video) {
            detachVideo(key); // cámara apagada: avatar en lugar de la última imagen congelada
        } else if (camOn && !tile.video && participant.getTrackPublication) {
            const pub = participant.getTrackPublication(LK.Track.Source.Camera);
            if (pub && pub.track && !pub.isMuted && (local || pub.isSubscribed)) attachVideo(key, pub.track, { local });
        }
    }

    function setBadge(key, text) {
        const tile = state.tiles.get(key);
        if (!tile) return;
        const b = tile.el.querySelector('.tc-tile-badge');
        b.textContent = text || '';
        b.hidden = !text;
    }

    /** Invitaciones que todavía suenan: una ficha «Sonando…» hasta que la persona entra o deja de sonar. */
    function syncPending() {
        const ringing = new Set();
        if (state.call && state.room) {
            const connected = new Set([...state.room.remoteParticipants.values()].map(userIdOf));
            for (const p of state.call.participants || []) {
                const id = Number(p.user_id);
                if (id !== Number(cfg.me.id) && !p.revoked && p.state === 'ringing' && !connected.has(id)) ringing.add(id);
            }
        }
        for (const key of [...state.tiles.keys()]) {
            if (key.startsWith('p:') && !ringing.has(Number(key.slice(2)))) removeTile(key);
        }
        ringing.forEach((id) => {
            const key = 'p:' + id;
            if (state.tiles.has(key)) return;
            tileFor(key, { label: nameOf(id), kind: 'pending' });
            setBadge(key, t('ringing_badge'));
        });
    }

    /** ¿Vista de orador? Automática con 3 o más personas, salvo que quien mira elija otra. */
    function speakerView() {
        const cams = [...state.tiles.keys()].filter((k) => k.startsWith('c:')).length;
        return state.view === 'speaker' || (state.view === 'auto' && cams >= 3);
    }

    /** Orador destacado con histéresis (SpeakerTracker): sin cambios bruscos ni constantes. */
    function currentSpeaker() {
        if (!speakerView()) return null;
        const cams = [...state.tiles.keys()].filter((k) => k.startsWith('c:'));
        const remotes = cams.filter((k) => k !== 'c:local');
        return speakerTracker.update(state.speaking, remotes.length ? remotes : cams, Date.now());
    }

    /**
     * Distribución adaptable (fases 4F y 4G). Área principal: la ficha FIJADA por quien mira (temporal),
     * si no la pantalla compartida y, si no, el orador en la vista de orador; el resto en la tira. Sin
     * nada destacado, rejilla según el número de personas. Solo se mueven las fichas que cambian de sitio.
     */
    function layout() {
        const room = $('tc-room');
        if (!room) return;
        if (state.pinned && !state.tiles.has(state.pinned)) state.pinned = null;
        const screens = [...state.tiles.keys()].filter((k) => k.startsWith('s:'));
        const f = Core.featuredFor({ pinned: state.pinned, screen: screens[screens.length - 1] || null, speakerView: speakerView(), speaker: currentSpeaker() });
        state.featured = f;
        const focus = $('tc-focus');
        const strip = $('tc-tiles');
        state.tiles.forEach((tile, key) => {
            const target = key === f.key ? focus : strip;
            if (tile.el.parentNode !== target) target.appendChild(tile.el);
            tile.el.dataset.featured = key === f.key ? '1' : '0';
            const pin = tile.el.querySelector('.tc-pin');
            if (pin) {
                // En la pantalla compartida, el botón devuelve la pantalla al área principal.
                const pinned = key === state.pinned;
                const label = key.startsWith('s:') ? t('view_large') : t(pinned ? 'unpin' : 'pin');
                pin.setAttribute('aria-pressed', String(pinned));
                pin.setAttribute('aria-label', label);
                pin.title = label;
                pin.hidden = key.startsWith('s:') && f.key === key;
            }
        });
        const tiles = strip.children.length;
        const l = Core.layoutFor({ tiles, screens: f.key ? 1 : 0, width: room.clientWidth || window.innerWidth });
        room.dataset.mode = l.mode;
        room.dataset.strip = l.strip || '';
        room.dataset.reason = f.reason || '';
        room.style.setProperty('--tc-cols', String(l.columns));
        room.dataset.count = String(tiles);
        $('tc-back-screen').hidden = !(f.reason === 'pinned' && screens.length);
        const view = $('tc-view');
        const sv = speakerView();
        view.innerHTML = sv ? ICON.grid : ICON.speaker;
        view.title = t(sv ? 'view_grid' : 'view_speaker');
        view.setAttribute('aria-label', view.title);
    }

    function togglePin(key) {
        state.pinned = key.startsWith('s:') || state.pinned === key ? null : key;
        layout();
    }

    /** Solo vuelve a distribuir si cambia el orador destacado (lo comprueba el pulso de los medidores). */
    function maybeRelayout() {
        const f = state.featured;
        if (f.reason === 'pinned' || f.reason === 'screen') return;
        if ((speakerView() ? currentSpeaker() : null) !== f.key) layout();
    }

    // ─── Nivel de audio y calidad de conexión (información real del SDK de LiveKit) ─────────────
    function participantFor(key) {
        const room = state.room;
        if (!room) return null;
        if (key === 'c:local') return room.localParticipant;
        return room.getParticipantByIdentity ? room.getParticipantByIdentity(key.slice(2)) : room.remoteParticipants.get(key.slice(2));
    }

    function tickMeters() {
        if (!state.room) return;
        state.tiles.forEach((tile, key) => {
            if (!key.startsWith('c:')) return;
            const p = participantFor(key);
            if (!p) return;
            const muted = key === 'c:local' && state.synthetic ? isSyntheticMuted('audio') : !p.isMicrophoneEnabled;
            const bars = String(Core.levelBars(p.audioLevel, muted));
            if (tile.el.dataset.level !== bars) tile.el.dataset.level = bars;
            if (key === 'c:local' && $('tc-mic').dataset.level !== bars) $('tc-mic').dataset.level = bars;
        });
        maybeRelayout();
    }

    function setQuality(key, quality) {
        const tile = state.tiles.get(key);
        const q = tile && tile.el.querySelector('.tc-q');
        if (!q) return;
        const cls = Core.qualityClass(quality);
        q.dataset.q = cls;
        q.hidden = !cls;
        if (cls) { q.title = t('q_' + cls); q.setAttribute('aria-label', q.title); }
    }

    /** Confirma la salida de quien se desconectó de la sala (aviso salvo que ya se avisó la expulsión). */
    function departed(uid, announce) {
        const away = state.departures.get(uid);
        if (!away) return;
        clearTimeout(away.timer);
        state.departures.delete(uid);
        removeTile(away.key);
        if (announce) toast(t('toast_left', { name: away.name }));
    }

    function setSpeaking(speakers) {
        const keys = new Set((speakers || []).map((p) => camKey(p, state.room && p === state.room.localParticipant)));
        state.tiles.forEach((tile, key) => { tile.el.dataset.speaking = keys.has(key) ? '1' : '0'; });
    }

    // ─── Medios (SDK oficial de LiveKit) ────────────────────────────────────────────────────────
    function mediaError(kind, error, append) {
        const failure = LK.MediaDeviceFailure ? LK.MediaDeviceFailure.getFailure(error) : null;
        const dev = kind === 'cam' ? 'cam' : 'mic';
        if (!window.isSecureContext || !navigator.mediaDevices) return alertBox(t('err_insecure'), 'error', append);
        if (failure === 'PermissionDenied') return alertBox(t('err_permission_' + dev), 'error', append);
        if (failure === 'NotFound') return alertBox(t('err_no_' + dev), dev === 'cam' ? 'info' : 'error', append);
        if (failure === 'DeviceInUse') return alertBox(t('err_busy_' + dev), 'error', append);
        return alertBox(t('err_generic', { code: (error && error.name) || 'media' }), 'error', append);
    }

    async function enableLocalMedia() {
        const room = state.room;
        if (!room) return;
        if (cfg.synthetic) {
            await publishSynthetic(room);
        } else {
            try {
                await room.localParticipant.setMicrophoneEnabled(state.wantMic);
            } catch (e) {
                state.wantMic = false;
                mediaError('mic', e);
            }
            if (state.call && state.call.media === 'video') {
                try {
                    await room.localParticipant.setCameraEnabled(state.wantCam);
                } catch (e) {
                    state.wantCam = false;
                    mediaError('cam', e, true);
                }
            }
        }
        await refreshDevices(false);
        render();
    }

    async function toggle(kind) {
        const room = state.room;
        if (!room || !state.admitted) return;
        try {
            if (state.synthetic) {
                const pub = state.synthetic.pubs[kind];
                if (pub) (pub.isMuted ? await pub.unmute() : await pub.mute());
            } else if (kind === 'audio') {
                state.wantMic = !room.localParticipant.isMicrophoneEnabled;
                await room.localParticipant.setMicrophoneEnabled(state.wantMic);
            } else {
                state.wantCam = !room.localParticipant.isCameraEnabled;
                await room.localParticipant.setCameraEnabled(state.wantCam);
                if (!state.wantCam) detachVideo(camKey(null, true));
            }
        } catch (e) {
            mediaError(kind === 'audio' ? 'mic' : 'cam', e);
        }
        render();
    }

    // Audio y video GENERADOS (prueba automatizada sin cámara ni micrófono): mismas reglas de permisos.
    async function publishSynthetic(room) {
        const canvas = document.createElement('canvas');
        canvas.width = 320;
        canvas.height = 240;
        const g = canvas.getContext('2d');
        let f = 0;
        const timer = setInterval(() => {
            g.fillStyle = 'hsl(' + ((f++ * 5) % 360) + ',60%,45%)';
            g.fillRect(0, 0, 320, 240);
            g.fillStyle = '#fff';
            g.font = '24px sans-serif';
            g.fillText(cfg.me.name + ' ' + f, 16, 128);
        }, 66);
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const dst = ctx.createMediaStreamDestination();
        osc.frequency.value = 440;
        osc.connect(dst);
        osc.start();
        const pubs = {};
        pubs.audio = await room.localParticipant.publishTrack(dst.stream.getAudioTracks()[0], { source: LK.Track.Source.Microphone, name: 'microphone' });
        if (state.call && state.call.media === 'video') {
            pubs.video = await room.localParticipant.publishTrack(canvas.captureStream(15).getVideoTracks()[0], { source: LK.Track.Source.Camera, name: 'camera' });
        }
        state.synthetic = { timer, ctx, osc, pubs };
    }

    // ─── Compartir pantalla (fase 4F): SDK de LiveKit y diálogo nativo del navegador ─────────────
    function isSharingScreen() {
        if (state.syntheticScreen) return true;
        const lp = state.room && state.room.localParticipant;
        return Boolean(lp && lp.isScreenShareEnabled);
    }

    async function toggleScreen() {
        const room = state.room;
        if (!room || !state.admitted) return;
        const lp = room.localParticipant;
        // El backend solo concede la fuente de pantalla en videollamadas; LiveKit rechaza cualquier otra.
        if (!isSharingScreen() && !Core.sourceAllowed(lp.permissions, 'SCREEN_SHARE')) return alertBox(t('err_forbidden'));
        try {
            if (isSharingScreen()) {
                state.screenStopping = true;
                if (state.syntheticScreen) await stopSyntheticScreen(room);
                else await lp.setScreenShareEnabled(false);
            } else if (cfg.synthetic) {
                await publishSyntheticScreen(room);
            } else {
                if (!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia)) return alertBox(t('err_screen_unsupported'));
                // Diálogo nativo: la persona elige pantalla, ventana o pestaña (sin incluir esta misma pestaña).
                await lp.setScreenShareEnabled(true, { audio: true, selfBrowserSurface: 'exclude', surfaceSwitching: 'include', systemAudio: 'include' });
            }
        } catch (e) {
            state.screenStopping = false;
            // Cancelar el diálogo del navegador no es un error.
            if (!(e && (e.name === 'NotAllowedError' || e.name === 'AbortError'))) alertBox(t('err_screen'));
        }
        render();
    }

    /** La transmisión terminó desde el navegador (botón «Dejar de compartir» o ventana cerrada). */
    function screenEnded() {
        const room = state.room;
        // LiveKit puede despublicar antes que este aviso: se vuelve a pintar al terminar en cualquier orden.
        const done = state.syntheticScreen ? stopSyntheticScreen(room) : (room ? room.localParticipant.setScreenShareEnabled(false) : Promise.resolve());
        done.catch(() => {}).finally(render);
    }

    // Pantalla GENERADA (pruebas automatizadas: el diálogo nativo no se puede automatizar). Se publica con la
    // fuente ScreenShare, así que LiveKit aplica exactamente los mismos permisos que a una pantalla real.
    async function publishSyntheticScreen(room) {
        const canvas = document.createElement('canvas');
        canvas.width = 640;
        canvas.height = 360;
        const g = canvas.getContext('2d');
        let f = 0;
        const timer = setInterval(() => {
            g.fillStyle = '#0f2747';
            g.fillRect(0, 0, 640, 360);
            g.fillStyle = '#c9a84c';
            g.fillRect(40 + (f++ * 4) % 520, 140, 80, 80);
            g.fillStyle = '#fff';
            g.font = '28px sans-serif';
            g.fillText('Pantalla · ' + cfg.me.name, 40, 80);
        }, 100);
        const track = canvas.captureStream(10).getVideoTracks()[0];
        try {
            const pub = await room.localParticipant.publishTrack(track, { source: LK.Track.Source.ScreenShare, name: 'screen' });
            state.syntheticScreen = { timer, track, pub };
        } catch (e) {
            clearInterval(timer);
            track.stop();
            throw e;
        }
    }

    async function stopSyntheticScreen(room) {
        const s = state.syntheticScreen;
        if (!s) return;
        state.syntheticScreen = null;
        clearInterval(s.timer);
        try { if (room && s.pub && s.pub.track) await room.localParticipant.unpublishTrack(s.pub.track); } finally { s.track.stop(); }
    }

    function isSyntheticMuted(kind) {
        const pub = state.synthetic && state.synthetic.pubs[kind];
        return pub ? pub.isMuted : true;
    }

    function stopSynthetic() {
        if (!state.synthetic) return;
        clearInterval(state.synthetic.timer);
        try { state.synthetic.osc.stop(); state.synthetic.ctx.close(); } catch (e) { /* ya parado */ }
        state.synthetic = null;
    }

    async function refreshDevices(requestPermissions) {
        if (!navigator.mediaDevices) return;
        for (const kind of ['audioinput', 'videoinput', 'audiooutput']) {
            const select = $('tc-dev-' + kind);
            let devices = [];
            try { devices = await LK.Room.getLocalDevices(kind, requestPermissions); } catch (e) { devices = []; }
            if (kind === 'audiooutput') $('tc-dev-audiooutput-wrap').hidden = !devices.length || !('setSinkId' in HTMLMediaElement.prototype);
            const active = state.room ? state.room.getActiveDevice(kind) : undefined;
            select.innerHTML = '';
            devices.forEach((d, i) => {
                const o = document.createElement('option');
                o.value = d.deviceId;
                o.textContent = d.label || (t('default_device') + ' ' + (i + 1));
                if (d.deviceId === active) o.selected = true;
                select.appendChild(o);
            });
            select.disabled = !devices.length || Boolean(state.synthetic);
        }
    }

    function wireRoom(room) {
        const E = LK.RoomEvent;
        const mine = () => room === state.room; // eventos de una sala ya desechada se ignoran
        const onPermissions = () => {
            if (!mine()) return;
            const p = room.localParticipant.permissions;
            const nowAdmitted = Boolean(p && p.canPublish && p.canSubscribe);
            if (nowAdmitted && !state.admitted) {
                state.admitted = true;
                enableLocalMedia();
            }
            state.admitted = nowAdmitted;
            render();
        };
        room.localParticipant.on(LK.ParticipantEvent.ParticipantPermissionsChanged, onPermissions);
        room.on(E.ParticipantPermissionsChanged, onPermissions);
        const S = LK.Track.Source;
        const isScreen = (pub) => pub && (pub.source === S.ScreenShare || pub.source === S.ScreenShareAudio);
        const isLocal = (participant) => participant === room.localParticipant;
        const remoteTile = (participant) => {
            const tile = tileFor(camKey(participant), { label: participantLabel(participant) });
            setQuality(camKey(participant), participant.connectionQuality);
            return tile;
        };

        room.on(E.TrackSubscribed, (track, pub, participant) => {
            if (!mine()) return;
            if (track.kind === 'video' && pub.source === S.ScreenShare) {
                // Pantalla compartida: contenido principal; la cámara de quien presenta sigue en el mosaico.
                state.pinned = null; // una pantalla nueva vuelve a ser el contenido principal
                attachVideo(screenKey(participant), track, { label: t('screen_of', { name: participantLabel(participant) }), avatar: participantLabel(participant), kind: 'screen' });
                toast(t('toast_presenting', { name: participantLabel(participant) }));
            } else if (track.kind === 'video') {
                remoteTile(participant);
                attachVideo(camKey(participant), track, {});
            } else if (track.kind === 'audio') {
                const a = track.attach();
                a.dataset.tcAudio = participant.identity;
                document.body.appendChild(a);
            }
            refreshParticipant(participant, false);
        });
        room.on(E.TrackUnsubscribed, (track, pub, participant) => {
            if (track.kind === 'video' && pub.source === S.ScreenShare) { track.detach(); removeTile(screenKey(participant)); }
            else if (track.kind === 'video') detachVideo(camKey(participant), track);
            else track.detach().forEach((el) => el.remove());
            refreshParticipant(participant, false);
        });
        room.on(E.ParticipantConnected, (participant) => {
            if (!mine()) return;
            const name = participantLabel(participant);
            const away = state.departures.get(userIdOf(participant));
            if (away) { // volvió dentro del margen de reconexión: no era una salida
                clearTimeout(away.timer);
                state.departures.delete(userIdOf(participant));
                if (away.key !== camKey(participant)) removeTile(away.key); // reconectó desde otro dispositivo
                setBadge(camKey(participant), '');
                toast(t('toast_reconnected', { name }));
            } else {
                toast(t('toast_joined', { name }));
            }
            remoteTile(participant);
            refreshParticipant(participant, false);
            syncPending();
        });
        room.on(E.ParticipantDisconnected, (participant) => {
            removeTile(screenKey(participant));
            if (!mine()) { removeTile(camKey(participant)); return; }
            // Salida o corte: el servidor da un margen de reconexión. Mientras tanto la ficha queda con
            // «Reconectando…»; la salida se confirma cuando la API la registra o al vencer el margen.
            const uid = userIdOf(participant);
            const key = camKey(participant);
            detachVideo(key);
            setBadge(key, t('reconnecting_badge'));
            const prev = state.departures.get(uid);
            if (prev) clearTimeout(prev.timer);
            state.departures.set(uid, { key, name: participantLabel(participant), timer: setTimeout(() => departed(uid, true), cfg.reconnectGraceMs + 5000) });
            if (state.call && tabs.controlling) coalescer.run('call', () => refreshCall(state.call.id)).catch(() => {});
        });
        room.on(E.ConnectionQualityChanged, (quality, participant) => {
            if (!mine()) return;
            setQuality(camKey(participant, isLocal(participant)), quality);
            if (isLocal(participant)) return;
            const lost = quality === (LK.ConnectionQuality && LK.ConnectionQuality.Lost);
            const key = camKey(participant);
            const had = state.tiles.get(key) && !state.tiles.get(key).el.querySelector('.tc-tile-badge').hidden;
            setBadge(key, lost ? t('reconnecting_badge') : '');
            if (lost && !had) toast(t('toast_reconnecting', { name: participantLabel(participant) }));
        });
        room.on(E.ActiveSpeakersChanged, (speakers) => {
            if (!mine()) return;
            setSpeaking(speakers);
            state.speaking = (speakers || []).filter((p) => !isLocal(p)).map((p) => camKey(p)); // de más a menos fuerte
            maybeRelayout();
        });
        [E.TrackMuted, E.TrackUnmuted, E.TrackPublished, E.TrackUnpublished].forEach((ev) => room.on(ev, (pub, participant) => {
            if (mine()) { refreshParticipant(participant, isLocal(participant)); renderControls(); }
        }));
        room.on(E.LocalTrackPublished, (pub) => {
            if (!mine()) return;
            if (pub.track && pub.source === S.ScreenShare) {
                state.pinned = null;
                attachVideo(screenKey(null, true), pub.track, { label: t('screen_mine'), avatar: cfg.me.name, kind: 'screen', local: true });
            } else if (pub.track && pub.kind === 'video') {
                attachVideo(camKey(null, true), pub.track, { label: t('you'), avatar: cfg.me.name, local: true });
            }
            if (pub.track) {
                pub.track.on(LK.TrackEvent.Ended, () => {
                    if (isScreen(pub)) return screenEnded(); // «Dejar de compartir» del navegador
                    alertBox(t('err_track_ended', { device: t(pub.kind === 'video' ? 'dev_camera' : 'dev_microphone') }));
                    render();
                });
            }
            refreshParticipant(room.localParticipant, true);
            render();
        });
        room.on(E.LocalTrackUnpublished, (pub) => {
            if (pub.source === S.ScreenShare) {
                removeTile(screenKey(null, true));
                if (!state.screenStopping && mine()) toast(t('screen_ended'));
                state.screenStopping = false;
            } else if (pub.kind === 'video') {
                detachVideo(camKey(null, true));
            }
            refreshParticipant(room.localParticipant, true);
            render();
        });
        room.on(E.MediaDevicesError, (e) => mediaError(/video/i.test(String(e && e.message)) ? 'cam' : 'mic', e));
        room.on(E.AudioPlaybackStatusChanged, () => {
            if (!room.canPlaybackAudio) document.addEventListener('click', () => room.startAudio(), { once: true });
        });
        room.on(E.Reconnecting, () => render());   // el SDK intenta recuperar la sesión por su cuenta
        room.on(E.Reconnected, () => render());
        room.on(E.Disconnected, (reason) => {
            if (!mine()) return;
            const R = LK.DisconnectReason || {};
            disposeRoom(false);
            if (reason === R.CLIENT_INITIATED) return;
            if (reason === R.DUPLICATE_IDENTITY || state.handingOver) {
                // La misma identidad entró desde otra pestaña o dispositivo (el servidor manda): no se reconecta
                // para no quitársela de vuelta; se ofrece «Continuar aquí».
                const s = state.call;
                state.call = null;
                tabs.releaseControl();
                if (s) showElsewhere(s);
                return render();
            }
            recover(); // sala eliminada (rotación), expulsión, fallo de red o del servidor
        });
    }

    async function connectMedia() {
        if (!state.call || state.connecting) return;
        state.connecting = true;
        try {
            const callId = state.call.id;
            state.call = (await api('POST', route('join', callId))).data;
            // Credenciales NUEVAS en cada conexión (también tras una rotación de sala).
            const cred = (await api('POST', route('credentials', callId))).data;
            if (!state.call || state.call.id !== callId) return;
            // adaptiveStream pausa el video remoto que no se ve; en el modo sintético (pruebas automatizadas en
            // ventanas que pueden no dibujarse) se desactiva para medir el flujo real de medios.
            const room = new LK.Room({ adaptiveStream: !cfg.synthetic, dynacast: true, disconnectOnPageLeave: true });
            state.room = room;
            state.admitted = false;
            wireRoom(room);
            await room.connect(cred.server_url, cred.token, { autoSubscribe: true });
            if (state.room !== room) return;
            // Ficha propia (avatar en voz) y las de quienes ya estaban en la sala (sin evento de entrada).
            tileFor(camKey(null, true), { label: t('you'), avatar: cfg.me.name, local: true });
            room.remoteParticipants.forEach((p) => {
                tileFor(camKey(p), { label: participantLabel(p) });
                refreshParticipant(p, false);
            });
            refreshParticipant(room.localParticipant, true);
            setQuality(camKey(null, true), room.localParticipant.connectionQuality);
            syncPending();
            // Indicadores de audio y orador: un pulso ligero con los niveles que ya calcula LiveKit.
            clearInterval(state.meterTimer);
            state.meterTimer = setInterval(tickMeters, 250);
            // Por si los permisos llegaron antes de escuchar el evento.
            const p = room.localParticipant.permissions;
            if (p && p.canPublish && p.canSubscribe && !state.admitted) {
                state.admitted = true;
                enableLocalMedia();
            }
            render();
        } finally {
            state.connecting = false;
        }
    }

    async function disposeRoom(intentional) {
        const room = state.room;
        state.room = null;
        state.admitted = false;
        clearInterval(state.meterTimer);
        state.meterTimer = null;
        state.speaking = [];
        if (intentional !== false) state.pinned = null; // fijar es de esta llamada; una reconexión lo conserva
        stopSynthetic();
        if (state.syntheticScreen) { clearInterval(state.syntheticScreen.timer); state.syntheticScreen.track.stop(); state.syntheticScreen = null; }
        clearTiles();
        if (room) {
            try { room.localParticipant.trackPublications.forEach((pub) => pub.track && pub.track.stop()); } catch (e) { /* ya liberadas */ }
            if (intentional !== false) {
                try { await room.disconnect(true); } catch (e) { /* ya desconectada */ }
            }
        }
    }

    // ─── Flujo de la llamada ────────────────────────────────────────────────────────────────────
    function newKey() {
        return (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random()).replace(/[^A-Za-z0-9._:-]/g, '');
    }

    async function startCall(userId, media, key) {
        if (state.call) return;
        if (state.elsewhere || !(await tabs.acquireControl(false))) return alertBox(t('err_other_tab'));
        // Llamada individual: API web de TeamCalls. La misma clave repetida devuelve la misma llamada.
        await begin(media, () => api('POST', cfg.routes.store, { user_id: Number(userId), media, idempotency_key: 'ui-' + (key || newKey()) }));
    }

    /**
     * Llamada desde una conversación (fase 4E): API definitiva (CallsGateway). En las privadas TeamChat
     * resuelve a la otra persona en el servidor; en grupos y canales la selección explícita se valida
     * todo o nada contra la membresía. La página no decide nada: solo transmite la intención.
     */
    async function startConversationCall(conversation, invitees, media, key) {
        if (state.call) return;
        if (state.elsewhere || !(await tabs.acquireControl(false))) return alertBox(t('err_other_tab'));
        const body = { media, conversation: { type: conversation.type, id: conversation.id } };
        if (conversation.type !== 'direct') body.invitees = invitees.map(Number);
        await begin(media, () => api('POST', cfg.routes.conversation_store, body, { 'Idempotency-Key': 'ui-' + (key || newKey()) }));
    }

    async function begin(media, request) {
        try {
            state.call = (await request()).data;
            state.wantMic = true;
            state.wantCam = media === 'video';
            $('tc-alert').hidden = true;
            render();
            await connectMedia(); // quien llama espera en la sala mientras suena
        } catch (e) {
            showError(e);
            if (state.call) await finish(null, null);
            else tabs.releaseControl();
        }
    }

    /** Abrir una llamada existente: contestar si suena; entrar si la persona sigue autorizada (lo decide el servidor). */
    async function openCall(callId) {
        if (state.call) return state.call.id === callId ? undefined : alertBox(t('err_in_call'));
        let s;
        try {
            s = (await api('GET', route('show', callId))).data;
        } catch (e) {
            return showError(e);
        }
        const mine = me(s);
        if (!LIVE.includes(s.status)) return alertBox(t('err_call_gone'));
        if (!mine || mine.revoked) return alertBox(t('err_forbidden'));
        if (mine.state === 'ringing') return showIncoming(s);
        if (await tabs.controlledElsewhere()) return showElsewhere(s);
        if (!(await tabs.acquireControl(false))) return alertBox(t('err_other_tab'));
        hideElsewhere();
        state.call = s;
        state.wantMic = true;
        state.wantCam = s.media === 'video';
        render();
        try {
            await connectMedia(); // unirse: el servidor rechaza a quien ya no puede entrar
        } catch (e) {
            showError(e);
            await finish(null, null);
        }
    }

    // ─── Intención recibida de otra aplicación (fase 4E) ───────────────────────────────────────
    /** Intención normalizada por el lanzador (mismos nombres que la URL) → intención de la página. */
    function intentFromQuery(q) {
        if (!q || typeof q !== 'object') return { action: 'invalid' };
        if (q.call) return { action: 'open', call: String(q.call) };
        const out = { action: 'start', media: q.media, key: q.key || null };
        if (q.user) out.user = Number(q.user);
        if (q.conversation) {
            const m = /^(direct|group|channel):([A-Za-z0-9_-]{1,64})$/.exec(String(q.conversation));
            if (!m) return { action: 'invalid' };
            out.conversation = { type: m[1], id: m[2] };
            if (m[1] !== 'direct') out.invitees = String(q.invitees || '').split(',').filter(Boolean).map(Number);
        }
        if (!['audio', 'video'].includes(out.media) || !(out.user || out.conversation)) return { action: 'invalid' };
        return out;
    }

    function describeIntent(intent) {
        if (intent.action === 'open') return [t('launch_title_open'), t('launch_existing')];
        const title = t(intent.media === 'video' ? 'launch_title_video' : 'launch_title_audio');
        if (intent.user) return [title, t('launch_user', { name: nameOf(intent.user) })];
        if (intent.conversation.type === 'direct') return [title, t('launch_direct')];
        return [title, t('launch_space', { names: (intent.invitees || []).map(nameOf).join(', ') })];
    }

    function executeIntent(intent) {
        if (intent.action === 'open') return openCall(intent.call);
        if (intent.user) return startCall(intent.user, intent.media, intent.key);
        if (intent.conversation.type !== 'direct' && !(intent.invitees || []).length) return alertBox(t('err_invitees_required'));
        return startConversationCall(intent.conversation, intent.invitees || [], intent.media, intent.key);
    }

    function runIntent(intent) {
        if (!intent) return;
        if (intent.action === 'invalid') return alertBox(t('err_launch_invalid'));
        if (state.call && !(intent.action === 'open' && state.call.id === intent.call)) return alertBox(t('err_in_call'));
        if (!intent.confirm) return executeIntent(intent);
        // Navegación que no viene de esta aplicación (enlace externo, URL pegada): nada empieza sin un clic.
        const [title, detail] = describeIntent(intent);
        $('tc-launch-title').textContent = title;
        $('tc-launch-detail').textContent = detail;
        $('tc-launch-go').textContent = t('launch_confirm');
        $('tc-launch-cancel').textContent = t('launch_cancel');
        $('tc-launch').hidden = false;
        $('tc-launch-go').onclick = () => { $('tc-launch').hidden = true; executeIntent(intent); };
        $('tc-launch-cancel').onclick = () => { $('tc-launch').hidden = true; };
        $('tc-launch-go').focus();
    }

    /** La intención solo vale una vez: se quita de la dirección para que recargar no repita nada. */
    function consumeIntentFromUrl() {
        const url = new URL(window.location.href);
        let changed = false;
        ['media', 'user', 'conversation', 'invitees', 'call', 'key'].forEach((k) => {
            if (url.searchParams.has(k)) { url.searchParams.delete(k); changed = true; }
        });
        if (changed && window.history && window.history.replaceState) window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    }

    function showIncoming(summary) {
        if (state.incoming && state.incoming.id === summary.id) return;
        state.incoming = summary;
        $('tc-incoming-title').textContent = t('incoming_from', { name: nameOf(summary.initiator_id) });
        $('tc-incoming-kind').textContent = t(summary.media === 'video' ? 'incoming_video' : 'incoming_audio');
        $('tc-accept').innerHTML = (summary.media === 'video' ? ICON.video : ICON.phone) + '<span>' + t('accept') + '</span>';
        $('tc-decline').innerHTML = ICON.hangup + '<span>' + t('decline') + '</span>';
        $('tc-incoming').hidden = false;
        render();
    }

    function hideIncoming() {
        state.incoming = null;
        $('tc-incoming').hidden = true;
        render();
    }

    async function acceptIncoming() {
        const summary = state.incoming;
        if (!summary) return;
        hideIncoming();
        if (!(await tabs.acquireControl(false))) return alertBox(t('err_other_tab'));
        tabs.send('incoming-handled', { callId: summary.id, outcome: 'accepted' });
        try {
            state.call = (await api('POST', route('accept', summary.id))).data;
            state.wantMic = true;
            state.wantCam = summary.media === 'video';
            render();
            await connectMedia();
        } catch (e) {
            showError(e);
            await finish(null, null);
        }
    }

    async function declineIncoming() {
        const summary = state.incoming;
        if (!summary) return;
        state.dismissed.add(summary.id);
        hideIncoming();
        tabs.send('incoming-handled', { callId: summary.id, outcome: 'declined' });
        try { await api('POST', route('decline', summary.id)); } catch (e) { /* ya no sonaba */ }
        loadHistory();
    }

    function onUpdate(summary) {
        if (!state.call) return;
        if (summary === null) return finish(null, 'end_ended');
        // Avisos de la llamada grupal: rechazos, sin respuesta, retirados e invitaciones nuevas.
        Core.participantChanges(state.call, summary, cfg.me.id).forEach((c) => toast(t('toast_' + c.kind, { name: nameOf(c.userId) })));
        // Quien se desconectó de la sala y la API ya da por fuera (salió o fue retirado): salida confirmada.
        (summary.participants || []).forEach((p) => {
            if (state.departures.has(Number(p.user_id)) && (p.revoked || !['accepted', 'joined'].includes(p.state))) departed(Number(p.user_id), !p.revoked);
        });
        state.call = summary;
        const mine = me(summary);
        if (!LIVE.includes(summary.status)) return finish(summary);
        if (mine && mine.revoked) return finish(null, 'end_removed');
        render();
    }

    async function recover() {
        if (state.recovering || !state.call || state.sessionLost) return;
        if (!tabs.controlling && !(await tabs.acquireControl(false))) {
            const s = state.call;
            state.call = null;
            if (s) showElsewhere(s);
            return render();
        }
        state.recovering = true;
        render();
        const deadline = Date.now() + cfg.reconnectGraceMs + 10000;
        try {
            for (let attempt = 1; Date.now() < deadline && state.call; attempt++) {
                try {
                    const s = (await api('GET', route('show', state.call.id))).data;
                    state.call = s;
                    const mine = me(s);
                    if (!LIVE.includes(s.status)) return finish(s);
                    if (!mine || mine.revoked) return finish(null, 'end_removed');
                    if (!['accepted', 'joined', 'left'].includes(mine.state)) return finish(s);
                    await connectMedia();
                    return;
                } catch (e) {
                    if (['forbidden', 'not_found', 'invalid_transition', 'answered_elsewhere'].includes(e.code) || state.sessionLost) {
                        if (e.code !== 'not_found') showError(e);
                        return finish(null, e.code === 'forbidden' ? 'end_removed' : null);
                    }
                    if (e.code === 'network') alertBox(t('err_network'), 'info');
                }
                await sleep(Math.min(1000 * attempt, 4000));
            }
            if (state.call) finish(null, 'end_failed');
        } finally {
            state.recovering = false;
            render();
        }
    }

    async function hangUp() {
        const call = state.call;
        if (!call) return;
        await disposeRoom(true);
        try { await api('POST', route(hangUpRoute(call), call.id)); } catch (e) { /* ya terminada */ }
        finish(null, 'end_ended');
    }

    async function finish(summary, messageKey) {
        await disposeRoom(true);
        const s = summary || state.call;
        state.call = null;
        state.recovering = false;
        tabs.releaseControl();
        if (s) tabs.send('released', { callId: s.id });
        let key = messageKey;
        if (key === undefined || (key === null && summary)) {
            key = s ? ({ declined: 'end_declined', missed: 'end_missed', cancelled: 'end_cancelled', ended: 'end_ended', failed: 'end_failed' }[s.status] || null) : null;
        }
        if (key) alertBox(t(key), key === 'end_ended' ? 'info' : (key === 'end_failed' || key === 'end_removed' ? 'error' : 'info'));
        render();
        loadHistory();
    }

    // ─── Arranque ───────────────────────────────────────────────────────────────────────────────
    const baseTitle = document.title;

    async function boot() {
        if (cfg.synthetic) {
            const s = $('tc-synthetic');
            s.textContent = t('synthetic_notice');
            s.hidden = false;
        }
        $('tc-alert-close').addEventListener('click', () => { $('tc-alert').hidden = true; });
        $('tc-mic').addEventListener('click', () => toggle('audio'));
        $('tc-cam').addEventListener('click', () => toggle('video'));
        $('tc-hangup').addEventListener('click', hangUp);
        $('tc-screen').addEventListener('click', toggleScreen);
        $('tc-view').addEventListener('click', () => { state.view = speakerView() ? 'grid' : 'speaker'; layout(); });
        $('tc-back-screen').addEventListener('click', () => { state.pinned = null; layout(); });
        $('tc-people-btn').addEventListener('click', () => {
            const people = $('tc-people');
            people.open = !people.open;
            if (people.open) people.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            renderControls();
        });
        $('tc-people').addEventListener('toggle', renderControls);
        $('tc-invite-open').addEventListener('click', () => toggleInvite($('tc-invite-panel').hidden));
        $('tc-invite-cancel').addEventListener('click', () => toggleInvite(false));
        $('tc-invite-send').addEventListener('click', sendInvite);
        $('tc-invite-search').addEventListener('input', renderInvite);
        $('tc-fullscreen').addEventListener('click', () => {
            if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
            else $('tc-stage').requestFullscreen().catch(() => {});
        });
        document.addEventListener('fullscreenchange', () => { renderControls(); layout(); });
        if (window.ResizeObserver) new ResizeObserver(() => layout()).observe($('tc-room'));
        $('tc-accept').addEventListener('click', acceptIncoming);
        $('tc-decline').addEventListener('click', declineIncoming);
        $('tc-search').addEventListener('input', (e) => renderContacts(e.target.value));
        ['audioinput', 'videoinput', 'audiooutput'].forEach((kind) => {
            $('tc-dev-' + kind).addEventListener('change', async (e) => {
                if (!state.room) return;
                try { await state.room.switchActiveDevice(kind, e.target.value); } catch (err) { mediaError(kind === 'videoinput' ? 'cam' : 'mic', err); }
            });
        });
        if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', () => refreshDevices(false));
        }
        $('tc-takeover').addEventListener('click', takeOver);
        $('tc-elsewhere-hangup').addEventListener('click', hangUpElsewhere);
        // Al volver a la pestaña se consulta enseguida (en segundo plano el sondeo se espacia).
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poller.poke(); });
        window.addEventListener('offline', () => { if (state.call) alertBox(t('err_network'), 'info', false, 'network'); });
        window.addEventListener('online', () => { poller.poke(); if (state.call && !state.room) recover(); });
        // Al cerrar o recargar: cámara y micrófono se paran ya (LiveKit cierra la conexión, el motor espera el
        // margen de reconexión y el bloqueo de control se libera solo).
        window.addEventListener('pagehide', () => {
            try { if (state.room) state.room.localParticipant.trackPublications.forEach((pub) => pub.track && pub.track.stop()); } catch (e) { /* ya paradas */ }
            stopSynthetic();
        });

        if (!LK) {
            alertBox(t('err_generic', { code: 'sdk' }));
            return;
        }
        try {
            const contacts = (await api('GET', cfg.routes.contacts)).data.contacts || [];
            contacts.forEach((c) => state.contacts.set(Number(c.id), c.name));
        } catch (e) {
            showError(e);
        }
        renderContacts('');
        loadHistory();
        tabs.onMessage(onTabMessage);
        // Solo una pestaña de la sesión sondea la lista de llamadas; si se cierra, la sustituye otra.
        tabs.pollLeadership(() => { poller.leader = true; poller.poke(); });
        poller.schedule(0);
        // Al cargar (o recargar en mitad de una llamada) cada pestaña consulta UNA vez por su cuenta: no espera al
        // próximo sondeo de la que sondea, que en segundo plano puede tardar más que el margen de reconexión.
        const firstLive = coalescer.run('live', refreshLive).catch(() => {});
        // Intención de otra aplicación (fase 4E): se ejecuta tras la primera lista de llamadas vivas, para no
        // duplicar la recuperación automática tras recargar ni el aviso de una llamada que ya suena.
        consumeIntentFromUrl();
        firstLive.then(() => runIntent(cfg.intent));
        // CollaborationRealtime: un adaptador registrado antes de cargar la página o, si el servidor publica la
        // dirección de Reverb, el cliente del sistema común (teamcalls-realtime.js). Sin él, solo sondeo.
        const rt = (window.TeamCallsRealtime && window.TeamCallsRealtime.adapter)
            || (cfg.realtime && cfg.realtime.socket && cfg.realtime.socket.key && window.TeamCallsRealtimeAdapter
                ? window.TeamCallsRealtimeAdapter.createAdapter({ api: cfg.realtime.api, csrf, socket: cfg.realtime.socket }) : null);
        if (rt) hub.connect(rt);
        render();

        // Para las pruebas automatizadas y la depuración (solo lectura).
        window.teamcallsUi = {
            // Punto de enganche para CollaborationRealtime: adapter.subscribe({ userId, onEvent, onStatus }).
            useRealtime: (adapter) => hub.connect(adapter),
            // Lanzador (teamcalls-launcher.js): la ventana de llamadas ya abierta recibe la intención sin recargarse
            // (recargar colgaría la llamada en curso). Solo la alcanza código del mismo origen.
            launch: (query) => runIntent(intentFromQuery(query)),
            // Solo con medios sintéticos (pruebas): simula «Dejar de compartir» del navegador (evento ended de la pista).
            simulateScreenEnded: cfg.synthetic ? () => { if (state.syntheticScreen) state.syntheticScreen.track.dispatchEvent(new Event('ended')); } : undefined,
            tabs: () => ({ tabId, controlling: tabs.controlling, pollLeader: poller.leader, realtime: hub.realtime, elsewhere: state.elsewhere && state.elsewhere.id, pollFailures: poller.failures, sessionLost: state.sessionLost }),
            // Bytes recibidos por pista remota (estadísticas WebRTC del SDK): prueba que los medios fluyen.
            stats: async () => {
                const out = [];
                if (!state.room) return out;
                for (const p of state.room.remoteParticipants.values()) {
                    for (const pub of p.trackPublications.values()) {
                        const st = pub.track && pub.track.getReceiverStats ? await pub.track.getReceiverStats() : null;
                        out.push({ from: p.identity, kind: pub.kind, muted: pub.isMuted, bytes: st ? st.bytesReceived : null, frames: st ? st.framesDecoded : undefined });
                    }
                }
                return out;
            },
            state: () => ({
                phase: phase(), callId: state.call && state.call.id, status: state.call && state.call.status,
                admitted: state.admitted, room: state.room ? state.room.state : null,
                tiles: [...state.tiles.keys()], videos: [...document.querySelectorAll('#tc-tiles video')].map((v) => ({ w: v.videoWidth, h: v.videoHeight, t: v.currentTime })),
                // Fase 4F: distribución, pantalla principal e indicadores de cada ficha.
                layout: { mode: $('tc-room').dataset.mode, columns: $('tc-room').style.getPropertyValue('--tc-cols'), strip: $('tc-room').dataset.strip },
                focus: [...document.querySelectorAll('#tc-focus video')].map((v) => ({ w: v.videoWidth, h: v.videoHeight, t: v.currentTime })),
                screen: isSharingScreen(), screenAllowed: Boolean(state.room && Core.sourceAllowed(state.room.localParticipant.permissions, 'SCREEN_SHARE')),
                flags: [...state.tiles.entries()].map(([k, tile]) => k + ':' + ['mic', 'cam'].filter((i) => !tile.el.querySelector('[data-ic="' + i + '"]').hidden).join('+')
                    + (tile.el.querySelector('.tc-tile-badge').hidden ? '' : ':' + tile.el.querySelector('.tc-tile-badge').textContent)),
                toasts: toastLog.slice(),
                // Fase 4G: área principal, fijado, vista, niveles de audio (0–3) y calidad por ficha.
                featured: state.featured, pinned: state.pinned, view: state.view, speakerView: speakerView(),
                levels: Object.fromEntries([...state.tiles.entries()].filter(([k]) => k.startsWith('c:')).map(([k, tile]) => [k, tile.el.dataset.level || '0'])),
                quality: Object.fromEntries([...state.tiles.entries()].filter(([k]) => k.startsWith('c:')).map(([k, tile]) => [k, (tile.el.querySelector('.tc-q') || {}).dataset ? tile.el.querySelector('.tc-q').dataset.q || '' : ''])),
                micLevel: $('tc-mic').dataset.level || '0',
                mic: state.room ? state.room.localParticipant.isMicrophoneEnabled : null, cam: state.room ? state.room.localParticipant.isCameraEnabled : null,
                alert: $('tc-alert').hidden ? null : $('tc-alert-text').textContent,
                remotes: state.room ? [...state.room.remoteParticipants.values()].map((p) => ({
                    identity: p.identity,
                    tracks: [...p.trackPublications.values()].map((x) => x.kind + ':' + (x.isSubscribed ? 'sub' : 'nosub') + ':' + (x.track ? x.track.streamState : '-')),
                })) : [],
            }),
        };
    }

    boot();
})();
