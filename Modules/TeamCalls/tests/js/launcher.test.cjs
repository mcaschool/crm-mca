'use strict';
/*
 * Pruebas del lanzador de llamadas (teamcalls-launcher.js, fase 4E) con el ejecutor nativo de Node,
 * sin dependencias. Las lanza CallLaunchTest (Pest) si Node está disponible. La ventana, el documento y
 * fetch se simulan con lo mínimo que usa el lanzador.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

require(path.join(__dirname, '../../resources/assets/js/teamcalls-launcher.js'));
const { create, normalize, intentFromElement } = globalThis.MCACallsLauncher;

const ULID = '01HZY3J9S2Q8W6N4R7T5V1X0BC';
const PAGE = 'https://crm.test/colaboracion/teamcalls';
const fixedCrypto = { randomUUID: () => '11111111-2222-3333-4444-555555555555' };

class FakeEvent {
    constructor(name, init) { Object.assign(this, { type: name, detail: init.detail, cancelable: init.cancelable, defaultPrevented: false }); }
    preventDefault() { if (this.cancelable) this.defaultPrevented = true; }
}

function element(dataset, listeners) {
    return {
        dataset, disabled: false,
        getAttribute: () => null,
        closest() { return this; },
        dispatchEvent(ev) { (listeners[ev.type] || []).forEach((fn) => fn(ev)); return !ev.defaultPrevented; },
    };
}

function env({ existing = null, blocked = false, framed = false, fetchStatus = 200 } = {}) {
    const calls = { open: [], assign: [], fetch: [] };
    const doc = { listeners: {}, attrs: {}, documentElement: { setAttribute(k, v) { doc.attrs[k] = v; } }, addEventListener(t, fn) { doc.listeners[t] = fn; } };
    const win = {
        crypto: fixedCrypto,
        location: { origin: 'https://crm.test', assign: (u) => calls.assign.push(u) },
        CustomEvent: FakeEvent,
        open(url, name) {
            calls.open.push([url, name]);
            if (blocked) return null;
            return existing || (existing = { location: { href: 'about:blank', origin: 'null' }, focus() { this.focused = true; } });
        },
        async fetch(url) { calls.fetch.push(url); return { ok: fetchStatus === 200, json: async () => ({ data: { id: ULID, media: 'video' } }) }; },
    };
    win.top = framed ? {} : win;
    win.self = win;
    const api = create({ window: win, document: doc, page: PAGE, active: 'https://crm.test/api/v1/collaboration/calls/active' });
    return { api, win, doc, calls, existing: () => existing };
}

test('normalize: mismas combinaciones que el servidor (CallLaunch)', () => {
    assert.deepEqual(normalize({ media: 'video', user: 42, key: 'l-key-0001' }), { media: 'video', user: '42', key: 'l-key-0001' });
    assert.deepEqual(normalize({ media: 'audio', conversation: { type: 'direct', id: ULID } }, fixedCrypto).conversation, 'direct:' + ULID);
    assert.equal(normalize({ media: 'audio', conversation: 'channel:' + ULID, invitees: [5, '6', 5] }).invitees, '5,6');
    assert.deepEqual(normalize({ call: ULID.toLowerCase() }), { call: ULID.toLowerCase() }); // tal cual (TeamCalls: minúsculas)
    // Fase 4I: contestar al abrir (aviso de llamada entrante); solo con una llamada existente.
    assert.deepEqual(normalize({ call: ULID, answer: true }), { call: ULID, answer: '1' });
    assert.throws(() => normalize({ media: 'audio', user: 1, answer: true }), TypeError);
    // Clave de idempotencia generada si no se da (recargar la misma URL no crea otra llamada).
    assert.match(normalize({ media: 'audio', user: 1 }, fixedCrypto).key, /^l-[A-Za-z0-9-]{8,46}$/);

    const bad = [
        null, {}, { user: 1 }, { media: 'fax', user: 1 }, { media: 'audio', user: 0 },
        { media: 'audio', user: 1, conversation: 'direct:' + ULID },
        { media: 'audio', user: 1, invitees: [2] },
        { media: 'audio', conversation: 'direct:' + ULID, invitees: [2] },
        { media: 'audio', conversation: 'group:' + ULID },
        { media: 'audio', conversation: 'group:' + ULID, invitees: [] },
        { media: 'audio', conversation: 'channel:' + ULID, invitees: Array.from({ length: 25 }, (_, i) => i + 1) },
        { media: 'audio', conversation: 'team:' + ULID, invitees: [2] },
        { media: 'audio', conversation: 'direct:../x' },
        { call: ULID, media: 'audio' }, { call: 'x' },
        { media: 'audio', user: 1, key: 'corta' },
    ];
    for (const intent of bad) assert.throws(() => normalize(intent), TypeError, JSON.stringify(intent));
});

test('url: la intención validada en los parámetros que entiende la página', () => {
    const { api } = env();
    const u = new URL(api.url({ media: 'video', conversation: 'group:' + ULID, invitees: [3, 4], key: 'l-key-0002' }));
    assert.equal(u.origin + u.pathname, PAGE);
    assert.deepEqual(Object.fromEntries(u.searchParams), { media: 'video', conversation: 'group:' + ULID, invitees: '3,4', key: 'l-key-0002' });
});

test('open: sin ventana de llamadas la abre en la URL de la intención (ventana con nombre propio)', () => {
    const { api, calls, existing } = env();
    const r = api.open({ media: 'audio', user: 7, key: 'l-key-0003' });
    assert.deepEqual(calls.open[0], ['', 'mca-teamcalls']);
    assert.equal(r.opened, true);
    assert.equal(r.reused, false);
    assert.equal(existing().location.href, PAGE + '?media=audio&user=7&key=l-key-0003');
    assert.equal(existing().focused, true);
});

test('open: si la ventana de llamadas ya está abierta le pasa la intención SIN recargarla', () => {
    const launched = [];
    const page = { location: { href: PAGE, origin: 'https://crm.test' }, teamcallsUi: { launch: (q) => launched.push(q) }, focus() {} };
    const { api } = env({ existing: page });
    const r = api.open({ call: ULID });
    assert.equal(r.reused, true);
    assert.deepEqual(launched, [{ call: ULID }]);
    assert.equal(page.location.href, PAGE); // no se navegó: la llamada en curso sigue
});

test('open: una ventana con ese nombre de otro origen o página no recibe la intención: se navega', () => {
    const foreign = { location: { href: 'https://otro.test/', origin: 'https://otro.test' }, teamcallsUi: { launch() { throw new Error('no'); } }, focus() {} };
    const { api } = env({ existing: foreign });
    assert.equal(api.open({ call: ULID }).reused, false);
    assert.equal(foreign.location.href, PAGE + '?call=' + ULID);
});

test('open: ventanas emergentes bloqueadas → navega la página; dentro de un marco (widget) no navega', () => {
    const top = env({ blocked: true });
    assert.equal(top.api.open({ call: ULID }).navigated, true);
    assert.deepEqual(top.calls.assign, [PAGE + '?call=' + ULID]);

    const framed = env({ blocked: true, framed: true });
    const r = framed.api.open({ call: ULID });
    assert.equal(r.opened, false);
    assert.equal(r.url, PAGE + '?call=' + ULID);
    assert.deepEqual(framed.calls.assign, []);
});

test('active: llamada viva de una conversación; sin acceso (p. ej. credenciales del widget) → null', async () => {
    const ok = env();
    assert.equal((await ok.api.active({ type: 'direct', id: ULID })).id, ULID);
    assert.equal(ok.calls.fetch[0], 'https://crm.test/api/v1/collaboration/calls/active?type=direct&id=' + ULID);
    assert.equal(await env({ fetchStatus: 403 }).api.active('channel:' + ULID), null);
    await assert.rejects(() => ok.api.active('team:' + ULID), TypeError);
});

test('botones declarativos: atributos → intención; before puede completarla o cancelar; error si no es válida', () => {
    assert.deepEqual(intentFromElement({ dataset: { mcaCall: 'video', mcaCallConversation: 'channel:' + ULID, mcaCallInvitees: '3,4' } }),
        { media: 'video', conversation: 'channel:' + ULID, invitees: '3,4' });
    assert.deepEqual(intentFromElement({ dataset: { mcaCall: 'open', mcaCallId: ULID } }), { call: ULID });

    const { doc, existing } = env();
    assert.equal(doc.attrs['data-mca-calls'], 'on');
    const seen = {};
    const listeners = {
        'mca-call:before': [(ev) => { ev.detail.intent.invitees = [9]; }], // la aplicación aporta su selección
        'mca-call:opened': [(ev) => { seen.opened = ev.detail; }],
        'mca-call:error': [(ev) => { seen.error = ev.detail.error; }],
    };
    const btn = element({ mcaCall: 'audio', mcaCallConversation: 'group:' + ULID }, listeners);
    const click = { target: btn, preventDefault() { this.prevented = true; } };
    doc.listeners.click(click);
    assert.equal(click.prevented, true);
    assert.equal(seen.opened.opened, true);
    assert.match(existing().location.href, /media=audio&conversation=group%3A01HZY3J9S2Q8W6N4R7T5V1X0BC&invitees=9&key=/);

    const cancelled = env();
    const stop = element({ mcaCall: 'audio', mcaCallUser: '5' }, { 'mca-call:before': [(ev) => ev.preventDefault()] });
    cancelled.doc.listeners.click({ target: stop, preventDefault() {} });
    assert.deepEqual(cancelled.calls.open, []);

    const invalid = env();
    const broken = element({ mcaCall: 'audio', mcaCallConversation: 'group:' + ULID }, { 'mca-call:error': [(ev) => { seen.invalid = ev.detail.error; }] });
    invalid.doc.listeners.click({ target: broken, preventDefault() {} });
    assert.ok(seen.invalid instanceof TypeError); // grupo sin selección explícita
    assert.deepEqual(invalid.calls.open, []);
});
