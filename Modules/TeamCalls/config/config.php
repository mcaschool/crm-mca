<?php

declare(strict_types=1);

/*
| TeamCalls · motor de llamadas internas. Sin secretos: las credenciales del futuro servidor de
| medios (LiveKit) se leerán del entorno cuando exista la integración real.
*/
return [
    'name' => 'TeamCalls',

    // Segundos que suena una invitación antes de contarse como perdida.
    'ring_seconds' => (int) env('TEAMCALLS_RING_SECONDS', 45),

    // Segundos máximos entre la primera aceptación y que haya dos personas conectadas a la sala.
    'connect_seconds' => (int) env('TEAMCALLS_CONNECT_SECONDS', 90),

    // Segundos para reconectarse tras una desconexión confirmada por el servidor de medios antes de
    // salir de la llamada (LiveKit notifica la salida de la sesión vieja antes que la entrada de la nueva).
    'reconnect_grace' => (int) env('TEAMCALLS_RECONNECT_GRACE', 20),

    // Participantes máximos por llamada (incluida quien la inicia).
    'max_participants' => (int) env('TEAMCALLS_MAX_PARTICIPANTS', 25),

    // Vigencia de las credenciales de acceso a la sala de medios (no se asumen de un solo uso).
    'join_token_ttl' => (int) env('TEAMCALLS_JOIN_TOKEN_TTL', 300),

    /*
    | Servidor de medios. driver «none» (por defecto): sin salas reales, unirse y credenciales dan 503.
    | driver «livekit»: LiveKit AUTOHOSPEDADO (VPS propio, nunca el hosting compartido). Si falta algún
    | dato, o las URL no son https/wss (salvo allow_insecure_dev_urls en local/testing), se comporta
    | como «none» (falla cerrado).
    | En el servidor LiveKit: room.auto_create = false (solo el backend crea salas) y webhook hacia
    | /api/teamcalls/media/livekit con la misma API key.
    */
    'media' => [
        'driver' => env('TEAMCALLS_MEDIA_DRIVER', 'none'),

        'livekit' => [
            // URL del API de LiveKit desde ESTE servidor (Twirp RoomService), p. ej. https://rtc.interno:7880
            'api_url' => env('TEAMCALLS_LIVEKIT_API_URL'),
            // URL a la que se conectan los clientes (navegador, Android, iOS), p. ej. wss://rtc.ejemplo.org
            'client_url' => env('TEAMCALLS_LIVEKIT_CLIENT_URL'),
            'api_key' => env('TEAMCALLS_LIVEKIT_API_KEY'),
            // Secreto HS256 (mínimo 32 caracteres). Solo en el entorno del servidor, nunca en el repositorio.
            'api_secret' => env('TEAMCALLS_LIVEKIT_API_SECRET'),

            // Llamadas al API: segundos de espera, de conexión y reintentos (solo operaciones idempotentes).
            'timeout' => (int) env('TEAMCALLS_LIVEKIT_TIMEOUT', 5),
            'connect_timeout' => (int) env('TEAMCALLS_LIVEKIT_CONNECT_TIMEOUT', 3),
            'retries' => (int) env('TEAMCALLS_LIVEKIT_RETRIES', 2),
            'retry_sleep_ms' => (int) env('TEAMCALLS_LIVEKIT_RETRY_SLEEP_MS', 200),
            // Fase 4I · fallos de LiveKit: reintentos ante fallo de CONEXIÓN (menos que ante 5xx) y disyuntor:
            // tras un fallo, las operaciones fallan al instante durante breaker_seconds (0 lo desactiva).
            'connect_retries' => (int) env('TEAMCALLS_LIVEKIT_CONNECT_RETRIES', 1),
            'breaker_seconds' => (int) env('TEAMCALLS_LIVEKIT_BREAKER_SECONDS', 30),

            // Vigencia de los tokens de servicio del backend hacia LiveKit (segundos).
            'server_token_ttl' => (int) env('TEAMCALLS_LIVEKIT_SERVER_TOKEN_TTL', 60),
            // La sala se cierra sola si queda vacía este tiempo, o tras la salida del último participante.
            'empty_timeout' => (int) env('TEAMCALLS_LIVEKIT_EMPTY_TIMEOUT', 120),
            'departure_timeout' => (int) env('TEAMCALLS_LIVEKIT_DEPARTURE_TIMEOUT', 20),
            // Tolerancia de reloj al validar la firma de los webhooks (segundos).
            'webhook_leeway' => (int) env('TEAMCALLS_LIVEKIT_WEBHOOK_LEEWAY', 30),

            // Excepción SOLO de desarrollo: admite http/ws (LiveKit local sin TLS). Se ignora fuera de los
            // entornos local y testing. Las credenciales del contrato común siguen exigiendo https/wss.
            'allow_insecure_dev_urls' => (bool) env('TEAMCALLS_LIVEKIT_ALLOW_INSECURE_DEV_URLS', false),
        ],
    ],

    /*
    | Autorización de personas (fase 4B). «collaboration» (por defecto): permiso definitivo collaboration.use
    | de CollaborationCore, que no exige ni concede acceso al CRM. «provisional»: el criterio anterior (panel
    | activo con acceso al CRM), solo como vuelta atrás mientras se valida la integración.
    */
    'authorization' => [
        'driver' => env('TEAMCALLS_AUTHORIZATION_DRIVER', 'collaboration'),
    ],

    /*
    | Tiempo real de la interfaz (CollaborationRealtime): dirección PÚBLICA de Reverb para el navegador (puede
    | diferir de la interna del publicador). Sin clave de Reverb, la interfaz sigue solo con el sondeo.
    */
    'realtime' => [
        'host' => env('TEAMCALLS_REVERB_HOST', env('REVERB_HOST', '127.0.0.1')),
        'port' => (int) env('TEAMCALLS_REVERB_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('TEAMCALLS_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
    ],

    /*
    | Interfaz web de llamadas. Deshabilitada por defecto: solo existe con el interruptor activo Y en uno
    | de los entornos listados (fuera de eso, sus rutas dan 404). Por defecto, solo desarrollo y pruebas;
    | la PREPRODUCCIÓN se habilita nombrando su entorno de forma explícita (fase 4H), p. ej.
    | TEAMCALLS_UI_ENVIRONMENTS=staging. «production» se ignora siempre (CallLaunch::enabled): abrirla a
    | producción requiere una autorización posterior y un cambio de código, no de configuración.
    */
    'ui' => [
        'enabled' => (bool) env('TEAMCALLS_UI_ENABLED', false),
        'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEAMCALLS_UI_ENVIRONMENTS', 'local,testing'))))),
        // Sondeo temporal (hasta CollaborationRealtime): cadencia base con la pestaña visible y sin llamada.
        // El cliente la adapta (oculta 15 s, en llamada 6 s, retroceso ante fallos) y solo sondea una pestaña.
        'poll_ms' => (int) env('TEAMCALLS_UI_POLL_MS', 4000),
        // Permite ?sintetico=1 (audio/video generados) para pruebas automatizadas sin cámara ni micrófono.
        'synthetic_media' => (bool) env('TEAMCALLS_UI_SYNTHETIC_MEDIA', false),
        // Fase 4I · tiempos máximos del navegador (ms): peticiones normales, unirse y credenciales (incluye la
        // detección de LiveKit caído en el servidor, ~6 s), conexión de medios de LiveKit (WebSocket y WebRTC,
        // holgada para redes lentas legítimas) y a partir de cuándo se avisa de que tarda más de lo normal.
        // Fase 4I · aviso de llamada entrante en cualquier pantalla del CRM (solo donde la interfaz está habilitada).
        // Se desactiva cuando TeamChat/el widget asuman los avisos de llamada (o lo hacen marcando
        // <html data-mca-call-alerts="widget">). Sondeo de respaldo de la pestaña que lidera, en ms.
        'incoming' => [
            'enabled' => (bool) env('TEAMCALLS_UI_INCOMING_NOTIFIER', true),
            'ringtone' => (bool) env('TEAMCALLS_UI_INCOMING_RINGTONE', true),
            'poll_ms' => (int) env('TEAMCALLS_UI_INCOMING_POLL_MS', 8000),
            'hidden_poll_ms' => (int) env('TEAMCALLS_UI_INCOMING_HIDDEN_POLL_MS', 20000),
            'realtime_poll_ms' => (int) env('TEAMCALLS_UI_INCOMING_REALTIME_POLL_MS', 30000),
        ],
        'timeouts' => [
            'request_ms' => (int) env('TEAMCALLS_UI_REQUEST_TIMEOUT_MS', 15000),
            'connect_ms' => (int) env('TEAMCALLS_UI_CONNECT_TIMEOUT_MS', 20000),
            'media_ms' => (int) env('TEAMCALLS_UI_MEDIA_TIMEOUT_MS', 20000),
            'slow_ms' => (int) env('TEAMCALLS_UI_SLOW_AFTER_MS', 6000),
        ],
    ],

    // Límites de la API web por persona y minuto: iniciar llamadas, resto de operaciones y consultas.
    'rate_limits' => [
        'start' => (int) env('TEAMCALLS_RATE_START', 10),
        'write' => (int) env('TEAMCALLS_RATE_WRITE', 60),
        'read' => (int) env('TEAMCALLS_RATE_READ', 120),
        // Webhooks del servidor de medios, por IP.
        'webhook' => (int) env('TEAMCALLS_RATE_WEBHOOK', 600),
    ],
];
