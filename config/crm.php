<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuracion de dominio CRM-MCA
|--------------------------------------------------------------------------
|
| Valores de negocio del sistema. Se leen SIEMPRE via config('crm.*'),
| nunca con env() fuera de este archivo (regla: config:cache en produccion
| invalida cualquier env() disperso). Ver docs/BLOQUE-0-PROPUESTA-ARQUITECTURA.md.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Internacionalizacion (i18n) — dos idiomas fijos (decision cerrada)
    |--------------------------------------------------------------------------
    | Interfaz: localizacion nativa de Laravel (lang/es, lang/en).
    | Contenido administrable: columnas _es / _en via HasTranslatedColumns.
    */
    'locales' => ['es', 'en'],

    // Si falta la traduccion pedida, se devuelve esta antes que una cadena vacia.
    'fallback_locale' => 'es',

    /*
    |--------------------------------------------------------------------------
    | Zona horaria (D9)
    |--------------------------------------------------------------------------
    | Se guarda TODO en UTC. La presentacion usa la zona de la institucion;
    | este es el valor por defecto cuando la institucion no fija una.
    */
    'default_timezone' => 'America/New_York',

    /*
    |--------------------------------------------------------------------------
    | Leads (D3, D4)
    |--------------------------------------------------------------------------
    */
    'lead' => [
        // D3 — embudo comercial.
        'statuses' => ['new', 'contacted', 'qualified', 'enrolled', 'discarded'],
        'interest_levels' => ['low', 'medium', 'high'],
        // product_type es extensible; day 1 solo microcredential.
        'product_types' => ['microcredential'],

        // D4 — se abre un lead nuevo tras N dias de inactividad o cambio de producto.
        'reopen_after_days' => (int) env('CRM_LEAD_REOPEN_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retencion de datos (D5) — coordinado con consentimiento (D2)
    |--------------------------------------------------------------------------
    | Tras N meses se purgan los `messages`; contacto, lead y eventos se
    | conservan. Configurable para cumplir peticiones de supresion RGPD.
    */
    'retention' => [
        'messages_months' => (int) env('CRM_MESSAGE_RETENTION_MONTHS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget publico — anti-abuso (D8): rate limit OBLIGATORIO
    |--------------------------------------------------------------------------
    | Sin CAPTCHA al inicio (honeypot + rate limit). El endpoint de mensajes
    | (unico que gasta tokens de IA) lleva un limite mas estricto.
    */
    'widget' => [
        'rate_per_min' => (int) env('CRM_WIDGET_RATE_PER_MIN', 30),
        'message_rate_per_min' => (int) env('CRM_WIDGET_MESSAGE_RATE_PER_MIN', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Procesos de IA (capa agnostica) — se configuran por institucion/bot
    |--------------------------------------------------------------------------
    | Catalogo de procesos elegibles. La asignacion proveedor+modelo vive en
    | la tabla ai_process_configs, no aqui.
    */
    'ai_processes' => ['conversation', 'classification', 'summary', 'email_draft'],

    /*
    |--------------------------------------------------------------------------
    | Tipos de integracion admitidos
    |--------------------------------------------------------------------------
    */
    'integration_types' => ['ai_provider', 'google', 'n8n', 'mailrelay', 'smtp', 'stripe', 'moodle'],

    'ai_providers' => ['openai', 'gemini', 'anthropic', 'deepseek', 'qwen', 'kimi'],
];
