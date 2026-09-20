<?php

declare(strict_types=1);

namespace Modules\Mcp\Support;

use Illuminate\Support\Facades\Artisan;
use Modules\Ai\Services\KnowledgeSyncService;
use Modules\Institutions\Models\Bot;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\MetaWebhookNormalizer;
use Modules\Social\Services\SocialIngestService;
use Modules\Social\Services\SocialPublishService;
use Modules\Social\Services\WhatsAppCoexistenceService;
use Modules\Social\Services\WhatsAppTemplateService;

/**
 * Operaciones REALES ejecutables vía crm_execute: cada una delega en los
 * servicios existentes del CRM (nunca SQL a mano) y corre ya dentro del
 * contexto de institución resuelto por McpContext. Registro único y
 * extensible: añadir una operación = añadir una entrada aquí. Las marcadas
 * dangerous exigen confirm: true en la llamada.
 */
final class OperationRegistry
{
    /**
     * @return array<string, array{description: string, params: array<string, string>, dangerous: bool}>
     */
    public function catalog(): array
    {
        return [
            'social.templates.sync' => [
                'description' => 'Sincroniza las plantillas de WhatsApp del canal contra Meta.',
                'params' => ['channel_id' => 'int (canal whatsapp)'],
                'dangerous' => false,
            ],
            'social.publish.resume' => [
                'description' => 'Reintenta un destino de publicación en estado processing (botón Continuar).',
                'params' => ['target_id' => 'int'],
                'dangerous' => false,
            ],
            'whatsapp.contacts_sync' => [
                'description' => 'Solicita el sync de contactos de Coexistence (una sola vez; respeta la marca).',
                'params' => ['channel_id' => 'int'],
                'dangerous' => false,
            ],
            'whatsapp.history_sync' => [
                'description' => 'Solicita el sync de historial de Coexistence (una sola vez; declined se respeta).',
                'params' => ['channel_id' => 'int'],
                'dangerous' => false,
            ],
            'whatsapp.subscribe_waba' => [
                'description' => 'Suscribe la app a la WABA del canal (POST subscribed_apps).',
                'params' => ['channel_id' => 'int'],
                'dangerous' => false,
            ],
            'channel.toggle' => [
                'description' => 'Activa o desactiva un canal social ya configurado.',
                'params' => ['channel_id' => 'int', 'active' => 'bool'],
                'dangerous' => false,
            ],
            'knowledge.sync' => [
                'description' => 'Re-sincroniza la carpeta de conocimiento de un asesor (KnowledgeSyncService).',
                'params' => ['bot_id' => 'int', 'folder' => 'string opcional'],
                'dangerous' => false,
            ],
            'webhook.simulate' => [
                'description' => 'Inyecta un payload de webhook Meta por el normalizador+ingesta (SIN red, para diagnóstico).',
                'params' => ['provider' => 'whatsapp|messenger|instagram', 'payload' => 'object (payload crudo de Meta)'],
                'dangerous' => true,
            ],
            'queue.retry_failed' => [
                'description' => 'Reintenta jobs fallidos (uno por uuid, o todos).',
                'params' => ['uuid' => "string o 'all'"],
                'dangerous' => true,
            ],
            'queue.forget_failed' => [
                'description' => 'Elimina un job fallido del registro.',
                'params' => ['uuid' => 'string'],
                'dangerous' => true,
            ],
            'cache.optimize_clear' => [
                'description' => 'Ejecuta php artisan optimize:clear (limpia caches de config/rutas/vistas).',
                'params' => [],
                'dangerous' => true,
            ],
        ];
    }

    /**
     * Ejecuta una operación del catálogo. Corre YA dentro del contexto de
     * institución; los modelos usados están acotados por el scope global.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function run(string $operation, array $params): array
    {
        return match ($operation) {
            'social.templates.sync' => app(WhatsAppTemplateService::class)->sync($this->channel($params)),
            'social.publish.resume' => $this->resumeTarget($params),
            'whatsapp.contacts_sync' => ['result' => app(WhatsAppCoexistenceService::class)->startContactsSync($this->channel($params))],
            'whatsapp.history_sync' => ['result' => app(WhatsAppCoexistenceService::class)->startHistorySync($this->channel($params))],
            'whatsapp.subscribe_waba' => ['subscribed' => app(WhatsAppCoexistenceService::class)->subscribeWaba($this->channel($params))],
            'channel.toggle' => $this->toggleChannel($params),
            'knowledge.sync' => $this->knowledgeSync($params),
            'webhook.simulate' => $this->simulateWebhook($params),
            'queue.retry_failed' => $this->artisan('queue:retry', ['id' => [($params['uuid'] ?? '') === 'all' ? 'all' : (string) ($params['uuid'] ?? '')]]),
            'queue.forget_failed' => $this->artisan('queue:forget', ['id' => (string) ($params['uuid'] ?? '')]),
            'cache.optimize_clear' => $this->artisan('optimize:clear', []),
            default => throw new McpToolException('Operación desconocida: '.$operation.'. Llama a crm_execute sin operation para ver el catálogo.'),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function channel(array $params): SocialChannel
    {
        $id = (int) ($params['channel_id'] ?? 0);
        $channel = SocialChannel::query()->find($id);
        if ($channel === null) {
            throw new McpToolException('Canal no encontrado (channel_id '.$id.') en el contexto actual.');
        }

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resumeTarget(array $params): array
    {
        $target = SocialPostTarget::query()->find((int) ($params['target_id'] ?? 0));
        if ($target === null) {
            throw new McpToolException('Destino de publicación no encontrado en el contexto actual.');
        }
        app(SocialPublishService::class)->resumeTarget($target);

        return ['target_id' => $target->id, 'status' => $target->refresh()->status];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toggleChannel(array $params): array
    {
        $channel = $this->channel($params);
        $channel->is_active = (bool) ($params['active'] ?? true);
        $channel->save();

        return ['channel_id' => $channel->id, 'is_active' => $channel->is_active];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function knowledgeSync(array $params): array
    {
        $bot = Bot::query()->find((int) ($params['bot_id'] ?? 0));
        if ($bot === null) {
            throw new McpToolException('Bot no encontrado en el contexto actual.');
        }
        $folder = (string) ($params['folder'] ?? $bot->advisorFolder());

        return app(KnowledgeSyncService::class)->sync((int) $bot->getKey(), $folder);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function simulateWebhook(array $params): array
    {
        $provider = (string) ($params['provider'] ?? '');
        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : null;
        if (! in_array($provider, ['whatsapp', 'messenger', 'instagram'], true) || $payload === null) {
            throw new McpToolException('webhook.simulate requiere provider (whatsapp|messenger|instagram) y payload (object).');
        }

        $normalizer = app(MetaWebhookNormalizer::class);
        $ingest = app(SocialIngestService::class);
        $results = [];
        foreach ($normalizer->normalize($provider, $payload) as $message) {
            $r = $ingest->ingest($message);
            $results[] = ['status' => $r->status, 'conversation_id' => $r->conversationId, 'message_id' => $r->messageId];
        }
        $statuses = [];
        foreach ($normalizer->normalizeStatuses($provider, $payload) as $status) {
            $statuses[] = $ingest->applyStatus($status);
        }

        return ['results' => $results, 'statuses' => $statuses];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function artisan(string $command, array $arguments): array
    {
        $exit = Artisan::call($command, $arguments);

        return ['command' => $command, 'exit_code' => $exit, 'output' => trim(Artisan::output())];
    }
}
