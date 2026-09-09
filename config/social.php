<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Módulo Social — Webhook directo Meta ↔ CRM (Bloque 3 / 3.1)
|--------------------------------------------------------------------------
| Meta llama DIRECTO al CRM. El CRM verifica el handshake y la firma HMAC de
| cada evento. Estos secretos NO son de panel: los emite Meta.
|
| FIRMA POR PROVEEDOR (Bloque 3.1). Meta firma cada webhook con el App Secret de
| la app DUEÑA de la suscripción:
|   - WhatsApp Cloud API y Messenger viven en la app de Facebook/Meta → mismo App Secret.
|   - «Instagram API con inicio de sesión de Instagram» es una app APARTE, con su
|     propio App ID y App Secret → sus webhooks se firman con el secreto de Instagram.
| Por eso el secreto es por proveedor, con fallback a un secreto común (SOCIAL_APP_SECRET)
| para los proveedores que comparten app.
|
| Líneas para .env:
|   SOCIAL_WEBHOOK_VERIFY_TOKEN=una-cadena-larga-que-tu-eliges
|   SOCIAL_APP_SECRET=app-secret-comun            # WhatsApp + Messenger (app de Facebook)
|   SOCIAL_APP_SECRET_INSTAGRAM=app-secret-de-IG  # solo si IG usa una app propia (IG Login)
|   # Opcionales, si algún proveedor tuviera app propia distinta:
|   # SOCIAL_APP_SECRET_WHATSAPP=...
|   # SOCIAL_APP_SECRET_MESSENGER=...
*/

return [
    'webhook_verify_token' => env('SOCIAL_WEBHOOK_VERIFY_TOKEN'),

    // Secreto de firma específico por proveedor (null → cae al común 'app_secret').
    'secrets' => [
        'whatsapp' => env('SOCIAL_APP_SECRET_WHATSAPP'),
        'messenger' => env('SOCIAL_APP_SECRET_MESSENGER'),
        'instagram' => env('SOCIAL_APP_SECRET_INSTAGRAM'),
    ],

    // Secreto común de respaldo (p. ej. WhatsApp + Messenger comparten la app de Facebook).
    'app_secret' => env('SOCIAL_APP_SECRET'),

    /*
    | App ID de la app de Meta (MCA Automation). NO es un secreto, pero vive en env para no
    | hardcodearlo. Lo usa la Resumable Upload API del Post de VIDEO en Facebook
    | (POST /{APP_ID}/uploads). La subida usa credentials['video_upload_token'] del canal
    | (Long-Lived User Token); la publicación final usa el Page Access Token de siempre.
    */
    'meta_app_id' => env('SOCIAL_META_APP_ID'),

    /*
    | Salida (Bloque 4): responder desde el CRM hacia Meta (Instagram + Messenger).
    | Versión de Graph API CONFIGURABLE (no hardcodeada). La última publicada por Meta a
    | fecha de este bloque es v26.0 (changelog oficial, jul-2026); se deja en env para
    | subirla sin tocar código. Host único: graph.facebook.com con el Page Access Token
    | (mensajería, publicación y perfil de contacto; arquitectura Facebook Login).
    */
    'graph_version' => env('SOCIAL_GRAPH_VERSION', 'v26.0'),

    /*
    | Atajo SOLO-LOCAL para verificación visual sin tokens reales de Meta: si está definido
    | (y APP_ENV=local), MetaMessageSender NO llama a la red y simula el resultado:
    |   SOCIAL_FAKE_SEND=ok | window | error
    | En producción se deja SIN definir → envío real. Los tests no lo usan (usan Http::fake).
    */
    'fake_send' => env('SOCIAL_FAKE_SEND'),

    /*
    | Atajo SOLO-LOCAL para verificar el Publicador (Bloque 5) sin tokens reales:
    |   SOCIAL_FAKE_PUBLISH=ok | partial | fail
    | 'partial' = Facebook ok + Instagram falla (el caso de éxito parcial). En producción SIN
    | definir → publicación real. Los tests no lo usan (usan Http::fake).
    */
    'fake_publish' => env('SOCIAL_FAKE_PUBLISH'),
];
