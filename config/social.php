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
    | hardcodearlo. Lo usan: la Uploads API de ejemplos de media para PLANTILLAS de WhatsApp
    | (POST /{APP_ID}/uploads) y el intercambio server-side del authorization code del
    | Embedded Signup (junto con el App Secret, que nunca sale del servidor).
    */
    'meta_app_id' => env('SOCIAL_META_APP_ID'),

    /*
    | Embedded Signup (Coexistence-ready). PREPARADO pero APAGADO por defecto: mientras
    | enabled=false, el botón "Conectar WhatsApp Business" muestra "Configuración
    | pendiente" y el endpoint de conexión rechaza cualquier intento (evita una conexión
    | accidental en producción mientras el número real siga en YCloud).
    | Requires Facebook Login for Business configuration using WhatsApp Embedded
    | Signup v4 (la versión del flujo la gobierna el Configuration ID, no el código).
    |   SOCIAL_WA_SIGNUP_ENABLED=true          # activar SOLO en el cutover
    |   SOCIAL_WA_SIGNUP_CONFIG_ID=...         # config ID de Facebook Login for Business
    |
    | NOTA OPERATIVA (antes del cutover, en el panel de Meta — NO lo hace el código):
    | la suscripción del webhook de whatsapp_business_account debe incluir los campos
    |   messages, account_update, history, smb_app_state_sync, smb_message_echoes,
    |   message_template_status_update, template_category_update,
    |   message_template_quality_update
    | apuntando a /api/social/webhook/whatsapp con SOCIAL_APP_SECRET(_WHATSAPP).
    */
    'embedded_signup' => [
        'enabled' => (bool) env('SOCIAL_WA_SIGNUP_ENABLED', false),
        'config_id' => env('SOCIAL_WA_SIGNUP_CONFIG_ID'),
    ],

    /*
    | «Conectar Meta» — onboarding visual de Facebook Login for Business para descubrir
    | Páginas de Facebook (Messenger) e Instagram Professional asociado. Es un flujo NUEVO
    | que CONVIVE con la conexión Meta ya operativa (no la sustituye) y que en esta etapa
    | solo hace login + descubrimiento + visualización (NO crea ni sobreescribe canales).
    |
    | El Configuration ID es configuración de PLATAFORMA de MCA CRM (la app de Meta es única
    | y da servicio a todas las instituciones); NO pertenece a ninguna institución y NO se
    | hardcodea. Se define UNA sola vez en el .env del servidor:
    |   SOCIAL_META_LOGIN_CONFIG_ID=...   # config ID de Facebook Login for Business (Pages/IG)
    |
    | Reutiliza el resto de la config Meta existente: SOCIAL_META_APP_ID (App ID), el App
    | Secret real de la Meta App (social.meta.app_secret; jamás sale del servidor) y
    | SOCIAL_GRAPH_VERSION. Permisos que este flujo evidencia para App Review:
    | business_management, pages_show_list, instagram_basic (y luego, solo donde haga falta,
    | pages_read_engagement y pages_manage_metadata).
    |
    | Si el config_id está vacío, el flujo NO se rompe: el botón muestra un estado amigable
    | según el tipo de usuario y no se inicia ningún login.
    */
    'meta' => [
        'login_config_id' => env('SOCIAL_META_LOGIN_CONFIG_ID'),

        /*
        | Tipo de token de la configuración de Facebook Login for Business (config de
        | PLATAFORMA). Debe conocerse ANTES de abrir el diálogo porque cambia los parámetros
        | de FB.login (Meta lo documenta y un System User con response_type incorrecto es una
        | causa conocida de fallo):
        |   - 'user'   → FB.login(cb, { config_id })                    (User Access Token)
        |   - 'system' → FB.login(cb, { config_id, response_type:'code',
        |                             override_default_response_type:true })  (System User)
        |   SOCIAL_META_LOGIN_TOKEN_TYPE=user|system
        | Cualquier valor distinto de 'user' se normaliza a 'system' (default seguro): así el
        | flujo System User existente no se rompe si la variable falta o viene mal escrita.
        */
        'login_token_type' => strtolower((string) env('SOCIAL_META_LOGIN_TOKEN_TYPE', 'system')) === 'user'
            ? 'user'
            : 'system',

        /*
        | App Secret de PLATAFORMA para el Facebook Login for Business general. Semánticamente
        | pertenece a la Meta App de MCA CRM, no a un canal concreto: el flujo «Conectar Meta»
        | no debe depender de WhatsApp/Messenger/Instagram para decidir qué secreto usa. No se
        | introduce una variable .env nueva: se reutiliza el App Secret REAL ya existente de la
        | app de Facebook (el que usan Facebook/Messenger), con el común como último recurso.
        | El App Secret jamás sale del servidor.
        */
        'app_secret' => env('SOCIAL_APP_SECRET_WHATSAPP') ?? env('SOCIAL_APP_SECRET'),

        /*
        | Atajo SOLO-LOCAL (APP_ENV=local) para desarrollar el flujo sin credenciales reales
        | de Meta: si está definido, MetaConnectionService NO llama a la red y devuelve activos
        | simulados, y el botón se considera disponible aunque falte el config_id.
        |   SOCIAL_META_FAKE_DISCOVERY=ok | empty
        | En producción se deja SIN definir → login y descubrimiento REALES. Los tests no lo
        | usan (usan Http::fake).
        */
        'fake_discovery' => env('SOCIAL_META_FAKE_DISCOVERY'),
    ],

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
