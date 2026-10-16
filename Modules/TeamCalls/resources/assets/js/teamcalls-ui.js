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
    };

    // ─── API de TeamCalls ───────────────────────────────────────────────────────────────────────
    function route(name, callId) {
        return cfg.routes[name].replace('__CALL__', encodeURIComponent(callId || ''));
    }

    async function api(method, url, body) {
        let res;
        try {
            res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
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
            invalid_transition: 'err_call_gone', not_found: 'err_call_gone',
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
        $('tc-empty').hidden = inCall;
        $('tc-controls').hidden = !inCall;
        $('tc-bottom').hidden = !inCall;
        const others = inCall ? (state.call.participants || []).filter((x) => Number(x.user_id) !== Number(cfg.me.id)) : [];
        $('tc-peer').textContent = others.map((x) => nameOf(x.user_id)).join(', ');
        renderControls();
        renderParticipants();
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
    }

    function renderParticipants() {
        const list = $('tc-participants');
        list.innerHTML = '';
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
            list.appendChild(li);
        });
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
    function tileFor(key, label, local) {
        let tile = state.tiles.get(key);
        if (!tile) {
            const el = document.createElement('div');
            el.className = 'tc-tile';
            el.dataset.local = local ? '1' : '0';
            el.innerHTML = '<span class="tc-tile-ph"></span><span class="tc-tile-name"></span><span class="tc-tile-badge" hidden></span>';
            el.children[0].textContent = initials(label);
            el.children[1].textContent = label;
            $('tc-tiles').appendChild(el);
            tile = { el, video: null };
            state.tiles.set(key, tile); // una sola por participante: reconectar no duplica
        }
        return tile;
    }

    function participantLabel(participant) {
        const m = /^u(\d+)\./.exec(participant.identity || '');
        return m ? nameOf(m[1]) : t('unknown_user', { id: '?' });
    }

    function attachVideo(key, track, label, local) {
        const tile = tileFor(key, label, local);
        if (tile.video) tile.video.remove();
        const v = track.attach();
        v.muted = true;
        v.playsInline = true;
        tile.el.insertBefore(v, tile.el.firstChild);
        tile.el.children[1].hidden = true; // placeholder de iniciales
        tile.video = v;
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
    }

    function clearTiles() {
        state.tiles.forEach((tile) => tile.el.remove());
        state.tiles.clear();
        document.querySelectorAll('audio[data-tc-audio]').forEach((a) => a.remove());
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
                if (!state.wantCam) detachVideo('local');
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
        room.on(E.TrackSubscribed, (track, pub, participant) => {
            if (!mine()) return;
            if (track.kind === 'video') {
                attachVideo(participant.identity, track, participantLabel(participant), false);
            } else if (track.kind === 'audio') {
                const a = track.attach();
                a.dataset.tcAudio = participant.identity;
                document.body.appendChild(a);
            }
        });
        room.on(E.TrackUnsubscribed, (track, pub, participant) => {
            if (track.kind === 'video') detachVideo(participant.identity, track);
            else track.detach().forEach((el) => el.remove());
        });
        room.on(E.ParticipantConnected, (participant) => { if (mine()) tileFor(participant.identity, participantLabel(participant), false); });
        room.on(E.ParticipantDisconnected, (participant) => removeTile(participant.identity));
        room.on(E.TrackMuted, (pub, participant) => {
            const tile = state.tiles.get(participant === room.localParticipant ? 'local' : participant.identity);
            if (tile && pub.kind === 'audio') { const b = tile.el.querySelector('.tc-tile-badge'); b.textContent = t('muted'); b.hidden = false; }
        });
        room.on(E.TrackUnmuted, (pub, participant) => {
            const tile = state.tiles.get(participant === room.localParticipant ? 'local' : participant.identity);
            if (tile && pub.kind === 'audio') tile.el.querySelector('.tc-tile-badge').hidden = true;
        });
        room.on(E.LocalTrackPublished, (pub) => {
            if (!mine()) return;
            if (pub.track && pub.kind === 'video' && pub.source !== LK.Track.Source.ScreenShare) attachVideo('local', pub.track, t('you'), true);
            if (pub.track) {
                pub.track.on(LK.TrackEvent.Ended, () => {
                    alertBox(t('err_track_ended', { device: t(pub.kind === 'video' ? 'dev_camera' : 'dev_microphone') }));
                    render();
                });
            }
            render();
        });
        room.on(E.LocalTrackUnpublished, (pub) => { if (pub.kind === 'video') detachVideo('local'); render(); });
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
            if (state.call && state.call.media === 'video' && !cfg.synthetic) tileFor('local', t('you'), true);
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
        stopSynthetic();
        clearTiles();
        if (room) {
            try { room.localParticipant.trackPublications.forEach((pub) => pub.track && pub.track.stop()); } catch (e) { /* ya liberadas */ }
            if (intentional !== false) {
                try { await room.disconnect(true); } catch (e) { /* ya desconectada */ }
            }
        }
    }

    // ─── Flujo de la llamada ────────────────────────────────────────────────────────────────────
    async function startCall(userId, media) {
        if (state.call) return;
        if (state.elsewhere || !(await tabs.acquireControl(false))) return alertBox(t('err_other_tab'));
        try {
            const key = (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random()).replace(/[^A-Za-z0-9._:-]/g, '');
            state.call = (await api('POST', cfg.routes.store, { user_id: Number(userId), media, idempotency_key: 'ui-' + key })).data;
            state.wantMic = true;
            state.wantCam = media === 'video';
            $('tc-alert').hidden = true;
            render();
            await connectMedia(); // quien llama espera en la sala mientras suena
        } catch (e) {
            showError(e);
            if (state.call) await finish(null, null);
        }
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
        coalescer.run('live', refreshLive).catch(() => {});
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
