<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catálogo de proveedores/modelos de IA y sus CAPACIDADES
|--------------------------------------------------------------------------
| ÚNICA fuente versionada (Opción A). NO contiene secretos ni precios (el
| pricing irá aparte porque cambia por región/tiempo). `ModelCatalog` es el
| ÚNICO que lee este archivo, de modo que mañana esta fuente pueda sustituirse
| por una tabla `ai_models` sin tocar agentes, Celia ni los adapters.
|
| Precedencia de capacidades (la resuelve ModelCatalog):
|   generic (seguro) → provider defaults → model overrides → params del proceso
| El último nivel NO puede habilitar una capacidad que el perfil diga que no
| está soportada. Capacidad desconocida = desactivada de forma segura.
|
| Un modelo NO catalogado (p. ej. "qwen-nuevo-2027") NO se rechaza: usa el
| perfil del proveedor + genérico y hace una llamada OpenAI-compatible estándar
| sin optimizaciones no confirmadas.
*/

return [
    // Perfil GENÉRICO seguro para proveedor/modelo no catalogado: todo lo avanzado
    // apagado; solo una llamada OpenAI-compatible estándar.
    'generic' => [
        'transport' => 'openai_compatible',
        'capabilities' => [
            'streaming' => false,
            'structured_output' => false,
            'thinking' => ['supported' => false, 'default' => false],
            'context_cache' => ['implicit' => false, 'explicit' => false, 'min_prefix_tokens' => null],
        ],
        'limits' => ['context_window' => null, 'max_output' => null],
    ],

    'providers' => [

        // ---------------------------------------------------------------- Qwen
        'qwen' => [
            'label' => 'Qwen (Alibaba Model Studio)',
            'transport' => 'openai_compatible',
            'default_base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            // Defaults CONSERVADORES del proveedor (cada modelo declara lo suyo).
            'capabilities' => [
                'streaming' => true,
                'structured_output' => false,
                'thinking' => ['supported' => false, 'default' => false],
                'context_cache' => ['implicit' => false, 'explicit' => false, 'min_prefix_tokens' => 1024],
            ],
            // Código de error del proveedor → categoría normalizada.
            'error_map' => [
                'insufficient_quota' => 'quota_exhausted',
                'InvalidApiKey' => 'authentication_error',
                'invalid_api_key' => 'authentication_error',
                'Throttling' => 'rate_limited',
                'Throttling.RateQuota' => 'rate_limited',
                'Throttling.AllocationQuota' => 'rate_limited',
                'model_not_found' => 'model_unavailable',
                'InvalidParameter' => 'invalid_request',
                'DataInspectionFailed' => 'invalid_request',
            ],
            'usage_map' => [], // vacío = campos de usage OpenAI estándar
            'models' => [
                // PRIMER perfil completo, verificado contra la doc de Alibaba + pruebas
                // reales. NO copiar estas capacidades a otros modelos Qwen sin confirmar.
                'qwen3.7-plus' => [
                    'label' => 'Qwen3.7 Plus',
                    'status' => 'supported',
                    'limits' => ['context_window' => 131072, 'max_output' => 8192],
                    'capabilities' => [
                        'structured_output' => true, // response_format: json_object
                        'thinking' => ['supported' => true, 'default' => false], // enable_thinking (no-stream ⇒ false)
                        // Cache IMPLÍCITO automático (doc Alibaba): prefijo estable ≥1024
                        // tokens; los tokens en caché llegan en
                        // usage.prompt_tokens_details.cached_tokens. NO requiere payload
                        // especial ni IDs de caché artificiales. MEDIDO en prod: 2ª llamada
                        // con mismo prefijo → 98.5% cache hit, latencia −47%.
                        // Explicit SÍ existe (cache_control: ephemeral en compatible-mode) pero
                        // se deja DESACTIVADO a propósito: implicit ya cubre nuestra estructura
                        // (system+conocimiento estable primero); se podría habilitar más adelante
                        // añadiendo el marcador en el adapter, sin tocar agentes.
                        'context_cache' => ['implicit' => true, 'explicit' => false, 'min_prefix_tokens' => 1024],
                    ],
                ],
            ],
        ],

        // -------------------------------------------------------------- OpenAI
        'openai' => [
            'label' => 'OpenAI',
            'transport' => 'openai_compatible',
            'default_base_url' => 'https://api.openai.com/v1',
            'capabilities' => [
                'streaming' => true,
                'structured_output' => true,
                'thinking' => ['supported' => false, 'default' => false],
                'context_cache' => ['implicit' => true, 'explicit' => false, 'min_prefix_tokens' => 1024],
            ],
            'error_map' => [
                'insufficient_quota' => 'quota_exhausted',
                'invalid_api_key' => 'authentication_error',
                'rate_limit_exceeded' => 'rate_limited',
                'model_not_found' => 'model_unavailable',
                'context_length_exceeded' => 'invalid_request',
            ],
            'usage_map' => [],
            'models' => [],
        ],

        // ------------------------------------------------------------ DeepSeek
        'deepseek' => [
            'label' => 'DeepSeek',
            'transport' => 'openai_compatible',
            'default_base_url' => 'https://api.deepseek.com',
            'capabilities' => [
                'streaming' => true,
                'structured_output' => true,
                'thinking' => ['supported' => false, 'default' => false],
                'context_cache' => ['implicit' => true, 'explicit' => false, 'min_prefix_tokens' => null],
            ],
            'error_map' => [
                'insufficient_quota' => 'quota_exhausted',
                'authentication_error' => 'authentication_error',
            ],
            'usage_map' => [],
            'models' => [],
        ],

        // ---------------------------------------------------------------- Kimi
        'kimi' => [
            'label' => 'Kimi (Moonshot)',
            'transport' => 'openai_compatible',
            'default_base_url' => 'https://api.moonshot.cn/v1',
            'capabilities' => [
                'streaming' => true,
                'structured_output' => true,
                'thinking' => ['supported' => false, 'default' => false],
                'context_cache' => ['implicit' => false, 'explicit' => false, 'min_prefix_tokens' => null],
            ],
            'error_map' => [],
            'usage_map' => [],
            'models' => [],
        ],

        // Proveedores NO OpenAI-compatibles: declarados para el panel, pero su
        // transporte real (adapter) se añade cuando se implemente. Hasta entonces el
        // sistema NO debe fingir compatibilidad (transport != openai_compatible).
        'anthropic' => [
            'label' => 'Anthropic (Claude)',
            'transport' => 'anthropic',
            'default_base_url' => 'https://api.anthropic.com',
            'capabilities' => [
                'streaming' => true,
                'structured_output' => false,
                'thinking' => ['supported' => true, 'default' => false],
                'context_cache' => ['implicit' => false, 'explicit' => true, 'min_prefix_tokens' => 1024],
            ],
            'error_map' => [
                'authentication_error' => 'authentication_error',
                'rate_limit_error' => 'rate_limited',
                'overloaded_error' => 'provider_unavailable',
            ],
            'usage_map' => [],
            'models' => [],
        ],

        'gemini' => [
            'label' => 'Google Gemini',
            'transport' => 'gemini',
            'default_base_url' => 'https://generativelanguage.googleapis.com',
            'capabilities' => [
                'streaming' => true,
                'structured_output' => true,
                'thinking' => ['supported' => false, 'default' => false],
                'context_cache' => ['implicit' => false, 'explicit' => true, 'min_prefix_tokens' => null],
            ],
            'error_map' => [],
            'usage_map' => [],
            'models' => [],
        ],
    ],
];
