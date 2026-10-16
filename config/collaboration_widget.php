<?php

declare(strict_types=1);

return [
    'cache_store' => env('COLLABORATION_WIDGET_CACHE', 'database'),
    'central_origin' => env('COLLABORATION_WIDGET_ORIGIN', env('APP_URL', 'http://localhost')),
    'crm_application' => env('COLLABORATION_WIDGET_CRM_APP'),
    'allow_local_http' => env('COLLABORATION_WIDGET_LOCAL_HTTP', false),
    'token_seconds' => 300,
    'instance_seconds' => 90,
    'lease_seconds' => 12,
];
