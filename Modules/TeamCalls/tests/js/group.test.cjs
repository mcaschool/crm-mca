'use strict';
/*
 * Fase 4F · lógica sin DOM de las llamadas grupales y la pantalla compartida (teamcalls-core.js):
 * distribución del mosaico, avisos de cambios de participantes y permisos de publicación de LiveKit.
 * La lanza CallsGroupUiTest (Pest) con node --test.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const loaded = require(path.join(__dirname, '../../resources/assets/js/teamcalls-core.js'));
const core = (loaded && loaded.layoutFor) ? loaded : globalThis.TeamCallsCore;

test('mosaico: columnas según el número de personas y el ancho disponible', () => {
    const cols = (tiles, width) => core.layoutFor({ tiles, screens: 0, width }).columns;
    assert.deepEqual([1, 2, 3, 4, 5, 9, 10, 25].map((n) => cols(n, 1400)), [1, 2, 2, 2, 3, 3, 4, 4]);
    // En pantallas estrechas nunca columnas de menos de ~220 px.
    assert.equal(cols(9, 480), 2);
    assert.equal(cols(4, 360), 1);
    assert.equal(core.layoutFor({ tiles: 0, screens: 0 }).mode, 'grid');
});

test('pantalla compartida: contenido principal con los participantes visibles en una tira', () => {
    assert.deepEqual(core.layoutFor({ tiles: 4, screens: 1, width: 1280 }), { mode: 'focus', columns: 1, strip: 'side' });
    assert.deepEqual(core.layoutFor({ tiles: 4, screens: 1, width: 600 }), { mode: 'focus', columns: 1, strip: 'bottom' });
});

test('avisos: rechazó, no contestó, retirado e invitado; nada de quien mira ni sin cambios', () => {
    const prev = { participants: [
        { user_id: 1, state: 'joined' }, { user_id: 2, state: 'ringing' }, { user_id: 3, state: 'ringing' },
        { user_id: 4, state: 'joined' }, { user_id: 5, state: 'missed' }, { user_id: 6, state: 'joined' },
    ] };
    const next = { participants: [
        { user_id: 1, state: 'left', revoked: true }, // quien mira: sin aviso
        { user_id: 2, state: 'declined' }, { user_id: 3, state: 'missed' }, { user_id: 4, state: 'left', revoked: true },
        { user_id: 5, state: 'ringing' }, { user_id: 6, state: 'joined' }, { user_id: 7, state: 'ringing' },
    ] };
    assert.deepEqual(core.participantChanges(prev, next, 1), [
        { userId: 2, kind: 'declined' }, { userId: 3, kind: 'missed' }, { userId: 4, kind: 'removed' },
        { userId: 5, kind: 'invited' }, { userId: 7, kind: 'invited' },
    ]);
    assert.deepEqual(core.participantChanges(next, next, 1), []);
    assert.deepEqual(core.participantChanges(null, { participants: [] }, 1), []);
});

test('4G · orador destacado sin saltos: espera a que alguien hable sostenido y no cambia más de una vez cada 5 s', () => {
    const tr = new core.SpeakerTracker({ hold: 1500, minGap: 5000 });
    const all = ['c:a', 'c:b', 'c:c'];
    assert.equal(tr.update([], all, 0), 'c:a'); // sin nadie hablando: el primero, estable
    assert.equal(tr.update(['c:b'], all, 100), 'c:a'); // b empieza a hablar: todavía no
    assert.equal(tr.update(['c:b'], all, 1000), 'c:a');
    assert.equal(tr.update(['c:b'], all, 5100), 'c:b'); // sostenido y pasado el margen mínimo
    assert.equal(tr.update(['c:c'], all, 5200), 'c:b'); // interrupción breve de c: no salta
    assert.equal(tr.update(['c:b'], all, 5400), 'c:b');
    assert.equal(tr.update(['c:c'], all, 5500), 'c:b');
    assert.equal(tr.update(['c:c'], all, 7100), 'c:b'); // c lleva 1,6 s, pero solo 2 s desde el último cambio
    assert.equal(tr.update(['c:c'], all, 10200), 'c:c');
    assert.equal(tr.update([], ['c:a', 'c:b'], 10300), 'c:a'); // el destacado salió: cambia enseguida
});

test('4G · nivel de audio (0–3 barras) y calidad de conexión del SDK', () => {
    assert.deepEqual([0, 0.01, 0.05, 0.1, 0.5, 1].map((l) => core.levelBars(l, false)), [0, 0, 1, 2, 3, 3]);
    assert.equal(core.levelBars(0.9, true), 0); // silenciado: nada aunque llegue nivel
    assert.equal(core.levelBars(undefined, false), 0);
    assert.deepEqual(['excellent', 'good', 'poor', 'lost', 'unknown', undefined].map(core.qualityClass), ['good', 'good', 'unstable', 'lost', '', '']);
});

test('4G · área principal: fijado (temporal) > pantalla compartida > orador; nada → mosaico', () => {
    assert.deepEqual(core.featuredFor({ pinned: 'c:b', screen: 's:a', speakerView: true, speaker: 'c:c' }), { key: 'c:b', reason: 'pinned' });
    assert.deepEqual(core.featuredFor({ pinned: null, screen: 's:a', speakerView: true, speaker: 'c:c' }), { key: 's:a', reason: 'screen' });
    assert.deepEqual(core.featuredFor({ pinned: null, screen: null, speakerView: true, speaker: 'c:c' }), { key: 'c:c', reason: 'speaker' });
    assert.deepEqual(core.featuredFor({ pinned: null, screen: null, speakerView: false, speaker: 'c:c' }), { key: null, reason: null });
    // Destacado sin nadie más en la tira: una sola columna.
    assert.equal(core.layoutFor({ tiles: 0, screens: 1, width: 1280 }).strip, null);
});

test('4H · sesión o dispositivo revocados terminan la sesión de la página (dejar de consultar y liberar medios)', () => {
    for (const code of ['unauthenticated', 'two_factor_required', 'csrf_token_mismatch', 'session_revoked', 'device_revoked']) {
        assert.equal(core.endsSession(code), true, code);
    }
    for (const code of ['forbidden', 'not_found', 'media_unavailable', 'network', 'too_many_requests', undefined]) {
        assert.equal(core.endsSession(code), false, String(code));
    }
});

test('4I · cada causa de fallo tiene su mensaje (sin mezclar medios, red, sesión, 2FA ni revocaciones)', () => {
    const k = core.failureKey;
    assert.equal(k('media_unavailable'), 'err_media_unavailable');
    assert.equal(k('media_connect_failed'), 'err_media_connect');
    assert.equal(k('timeout'), 'err_timeout');
    assert.equal(k('network'), 'err_server');
    assert.equal(k('unauthenticated'), 'err_session');
    assert.equal(k('two_factor_required'), 'err_two_factor');
    assert.equal(k('session_revoked'), 'err_session_revoked');
    assert.equal(k('device_revoked'), 'err_device_revoked');
    assert.equal(k('forbidden'), 'err_forbidden');
    assert.equal(k('desconocido'), null);
    const keys = ['media_unavailable', 'media_connect_failed', 'timeout', 'two_factor_required', 'session_revoked', 'device_revoked'].map(k);
    assert.equal(new Set(keys).size, keys.length);
});

test('4I · cómo terminó: la finalizó quien la inició (para los demás), cancelada, perdida, fallida', () => {
    const s = (status, reason, initiator) => ({ status, end_reason: reason, initiator_id: initiator });
    assert.equal(core.endKey(s('ended', 'ended_by_host', 1), 2), 'end_by_host');
    assert.equal(core.endKey(s('ended', 'ended_by_host', 2), 2), 'end_ended'); // quien la terminó no recibe ese aviso
    assert.equal(core.endKey(s('cancelled', 'cancelled_by_caller', 1), 2), 'end_cancelled');
    assert.equal(core.endKey(s('missed', 'no_answer', 1), 2), 'end_missed');
    assert.equal(core.endKey(s('failed', 'connect_timeout', 1), 2), 'end_failed');
    assert.equal(core.endKey(null, 2), null);
});

test('4I · indicador de conexión: sin red > servidor de llamadas caído > sin servidor > tiempo real o sondeo', () => {
    const c = core.connectionState;
    assert.equal(c({ online: false, media: 'down', realtime: true }), 'offline');
    assert.equal(c({ online: true, media: 'down', realtime: true }), 'media_down');
    assert.equal(c({ online: true, media: 'unconfigured', realtime: true }), 'media_none');
    assert.equal(c({ online: true, media: 'available', realtime: true }), 'live');
    assert.equal(c({ online: true, media: null, realtime: false }), 'polling');
});

test('4I · aviso de llamada entrante: solo lo que suena para mí, una vez, y lo atendido no vuelve', () => {
    const call = (id, myState, status = 'ringing', revoked = false) => ({ id, status, participants: [{ user_id: 1, state: 'joined' }, { user_id: 2, state: myState, revoked }] });
    assert.deepEqual(core.ringingFor([call('a', 'ringing'), call('b', 'joined'), call('c', 'ringing', 'ended'), call('d', 'ringing', 'ringing', true)], 2).map((c) => c.id), ['a']);

    const tr = new core.IncomingTracker();
    assert.deepEqual(tr.update([call('a', 'ringing')], 2).show.map((c) => c.id), ['a']);
    assert.deepEqual(tr.update([call('a', 'ringing')], 2), { show: [], hide: [] }); // la misma lista otra vez: sin duplicar
    assert.deepEqual(tr.update([call('a', 'ringing'), call('b', 'ringing')], 2).show.map((c) => c.id), ['b']);
    assert.equal(tr.handle('b'), true); // atendida aquí o en otra pestaña
    assert.deepEqual(tr.update([call('a', 'ringing'), call('b', 'ringing')], 2), { show: [], hide: [] }); // una lista atrasada no la revive
    assert.deepEqual(tr.update([], 2), { show: [], hide: ['a'] }); // dejó de sonar sin atenderla: comprobar si fue perdida
});

test('pantalla: solo si LiveKit concede la fuente (numérica o por nombre); sin permiso de publicar, nunca', () => {
    assert.equal(core.sourceAllowed({ canPublish: true, canPublishSources: [2, 1, 3, 4] }, 'SCREEN_SHARE'), true);
    assert.equal(core.sourceAllowed({ canPublish: true, canPublishSources: ['MICROPHONE', 'CAMERA', 'SCREEN_SHARE'] }, 'SCREEN_SHARE'), true);
    // Llamada de voz: el backend concede solo el micrófono.
    assert.equal(core.sourceAllowed({ canPublish: true, canPublishSources: [2] }, 'SCREEN_SHARE'), false);
    assert.equal(core.sourceAllowed({ canPublish: true, canPublishSources: ['MICROPHONE'] }, 'CAMERA'), false);
    // Credencial recién entregada (sala sin medios hasta que el backend admite la conexión).
    assert.equal(core.sourceAllowed({ canPublish: false, canPublishSources: [] }, 'SCREEN_SHARE'), false);
    assert.equal(core.sourceAllowed(null, 'SCREEN_SHARE'), false);
});
