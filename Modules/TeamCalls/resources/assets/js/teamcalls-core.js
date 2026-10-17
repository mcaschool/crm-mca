/*
 * TeamCalls · núcleo de la interfaz web (fase 3D), SIN DOM: se prueba con Node (tests/js) y lo usa
 * teamcalls-ui.js. Nada de aquí guarda ni difunde credenciales: los mensajes entre pestañas llevan solo
 * ids y estados (sanitize()).
 *
 *  - EventDeduper: un evento (por id) se procesa una sola vez.
 *  - Coalescer: varias peticiones de refresco de lo mismo → una en curso y, como mucho, una más.
 *  - pollDelay(): cadencia del sondeo temporal según pestaña visible, llamada, fallos y tiempo real.
 *  - TabCoordinator: una sola pestaña controla la llamada (Web Locks) y una sola sondea; se avisan
 *    por BroadcastChannel. El servidor sigue siendo la autoridad (LiveKit expulsa identidades duplicadas).
 *  - SignalHub: eventos de CollaborationRealtime (cuando exista un adaptador) con el sondeo de respaldo;
 *    los eventos solo disparan relecturas de la API, que es la fuente de verdad.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.TeamCallsCore = factory();
})(typeof globalThis !== 'undefined' ? globalThis : self, function () {
    'use strict';

    // ─── Eventos repetidos ──────────────────────────────────────────────────────────────────────
    class EventDeduper {
        constructor(max) { this.max = max || 500; this.seen = new Map(); }
        /** true la primera vez que se ve el id; false si es repetido (o no tiene id). */
        first(id) {
            if (!id) return false;
            if (this.seen.has(id)) return false;
            this.seen.set(id, true);
            if (this.seen.size > this.max) this.seen.delete(this.seen.keys().next().value);
            return true;
        }
    }

    // ─── Refrescos agrupados ────────────────────────────────────────────────────────────────────
    class Coalescer {
        constructor() { this.running = new Map(); this.pending = new Set(); }
        /** Ejecuta fn para key; si ya hay una en curso, se repite UNA vez al terminar (sin acumular). */
        run(key, fn) {
            if (this.running.has(key)) {
                this.pending.add(key);
                return this.running.get(key);
            }
            const p = (async () => {
                try {
                    do {
                        this.pending.delete(key);
                        await fn();
                    } while (this.pending.has(key));
                } finally {
                    this.running.delete(key);
                }
            })();
            this.running.set(key, p);
            return p;
        }
    }

    // ─── Cadencia del sondeo temporal ───────────────────────────────────────────────────────────
    const POLL = {
        idle: 4000, idleHidden: 15000, incoming: 3000, calling: 3000,
        inCall: 6000, inCallHidden: 10000, realtimeBackup: 30000, max: 60000,
    };

    /**
     * Milisegundos hasta el próximo sondeo. phase: idle | incoming | calling | in_call. Con tiempo real
     * conectado, el sondeo es solo un respaldo lento; con fallos, retroceso exponencial hasta max.
     */
    function pollDelay(opts, overrides) {
        const p = Object.assign({}, POLL, overrides || {});
        const o = opts || {};
        let base;
        if (o.realtime) base = p.realtimeBackup;
        else if (o.phase === 'incoming') base = p.incoming;
        else if (o.phase === 'calling') base = p.calling;
        else if (o.phase === 'in_call') base = o.hidden ? p.inCallHidden : p.inCall;
        else base = o.hidden ? p.idleHidden : p.idle;
        const failures = Math.max(0, Math.min(10, o.failures || 0));
        let delay = Math.min(p.max, base * Math.pow(2, failures));
        if (o.retryAfterMs) delay = Math.max(delay, Math.min(p.max * 5, o.retryAfterMs));
        return delay;
    }

    // ─── Mensajes entre pestañas (sin credenciales) ─────────────────────────────────────────────
    const MESSAGE_FIELDS = {
        takeover: ['callId'],
        released: ['callId'],
        'incoming-handled': ['callId', 'outcome'],
        snapshot: ['calls'],
        'call-update': ['call'],
    };
    const SUMMARY_FIELDS = ['id', 'media', 'scope', 'status', 'initiator_id', 'created_at', 'ended_at', 'end_reason', 'viewer_state'];
    const PARTICIPANT_FIELDS = ['user_id', 'role', 'state', 'media_state', 'revoked'];

    function sanitizeSummary(s) {
        if (!s || typeof s !== 'object') return null;
        const out = {};
        SUMMARY_FIELDS.forEach((k) => { if (k in s) out[k] = s[k]; });
        out.participants = Array.isArray(s.participants) ? s.participants.map((p) => {
            const q = {};
            PARTICIPANT_FIELDS.forEach((k) => { if (p && k in p) q[k] = p[k]; });
            return q;
        }) : [];
        return out;
    }

    /** Solo campos conocidos: nunca token, credenciales, sala ni identidad. */
    function sanitize(type, msg) {
        const fields = MESSAGE_FIELDS[type];
        if (!fields) return null;
        const out = { type, from: msg && msg.from };
        fields.forEach((k) => {
            if (!msg || !(k in msg)) return;
            if (k === 'calls') out.calls = (msg.calls || []).map(sanitizeSummary);
            else if (k === 'call') out.call = sanitizeSummary(msg.call);
            else out[k] = msg[k];
        });
        return out;
    }

    // ─── Coordinación de pestañas de la MISMA sesión ────────────────────────────────────────────
    class TabCoordinator {
        /**
         * deps: { locks (navigator.locks o null), channel (BroadcastChannel o null), tabId, scope }.
         * scope (p. ej. el id de usuario) separa los bloqueos de cuentas distintas en el mismo navegador.
         * Sin Web Locks, cada pestaña se cree controladora y la autoridad es el servidor (LiveKit
         * expulsa la conexión anterior de la misma identidad).
         */
        constructor(deps) {
            this.locks = deps.locks || null;
            this.channel = deps.channel || null;
            this.tabId = deps.tabId;
            const suffix = deps.scope ? ':' + deps.scope : '';
            this.names = { control: 'teamcalls-call-control' + suffix, poller: 'teamcalls-poller' + suffix };
            this.release = null;
            this.handlers = new Set();
            if (this.channel) {
                this.channel.onmessage = (ev) => {
                    const m = ev && ev.data;
                    if (!m || m.from === this.tabId) return;
                    const clean = sanitize(m.type, m);
                    if (clean) this.handlers.forEach((h) => h(clean));
                };
            }
        }

        get controlling() { return this.release !== null; }

        /** Toma el control de la llamada. steal: arrebatarlo a otra pestaña (tras avisarla). */
        async acquireControl(steal) {
            if (this.release) return true;
            if (!this.locks) { this.release = () => {}; return true; }
            return new Promise((resolve) => {
                const opts = steal ? { steal: true } : { ifAvailable: true };
                this.locks.request(this.names.control, opts, (lock) => {
                    if (!lock) { resolve(false); return undefined; }
                    return new Promise((done) => {
                        this.release = () => { this.release = null; done(); };
                        resolve(true);
                    });
                }).catch(() => { this.release = null; resolve(false); });
            });
        }

        releaseControl() { if (this.release) this.release(); }

        /** ¿Otra pestaña de esta sesión tiene el control? (null si no se puede saber) */
        async controlledElsewhere() {
            if (!this.locks || !this.locks.query) return null;
            if (this.release) return false;
            const q = await this.locks.query();
            return (q.held || []).some((l) => l.name === this.names.control);
        }

        /** Solo una pestaña sondea; las demás reciben sus resultados. Se encola: si se cierra, sigue otra. */
        pollLeadership(onLeader) {
            if (!this.locks) { onLeader(); return; }
            this.locks.request(this.names.poller, () => { onLeader(); return new Promise(() => {}); }).catch(() => onLeader());
        }

        send(type, msg) {
            const clean = sanitize(type, Object.assign({}, msg, { from: this.tabId }));
            if (clean && this.channel) this.channel.postMessage(clean);
            return clean;
        }

        onMessage(fn) { this.handlers.add(fn); }
    }

    // ─── Eventos de llamadas: tiempo real (futuro) + sondeo de respaldo ─────────────────────────
    /**
     * Contrato del adaptador de CollaborationRealtime (no incluido aquí):
     *   adapter.subscribe({ userId, onEvent(envelope), onStatus('connected'|'disconnected') }) → unsubscribe()
     * envelope = CallEnvelope v1: { id, type, v, occurred_at, institution_id, actor_id, data: { call_id, ... } }
     *
     * Tipos que consume la interfaz (nombres REALES de TeamCalls):
     *   call.ringing (data.user_id = destinatario) → aviso de entrante
     *   call.answered_elsewhere                    → cerrar el aviso en esta pestaña/dispositivo
     *   resto de call.* de la llamada              → releer la llamada en la API
     * Los ids repetidos se ignoran; los eventos nunca ejecutan acciones directamente: solo relecturas.
     */
    const REFRESH_TYPES = new Set([
        'call.started', 'call.accepted', 'call.declined', 'call.missed', 'call.ended', 'call.connected',
        'call.participant.connecting', 'call.participant.joined', 'call.participant.left', 'call.participant.revoked',
        'call.media.joined', 'call.media.left', 'call.media.room_finished', 'call.media.room_rotated',
    ]);

    class SignalHub {
        constructor(deps) {
            this.me = Number(deps.userId);
            this.on = deps.handlers; // { ringing(callId), answeredElsewhere(callId), refresh(callId), resync(), status(connected) }
            this.dedupe = new EventDeduper(1000);
            this.realtime = false;
            this.unsubscribe = null;
        }

        connect(adapter) {
            if (!adapter || typeof adapter.subscribe !== 'function') return false;
            this.unsubscribe = adapter.subscribe({
                userId: this.me,
                onEvent: (env) => this.handle(env),
                onStatus: (status) => {
                    const connected = status === 'connected';
                    const changed = connected !== this.realtime;
                    this.realtime = connected;
                    if (this.on.status) this.on.status(connected);
                    // Al (re)conectar o al perder el canal: el estado se recupera de la API.
                    if (changed && this.on.resync) this.on.resync();
                },
            });
            return true;
        }

        disconnect() { if (this.unsubscribe) this.unsubscribe(); this.unsubscribe = null; this.realtime = false; }

        /** @returns la acción aplicada (para pruebas): ringing | answered_elsewhere | refresh | duplicate | ignored */
        handle(env) {
            if (!env || env.v !== 1 || typeof env.type !== 'string') return 'ignored';
            if (!this.dedupe.first(env.id)) return 'duplicate';
            const callId = env.data && env.data.call_id;
            if (!callId) return 'ignored';
            if (env.type === 'call.ringing') {
                if (Number(env.data.user_id) !== this.me) return 'ignored';
                this.on.ringing(callId);
                return 'ringing';
            }
            if (env.type === 'call.answered_elsewhere') {
                this.on.answeredElsewhere(callId);
                return 'answered_elsewhere';
            }
            if (REFRESH_TYPES.has(env.type)) {
                this.on.refresh(callId);
                return 'refresh';
            }
            return 'ignored';
        }
    }

    // ─── Llamadas grupales y pantalla compartida (fase 4F) ─────────────────────────────────────
    /**
     * Distribución del mosaico según cuántos videos hay y el ancho disponible. Con una pantalla
     * compartida, la pantalla es el contenido principal y los participantes pasan a una tira lateral
     * (o inferior en pantallas estrechas), siempre visibles.
     * @returns {{mode: 'grid'|'focus', columns: number, strip: 'side'|'bottom'|null}}
     */
    function layoutFor(opts) {
        const tiles = Math.max(0, opts.tiles | 0);
        const width = opts.width || 1024;
        if (opts.screens > 0) {
            // Algo destacado (pantalla, fijado u orador); sin nadie más, sin tira.
            return { mode: 'focus', columns: 1, strip: tiles === 0 ? null : (width < 760 ? 'bottom' : 'side') };
        }
        let columns = tiles <= 1 ? 1 : tiles <= 4 ? 2 : tiles <= 9 ? 3 : 4;
        // Nunca columnas de menos de ~220 px: en móviles se apilan.
        columns = Math.max(1, Math.min(columns, Math.floor(width / 220) || 1));
        return { mode: 'grid', columns, strip: null };
    }

    /**
     * Cambios de estado de los participantes entre dos resúmenes de la API (para avisos breves):
     * rechazó, no contestó, salió, fue retirado, aceptó. Los de quien mira no se avisan.
     * @returns {Array<{userId: number, kind: string}>}
     */
    function participantChanges(prev, next, me) {
        const before = new Map(((prev && prev.participants) || []).map((p) => [Number(p.user_id), p]));
        const out = [];
        for (const p of (next && next.participants) || []) {
            const id = Number(p.user_id);
            if (id === Number(me)) continue;
            const old = before.get(id);
            if (p.revoked && !(old && old.revoked)) { out.push({ userId: id, kind: 'removed' }); continue; }
            if (!old) { if (p.state === 'ringing') out.push({ userId: id, kind: 'invited' }); continue; }
            if (old.state === p.state) continue;
            if (p.state === 'declined') out.push({ userId: id, kind: 'declined' });
            else if (p.state === 'missed') out.push({ userId: id, kind: 'missed' });
            else if (p.state === 'ringing') out.push({ userId: id, kind: 'invited' });
        }
        return out;
    }

    /**
     * ¿LiveKit permite publicar esa fuente? (permisos que concede el backend: TrackSource del proto,
     * numérico o por nombre). Sin permiso de publicar, nada; lista vacía con permiso = todas (LiveKit).
     */
    const SOURCES = { CAMERA: 1, MICROPHONE: 2, SCREEN_SHARE: 3, SCREEN_SHARE_AUDIO: 4 };
    function sourceAllowed(perms, name) {
        if (!perms || !perms.canPublish) return false;
        const list = perms.canPublishSources || [];
        if (!list.length) return true;
        return list.some((s) => s === SOURCES[name] || String(s).toUpperCase() === name);
    }

    // ─── Controles avanzados (fase 4G) ─────────────────────────────────────────────────────────
    /**
     * Orador destacado SIN saltos: quien habla tiene que mantenerse como orador principal `hold` ms
     * para sustituir al actual, y nunca se cambia antes de `minGap` ms desde el último cambio. Si el
     * destacado deja de estar disponible (salió), se cambia enseguida.
     */
    class SpeakerTracker {
        constructor(opts) {
            this.hold = (opts && opts.hold) || 1500;
            this.minGap = (opts && opts.minGap) || 5000;
            this.current = null;
            this.candidate = null;
            this.since = 0;
            this.changedAt = -Infinity;
        }

        /**
         * @param {string[]} speaking claves de quienes hablan, de más a menos fuerte (sin la propia)
         * @param {string[]} available claves que se pueden destacar, en orden estable
         * @returns {string|null}
         */
        update(speaking, available, now) {
            const can = new Set(available);
            if (this.current && !can.has(this.current)) this.current = null;
            const top = (speaking || []).find((k) => can.has(k)) || null;
            if (!this.current) {
                this.current = top || available[0] || null;
                this.changedAt = now;
                this.candidate = null;
                return this.current;
            }
            if (!top || top === this.current) { this.candidate = null; return this.current; }
            if (this.candidate !== top) { this.candidate = top; this.since = now; }
            if (now - this.since >= this.hold && now - this.changedAt >= this.minGap) {
                this.current = top;
                this.changedAt = now;
                this.candidate = null;
            }
            return this.current;
        }
    }

    /** Barras del indicador de audio (0–3) a partir del nivel de LiveKit (0–1); silenciado = 0. */
    function levelBars(level, muted) {
        if (muted || !(level > 0)) return 0;
        return level > 0.2 ? 3 : level > 0.08 ? 2 : level > 0.02 ? 1 : 0;
    }

    /** Calidad de conexión del SDK → buena | inestable | desconectada; desconocida = sin indicador. */
    function qualityClass(q) {
        const v = String(q || '').toLowerCase();
        if (v === 'excellent' || v === 'good') return 'good';
        if (v === 'poor') return 'unstable';
        if (v === 'lost') return 'lost';
        return '';
    }

    /**
     * Qué ocupa el área principal. La pantalla compartida manda, salvo que la persona fije a alguien
     * (temporalmente); sin pantalla ni fijado, la vista «orador» destaca a quien habla.
     */
    function featuredFor(opts) {
        if (opts.pinned) return { key: opts.pinned, reason: 'pinned' };
        if (opts.screen) return { key: opts.screen, reason: 'screen' };
        if (opts.speakerView && opts.speaker) return { key: opts.speaker, reason: 'speaker' };
        return { key: null, reason: null };
    }

    // ─── Preproducción (fase 4H) ───────────────────────────────────────────────────────────────
    /**
     * Errores con los que la sesión de ESTE navegador deja de servir: sin sesión, sin segundo factor,
     * CSRF caducado, o sesión o dispositivo revocados por CollaborationCore. La página deja de consultar,
     * cuelga localmente y libera cámara y micrófono; el servidor ya sacó a la persona de la llamada.
     */
    const SESSION_ENDING = ['unauthenticated', 'two_factor_required', 'csrf_token_mismatch', 'session_revoked', 'device_revoked'];
    function endsSession(code) {
        return SESSION_ENDING.includes(code);
    }

    // ─── Fallos y conexión (fase 4I) ───────────────────────────────────────────────────────────
    /**
     * Mensaje (clave de i18n) para cada causa de fallo, sin mezclarlas: servidor de medios caído, conexión
     * lenta o cortada, sin respuesta, permisos del navegador, sesión o dispositivo revocados, autenticación
     * y llamada terminada por otra persona. Desconocido → null (mensaje genérico con el código).
     */
    const FAILURES = {
        media_unavailable: 'err_media_unavailable',      // servidor de medios caído (disyuntor o 503)
        media_connect_failed: 'err_media_connect',       // API bien, pero la conexión de medios (WebRTC) no llega
        timeout: 'err_timeout',                          // el servidor tarda demasiado en responder
        network: 'err_server',                           // sin red o servidor inaccesible
        too_many_requests: 'err_server',
        unauthenticated: 'err_session',                  // sesión caducada o cerrada
        csrf_token_mismatch: 'err_session',
        two_factor_required: 'err_two_factor',           // hay que volver a verificar el segundo factor
        session_revoked: 'err_session_revoked',          // sesión cerrada desde otro sitio o por un administrador
        device_revoked: 'err_device_revoked',            // este dispositivo ya no está autorizado
        forbidden: 'err_forbidden',
        busy: 'err_busy',
        answered_elsewhere: 'err_answered_elsewhere',
        invalid_transition: 'err_call_gone',
        not_found: 'err_call_gone',
        invalid: 'err_launch_invalid',
        too_many_participants: 'err_too_many_participants',
    };
    function failureKey(code) {
        return FAILURES[code] || null;
    }

    /** Cómo terminó una llamada para quien mira (clave de i18n), según el estado y el motivo del servidor. */
    function endKey(summary, me) {
        if (!summary) return null;
        if (summary.status === 'ended' && summary.end_reason === 'ended_by_host' && Number(summary.initiator_id) !== Number(me)) return 'end_by_host';
        return { declined: 'end_declined', missed: 'end_missed', cancelled: 'end_cancelled', ended: 'end_ended', failed: 'end_failed' }[summary.status] || null;
    }

    /** Indicador de conexión: sin red > servidor de llamadas caído > sin servidor en este entorno > conectado. */
    function connectionState(opts) {
        if (opts.online === false) return 'offline';
        if (opts.media === 'down') return 'media_down';
        if (opts.media === 'unconfigured') return 'media_none';
        return opts.realtime ? 'live' : 'polling';
    }

    // ─── Aviso de llamada entrante en cualquier pantalla (fase 4I) ─────────────────────────────
    const LIVE_STATUSES = ['initiating', 'ringing', 'connecting', 'active'];

    /** Llamadas vivas que SUENAN para quien mira (su participación en «ringing», sin expulsión). */
    function ringingFor(calls, me) {
        return (calls || []).filter((c) => {
            const p = (c.participants || []).find((x) => Number(x.user_id) === Number(me));
            return Boolean(p) && p.state === 'ringing' && !p.revoked && LIVE_STATUSES.includes(c.status);
        });
    }

    /**
     * Qué avisos mostrar u ocultar SIN duplicados: cada llamada se muestra una vez mientras suene; una
     * llamada ya atendida (aceptada o rechazada aquí o en otra pestaña) no vuelve a mostrarse aunque una
     * lista atrasada la siga trayendo. Las ocultas sin atender se devuelven para comprobar si fue perdida.
     */
    class IncomingTracker {
        constructor() { this.shown = new Map(); this.handled = new Set(); }

        /** @returns {{show: object[], hide: string[]}} */
        update(calls, me) {
            const ringing = ringingFor(calls, me).filter((c) => !this.handled.has(c.id));
            const ids = new Set(ringing.map((c) => c.id));
            const show = ringing.filter((c) => !this.shown.has(c.id));
            const hide = [...this.shown.keys()].filter((id) => !ids.has(id));
            show.forEach((c) => this.shown.set(c.id, c));
            hide.forEach((id) => this.shown.delete(id));
            return { show, hide };
        }

        /** Atendida (aquí o en otra pestaña): se oculta y no vuelve. true si estaba a la vista. */
        handle(id) {
            this.handled.add(id);
            return this.shown.delete(id);
        }
    }

    return { EventDeduper, Coalescer, POLL, pollDelay, sanitize, sanitizeSummary, TabCoordinator, SignalHub, REFRESH_TYPES, layoutFor, participantChanges, sourceAllowed,
        SpeakerTracker, levelBars, qualityClass, featuredFor, endsSession, failureKey, endKey, connectionState, ringingFor, IncomingTracker };
});
