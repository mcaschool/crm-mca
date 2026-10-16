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
            return { mode: 'focus', columns: 1, strip: width < 760 ? 'bottom' : 'side' };
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

    return { EventDeduper, Coalescer, POLL, pollDelay, sanitize, sanitizeSummary, TabCoordinator, SignalHub, REFRESH_TYPES, layoutFor, participantChanges, sourceAllowed };
});
