<?php

declare(strict_types=1);

namespace Modules\Social\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\EventService;
use Modules\Crm\Services\LeadIntake;
use Modules\Crm\Support\ContactDataNormalizer;
use Modules\Social\Models\MetaLeadForm;
use Modules\Social\Models\MetaLeadPage;
use Modules\Social\Models\MetaLeadReceipt;
use Modules\Social\Support\GraphResponse;
use Modules\Social\Support\MetaLeadAccessGuidance;
use Throwable;

/**
 * Formularios publicitarios de Facebook e Instagram (Meta Lead Ads) DIRECTOS al CRM, por empresa.
 * Cada empresa conecta Meta, elige Páginas y formularios, asigna programa y asesor, comprueba el
 * acceso y activa la recepción desde su panel. Reutiliza la ingesta de leads del CRM (LeadIntake:
 * contacto por correo/teléfono, programa, deduplicación) y deja rastro de la atribución.
 *
 * Recepción: sondeo programado de los formularios activos (social:meta-leads-poll), sin
 * configuración por empresa en Meta; el aviso en tiempo real (campo «leadgen» del webhook de la
 * Página) se procesa igual si la plataforma lo tiene suscrito. Idempotente por el id de Meta.
 *
 * Solo recibe una Página que la empresa activó tras una comprobación de acceso VERIFICADA (Página,
 * formularios y lectura real de contactos) y con la conexión vigente. El operador de la plataforma
 * puede pararlo todo con social.meta.lead_forms_kill_switch. Los formularios de Instagram se
 * publican desde la Página de Facebook vinculada: llegan por la misma Página (platform = ig).
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

    /** Motivo legible (sin datos técnicos) cuando no se puede confirmar la limpieza en Meta. */
    private const CLEANUP_REASONS = [
        'network' => 'Meta no respondió (problema de red)',
        'token' => 'la conexión con Meta caducó o no es válida',
        'permission' => 'Meta no da permiso para borrarlo o comprobarlo',
        'rate_limit' => 'Meta limitó temporalmente las peticiones',
        'platform' => 'Meta respondió con un error interno',
        'graph' => 'Meta respondió con un error',
        'unreadable' => 'Meta dio una respuesta no reconocida',
    ];

    private const LEAD_FIELDS = 'created_time,field_data,form_id,platform,is_organic,custom_disclaimer_responses';

    private const AD_FIELDS = 'ad_name,adset_name,campaign_name';

    /** Margen al sondear (minutos) para no perder contactos en el borde de dos ejecuciones. */
    private const POLL_OVERLAP_MINUTES = 15;

    public function __construct(
        private readonly CurrentInstitution $tenancy,
        private readonly LeadIntake $intake,
        private readonly EventService $events,
        private readonly MetaLeadAccessCheck $check,
    ) {}

    /** Parada de emergencia de la PLATAFORMA (operador): ninguna empresa recibe contactos. */
    public function killSwitch(): bool
    {
        return (bool) config('social.meta.lead_forms_kill_switch', false);
    }

    /**
     * «Actualizar formularios» de una Página de la empresa (en su contexto).
     *
     * @return array{ok: bool, message: string, area: string|null}
     */
    public function syncForms(MetaLeadPage $page): array
    {
        if (! $page->selected || ! $page->available || ! ($page->metaConnection?->usable() ?? false)) {
            return ['ok' => false, 'message' => __('Conecta Meta y elige esta Página antes de actualizar sus formularios.'), 'area' => 'token'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()
                ->get($this->graph($page->page_id.'/leadgen_forms'), ['fields' => 'id,name,status', 'limit' => 100]);
        } catch (Throwable $e) {
            Log::warning('social.lead_forms: error de red al listar formularios', ['page' => $page->getKey(), 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('No se pudo contactar con Meta. Inténtalo más tarde.'), 'area' => 'network'];
        }
        if (! $response->successful()) {
            $area = $this->check->classify((array) $response->json('error', []));
            $issue = ['area' => $area, 'who' => MetaLeadAccessGuidance::who($area), 'permissions' => MetaLeadAccessGuidance::permissionsIn((string) $response->json('error.message', ''))];

            return ['ok' => false, 'message' => MetaLeadAccessGuidance::forCompany($issue), 'area' => $area];
        }

        $n = 0;
        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $form = MetaLeadForm::query()->firstOrNew(['form_id' => (string) $row['id']]);
            // Un formulario es de UNA Página de la empresa: no se reasigna en silencio.
            if ($form->exists && $form->meta_lead_page_id !== null && $form->meta_lead_page_id !== $page->getKey()) {
                continue;
            }
            $form->fill(['meta_lead_page_id' => $page->getKey(), 'name' => (string) ($row['name'] ?? $row['id']), 'last_synced_at' => now()])->save();
            $n++;
        }

        return ['ok' => true, 'message' => trans_choice(':n formulario actualizado.|:n formularios actualizados.', $n, ['n' => $n]), 'area' => null];
    }

    /**
     * Sondeo programado: contactos nuevos de los formularios activos de las Páginas que reciben,
     * empresa por empresa. Devuelve cuántos contactos se registraron.
     */
    public function poll(): int
    {
        if ($this->killSwitch()) {
            return 0;
        }

        $pages = $this->tenancy->runGlobally(fn () => MetaLeadPage::query()
            ->where('receiving_enabled', true)->where('selected', true)->where('available', true)->where('access_status', 'verified')
            ->get(['id', 'institution_id']));

        $created = 0;
        foreach ($pages as $row) {
            $created += $this->tenancy->runFor((int) $row->institution_id, function () use ($row): int {
                $page = MetaLeadPage::query()->with('metaConnection')->find($row->id);

                return $page !== null && $page->receiving() ? $this->pollPage($page) : 0;
            });
        }

        return $created;
    }

    /** «Buscar contactos ahora» desde el panel (misma lógica que el sondeo programado). */
    public function fetchNow(MetaLeadPage $page): int
    {
        return ! $this->killSwitch() && $page->receiving() ? $this->pollPage($page) : 0;
    }

    private function pollPage(MetaLeadPage $page): int
    {
        $created = 0;
        $forms = MetaLeadForm::query()->where('meta_lead_page_id', $page->getKey())->where('is_active', true)->whereNotNull('destination')->get()
            ->filter(fn (MetaLeadForm $f): bool => $f->hasDestination());
        foreach ($forms as $form) {
            $since = max(
                (int) ($form->receiving_since?->getTimestamp() ?? now()->getTimestamp()),
                (int) ($page->last_polled_at?->subMinutes(self::POLL_OVERLAP_MINUTES)->getTimestamp() ?? 0),
            );

            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()
                    ->get($this->graph($form->form_id.'/leads'), [
                        'fields' => 'id,created_time',
                        'limit' => 100,
                        'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since]]),
                    ]);
            } catch (Throwable $e) {
                Log::warning('social.lead_forms: error de red al sondear', ['page' => $page->getKey(), 'error' => $e->getMessage()]);

                return $created; // se reintenta en la próxima ejecución
            }

            if (! $response->successful()) {
                $this->pauseOnAccessError($page, (array) $response->json('error', []), (int) $response->status());

                return $created;
            }

            foreach ((array) $response->json('data', []) as $lead) {
                if (is_array($lead) && isset($lead['id'])
                    && $this->processLeadgen(['leadgen_id' => (string) $lead['id'], 'form_id' => $form->form_id, 'page_id' => $page->page_id]) === 'processed') {
                    $created++;
                }
            }
        }

        $page->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();

        return $created;
    }

    /**
     * Meta denegó el acceso al sondear: la Página deja de recibir hasta volver a comprobarlo.
     *
     * @param  array<string, mixed>  $error
     */
    private function pauseOnAccessError(MetaLeadPage $page, array $error, int $httpStatus): void
    {
        $area = $this->check->classify($error);
        if (in_array($area, ['request', 'other'], true)) {
            Log::warning('social.lead_forms: Meta rechazó el sondeo', ['page' => $page->getKey(), 'status' => $httpStatus, 'code' => $error['code'] ?? null]);

            return;
        }
        $issue = ['area' => $area, 'who' => MetaLeadAccessGuidance::who($area), 'permissions' => MetaLeadAccessGuidance::permissionsIn((string) ($error['message'] ?? '')),
            'http_status' => $httpStatus, 'code' => isset($error['code']) ? (int) $error['code'] : null, 'meta_message' => mb_substr((string) ($error['message'] ?? ''), 0, 240)];
        $result = (array) ($page->access_result ?? []);
        $result['issues'] = [$issue];
        $page->forceFill([
            'access_status' => 'failed',
            'access_result' => $result,
            'last_error' => mb_substr(MetaLeadAccessGuidance::forCompany($issue), 0, 255),
        ])->save();
        if ($area === 'token') {
            $page->metaConnection?->forceFill(['status' => 'invalid', 'last_error' => $issue['meta_message']])->save();
        }
    }

    /**
     * Avisos en tiempo real (objeto `page`, campo `leadgen`). Devuelve cuántos se trataron.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): int
    {
        if ($this->killSwitch() || ($payload['object'] ?? null) !== 'page') {
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
     * Un contacto nuevo de un formulario: en el contexto de la empresa DUEÑA de la Página,
     * solo si esa Página recibe y el formulario es suyo. Idempotente por su id de Meta.
     *
     * @param  array<string, mixed>  $value  {leadgen_id, form_id, page_id, ad_id?, created_time?}
     * @return string processed | duplicate | skipped | failed | unknown_page | not_receiving
     */
    public function processLeadgen(array $value): string
    {
        $leadgenId = (string) ($value['leadgen_id'] ?? '');
        $pageId = (string) ($value['page_id'] ?? '');
        $formId = (string) ($value['form_id'] ?? '');
        if ($leadgenId === '' || $pageId === '' || $this->killSwitch()) {
            return 'failed';
        }

        // La Página la usa como mucho UNA empresa (se impide al elegirla).
        $owner = $this->tenancy->runGlobally(fn (): ?MetaLeadPage => MetaLeadPage::query()
            ->where('page_id', $pageId)->where('selected', true)->orderByDesc('receiving_enabled')->first(['id', 'institution_id']));
        if ($owner === null) {
            Log::info('social.lead_forms: Página sin empresa', ['page_id' => $pageId]);

            return 'unknown_page';
        }

        return $this->tenancy->runFor((int) $owner->institution_id, function () use ($owner, $leadgenId, $pageId, $formId): string {
            $page = MetaLeadPage::query()->with('metaConnection')->find($owner->id);
            if ($page === null || ! $page->receiving()) {
                return 'not_receiving';
            }

            try {
                $receipt = MetaLeadReceipt::query()->create([
                    'leadgen_id' => $leadgenId, 'form_id' => $formId ?: null, 'page_id' => $pageId, 'status' => 'processing',
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'duplicate'; // reintento de Meta o ya recogido por el sondeo
            }

            // El formulario debe ser de ESTA Página de ESTA empresa.
            $form = $formId !== '' ? MetaLeadForm::query()->where('form_id', $formId)->where('meta_lead_page_id', $page->getKey())->first() : null;
            if ($form === null || ! $form->is_active) {
                return $this->finish($receipt, 'skipped', __('El formulario no está activado en el CRM.'));
            }
            if (! $form->hasDestination()) { // antes de pedir nada a Meta
                return $this->finish($receipt, 'failed', __('Asigna un destino al formulario (un programa o «contacto general»).'));
            }

            $lead = $this->fetchLead($page, $leadgenId);
            if ($lead === null) {
                return $this->finish($receipt, 'failed', __('No se pudieron leer los datos del contacto en Meta (acceso denegado o conexión caducada).'));
            }

            [$status, $error, $crmLead, $attribution] = $this->ingestLead($form, $lead, $leadgenId);
            if ($status !== 'processed' || $crmLead === null) {
                return $this->finish($receipt, 'failed', (string) $error);
            }

            $receipt->forceFill(['status' => 'processed', 'lead_id' => $crmLead->getKey(), 'attribution' => $attribution, 'error' => null])->save();

            return 'processed';
        });
    }

    /**
     * Un contacto de Meta → contacto + lead del CRM en el destino del formulario (mismo camino para
     * la recepción real y para la prueba completa). Sin recibos: los gestiona quien llama.
     *
     * @param  array<string, mixed>  $lead  respuesta de Meta (field_data, platform, campaña…)
     * @return array{0: string, 1: string|null, 2: Lead|null, 3: array<string, mixed>} [estado, error legible, lead, atribución]
     */
    private function ingestLead(MetaLeadForm $form, array $lead, string $leadgenId): array
    {
        if (! $form->hasDestination()) {
            return ['failed', __('Asigna un destino al formulario (un programa o «contacto general»).'), null, []];
        }
        // Destino: un programa de la empresa (su tipo sale de la línea del catálogo; si la línea
        // no es una conocida, la propia línea) o «contacto general» sin programa.
        $program = $form->destination === 'program' ? Program::query()->find($form->program_id) : null;
        if ($form->destination === 'program' && $program === null) {
            return ['failed', __('El programa de destino ya no existe: asigna otro destino al formulario.'), null, []];
        }
        $productType = mb_substr($program !== null
            ? (self::PRODUCT_TYPES[(string) $program->line] ?? ((string) $program->line !== '' ? (string) $program->line : 'programa'))
            : 'general', 0, 40); // leads.product_type es varchar(40)

        $fields = $this->fieldMap((array) ($lead['field_data'] ?? []));
        $fullName = $this->pick($fields, ['full_name'], ['nombre_completo', 'nombre completo', 'nombre y apellido']);
        $attribution = array_filter([
            'platform' => ($lead['platform'] ?? null) === 'ig' ? 'instagram' : 'facebook',
            'campaign' => $lead['campaign_name'] ?? null,
            'adset' => $lead['adset_name'] ?? null,
            'ad' => $lead['ad_name'] ?? null,
            'form' => $form->name,
            'organic' => (bool) ($lead['is_organic'] ?? false),
        ], fn ($v): bool => $v !== null && $v !== '');

        $data = array_filter([
            // Campos estándar de Meta o personalizados («Teléfono», «WhatsApp», «Correo»…).
            'email' => $this->pick($fields, ['email', 'work_email'], ['email', 'correo']),
            'phone' => $this->pick($fields, ['phone_number', 'phone', 'work_phone_number'], ['phone', 'telefono', 'teléfono', 'celular', 'movil', 'móvil', 'whatsapp']),
            // Formularios sin nombre (solo teléfono o correo): el contacto entra igual; el CRM le
            // pone «Sin nombre» (ContactService), como a cualquier contacto nuevo sin nombre.
            'first_name' => ($fields['first_name'] ?? '') !== '' ? $fields['first_name'] : $this->firstName($fullName),
            'last_name' => ($fields['last_name'] ?? '') !== '' ? $fields['last_name'] : $this->lastName($fullName),
            'country' => $fields['country'] ?? null,
            'product_type' => $productType,
            'program' => $program !== null ? (string) $program->code : null,
            'source' => 'meta_lead_ads',
            'channel' => $attribution['platform'],
            'form' => $form->name,
            // El formulario de Meta exige aceptar la política de privacidad; si añadió
            // casillas propias, todas deben estar marcadas.
            'consent' => $this->consented((array) ($lead['custom_disclaimer_responses'] ?? [])),
            'consent_source' => 'web_form',
        ], fn ($v): bool => $v !== null && $v !== '');

        if (! isset($data['email']) && ! isset($data['phone'])) {
            return ['failed', __('El contacto no trae correo ni teléfono.'), null, $attribution];
        }

        // Mismo camino que cualquier canal: la capa común del CRM valida y normaliza (y rechaza
        // sin llegar a la base de datos). Los registros llevan el campo, nunca el valor.
        try {
            $result = $this->intake->ingest($data, 'meta_lead:'.$leadgenId);
        } catch (InvalidContactDataException $e) {
            Log::warning('social.lead_forms: datos de contacto no válidos', ['leadgen_id' => $leadgenId, 'fields' => $e->fields()]);

            return ['failed', __('El contacto de Meta trae datos no válidos y no se registró (:why).', ['why' => $e->summary()]), null, $attribution];
        } catch (Throwable $e) {
            Log::warning('social.lead_forms: no se pudo registrar el lead', ['leadgen_id' => $leadgenId, 'error' => class_basename($e), 'code' => $e->getCode()]);

            return ['failed', __('No se pudo registrar el contacto en el CRM.'), null, $attribution];
        }

        $crmLead = $result['lead'];
        if ($form->bot_id !== null && $crmLead->getAttribute('bot_id') === null) {
            $crmLead->bot_id = $form->bot_id; // asesor responsable del formulario
            $crmLead->save();
        }
        $this->events->record('meta_lead_received', [
            'contact_id' => $crmLead->contact_id,
            'bot_id' => $crmLead->bot_id,
            'data' => $attribution + ['program_id' => $program?->getKey(), 'destination' => $form->destination],
        ]);

        return ['processed', null, $crmLead, $attribution];
    }

    /**
     * PRUEBA COMPLETA DE RECEPCIÓN de un formulario (desde el panel, antes o después de activar):
     * crea un contacto de prueba de Meta en el formulario, lo lee y lo pasa por el MISMO camino
     * que un contacto real (destino, campos, contacto y lead del CRM) dentro de una transacción que
     * se DESHACE: no deja datos en el CRM. Al final borra el contacto de prueba en Meta.
     *
     * Distinta de la «lectura autorizada» (Meta permite leer los contactos del formulario): esta
     * prueba demuestra que un contacto entraría de verdad en el destino elegido.
     *
     * Solo se da por SUPERADA si todas las fases se confirman: Meta crea el contacto de prueba, el
     * CRM lo procesa por el camino real, la transacción no deja nada y el contacto de prueba de esta
     * ejecución desaparece de Meta (borrado confirmado o ausencia comprobada; ver cleanupTestLead).
     *
     * @return array{status: string, detail: string, at: string, form: string, cleanup?: string}
     */
    public function rehearse(MetaLeadPage $page, MetaLeadForm $form): array
    {
        $out = fn (string $status, string $detail, ?string $cleanup = null): array => array_filter(['status' => $status, 'detail' => $detail, 'at' => now()->toIso8601String(), 'form' => $form->name, 'cleanup' => $cleanup], fn ($v): bool => $v !== null);

        if ($form->meta_lead_page_id !== $page->getKey()) {
            return $out('failed', __('El formulario no es de esta Página.'));
        }
        if (! $form->hasDestination()) {
            return $out('failed', __('Asigna un destino al formulario antes de la prueba completa.'));
        }

        try {
            $created = Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()->asForm()
                ->post($this->graph($form->form_id.'/test_leads'));
        } catch (Throwable $e) {
            Log::warning('social.lead_forms: error de red al crear el contacto de prueba', ['page' => $page->getKey(), 'error' => class_basename($e)]);

            return $out('failed', __('No se pudo contactar con Meta. Inténtalo más tarde.'));
        }
        $testId = (string) ($created->json('id') ?? '');
        if (! $created->successful() || $testId === '') {
            return $out('failed', __('Meta no permitió crear un contacto de prueba en este formulario: :why', ['why' => mb_substr((string) $created->json('error.message', ''), 0, 160)]));
        }

        $cleanupDone = false;
        try {
            $lead = $this->fetchLead($page, $testId);
            if ($lead === null) {
                return $out('failed', __('Se creó el contacto de prueba, pero Meta no permitió leerlo.'));
            }
            if (isset($lead['id']) && (string) $lead['id'] !== $testId) {
                return $out('failed', __('Meta devolvió un contacto distinto del creado por esta prueba.'));
            }

            // Mismo camino que un contacto real, sin dejar rastro: la transacción se deshace siempre.
            $lastContactId = (int) Contact::query()->max('id');
            DB::beginTransaction();
            try {
                [$status, $error, $crmLead] = $this->ingestLead($form, $lead, 'test:'.$testId);
            } finally {
                DB::rollBack();
            }
            if ($status !== 'processed' || $crmLead === null) {
                return $out('failed', (string) $error);
            }
            if (! $this->leftNothing($crmLead, $lastContactId, $testId)) {
                return $out('failed', __('La prueba no pudo deshacer sus datos en el CRM. Avisa al operador de la plataforma.'));
            }

            // Última fase real: el contacto de prueba creado por ESTA ejecución debe desaparecer de
            // Meta (borrado confirmado o ausencia comprobada). Si no se puede confirmar, no se supera.
            $cleanupDone = true;
            [$cleanup, $why] = $this->cleanupTestLead($page, $form, $testId);

            return match ($cleanup) {
                'deleted', 'absent' => $out('passed', __('Un contacto entraría en el CRM con el destino «:dest» (prueba deshecha: no quedan datos; contacto de prueba eliminado en Meta).', ['dest' => $form->destination === 'general' ? __('Contacto general') : (string) Program::query()->whereKey($form->program_id)->value('name_es')]), $cleanup),
                'present' => $out('failed', __('El contacto entraría bien en el CRM (prueba deshecha: no quedan datos), pero Meta mantiene el contacto de prueba: no permitió borrarlo. Bórralo desde la herramienta de pruebas de anuncios de clientes potenciales de Meta y repite la prueba.'), $cleanup),
                default => $out('failed', __('El contacto entraría bien en el CRM (prueba deshecha: no quedan datos), pero no se pudo confirmar en Meta que el contacto de prueba se borró: :why. Repite la prueba en unos minutos.', ['why' => __(self::CLEANUP_REASONS[$why] ?? self::CLEANUP_REASONS['unreadable'])]), $cleanup),
            };
        } finally {
            if (! $cleanupDone) {
                $this->cleanupTestLead($page, $form, $testId); // una fase anterior falló: se limpia igual
            }
        }
    }

    /** ¿La transacción deshecha no dejó el lead, el contacto nuevo ni un recibo de la prueba? */
    private function leftNothing(Lead $crmLead, int $lastContactId, string $testId): bool
    {
        $contactId = (int) $crmLead->contact_id;

        return Lead::query()->whereKey($crmLead->getKey())->doesntExist()
            && ($contactId <= $lastContactId || Contact::query()->whereKey($contactId)->doesntExist())
            && MetaLeadReceipt::query()->where('leadgen_id', 'test:'.$testId)->doesntExist();
    }

    /**
     * Limpieza VERIFICABLE del contacto de prueba creado por esta ejecución ($testId):
     *  - deleted: Meta confirma el borrado (2xx con el cuerpo oficial de éxito);
     *  - absent:  sin esa confirmación, una lectura del MISMO id responde «no existe» (#100/33) y una
     *             lectura de control del formulario con el mismo token funciona (descarta que el «no
     *             existe» sea en realidad falta de permisos o un token caído);
     *  - present: Meta sigue devolviendo el contacto de prueba;
     *  - unknown: permisos, token, red, límite de peticiones, error de Meta o respuesta no reconocida.
     * Se registran el estado y los códigos de Graph (nunca el token, el id ni el mensaje).
     *
     * @return array{0: string, 1: string|null} [resultado, motivo si unknown]
     */
    private function cleanupTestLead(MetaLeadPage $page, MetaLeadForm $form, string $testId): array
    {
        $trace = [];
        $delete = $this->graphRequest(fn () => Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()->delete($this->graph($testId)));
        $trace['delete'] = $delete !== null ? GraphResponse::summary($delete) : 'network';
        if ($delete !== null && GraphResponse::confirmsDeletion($delete)) {
            return ['deleted', null];
        }

        // Sin confirmación explícita: se comprueba leyendo el MISMO id creado en esta ejecución.
        $check = $this->graphRequest(fn () => Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()->get($this->graph($testId), ['fields' => 'id']));
        $trace['verify'] = $check !== null ? GraphResponse::summary($check) : 'network';
        [$outcome, $why] = match (true) {
            $check === null => ['unknown', 'network'],
            $check->successful() => is_array($check->json()) && (string) $check->json('id') === $testId ? ['present', null] : ['unknown', 'unreadable'],
            GraphResponse::errorKind($check) !== 'not_found' => ['unknown', GraphResponse::errorKind($check)],
            default => $this->controlRead($page, $form, $trace),
        };

        $context = ['page' => $page->getKey(), 'outcome' => $outcome, 'reason' => $why] + $trace;
        $outcome === 'absent'
            ? Log::info('social.lead_forms: contacto de prueba ya ausente en Meta (comprobado)', $context)
            : Log::warning('social.lead_forms: limpieza del contacto de prueba sin confirmar', $context);

        return [$outcome, $why];
    }

    /**
     * «No existe» solo vale si el mismo token sigue leyendo el formulario de esta prueba.
     *
     * @param  array<string, mixed>  $trace
     * @return array{0: string, 1: string|null}
     */
    private function controlRead(MetaLeadPage $page, MetaLeadForm $form, array &$trace): array
    {
        $control = $this->graphRequest(fn () => Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()->get($this->graph($form->form_id), ['fields' => 'id']));
        $trace['control'] = $control !== null ? GraphResponse::summary($control) : 'network';
        if ($control === null) {
            return ['unknown', 'network'];
        }
        if (! $control->successful()) {
            return ['unknown', GraphResponse::errorKind($control)];
        }

        return is_array($control->json()) && (string) $control->json('id') === (string) $form->form_id ? ['absent', null] : ['unknown', 'unreadable'];
    }

    /** Petición a Graph; null si no hubo respuesta (red, tiempo agotado). */
    private function graphRequest(callable $request): ?Response
    {
        try {
            return $request();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchLead(MetaLeadPage $page, string $leadgenId): ?array
    {
        // Con datos del anuncio si la conexión puede leerlos; si no, sin ellos (el contacto llega igual).
        foreach ([self::LEAD_FIELDS.','.self::AD_FIELDS, self::LEAD_FIELDS] as $fields) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->withToken($page->page_token)->acceptJson()
                    ->get($this->graph($leadgenId), ['fields' => $fields]);
            } catch (Throwable $e) {
                Log::warning('social.lead_forms: error de red al leer el lead', ['leadgen_id' => $leadgenId, 'error' => $e->getMessage()]);

                return null;
            }
            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }
        }

        return null;
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
                // Valor ficticio de Meta (contacto de prueba: «<test lead: dummy data for …>») →
                // vacío, con la misma regla común del CRM: nunca se toma por un dato real.
                $value = is_scalar($value) && ! ContactDataNormalizer::isProviderPlaceholder((string) $value) ? trim((string) $value) : '';
                $map[strtolower((string) $field['name'])] = $value;
            }
        }

        return $map;
    }

    /**
     * Primer valor no vacío: por nombre exacto o, si no, por un campo cuyo nombre contenga la pista.
     *
     * @param  array<string, string>  $fields
     * @param  list<string>  $exact
     * @param  list<string>  $contains
     */
    private function pick(array $fields, array $exact, array $contains): ?string
    {
        foreach ($exact as $name) {
            if (($fields[$name] ?? '') !== '') {
                return $fields[$name];
            }
        }
        foreach ($fields as $name => $value) {
            foreach ($contains as $hint) {
                if ($value !== '' && str_contains($name, $hint)) {
                    return $value;
                }
            }
        }

        return null;
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
