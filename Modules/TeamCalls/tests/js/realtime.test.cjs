// Adaptador de CollaborationRealtime para la interfaz de TeamCalls (node --test).
// Dobles de fetch y WebSocket: comprueba el protocolo (canal del dispositivo, firma, suscripción, sync)
// y que solo pasan los call.*. NO sustituye a la prueba con Reverb real (ver README, fase 4B).
const test = require('node:test');
const assert = require('node:assert/strict');
// El proyecto declara "type": "module": el archivo se carga como ESM y se registra en globalThis (como en el navegador).
const loaded = require('../../resources/assets/js/teamcalls-realtime.js');
const { createAdapter } = (loaded && loaded.createAdapter) ? loaded : globalThis.TeamCallsRealtimeAdapter;

function harness(pages, opts = {}) {
    const calls = [];
    const sockets = [];
    let sync = 0;
    const fetch = async (url, init) => {
        calls.push({ url, method: init.method, body: init.body, headers: init.headers });
        const path = url.replace('/api/v1/collaboration', '');
        const ok = (body) => ({ ok: true, status: 200, json: async () => body });
        const failure = opts.fail ? opts.fail(path) : 0;
        if (failure) return { ok: false, status: failure, json: async () => ({}) };
        if (path === '/realtime/channel') return ok({ data: { channel: 'private-collaboration.abc', event: 'collaboration.sync.required' } });
        if (path === '/realtime/auth') return ok({ auth: 'key:sig' });
        if (path.startsWith('/sync?bootstrap=1')) return ok({ data: { resources: [], cursor: 'c0', nextPageCursor: null } });
        if (path.startsWith('/sync?')) return ok({ data: pages[Math.min(sync++, pages.length - 1)] });
        throw new Error('unexpected ' + path);
    };
    class FakeWs {
        constructor(url) { this.url = url; this.sent = []; sockets.push(this); }
        send(m) { this.sent.push(JSON.parse(m)); }
        close() { if (this.onclose) this.onclose(); }
        async emit(event, data) { await this.onmessage({ data: JSON.stringify({ event, data: JSON.stringify(data || {}) }) }); }
    }
    const timers = [];
    const adapter = createAdapter({
        api: '/api/v1/collaboration', csrf: 'tok',
        socket: { key: 'app-key', host: 'localhost', port: 8080, scheme: 'http' },
        env: { fetch, WebSocket: FakeWs, setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout: () => {}, random: () => 0 },
    });
    return { adapter, calls, sockets, timers };
}

const tick = () => new Promise((r) => setImmediate(r));

test('canal del dispositivo, firma con CSRF y suscripción; solo eventos call.* llegan a la interfaz', async () => {
    const page = { events: [
        { id: 'e1', type: 'call.ringing', v: 1, data: { call_id: 'C1', user_id: 7 } },
        { id: 'e2', type: 'teamchat.message.created', v: 1, data: { conversation_id: 'X' } },
        { id: 'e3', type: 'call.ended', v: 1, data: { call_id: 'C1', status: 'cancelled', reason: 'x' } },
    ], cursor: 'c1', hasMore: false };
    const h = harness([page, { events: [], cursor: 'c2', hasMore: false }]);
    const got = []; const status = [];
    const stop = h.adapter.subscribe({ userId: 7, onEvent: (e) => got.push(e.id), onStatus: (s) => status.push(s) });
    await tick();
    const ws = h.sockets[0];
    assert.match(ws.url, /^ws:\/\/localhost:8080\/app\/app-key\?protocol=7/);
    await ws.emit('pusher:connection_established', { socket_id: '1.2' });
    const auth = h.calls.find((c) => c.url.endsWith('/realtime/auth'));
    assert.equal(auth.method, 'POST');
    assert.equal(auth.headers['X-CSRF-TOKEN'], 'tok');
    assert.deepEqual(JSON.parse(auth.body), { socket_id: '1.2', channel_name: 'private-collaboration.abc' });
    assert.deepEqual(ws.sent[0], { event: 'pusher:subscribe', data: { channel: 'private-collaboration.abc', auth: 'key:sig' } });
    await ws.emit('pusher_internal:subscription_succeeded');
    assert.deepEqual(got, ['e1', 'e3']);
    assert.deepEqual(status, ['connected']);
    // Un aviso posterior relee desde el cursor confirmado.
    await ws.emit('collaboration.sync.required');
    assert.ok(h.calls.some((c) => c.url.includes('cursor=c1')));
    stop();
    assert.deepEqual(status, ['connected', 'disconnected']);
});

test('caída del canal → disconnected y reintento con espera', async () => {
    const h = harness([{ events: [], cursor: 'c1', hasMore: false }]);
    const status = [];
    h.adapter.subscribe({ userId: 1, onEvent: () => {}, onStatus: (s) => status.push(s) });
    await tick();
    await h.sockets[0].emit('pusher:connection_established', { socket_id: '1.1' });
    await h.sockets[0].emit('pusher_internal:subscription_succeeded');
    h.sockets[0].close();
    assert.deepEqual(status, ['connected', 'disconnected']);
    assert.equal(h.timers.length, 1); // reconexión programada (el sondeo de la interfaz sigue mientras)
    await h.timers[0](); await tick();
    assert.equal(h.sockets.length, 2);
});

test('401/403 detienen el adaptador sin reintentos', async () => {
    const h = harness([], { fail: (p) => (p === '/realtime/channel' ? 403 : 0) });
    const status = [];
    h.adapter.subscribe({ userId: 1, onEvent: () => {}, onStatus: (s) => status.push(s) });
    await tick();
    assert.equal(h.sockets.length, 0);
    assert.equal(h.timers.length, 0);
});

test('sin clave de Reverb no abre socket: la interfaz sigue con el sondeo', async () => {
    const adapter = createAdapter({ api: '/x', socket: {}, env: { fetch: async () => { throw new Error('no'); }, WebSocket: class {} } });
    const status = [];
    adapter.subscribe({ userId: 1, onEvent: () => {}, onStatus: (s) => status.push(s) });
    await tick();
    assert.deepEqual(status, []); // nunca estuvo conectado: no hay cambio que notificar
});

test('cursor vencido (410): nuevo corte de bootstrap y la interfaz resincroniza', async () => {
    let calls = 0;
    const h = harness([{ events: [], cursor: 'c1', hasMore: false }], { fail: (p) => (p.startsWith('/sync?limit') && calls++ === 1 ? 410 : 0) });
    const status = [];
    h.adapter.subscribe({ userId: 1, onEvent: () => {}, onStatus: (s) => status.push(s) });
    await tick();
    await h.sockets[0].emit('pusher:connection_established', { socket_id: '1.1' });
    await h.sockets[0].emit('pusher_internal:subscription_succeeded');
    await h.sockets[0].emit('collaboration.sync.required'); // este sync recibe 410
    assert.deepEqual(status, ['connected', 'disconnected']); // SignalHub releerá el estado de la API
    await h.sockets[0].emit('collaboration.sync.required');
    assert.equal(h.calls.filter((c) => c.url.includes('bootstrap=1')).length, 2); // nuevo corte
});
