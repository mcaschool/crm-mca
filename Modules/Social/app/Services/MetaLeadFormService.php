<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadIntake;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Models\SocialChannel;
use Throwable;

/**
 * Formularios publicitarios de Facebook e Instagram (Meta Lead Ads) DIRECTOS al CRM (Meta → CRM),
 * sin intermediarios externos. Reutiliza la ingesta de leads del CRM (LeadIntake: contacto por
 * correo/teléfono, programa, deduplicación) y deja rastro de la atribución de la campaña.
 *
 * PREPARADO Y DESACTIVADO: leer los datos de un lead exige el permiso de Meta «leads_retrieval»
 * (y, para listar los formularios de la Página, «pages_manage_ads»), que NO forma parte de la
 * revisión de la app pendiente. Mientras social.meta.lead_forms_enabled sea false: no se llama a
 * Meta, no se procesa ningún aviso y la pantalla lo explica.
 *
 * Los formularios de Instagram se publican desde la Página de Facebook vinculada: llegan por la
 * misma Página (campo `platform` = ig).
 */
final class MetaLeadFormService
{
    /** Línea del programa → tipo de producto del CRM (taxonomía de leads). */
    private const PRODUCT_TYPES = [
        'microcredenciales' => 'microcredencial',
        'programas_ejecutivos' => 'programa_ejecutivo',
        'diplomas_avanzados' => 'diploma_avanzado',
        'maestrias' => 'maestria',
        'estancias' => 'estancia_internacional',
    ];

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly CurrentInstitution $tenancy,
        private readonly LeadIntake $intake,
        private readonly EventService $events,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('social.meta.lead_forms_enabled', false);
    }

    /**
     * Actualiza la lista de formularios de una Página (Facebook Messenger). Mientras no haya
     * aprobación de Meta no se consulta nada.
     *
     * @return array{ok: bool, message: string}
     */
    public function syncForms(SocialChannel $page): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => __('Pendiente de aprobación de Meta: todavía no se pueden consultar los formularios.')];
        }
        $token = (string) ($page->credentials['token'] ?? '');
        if ($page->provider !== 'messenger' || $token === '' || (string) $page->external_id === '') {
            return ['ok' => false, 'message' => __('Esta Página no está conectada correctamente.')];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()
                ->get($this->graph((string) $page->external_id.'/leadgen_forms'), ['fields' => 'id,name,status', 'limit' => 100]);
        } catch (Throwable $e) {
            Log::warning('social.lead_forms: error de red al listar formularios', ['channel_id' => $page->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('No se pudo contactar con Meta. Inténtalo más tarde.')];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Meta no permitió consultar los formularios de esta Página (revisa la conexión y los permisos).')];
        }

        $n = 0;
        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            MetaLeadForm::query()->updateOrCreate(
                ['form_id' => (string) $row['id']],
                ['social_channel_id' => $page->id, 'name' => (string) ($row['name'] ?? $row['id']), 'last_synced_at' => now()],
            );
            $n++;
        }

        return ['ok' => true, 'message' => __(':n formulario(s) actualizado(s).', ['n' => $n])];
    }

    /**
     * Avisos de nuevos contactos (objeto `page`, campo `leadgen`). Devuelve cuántos se trataron.
     * Desactivado → 0 (no se toca nada).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): int
    {
        if (! $this->enabled() || ($payload['object'] ?? null) !== 'page') {
            return 0;
        }

        $handled = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                if (is_array($change) && ($change['field'] ?? null) === 'leadgen' && is_array($change['value'] ?? null)) {
                    $this->processLeadgen($change['value']);
                    $handled++;
                }
            }
        }

        return $handled;
    }

    /**
     * Un contacto nuevo de un formulario: idempotente por su id de Meta (índice único).
     *
     * @param  array<string, mixed>  $value  {leadgen_id, form_id, page_id, ad_id?, created_time?}
     * @return string processed | duplicate | skipped | failed | unknown_page
     */
    public function processLeadgen(array $value): string
    {
        $leadgenId = (string) ($value['leadgen_id'] ?? '');
        $pageId = (string) ($value['page_id'] ?? '');
        $formId = (string) ($value['form_id'] ?? '');
        if ($leadgenId === '' || $pageId === '') {
            return 'failed';
        }

        $page = $this->tenancy->runGlobally(fn (): ?SocialChannel => SocialChannel::query()
            ->where('provider', 'messenger')->where('external_id', $pageId)->first());
        if ($page === null) {
            Log::info('social.lead_forms: Página no configurada', ['page_id' => $pageId]);

            return 'unknown_page';
        }

        return $this->tenancy->runFor((int) $page->institution_id, function () use ($page, $leadgenId, $pageId, $formId): string {
            try {
                $receipt = MetaLeadReceipt::query()->create([
                    'leadgen_id' => $leadgenId, 'form_id' => $formId ?: null, 'page_id' => $pageId, 'status' => 'processing',
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'duplicate'; // Meta reintentó el mismo aviso: ya está registrado
            }

            $form = $formId !== '' ? MetaLeadForm::query()->where('form_id', $formId)->first() : null;
            if ($form === null || ! $form->is_active) {
                return $this->finish($receipt, 'skipped', __('El formulario no está activado en el CRM.'));
            }
            $program = $form->program_id !== null ? Program::query()->find($form->program_id) : null;
            $productType = $program !== null ? (self::PRODUCT_TYPES[(string) $program->line] ?? null) : null;
            if ($program === null || $productType === null) {
                return $this->finish($receipt, 'failed', __('Asigna al formulario un programa con tipo de producto reconocido.'));
            }

            $lead = $this->fetchLead($page, $leadgenId);
            if ($lead === null) {
                return $this->finish($receipt, 'failed', __('No se pudieron leer los datos del contacto en Meta (permiso pendiente o conexión caducada).'));
            }

            $fields = $this->fieldMap((array) ($lead['field_data'] ?? []));
            $attribution = array_filter([
                'platform' => ($lead['platform'] ?? null) === 'ig' ? 'instagram' : 'facebook',
                'campaign' => $lead['campaign_name'] ?? null,
                'adset' => $lead['adset_name'] ?? null,
                'ad' => $lead['ad_name'] ?? null,
                'form' => $form->name,
                'organic' => (bool) ($lead['is_organic'] ?? false),
            ], fn ($v): bool => $v !== null && $v !== '');

            $data = array_filter([
                'email' => $fields['email'] ?? null,
                'phone' => $fields['phone_number'] ?? $fields['phone'] ?? null,
                'first_name' => $fields['first_name'] ?? $this->firstName($fields['full_name'] ?? null),
                'last_name' => $fields['last_name'] ?? $this->lastName($fields['full_name'] ?? null),
                'country' => $fields['country'] ?? null,
                'product_type' => $productType,
                'program' => (string) $program->code,
                'source' => 'meta_lead_ads',
                'channel' => $attribution['platform'],
                'form' => $form->name,
                // El formulario de Meta exige aceptar la política de privacidad; si añadió
                // casillas propias, todas deben estar marcadas.
                'consent' => $this->consented((array) ($lead['custom_disclaimer_responses'] ?? [])),
                'consent_source' => 'web_form',
            ], fn ($v): bool => $v !== null && $v !== '');

            if (! isset($data['email']) && ! isset($data['phone'])) {
                return $this->finish($receipt, 'failed', __('El contacto no trae correo ni teléfono.'));
            }

            try {
                $result = $this->intake->ingest($data, 'meta_lead:'.$leadgenId);
            } catch (Throwable $e) {
                Log::warning('social.lead_forms: no se pudo registrar el lead', ['leadgen_id' => $leadgenId, 'error' => $e->getMessage()]);

                return $this->finish($receipt, 'failed', __('No se pudo registrar el contacto en el CRM.'));
            }

            $crmLead = $result['lead'];
            if ($form->bot_id !== null && $crmLead->getAttribute('bot_id') === null) {
                $crmLead->bot_id = $form->bot_id; // asesor responsable del formulario
                $crmLead->save();
            }
            $this->events->record('meta_lead_received', [
                'contact_id' => $crmLead->contact_id,
                'bot_id' => $crmLead->bot_id,
                'data' => $attribution + ['program_id' => $program->getKey()],
            ]);

            $receipt->forceFill(['status' => 'processed', 'lead_id' => $crmLead->getKey(), 'attribution' => $attribution, 'error' => null])->save();

            return 'processed';
        });
    }

    /** @return array<string, mixed>|null */
    private function fetchLead(SocialChannel $page, string $leadgenId): ?array
    {
        $token = (string) ($page->credentials['token'] ?? '');
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($token)->acceptJson()->get($this->graph($leadgenId), [
                'fields' => 'created_time,field_data,form_id,ad_name,adset_name,campaign_name,platform,is_organic,custom_disclaimer_responses',
            ]);
        } catch (Throwable $e) {
            Log::warning('social.lead_forms: error de red al leer el lead', ['leadgen_id' => $leadgenId, 'error' => $e->getMessage()]);

            return null;
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /**
     * @param  array<int, mixed>  $fieldData  [{name, values: [...]}, …]
     * @return array<string, string>
     */
    private function fieldMap(array $fieldData): array
    {
        $map = [];
        foreach ($fieldData as $field) {
            if (is_array($field) && isset($field['name'])) {
                $value = is_array($field['values'] ?? null) ? ($field['values'][0] ?? '') : '';
                $map[strtolower((string) $field['name'])] = trim((string) $value);
            }
        }

        return $map;
    }

    /** @param  array<int, mixed>  $responses */
    private function consented(array $responses): bool
    {
        foreach ($responses as $r) {
            if (is_array($r) && array_key_exists('is_checked', $r) && ! filter_var($r['is_checked'], FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        return true;
    }

    private function firstName(?string $full): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $full)) ?: [];

        return ($parts[0] ?? '') !== '' ? $parts[0] : null;
    }

    private function lastName(?string $full): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $full)) ?: [];

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    }

    private function finish(MetaLeadReceipt $receipt, string $status, string $error): string
    {
        $receipt->forceFill(['status' => $status, 'error' => mb_substr($error, 0, 255)])->save();

        return $status;
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('social.graph_version', 'v26.0').'/'.ltrim($path, '/');
    }
}
