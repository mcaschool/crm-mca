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

    // Participantes máximos por llamada (incluida quien la inicia).
    'max_participants' => (int) env('TEAMCALLS_MAX_PARTICIPANTS', 25),

    // Vigencia de las credenciales de acceso a la sala de medios (no se asumen de un solo uso).
    'join_token_ttl' => (int) env('TEAMCALLS_JOIN_TOKEN_TTL', 300),

    /*
    | Servidor de medios. driver «none» (por defecto): sin salas reales, unirse y credenciales dan 503.
    | driver «livekit»: LiveKit AUTOHOSPEDADO (VPS propio, nunca el hosting compartido). Si falta algún
    | dato, o en producción las URL no son https/wss, se comporta como «none» (falla cerrado).
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

            // Vigencia de los tokens de servicio del backend hacia LiveKit (segundos).
            'server_token_ttl' => (int) env('TEAMCALLS_LIVEKIT_SERVER_TOKEN_TTL', 60),
            // La sala se cierra sola si queda vacía este tiempo, o tras la salida del último participante.
            'empty_timeout' => (int) env('TEAMCALLS_LIVEKIT_EMPTY_TIMEOUT', 120),
            'departure_timeout' => (int) env('TEAMCALLS_LIVEKIT_DEPARTURE_TIMEOUT', 20),
            // Tolerancia de reloj al validar la firma de los webhooks (segundos).
            'webhook_leeway' => (int) env('TEAMCALLS_LIVEKIT_WEBHOOK_LEEWAY', 30),
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
