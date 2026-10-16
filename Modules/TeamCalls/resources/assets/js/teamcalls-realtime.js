/*
 * TeamCalls · adaptador de CollaborationRealtime para la interfaz de llamadas.
 *
 * NO es otro sistema de tiempo real: es un cliente del existente (el mismo protocolo que usa TeamChat).
 *  1. GET  {api}/realtime/channel  → canal privado del DISPOSITIVO (lo deriva el servidor).
 *  2. WebSocket a Reverb, POST {api}/realtime/auth (firma del canal) y suscripción.
 *  3. Cada aviso `collaboration.sync.required` → GET {api}/sync desde el cursor confirmado. El servidor
 *     revalida sesión, dispositivo, collaboration.use y la política de cada evento antes de entregarlo.
 *  4. Solo los eventos call.* pasan a la interfaz (window.teamcallsUi.useRealtime / TeamCallsRealtime).
 * El socket solo despierta: los datos vienen de la API autorizada. Si el canal cae, la interfaz vuelve al
 * sondeo (SignalHub recibe 'disconnected'); al volver, resincroniza. 401/403 detienen el adaptador.
 * Sin credenciales en almacenamiento del navegador.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.TeamCallsRealtimeAdapter = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function createAdapter(cfg) {
        const env = Object.assign({
            fetch: (...a) => globalThis.fetch(...a),
            WebSocket: globalThis.WebSocket,
            setTimeout: (...a) => globalThis.setTimeout(...a),
            clearTimeout: (...a) => globalThis.clearTimeout(...a),
            random: Math.random,
        }, cfg.env || {});
        const base = String(cfg.api || '').replace(/\/$/, '');

        return {
            subscribe({ onEvent, onStatus }) {
                const st = { stopped: false, ws: null, cursor: null, syncing: false, again: false, retry: 0, timer: null, connected: false };

                async function call(method, path, body) {
                    const res = await env.fetch(base + path, {
                        method,
                        credentials: 'same-origin',
                        headers: Object.assign({ Accept: 'application/json' },
                            body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf || '' } : {}),
                        body: body ? JSON.stringify(body) : undefined,
                    });
                    if (!res.ok) { const e = new Error('http ' + res.status); e.status = res.status; throw e; }
                    return res.json();
                }

                function status(connected) {
                    if (st.connected === connected) return;
                    st.connected = connected;
                    onStatus(connected ? 'connected' : 'disconnected');
                }

                function stop() {
                    st.stopped = true;
                    if (st.ws) st.ws.close();
                    env.clearTimeout(st.timer);
                    status(false);
                }

                async function bootstrap() {
                    // Corte estable: los eventos posteriores llegan por el feed; el estado actual lo relee la interfaz.
                    const snap = await call('GET', '/sync?bootstrap=1&limit=1');
                    st.cursor = snap.data.cursor;
                }

                async function sync() {
                    if (st.stopped) return;
                    if (st.syncing) { st.again = true; return; }
                    st.syncing = true;
                    try {
                        if (!st.cursor) await bootstrap();
                        do {
                            st.again = false;
                            const page = (await call('GET', '/sync?limit=100&cursor=' + encodeURIComponent(st.cursor))).data;
                            for (const e of page.events) if (typeof e.type === 'string' && e.type.startsWith('call.')) onEvent(e);
                            st.cursor = page.cursor;
                            st.again = st.again || page.hasMore;
                        } while (st.again && !st.stopped);
                    } catch (e) {
                        if (e.status === 401 || e.status === 403) stop();
                        else if (e.status === 410) { st.cursor = null; status(false); } // cursor vencido: nuevo corte
                    } finally {
                        st.syncing = false;
                    }
                }

                function reconnect() {
                    if (st.stopped) return;
                    const wait = Math.min(30000, 1000 * 2 ** Math.min(st.retry++, 5)) + env.random() * 500;
                    st.timer = env.setTimeout(connect, wait);
                }

                async function connect() {
                    if (st.stopped) return;
                    const s = cfg.socket || {};
                    if (!s.key || !env.WebSocket) { status(false); return; }
                    let channel;
                    try {
                        channel = (await call('GET', '/realtime/channel')).data.channel;
                    } catch (e) {
                        if (e.status === 401 || e.status === 403) { stop(); return; }
                        reconnect(); return;
                    }
                    const scheme = s.scheme === 'https' ? 'wss' : 'ws';
                    const ws = new env.WebSocket(scheme + '://' + s.host + ':' + s.port + '/app/' + encodeURIComponent(s.key)
                        + '?protocol=7&client=teamcalls&version=1.0&flash=false');
                    st.ws = ws;
                    ws.onmessage = async (msg) => {
                        if (st.ws !== ws || st.stopped) return;
                        let frame;
                        try { frame = JSON.parse(msg.data); } catch (e) { return; }
                        const value = typeof frame.data === 'string' ? JSON.parse(frame.data || '{}') : (frame.data || {});
                        try {
                            if (frame.event === 'pusher:connection_established') {
                                const auth = await call('POST', '/realtime/auth', { socket_id: value.socket_id, channel_name: channel });
                                ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: auth.auth } }));
                            } else if (frame.event === 'pusher_internal:subscription_succeeded') {
                                st.retry = 0;
                                await sync();
                                status(true);
                            } else if (frame.event === 'collaboration.sync.required') {
                                await sync();
                            } else if (frame.event === 'pusher:ping') {
                                ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                            } else if (frame.event === 'pusher:error') {
                                ws.close();
                            }
                        } catch (e) {
                            if (e.status === 401 || e.status === 403) stop(); else ws.close();
                        }
                    };
                    ws.onerror = () => ws.close();
                    ws.onclose = () => {
                        if (st.ws !== ws || st.stopped) return;
                        status(false);
                        reconnect();
                    };
                }

                connect();
                return stop;
            },
        };
    }

    return { createAdapter };
});
