<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialConversation;
use RuntimeException;
use Throwable;

/**
 * Coexistence-ready (PREPARADO, apagado por feature flag): infraestructura para sustituir
 * a YCloud conectando el WhatsApp Business App real al CRM vía Embedded Signup.
 *
 * >>> Requires Facebook Login for Business configuration using WhatsApp Embedded
 * >>> Signup v4. <<<
 * La versión del flujo y los productos NO se hardcodean aquí (nada de sessionInfoVersion
 * ni version=v2/v3): los gobierna el Configuration ID de Facebook Login for Business
 * (SOCIAL_WA_SIGNUP_CONFIG_ID) conforme a Embedded Signup v4, junto con
 * SOCIAL_META_APP_ID y SOCIAL_GRAPH_VERSION.
 *
 * FRONTEND FUTURO (no activo mientras el flag esté OFF; el SDK no se carga): debe captar
 * DOS fuentes del flujo v4 y mandarlas juntas al endpoint del CRM:
 *  1) fbLoginCallback → response.authResponse.code (el authorization code; se envía de
 *     inmediato al backend, jamás a localStorage/sessionStorage ni a logs);
 *  2) window message con type = 'WA_EMBEDDED_SIGNUP' → evento de la sesión con los asset
 *     IDs; reconocer al menos FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING y capturar de forma
 *     segura data.waba_id y data.phone_number_id (sin loguear el payload).
 * Los asset IDs del navegador son SOLO una pista: la AUTORIDAD es la validación
 * server-side posterior (ver completeSignup → assertAssetsBelongToToken).
 *
 * NADA de esto llama a Meta hasta el cutover real (social.embedded_signup.enabled=false
 * bloquea el onboarding). El resto de handlers de webhook (contactos/historial/account)
 * sí quedan operativos: si Meta los enviara, se procesan con seguridad.
 *
 * Plan de cutover documentado (NO ejecutar aún):
 *  1) app preparada; 2) permisos/config Meta listos; 3) número operando en YCloud;
 *  4) ventana de mantenimiento; 5) desconectar YCloud; 6) Embedded Signup de MCA;
 *  7) conectar el mismo WhatsApp Business App; 8) POST /{WABA}/subscribed_apps;
 *  9) sync de contactos (smb_app_state_sync); 10) sync de historial (history);
 *  11) pruebas inbound/outbound/echo; 12) confirmar Coexistence estable.
 *
 * Seguridad: state/nonce de un solo uso con TTL (anti-CSRF/replay); el authorization
 * code se intercambia SERVER-SIDE (App Secret jamás sale del backend ni va en URLs);
 * el business token se guarda cifrado en SocialChannel.credentials; nada sensible en logs.
 */
final class WhatsAppCoexistenceService
{
    private const TIMEOUT_SECONDS = 20;

    private const STATE_TTL_SECONDS = 600;

    /** Código de Meta: el usuario RECHAZÓ compartir el historial en el teléfono. */
    public const HISTORY_DECLINED_CODE = 2593109;

    public function __construct(
        private readonly CurrentInstitution $context,
        private readonly SocialIngestService $ingest,
        private readonly MetaWebhookNormalizer $normalizer,
    ) {}

    /** El Embedded Signup está listo para ejecutarse (flag + App ID + config ID). */
    public function isEnabled(): bool
    {
        return (bool) config('social.embedded_signup.enabled')
            && (string) config('social.meta_app_id', '') !== ''
            && (string) config('social.embedded_signup.config_id', '') !== '';
    }

    // ------------------------------------------------------- Embedded Signup

    /** Emite el state anti-CSRF (un solo uso, TTL corto) ligado al usuario e institución. */
    public function issueState(int $userId, int $institutionId): string
    {
        $state = Str::random(40);
        Cache::put($this->stateKey($userId, $state), $institutionId, self::STATE_TTL_SECONDS);

        return $state;
    }

    /** Consume el state (replay protection: solo la PRIMERA validación pasa). */
    public function consumeState(int $userId, string $state): ?int
    {
        if ($state === '') {
            return null;
        }
        $institutionId = Cache::pull($this->stateKey($userId, $state));

        return is_int($institutionId) ? $institutionId : null;
    }

    /**
     * Completa el onboarding con el resultado del Embedded Signup v4: intercambia el
     * authorization code por el business token (server-side), VALIDA que los asset IDs
     * del navegador pertenecen realmente al token (el navegador nunca es autoridad),
     * guarda credenciales cifradas y suscribe la WABA. El code NUNCA se persiste.
     *
     * connection_status resultante:
     *  - 'connected_coexistence' SOLO si la verificación is_on_biz_app/platform_type
     *    confirma el número en el WhatsApp Business App por Cloud API;
     *  - 'onboarding' si esa verificación aún no pudo confirmarse (no se finge conexión).
     */
    public function completeSignup(int $institutionId, string $code, string $wabaId, string $phoneNumberId, string $displayPhoneNumber = ''): SocialChannel
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(__('La conexión de WhatsApp Business aún no está habilitada (configuración de Meta pendiente).'));
        }
        if ($code === '' || $wabaId === '' || $phoneNumberId === '') {
            throw new RuntimeException(__('Faltan datos de la conexión (código, WABA o número). Inténtalo de nuevo.'));
        }

        $token = $this->exchangeCode($code);

        // Autoridad server-side: si los activos no corresponden al token, se ABORTA sin
        // persistir token ni tocar canal alguno.
        $this->assertAssetsBelongToToken($token, $wabaId, $phoneNumberId);
        $verified = $this->verifyCoexistenceConnection($token, $phoneNumberId);

        return $this->context->runFor($institutionId, function () use ($wabaId, $phoneNumberId, $displayPhoneNumber, $token, $verified): SocialChannel {
            $channel = SocialChannel::query()
                ->where('provider', 'whatsapp')
                ->where('external_id', $phoneNumberId)
                ->first();
            $channel ??= new SocialChannel([
                'provider' => 'whatsapp',
                'display_name' => trim('WhatsApp · '.$displayPhoneNumber) ?: 'WhatsApp Business',
                'external_id' => $phoneNumberId,
                'is_active' => true,
            ]);

            $credentials = $channel->credentials ?? [];
            $credentials['token'] = $token;
            $credentials['waba_id'] = $wabaId;
            if ($displayPhoneNumber !== '') {
                $credentials['display_phone_number'] = $displayPhoneNumber;
            }
            $channel->credentials = $credentials;
            // Solo la verificación server-side confirma la conexión; si aún no pudo
            // confirmarse, el canal queda en 'onboarding' (nunca se finge conexión).
            $channel->connection_status = $verified === true ? 'connected_coexistence' : 'onboarding';
            $channel->connection_meta = array_merge($channel->connection_meta ?? [], [
                'onboarded_at' => now()->toIso8601String(),
            ]);
            $channel->save();

            $this->subscribeWaba($channel);

            return $channel;
        });
    }

    /**
     * El navegador NO es autoridad sobre los asset IDs: se verifica con el business token
     * que el PHONE_NUMBER_ID pertenece realmente al WABA (GET /{WABA_ID}/phone_numbers).
     * Si no coincide, se aborta el onboarding sin persistir nada, con error sanitizado.
     */
    private function assertAssetsBelongToToken(string $token, string $wabaId, string $phoneNumberId): void
    {
        $version = (string) config('social.graph_version', 'v26.0');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$wabaId}/phone_numbers", ['fields' => 'id,display_phone_number']);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: error de red al verificar activos', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudo completar la conexión con Meta (red). Inténtalo de nuevo.'));
        }

        $rows = $response->json('data');
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }

        if (! $response->successful() || ! in_array($phoneNumberId, $ids, true)) {
            // Diagnóstico seguro: nunca el token ni el payload.
            Log::warning('social.wa.coex: los activos no corresponden al token', [
                'status' => $response->status(),
                'phone_in_waba' => in_array($phoneNumberId, $ids, true),
            ]);
            throw new RuntimeException(__('Los datos de la conexión no corresponden a la cuenta autorizada. Vuelve a iniciar el proceso.'));
        }
    }

    /**
     * Confirma la conexión Coexistence: GET /{PHONE_NUMBER_ID}?fields=is_on_biz_app,
     * platform_type con el business token. true = confirmada (is_on_biz_app=true y
     * platform_type=CLOUD_API); false = respondió pero NO confirma; null = no se pudo
     * comprobar (red/error) — el canal queda en 'onboarding', jamás conectado a ciegas.
     */
    public function verifyCoexistenceConnection(string $token, string $phoneNumberId): ?bool
    {
        $version = (string) config('social.graph_version', 'v26.0');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$phoneNumberId}", ['fields' => 'is_on_biz_app,platform_type']);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: error de red al verificar coexistence', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->json('is_on_biz_app') === true
            && strtoupper((string) $response->json('platform_type')) === 'CLOUD_API';
    }

    /** POST /{WABA_ID}/subscribed_apps (Bearer). Deja el resultado verificable en meta. */
    public function subscribeWaba(SocialChannel $channel): bool
    {
        $token = (string) ($channel->credentials['token'] ?? '');
        $wabaId = (string) ($channel->credentials['waba_id'] ?? '');
        if ($token === '' || $wabaId === '') {
            return false;
        }
        $version = (string) config('social.graph_version', 'v26.0');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$wabaId}/subscribed_apps");
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: error de red al suscribir la WABA', ['error' => $e->getMessage()]);

            return false;
        }

        $ok = $response->successful() && (bool) $response->json('success');
        if (! $ok) {
            // Fallo: NO se marca suscrito y no se toca meta → el retry sigue disponible.
            Log::warning('social.wa.coex: subscribed_apps no confirmó la suscripción', ['status' => $response->status()]);

            return false;
        }

        $channel->connection_meta = array_merge($channel->connection_meta ?? [], [
            'waba_subscribed' => true,
            'waba_subscribed_at' => now()->toIso8601String(),
        ]);
        $channel->save();

        return true;
    }

    // ------------------------------------------------- sincronizaciones (una sola vez)

    /**
     * Solicita el sync de CONTACTOS del WhatsApp Business App. UNA sola vez por
     * onboarding, pero la marca SOLO se escribe tras una respuesta EXITOSA de Meta:
     * un timeout/500/429/respuesta inválida NO consume la oportunidad (retry posible).
     * Se persiste el request_id (Meta lo recomienda conservar para soporte).
     *
     * @return 'requested'|'already_requested'|'failed'
     */
    public function startContactsSync(SocialChannel $channel): string
    {
        if (isset(($channel->connection_meta ?? [])['contacts_sync_started_at'])) {
            return 'already_requested';
        }

        $result = $this->requestSmbAppData($channel, 'smb_app_state_sync');
        if ($result['ok']) {
            $channel->connection_meta = array_merge($channel->connection_meta ?? [], [
                'contacts_sync_started_at' => now()->toIso8601String(),
                'contacts_sync_request_id' => $result['request_id'],
            ]);
            $channel->save();

            return 'requested';
        }

        return 'failed'; // sin marca: el retry sigue disponible
    }

    /**
     * Solicita el sync de HISTORIAL. Misma regla: la marca solo tras éxito (con
     * request_id); un fallo transitorio permite retry. Si el usuario RECHAZÓ compartir
     * el historial en el teléfono (código 2593109), NO es un fallo transitorio: se marca
     * history_sync_status='declined' y el canal sigue plenamente operativo.
     *
     * @return 'requested'|'already_requested'|'declined'|'failed'
     */
    public function startHistorySync(SocialChannel $channel): string
    {
        $meta = $channel->connection_meta ?? [];
        if (isset($meta['history_sync_started_at']) || ($meta['history_sync_status'] ?? null) === 'declined') {
            return 'already_requested';
        }

        $result = $this->requestSmbAppData($channel, 'history');
        if ($result['ok']) {
            $channel->connection_meta = array_merge($meta, [
                'history_sync_started_at' => now()->toIso8601String(),
                'history_sync_request_id' => $result['request_id'],
                'history_sync_status' => 'requested',
            ]);
            $channel->save();

            return 'requested';
        }
        if ($result['code'] === self::HISTORY_DECLINED_CODE) {
            $channel->connection_meta = array_merge($meta, ['history_sync_status' => 'declined']);
            $channel->save();

            return 'declined';
        }

        return 'failed'; // sin marca: el retry sigue disponible
    }

    /**
     * POST /{PHONE_NUMBER_ID}/smb_app_data. Devuelve ok + código de error de Meta (0 si
     * no aplica) + request_id (si Meta lo devolvió).
     *
     * @return array{ok: bool, code: int, request_id: string|null}
     */
    private function requestSmbAppData(SocialChannel $channel, string $syncType): array
    {
        $token = (string) ($channel->credentials['token'] ?? '');
        $phoneNumberId = (string) ($channel->external_id ?? '');
        if ($token === '' || $phoneNumberId === '') {
            return ['ok' => false, 'code' => 0, 'request_id' => null];
        }
        $version = (string) config('social.graph_version', 'v26.0');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => $syncType,
                ]);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: error de red en smb_app_data', ['sync_type' => $syncType, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 0, 'request_id' => null];
        }

        if ($response->successful()) {
            $requestId = $response->json('request_id') ?? $response->json('id');

            return ['ok' => true, 'code' => 0, 'request_id' => is_string($requestId) ? $requestId : null];
        }

        $code = (int) ($response->json('error.code') ?? 0);
        Log::warning('social.wa.coex: Meta rechazó smb_app_data', ['sync_type' => $syncType, 'code' => $code]);

        return ['ok' => false, 'code' => $code, 'request_id' => null];
    }

    // --------------------------------------------------------------- webhooks

    /**
     * Procesa los fields de Coexistence del payload (smb_app_state_sync, history,
     * account_update). Devuelve contadores; nunca lanza (el webhook responde 200 siempre).
     *
     * @param  array<string, mixed>  $payload
     * @return array{contacts: int, history: int, account: int}
     */
    public function handleWebhook(array $payload): array
    {
        $counts = ['contacts' => 0, 'history' => 0, 'account' => 0];

        try {
            foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                    if (! is_array($change)) {
                        continue;
                    }
                    $field = (string) ($change['field'] ?? '');
                    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                    $channel = $this->resolveChannel($value, (string) ($entry['id'] ?? ''));
                    if ($channel === null) {
                        continue;
                    }

                    if ($field === 'smb_app_state_sync') {
                        $counts['contacts'] += $this->context->runFor(
                            $channel->institution_id,
                            fn (): int => $this->applyStateSync($channel, $value),
                        );
                    } elseif ($field === 'history') {
                        $counts['history'] += $this->applyHistory($channel, $value);
                    } elseif ($field === 'account_update') {
                        $counts['account'] += $this->applyAccountUpdate($channel, $value);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('social.wa.coex.webhook: error procesando evento', ['error' => $e->getMessage()]);
        }

        return $counts;
    }

    /**
     * Contactos de la agenda del WhatsApp Business App. 'add' actualiza el nombre del
     * contacto en la conversación existente (sin agenda paralela); 'remove' NO borra
     * nada (las conversaciones históricas se conservan).
     *
     * @param  array<string, mixed>  $value
     */
    private function applyStateSync(SocialChannel $channel, array $value): int
    {
        $applied = 0;
        foreach (is_array($value['state_sync'] ?? null) ? $value['state_sync'] : [] as $item) {
            if (! is_array($item) || (string) ($item['type'] ?? '') !== 'contact') {
                continue;
            }
            $action = (string) ($item['action'] ?? 'add');
            $contact = is_array($item['contact'] ?? null) ? $item['contact'] : [];
            $waId = preg_replace('/\D+/', '', (string) ($contact['phone_number'] ?? '')) ?? '';
            if ($waId === '') {
                continue;
            }

            if ($action === 'remove') {
                $applied++; // tolerado y deliberadamente NO destructivo

                continue;
            }

            $name = trim((string) ($contact['full_name'] ?? $contact['first_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $conversation = SocialConversation::query()
                ->where('social_channel_id', $channel->id)
                ->where('external_conversation_id', $waId)
                ->first();
            if ($conversation !== null) {
                $conversation->contact_name = $name; // la agenda del teléfono es autoritativa
                $conversation->save();
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * Backfill de historial: cada mensaje pasa por la MISMA ingesta idempotente por wamid
     * (chunks fuera de orden y reintentos no duplican; media con media_id se descarga
     * after-response por el job existente). Se registra el progreso en connection_meta.
     *
     * @param  array<string, mixed>  $value
     */
    private function applyHistory(SocialChannel $channel, array $value): int
    {
        $messages = $this->normalizer->fromWhatsappHistory((string) $channel->external_id, $value);
        $ingested = 0;
        foreach ($messages as $message) {
            $result = $this->ingest->ingest($message);
            if ($result->status === 'created') {
                $ingested++;
            }
        }

        // Progreso (phase/chunk_order/progress) — informativo, tolerante a desorden.
        foreach (is_array($value['history'] ?? null) ? $value['history'] : [] as $chunk) {
            $meta = is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [];
            if ($meta !== []) {
                $this->context->runFor($channel->institution_id, function () use ($channel, $meta): void {
                    $current = $channel->connection_meta ?? [];
                    $progress = max((int) ($current['history_sync_progress'] ?? 0), (int) ($meta['progress'] ?? 0));
                    $channel->connection_meta = array_merge($current, [
                        'history_sync_phase' => $meta['phase'] ?? ($current['history_sync_phase'] ?? null),
                        'history_sync_progress' => $progress,
                    ]);
                    $channel->save();
                });
            }
        }

        return $ingested;
    }

    /**
     * account_update de Coexistence. OFFBOARDED: NO se borra el canal ni sus credenciales;
     * se marca el estado (bloquea envíos API y alerta en la UI). RECONNECTED: restaura.
     *
     * @param  array<string, mixed>  $value
     */
    private function applyAccountUpdate(SocialChannel $channel, array $value): int
    {
        $event = strtoupper((string) ($value['event'] ?? ''));

        return $this->context->runFor($channel->institution_id, function () use ($channel, $event): int {
            if ($event === 'ACCOUNT_OFFBOARDED') {
                $this->markOffboarded($channel);

                return 1;
            }
            if ($event === 'ACCOUNT_RECONNECTED') {
                $this->markReconnected($channel);

                return 1;
            }

            return 0;
        });
    }

    public function markOffboarded(SocialChannel $channel): void
    {
        $channel->connection_status = 'offboarded';
        $channel->connection_meta = array_merge($channel->connection_meta ?? [], [
            'offboarded_at' => now()->toIso8601String(),
        ]);
        $channel->save();
    }

    public function markReconnected(SocialChannel $channel): void
    {
        $channel->connection_status = 'connected_coexistence';
        $channel->connection_meta = array_merge($channel->connection_meta ?? [], [
            'reconnected_at' => now()->toIso8601String(),
        ]);
        $channel->save();
    }

    // ---------------------------------------------------------------- internos

    /**
     * Canal por metadata.phone_number_id; si no viene, por la WABA del entry.
     *
     * @param  array<string, mixed>  $value
     */
    private function resolveChannel(array $value, string $entryWabaId): ?SocialChannel
    {
        $phoneNumberId = (string) (is_array($value['metadata'] ?? null) ? ($value['metadata']['phone_number_id'] ?? '') : '');

        return $this->context->runGlobally(function () use ($phoneNumberId, $entryWabaId): ?SocialChannel {
            if ($phoneNumberId !== '') {
                return SocialChannel::query()
                    ->where('provider', 'whatsapp')
                    ->where('external_id', $phoneNumberId)
                    ->first();
            }
            foreach (SocialChannel::query()->where('provider', 'whatsapp')->get() as $channel) {
                if ((string) ($channel->credentials['waba_id'] ?? '') === $entryWabaId && $entryWabaId !== '') {
                    return $channel;
                }
            }

            return null;
        });
    }

    /** Intercambio server-side del authorization code por el business token. */
    private function exchangeCode(string $code): string
    {
        $version = (string) config('social.graph_version', 'v26.0');
        $appId = (string) config('social.meta_app_id', '');
        $secret = (string) (config('social.app_secret') ?? '');
        if ($secret === '') {
            throw new RuntimeException(__('Falta el App Secret en la configuración del servidor.'));
        }

        try {
            // POST con cuerpo form: ni el code ni el secret viajan en la URL.
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/oauth/access_token", [
                    'client_id' => $appId,
                    'client_secret' => $secret,
                    'code' => $code,
                ]);
        } catch (Throwable $e) {
            Log::warning('social.wa.coex: error de red en el intercambio de código', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudo completar la conexión con Meta (red). Inténtalo de nuevo.'));
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            Log::warning('social.wa.coex: Meta rechazó el intercambio de código', [
                'status' => $response->status(),
                'code' => (int) ($response->json('error.code') ?? 0),
            ]);
            throw new RuntimeException(__('Meta rechazó la conexión. Vuelve a intentar el proceso desde el principio.'));
        }

        return $token;
    }

    private function stateKey(int $userId, string $state): string
    {
        return "social.wa.signup.state.{$userId}.".hash('sha256', $state);
    }
}
