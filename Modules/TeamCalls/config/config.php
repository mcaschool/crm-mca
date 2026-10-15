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

    // Límites de la API web por persona y minuto: iniciar llamadas, resto de operaciones y consultas.
    'rate_limits' => [
        'start' => (int) env('TEAMCALLS_RATE_START', 10),
        'write' => (int) env('TEAMCALLS_RATE_WRITE', 60),
        'read' => (int) env('TEAMCALLS_RATE_READ', 120),
    ],
];
