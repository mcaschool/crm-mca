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
    | Modo multi-institucion (flag maestro)
    |--------------------------------------------------------------------------
    | Modelo de producto: UNA institucion por instalacion. Cada cliente es una
    | copia separada del software en su propio servidor. El MOTOR multi-tenant
    | (institution_id, InstitutionScope, BelongsToInstitution, TenantAwareJob/
    | Command, suite de aislamiento) se conserva INTACTO y DORMANTE tras este flag.
    |
    | false (normal): se ocultan las superficies multi-institucion del panel
    |   (cambiador de institucion activa, gestion del atributo super-admin). La
    |   institucion activa se resuelve sola a la unica de la instalacion.
    | true: reaparecen esas superficies y el sistema se comporta multi-tenant.
    |
    | Si algun dia se activa, se hara una auditoria de aislamiento completa.
    */
    'multi_institution' => (bool) env('CRM_MULTI_INSTITUTION', false),

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
    | Dominio del panel administrativo (D7)
    |--------------------------------------------------------------------------
    | El panel va en su propio subdominio, separado de la API del widget. En
    | produccion se fija PANEL_DOMAIN (p. ej. crm.mcaschool.us). Vacio = sin
    | restriccion de dominio (desarrollo local y pruebas).
    */
    'panel_domain' => env('PANEL_DOMAIN'),

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
    'integration_types' => ['ai_provider', 'google', 'n8n', 'mailrelay', 'mailjet', 'smtp', 'stripe', 'moodle'],

    'ai_providers' => ['openai', 'gemini', 'anthropic', 'deepseek', 'qwen', 'kimi'],

    /*
    |--------------------------------------------------------------------------
    | Emparejador (Opcion B — 5 preguntas, determinista, sin IA)
    |--------------------------------------------------------------------------
    | area y meta se derivan del catalogo real (categorias y goals). Aqui van las
    | opciones fijas y el mapeo seniority+educacion -> nivel (logica confirmada).
    | La motivacion NO filtra: se guarda como senal en el CRM.
    */
    'matcher' => [
        'seniority' => ['estudiante', 'inicio', 'desarrollo', 'mando_medio', 'directivo', 'empresario'],
        'educacion' => ['secundaria', 'tecnico', 'universitario_incompleto', 'universitario_completo', 'posgrado'],
        'motivacion' => ['mejorar_empleo', 'ascender', 'reconversion', 'emprender', 'crecimiento_personal'],

        // seniority -> nivel base.
        'seniority_level' => [
            'estudiante' => 'inicial',
            'inicio' => 'inicial',
            'desarrollo' => 'intermedio',
            'mando_medio' => 'intermedio',
            'directivo' => 'avanzado',
            'empresario' => 'avanzado',
        ],

        // Educaciones que NO bajan el nivel. El resto lo afina un escalon a la baja.
        'educacion_alta' => ['universitario_completo', 'posgrado'],

        'levels_order' => ['inicial', 'intermedio', 'avanzado'],
    ],
];
