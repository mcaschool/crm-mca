<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Social\Models\SocialChannel;
use Modules\Social\Models\SocialWhatsAppTemplate;
use RuntimeException;
use Throwable;

/**
 * Gestión de plantillas de WhatsApp contra la WABA del canal (credentials['waba_id'],
 * token de credentials['token']; nunca hardcodeado, nunca en URL ni en logs).
 *
 *  - sync():    GET /{WABA_ID}/message_templates (paginado por cursor 'after'; nunca se
 *               sigue paging.next crudo para no arrastrar tokens en URL). Upsert local por
 *               meta_template_id o (canal, name, language). NUNCA borra locales por no
 *               venir en una página.
 *  - create():  POST /{WABA_ID}/message_templates. Meta puede RECATEGORIZAR (p. ej.
 *               UTILITY→MARKETING): se persiste la categoría/estado que Meta devuelva.
 *  - refresh(): GET /{meta_template_id} para una sola plantilla.
 *  - Sin archivado/borrado local: el estado local NUNCA se altera a mano. ARCHIVED /
 *    DELETED / PENDING_DELETION solo entran desde Meta (sync o webhook); el DELETE
 *    remoto queda fuera de esta versión (borrar por name afecta a TODOS los idiomas).
 *  - handleWebhook(): message_template_status_update / template_category_update /
 *               message_template_quality_update → actualiza la plantilla local (el canal
 *               se resuelve por el WABA ID del entry). Nunca lanza hacia el webhook.
 */
final class WhatsAppTemplateService
{
    private const TIMEOUT_SECONDS = 20;

    private const SYNC_PAGE_SIZE = 50;

    private const SYNC_MAX_PAGES = 20;

    private const FIELDS = 'id,name,status,category,language,components,quality_score,parameter_format,rejected_reason';

    public function __construct(private readonly CurrentInstitution $context) {}

    /**
     * Sincroniza todas las plantillas de la WABA del canal. Devuelve [synced, pages].
     *
     * @return array{synced: int, pages: int}
     */
    public function sync(SocialChannel $channel): array
    {
        [$wabaId, $token] = $this->wabaContext($channel);
        $version = (string) config('social.graph_version', 'v26.0');

        $synced = 0;
        $pages = 0;
        $after = null;

        do {
            $params = ['fields' => self::FIELDS, 'limit' => self::SYNC_PAGE_SIZE];
            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", $params);

            if (! $response->successful()) {
                throw new RuntimeException($this->safeError($response->json(), 'No se pudieron obtener las plantillas de Meta.'));
            }

            $pages++;
            $rows = $response->json('data');
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $this->upsertFromMeta($channel, $row);
                    $synced++;
                }
            }

            $after = $response->json('paging.cursors.after');
            $hasNext = is_string($response->json('paging.next')) && is_string($after) && $after !== '';
        } while ($hasNext && $pages < self::SYNC_MAX_PAGES);

        return ['synced' => $synced, 'pages' => $pages];
    }

    /**
     * Crea la plantilla en Meta a partir de la definición del diseñador (YA validada por
     * WhatsAppTemplateValidator) y la persiste con el estado/categoría que Meta devuelva.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(SocialChannel $channel, array $input): SocialWhatsAppTemplate
    {
        [$wabaId, $token] = $this->wabaContext($channel);
        $version = (string) config('social.graph_version', 'v26.0');

        $components = $this->buildComponents($input);
        $category = (string) $input['category'];
        $payload = [
            'name' => (string) $input['name'],
            'language' => (string) $input['language'],
            'category' => $category,
            'parameter_format' => 'POSITIONAL',
            'components' => $components,
        ];
        // Comportamiento documentado de Graph v26: allow_category_change=true permite que
        // Meta recategorice (p. ej. UTILITY→MARKETING) en vez de RECHAZAR por categoría
        // incorrecta. Se envía EXPLÍCITO para MARKETING/UTILITY (AUTHENTICATION no aplica
        // y de todos modos no se crea desde el diseñador).
        if (in_array($category, ['MARKETING', 'UTILITY'], true)) {
            $payload['allow_category_change'] = true;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", $payload);
        } catch (Throwable $e) {
            Log::warning('social.wa.tpl: error de red al crear plantilla', ['error' => $e->getMessage()]);
            throw new RuntimeException(__('No se pudo contactar con Meta (red). Inténtalo de nuevo.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->safeError($response->json(), 'Meta rechazó la plantilla.'));
        }

        // Meta puede devolver una categoría distinta a la solicitada (recategorización).
        return SocialWhatsAppTemplate::query()->create([
            'social_channel_id' => $channel->id,
            'meta_template_id' => is_string($response->json('id')) ? $response->json('id') : null,
            'name' => (string) $input['name'],
            'language' => (string) $input['language'],
            'category' => is_string($response->json('category')) ? $response->json('category') : (string) $input['category'],
            'status' => is_string($response->json('status')) ? strtoupper($response->json('status')) : 'PENDING',
            'parameter_format' => 'POSITIONAL',
            'components' => $components,
            'last_synced_at' => now(),
        ]);
    }

    /** Refresca UNA plantilla desde Meta por su id (metadata/estado/calidad). */
    public function refresh(SocialWhatsAppTemplate $template): SocialWhatsAppTemplate
    {
        $channel = $template->channel;
        if ($channel === null || $template->meta_template_id === null) {
            throw new RuntimeException(__('La plantilla no tiene id de Meta: usa Sincronizar.'));
        }
        [, $token] = $this->wabaContext($channel);
        $version = (string) config('social.graph_version', 'v26.0');

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withToken($token)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/{$template->meta_template_id}", ['fields' => self::FIELDS]);

        if (! $response->successful()) {
            throw new RuntimeException($this->safeError($response->json(), 'No se pudo refrescar la plantilla.'));
        }

        $row = $response->json();
        $this->applyMetaRow($template, is_array($row) ? $row : []);
        $template->save();

        return $template;
    }

    /**
     * Procesa los webhooks de plantillas del payload de whatsapp_business_account.
     * Devuelve cuántos eventos aplicó. Nunca lanza (el webhook siempre responde 200).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): int
    {
        $handled = 0;

        try {
            foreach ($this->entries($payload) as [$wabaId, $field, $value]) {
                if (! in_array($field, ['message_template_status_update', 'template_category_update', 'message_template_quality_update'], true)) {
                    continue;
                }

                $channel = $this->channelByWaba($wabaId);
                if ($channel === null) {
                    Log::info('social.wa.tpl.webhook: WABA sin canal, evento aparcado', ['field' => $field]);

                    continue;
                }

                $applied = $this->context->runFor(
                    $channel->institution_id,
                    fn (): bool => $this->applyTemplateEvent($channel, $field, $value),
                );
                if ($applied) {
                    $handled++;
                }
            }
        } catch (Throwable $e) {
            Log::warning('social.wa.tpl.webhook: error procesando evento', ['error' => $e->getMessage()]);
        }

        return $handled;
    }

    // ------------------------------------------------------------------ internos

    /**
     * @param  array<string, mixed>  $value
     */
    private function applyTemplateEvent(SocialChannel $channel, string $field, array $value): bool
    {
        $metaId = isset($value['message_template_id']) ? (string) $value['message_template_id'] : '';
        $name = (string) ($value['message_template_name'] ?? '');
        $language = (string) ($value['message_template_language'] ?? '');
        if ($metaId === '' && ($name === '' || $language === '')) {
            return false;
        }

        // Identificar por id de Meta primero; después por (canal, name, language).
        $template = null;
        if ($metaId !== '') {
            $template = SocialWhatsAppTemplate::query()
                ->where('social_channel_id', $channel->id)
                ->where('meta_template_id', $metaId)
                ->first();
        }
        if ($template === null && $name !== '' && $language !== '') {
            $template = SocialWhatsAppTemplate::query()
                ->where('social_channel_id', $channel->id)
                ->where('name', $name)
                ->where('language', $language)
                ->first();
        }

        // Desconocida → stub seguro (el próximo sync completa los components).
        if ($template === null) {
            $template = new SocialWhatsAppTemplate;
            $template->social_channel_id = $channel->id;
            $template->name = $name !== '' ? $name : 'desconocida_'.$metaId;
            $template->language = $language !== '' ? $language : 'es';
            $template->category = 'UTILITY';
            $template->status = 'PENDING';
            $template->components = [];
        }
        if ($metaId !== '') {
            $template->meta_template_id = $metaId;
        }

        if ($field === 'message_template_status_update') {
            $event = strtoupper((string) ($value['event'] ?? ''));
            if ($event === '') {
                return false;
            }
            $template->status = $event;
            $reason = $value['reason'] ?? null;
            $template->rejection_reason = is_string($reason) && strtoupper($reason) !== 'NONE' ? $reason : null;
            $template->rejection_details = is_array($value['other_info'] ?? null) ? $value['other_info'] : $template->rejection_details;
            if (is_string($value['message_template_category'] ?? null)) {
                $template->category = strtoupper($value['message_template_category']);
            }
        } elseif ($field === 'template_category_update') {
            $new = $value['new_category'] ?? $value['correct_category'] ?? null;
            if (is_string($new) && $new !== '') {
                $template->category = strtoupper($new);
            }
        } else { // message_template_quality_update
            $score = $value['new_quality_score'] ?? null;
            if (is_string($score) && $score !== '') {
                $template->quality_score = strtoupper($score);
            }
        }

        $template->last_synced_at = now();
        $template->save();

        return true;
    }

    /**
     * Arma los components oficiales a partir de la definición del diseñador.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    public function buildComponents(array $input): array
    {
        $components = [];
        $examples = is_array($input['examples'] ?? null) ? $input['examples'] : [];
        $validator = new WhatsAppTemplateValidator;

        $headerFormat = strtoupper((string) ($input['headerFormat'] ?? ''));
        if ($headerFormat === 'TEXT') {
            $headerText = (string) ($input['headerText'] ?? '');
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
            $headerVars = $validator->variables($headerText);
            if ($headerVars !== []) {
                $header['example'] = ['header_text' => [(string) ($examples[$headerVars[0]] ?? '')]];
            }
            $components[] = $header;
        } elseif (in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $components[] = [
                'type' => 'HEADER',
                'format' => $headerFormat,
                'example' => ['header_handle' => [(string) ($input['headerExample'] ?? '')]],
            ];
        }

        $body = (string) ($input['body'] ?? '');
        $bodyComponent = ['type' => 'BODY', 'text' => $body];
        $bodyVars = $validator->variables($body);
        if ($bodyVars !== []) {
            $bodyComponent['example'] = [
                'body_text' => [array_map(fn (int $n): string => (string) ($examples[$n] ?? ''), $bodyVars)],
            ];
        }
        $components[] = $bodyComponent;

        $footer = (string) ($input['footer'] ?? '');
        if (trim($footer) !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }

        $buttons = [];
        foreach (is_array($input['buttons'] ?? null) ? $input['buttons'] : [] as $button) {
            if (! is_array($button)) {
                continue;
            }
            $type = strtoupper((string) ($button['type'] ?? ''));
            $text = (string) ($button['text'] ?? '');
            $buttons[] = match ($type) {
                'URL' => ['type' => 'URL', 'text' => $text, 'url' => (string) ($button['url'] ?? '')],
                'PHONE_NUMBER' => ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => (string) ($button['phone'] ?? '')],
                default => ['type' => 'QUICK_REPLY', 'text' => $text],
            };
        }
        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /**
     * Upsert local de una fila de Meta (sync). Nunca borra.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertFromMeta(SocialChannel $channel, array $row): void
    {
        $metaId = isset($row['id']) ? (string) $row['id'] : '';
        $name = (string) ($row['name'] ?? '');
        $language = (string) ($row['language'] ?? '');
        if ($name === '' || $language === '') {
            return;
        }

        $template = null;
        if ($metaId !== '') {
            $template = SocialWhatsAppTemplate::query()
                ->where('social_channel_id', $channel->id)
                ->where('meta_template_id', $metaId)
                ->first();
        }
        $template ??= SocialWhatsAppTemplate::query()
            ->where('social_channel_id', $channel->id)
            ->where('name', $name)
            ->where('language', $language)
            ->first();
        $template ??= new SocialWhatsAppTemplate(['social_channel_id' => $channel->id]);

        $this->applyMetaRow($template, $row);
        $template->save();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyMetaRow(SocialWhatsAppTemplate $template, array $row): void
    {
        if (isset($row['id'])) {
            $template->meta_template_id = (string) $row['id'];
        }
        $template->name = (string) ($row['name'] ?? $template->name);
        $template->language = (string) ($row['language'] ?? $template->language);
        $template->category = strtoupper((string) ($row['category'] ?? $template->category ?: 'UTILITY'));
        $template->status = strtoupper((string) ($row['status'] ?? $template->status ?: 'PENDING'));
        if (array_key_exists('quality_score', $row)) {
            [$template->quality_score, $template->quality_details] = $this->parseQuality($row['quality_score']);
        }
        if (isset($row['parameter_format']) && is_string($row['parameter_format'])) {
            $template->parameter_format = strtoupper($row['parameter_format']);
        }
        if (is_array($row['components'] ?? null)) {
            $template->components = $row['components'];
        }
        if (isset($row['rejected_reason']) && is_string($row['rejected_reason']) && strtoupper($row['rejected_reason']) !== 'NONE') {
            $template->rejection_reason = $row['rejected_reason'];
        }
        $template->last_synced_at = now();
    }

    /**
     * quality_score de Graph v26 es una ESTRUCTURA {score, date, reasons}; por
     * compatibilidad se toleran también string plano y null. Se guarda SOLO el score en
     * quality_score y {date, reasons} en quality_details — jamás la respuesta completa.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function parseQuality(mixed $quality): array
    {
        if (is_string($quality) && $quality !== '') {
            return [strtoupper($quality), null];
        }
        if (is_array($quality)) {
            $score = isset($quality['score']) && is_string($quality['score'])
                ? strtoupper($quality['score'])
                : null;
            $details = array_intersect_key($quality, ['date' => true, 'reasons' => true]);

            return [$score, $details !== [] ? $details : null];
        }

        return [null, null]; // null o forma desconocida: sin score, sin detalles
    }

    /**
     * @return array{0: string, 1: string} [waba_id, token]
     */
    private function wabaContext(SocialChannel $channel): array
    {
        $wabaId = (string) ($channel->credentials['waba_id'] ?? '');
        $token = (string) ($channel->credentials['token'] ?? '');
        if ($channel->provider !== 'whatsapp' || $wabaId === '' || $token === '') {
            throw new RuntimeException(__('El canal de WhatsApp no tiene configurado el WABA ID o el token.'));
        }

        return [$wabaId, $token];
    }

    /** Canal whatsapp cuyo credentials['waba_id'] coincide (cifrado → se recorren en PHP). */
    private function channelByWaba(string $wabaId): ?SocialChannel
    {
        if ($wabaId === '') {
            return null;
        }

        return $this->context->runGlobally(function () use ($wabaId): ?SocialChannel {
            foreach (SocialChannel::query()->where('provider', 'whatsapp')->get() as $channel) {
                if ((string) ($channel->credentials['waba_id'] ?? '') === $wabaId) {
                    return $channel;
                }
            }

            return null;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function entries(array $payload): iterable
    {
        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $wabaId = (string) ($entry['id'] ?? '');
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $field = (string) ($change['field'] ?? '');
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                yield [$wabaId, $field, $value];
            }
        }
    }

    /**
     * Mensaje de error apto para el usuario: usa error_user_msg de Meta si viene; nunca
     * expone tokens ni el payload. El detalle técnico va al log (código/subcódigo).
     */
    private function safeError(mixed $json, string $fallback): string
    {
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        Log::warning('social.wa.tpl: Meta rechazó la operación', [
            'code' => (int) ($error['code'] ?? 0),
            'subcode' => (int) ($error['error_subcode'] ?? 0),
            'message' => (string) ($error['message'] ?? ''),
        ]);

        $userMsg = $error['error_user_msg'] ?? null;

        return is_string($userMsg) && $userMsg !== '' ? $userMsg : __($fallback);
    }
}
