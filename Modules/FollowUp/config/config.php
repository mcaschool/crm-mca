<?php

declare(strict_types=1);

return [
    'name' => 'FollowUp',

    // Etapa 1: solo detecta y registra. El interruptor nace apagado para que un
    // despliegue del codigo no active procesos nuevos sin una decision operativa.
    'detection_enabled' => (bool) env('FOLLOW_UP_DETECTION_ENABLED', false),

    // Una conversacion solo se evalua tras este periodo desde su ultimo mensaje.
    'silence_hours' => (int) env('FOLLOW_UP_SILENCE_HOURS', 24),

    // Acota el bootstrap y evita recorrer historiales indefinidos.
    'lookback_days' => (int) env('FOLLOW_UP_LOOKBACK_DAYS', 90),

    // Lote pequeno e indexado; cada pasada continua donde haya actividad nueva.
    'batch_size' => (int) env('FOLLOW_UP_BATCH_SIZE', 100),

    // Etapa 2: interruptor independiente para evaluar/programar acciones locales.
    // Nace apagado; la ejecución requiere además su propio interruptor independiente.
    'policy_enabled' => (bool) env('FOLLOW_UP_POLICY_ENABLED', false),
    'draft_generations_per_hour' => 3,
    'execution_enabled' => (bool) env('FOLLOW_UP_EXECUTION_ENABLED', false),
];
