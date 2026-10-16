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
