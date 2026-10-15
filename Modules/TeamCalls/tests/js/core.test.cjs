'use strict';
/*
 * Pruebas del núcleo de la interfaz web de TeamCalls (teamcalls-core.js) con el ejecutor nativo de
 * Node (node --test), sin dependencias. Las lanza CallsUiCoreJsTest (Pest) si Node está disponible.
 * Web Locks y BroadcastChannel se simulan con la semántica del estándar que usa el núcleo.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
// El proyecto declara "type": "module": el archivo se carga como ESM y se registra en globalThis (como en el navegador).
const loaded = require(path.join(__dirname, '../../resources/assets/js/teamcalls-core.js'));
const core = (loaded && loaded.SignalHub) ? loaded : globalThis.TeamCallsCore;

const tick = () => new Promise((r) => setImmediate(r));

// Web Locks simulado con la semántica del estándar: request() se resuelve cuando se LIBERA el bloqueo y
// se rechaza (AbortError) para quien lo pierde por «steal»; ifAvailable entrega null; el resto hace cola.
function fakeLocks() {
    const held = new Map(); // name → { reject }
    const queues = new Map();
    const grant = (name, cb, resolve, reject) => {
        const entry = { reject };
        held.set(name, entry);
        Promise.resolve(cb({ name })).then((v) => {
            if (held.get(name) === entry) held.delete(name);
            resolve(v);
            const q = queues.get(name) || [];
            if (!held.has(name) && q.length) { const n = q.shift(); grant(name, n.cb, n.resolve, n.reject); }
        });
    };
    return {
        request(name, opts, cb) {
            if (typeof opts === 'function') { cb = opts; opts = {}; }
            return new Promise((resolve, reject) => {
                if (opts.steal) {
                    const prev = held.get(name);
                    if (prev) prev.reject(Object.assign(new Error('stolen'), { name: 'AbortError' }));
                    grant(name, cb, resolve, reject);
                } else if (held.has(name)) {
                    if (opts.ifAvailable) Promise.resolve(cb(null)).then(resolve);
                    else { const q = queues.get(name) || []; q.push({ cb, resolve, reject }); queues.set(name, q); }
                } else {
                    grant(name, cb, resolve, reject);
                }
            });
        },
        async query() { return { held: [...held.keys()].map((name) => ({ name })) }; },
    };
}

// BroadcastChannel simulado: entrega a las demás pestañas, como el real.
function fakeBus() {
    const channels = new Set();
    return () => {
        const ch = { onmessage: null, posted: [], postMessage(m) { this.posted.push(m); channels.forEach((c) => { if (c !== ch && c.onmessage) c.onmessage({ data: JSON.parse(JSON.stringify(m)) }); }); } };
        channels.add(ch);
        return ch;
    };
}

test('eventos duplicados: el mismo id se procesa una sola vez', () => {
    const d = new core.EventDeduper(3);
    assert.equal(d.first('EV_1'), true);
    assert.equal(d.first('EV_1'), false);
    assert.equal(d.first(undefined), false);
    ['EV_2', 'EV_3', 'EV_4'].forEach((id) => d.first(id));
    assert.equal(d.first('EV_1'), true, 'memoria acotada: los más viejos se olvidan');
});

test('refrescos agrupados: muchas peticiones simultáneas → una en curso y como mucho una más', async () => {
    const c = new core.Coalescer();
    let runs = 0;
    const fn = async () => { runs++; await tick(); };
    await Promise.all([c.run('call', fn), c.run('call', fn), c.run('call', fn), c.run('call', fn)]);
    assert.equal(runs, 2);
    await c.run('call', fn);
    assert.equal(runs, 3);
});

test('cadencia del sondeo: pestaña oculta, llamada, fallos, Retry-After y tiempo real', () => {
    const P = core.POLL;
    assert.equal(core.pollDelay({ phase: 'idle' }), P.idle);
    assert.equal(core.pollDelay({ phase: 'idle', hidden: true }), P.idleHidden);
    assert.ok(core.pollDelay({ phase: 'incoming' }) <= P.idle, 'con un aviso entrante se consulta más a menudo');
    assert.equal(core.pollDelay({ phase: 'in_call' }), P.inCall);
    assert.equal(core.pollDelay({ phase: 'idle', failures: 2 }), P.idle * 4, 'retroceso exponencial ante errores de red');
    assert.equal(core.pollDelay({ phase: 'idle', failures: 10 }), P.max, 'con tope');
    assert.equal(core.pollDelay({ phase: 'idle', retryAfterMs: 45000 }), 45000, 'respeta Retry-After (429)');
    assert.equal(core.pollDelay({ phase: 'in_call', realtime: true }), P.realtimeBackup, 'con tiempo real, el sondeo es solo respaldo');
});

test('mensajes entre pestañas: nunca llevan token, credenciales, sala ni identidad', () => {
    const msg = core.sanitize('call-update', {
        from: 'tab-1',
        token: 'eyJhbGci.secreto',
        call: { id: '01ABC', status: 'active', room_name: 'tc-x', token: 'x', server_url: 'wss://x',
            participants: [{ user_id: 2, state: 'joined', identity: 'u2.web.x', device_id: 'web.x', media_state: 'connected' }] },
    });
    const json = JSON.stringify(msg);
    assert.ok(!/token|secreto|room_name|server_url|identity|device_id|wss:/.test(json), json);
    assert.equal(msg.call.participants[0].media_state, 'connected');
    assert.equal(core.sanitize('desconocido', { a: 1 }), null);
});

test('dos pestañas de la misma sesión: solo una controla; la otra toma el control solo de forma explícita', async () => {
    const locks = fakeLocks();
    const bus = fakeBus();
    const a = new core.TabCoordinator({ locks, channel: bus(), tabId: 'A' });
    const b = new core.TabCoordinator({ locks, channel: bus(), tabId: 'B' });

    assert.equal(await a.acquireControl(false), true);
    assert.equal(await b.acquireControl(false), false, 'la segunda pestaña no toma el control sola');
    assert.equal(await b.controlledElsewhere(), true);

    const seen = [];
    a.onMessage((m) => seen.push(m));
    b.send('takeover', { callId: '01ABC', token: 'nunca' });
    assert.equal(seen[0].type, 'takeover');
    assert.equal(seen[0].token, undefined);
    assert.equal(await b.acquireControl(true), true, 'arrebatar el control (tras avisar)');
    await tick(); await tick();
    assert.equal(a.controlling, false, 'la pestaña anterior pierde el control');
    assert.equal(b.controlling, true);

    b.releaseControl();
    await tick(); await tick();
    assert.equal(await a.acquireControl(false), true, 'al cerrar o recargar, otra pestaña puede recuperar la llamada');
});

test('un solo sondeo por sesión: si la pestaña que sondea se cierra, sigue otra', async () => {
    const locks = fakeLocks();
    const leaders = [];
    let releaseFirst;
    // Primera pestaña: retiene el bloqueo hasta «cerrarse».
    locks.request('teamcalls-poller', () => { leaders.push('A'); return new Promise((r) => { releaseFirst = r; }); });
    const b = new core.TabCoordinator({ locks, channel: null, tabId: 'B' });
    b.pollLeadership(() => leaders.push('B'));
    await tick();
    assert.deepEqual(leaders, ['A']);
    releaseFirst();
    await tick(); await tick();
    assert.deepEqual(leaders, ['A', 'B']);
});

test('sin Web Locks (navegador antiguo): cada pestaña actúa y el servidor decide', async () => {
    const t = new core.TabCoordinator({ locks: null, channel: null, tabId: 'X' });
    assert.equal(await t.acquireControl(false), true);
    assert.equal(await t.controlledElsewhere(), null);
});

test('eventos de tiempo real: nombres reales, sin repetir acciones, y resincronización al (re)conectar', () => {
    const calls = [];
    const hub = new core.SignalHub({ userId: 7, handlers: {
        ringing: (id) => calls.push(['ringing', id]),
        answeredElsewhere: (id) => calls.push(['elsewhere', id]),
        refresh: (id) => calls.push(['refresh', id]),
        resync: () => calls.push(['resync']),
        status: () => {},
    } });
    const env = (id, type, data) => ({ id, type, v: 1, occurred_at: '2026-10-15T00:00:00Z', institution_id: 1, actor_id: 1, data: Object.assign({ call_id: 'C1' }, data) });

    assert.equal(hub.handle(env('E1', 'call.ringing', { user_id: 7 })), 'ringing');
    assert.equal(hub.handle(env('E1', 'call.ringing', { user_id: 7 })), 'duplicate');
    assert.equal(hub.handle(env('E2', 'call.ringing', { user_id: 8 })), 'ignored', 'suena para otra persona');
    assert.equal(hub.handle(env('E3', 'call.answered_elsewhere', { user_id: 7 })), 'answered_elsewhere');
    for (const type of ['call.accepted', 'call.declined', 'call.ended', 'call.participant.joined', 'call.participant.left', 'call.media.room_rotated', 'call.media.left']) {
        assert.equal(hub.handle(env('R-' + type, type, {})), 'refresh', type);
    }
    assert.equal(hub.handle(env('E9', 'call.cancelled', {})), 'ignored', 'no existe: la cancelación llega como call.ended con status cancelled');
    assert.equal(hub.handle({ id: 'E10', type: 'call.ended', v: 2, data: { call_id: 'C1' } }), 'ignored', 'versión desconocida del sobre');

    let push;
    hub.connect({ subscribe: (o) => { push = o; return () => {}; } });
    push.onStatus('connected');
    push.onStatus('connected');
    push.onStatus('disconnected');
    assert.equal(calls.filter((c) => c[0] === 'resync').length, 2, 'resincroniza al conectar y al perder el canal, no en cada aviso igual');
    assert.equal(calls.filter((c) => c[0] === 'ringing').length, 1);
});
