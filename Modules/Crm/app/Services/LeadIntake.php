<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Ingesta GENERAL de leads de captación recibidos por el endpoint público
 * POST /api/v1/leads/intake (n8n → CRM). Un solo punto de entrada para todos los
 * formularios/flujos de n8n que hoy escriben en Google Sheets.
 *
 * REUTILIZA las abstracciones existentes del CRM (no duplica lógica):
 *  - ContactService::createOrUpdate → dedup por (institution_id, email), enriquece sin borrar.
 *  - LeadService::recordIntent → granularidad D4 (mismo lead vigente por product_type dentro
 *    de la ventana de reapertura, o uno nuevo). Esa es la regla de dedup del CRM.
 *  - Program::findByCourseIdnumberOrCode → enlaza el programa por code o course_idnumber.
 *  - EventService → deja rastro (lead_intake).
 *
 * Idempotencia: un `request_id` estable (cabecera Idempotency-Key o campo request_id) hace
 * que los reintentos de n8n dentro de la ventana devuelvan el MISMO lead sin duplicar
 * (action='duplicate'). El candado atómico evita carreras entre reintentos concurrentes.
 *
 * Aislamiento: la institución la fija el middleware (token → institución); aquí solo se lee
 * del contexto. Nunca se mezclan leads entre instituciones.
 */
class LeadIntake
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly LeadService $leads,
        private readonly EventService $events,
        private readonly CurrentInstitution $context,
    ) {}

    /**
     * Crea o actualiza el lead de forma idempotente.
     *
     * @param  array<string,mixed>  $data  ya VALIDADO y saneado por el controlador
     * @return array{lead: Lead, action: string, request_id: string} action: created|updated|duplicate
     */
    public function ingest(array $data, string $requestId): array
    {
        $institutionId = $this->context->idOrFail('lead_intake');
        $resultKey = 'lead_intake:'.$institutionId.':'.sha1($requestId);
        $ttl = now()->addMinutes((int) config('crm.lead_intake.idempotency_minutes', 1440));

        // Candado por request_id: serializa reintentos concurrentes con el mismo id.
        return Cache::lock('lock:'.$resultKey, 10)->block(5, function () use ($data, $requestId, $resultKey, $ttl): array {
            // Reintento dentro de la ventana → mismo lead, sin duplicar.
            $prior = Cache::get($resultKey);
            if (is_array($prior) && isset($prior['lead_id'])) {
                $lead = Lead::query()->find($prior['lead_id']);
                if ($lead !== null) {
                    return ['lead' => $lead, 'action' => 'duplicate', 'request_id' => $requestId];
                }
            }

            ['lead' => $lead, 'created' => $created] = DB::transaction(fn (): array => $this->upsert($data));

            Cache::put($resultKey, ['lead_id' => $lead->getKey()], $ttl);

            return ['lead' => $lead, 'action' => $created ? 'created' : 'updated', 'request_id' => $requestId];
        });
    }

    /**
     * Contacto (dedup email) + lead (regla D4) + programa + evento. Transaccional.
     *
     * @param  array<string,mixed>  $data
     * @return array{lead: Lead, created: bool}
     */
    private function upsert(array $data): array
    {
        $contact = $this->contacts->createOrUpdate([
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'] ?? null,
            'preferred_language' => $data['preferred_language'] ?? null,
        ]);

        // Programa: se enlaza por code o course_idnumber; null si no matchea (degradar, no romper).
        $programId = null;
        $programCode = isset($data['program']) ? trim((string) $data['program']) : '';
        if ($programCode !== '') {
            $programId = Program::findByCourseIdnumberOrCode($programCode)?->getKey();
        }

        // Regla de dedup/creación del CRM (D4). recordIntent actualiza el lead vigente del
        // contacto para este product_type dentro de la ventana, o crea uno nuevo.
        $attrs = array_filter([
            'bot_id' => $this->defaultBotId(),
            'product_type' => $data['product_type'] ?? 'microcredential',
            'source' => $data['source'] ?? 'n8n_intake',
            'program_id' => $programId,
            'area' => $data['area'] ?? null,
            'goal' => $data['goal'] ?? null,
            'level' => $data['level'] ?? null,
            'interest_level' => $data['interest_level'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $lead = $this->leads->recordIntent($contact, $attrs);
        $created = $lead->wasRecentlyCreated;

        // Rastro: toda llegada deja evento (canal/formulario para trazabilidad, sin datos personales).
        $this->events->record('lead_intake', [
            'contact_id' => $contact->getKey(),
            'bot_id' => $lead->bot_id,
            'data' => array_filter([
                'source' => $attrs['source'] ?? null,
                'channel' => $data['channel'] ?? null,
                'form' => $data['form'] ?? null,
                'program' => $programCode !== '' ? $programCode : null,
                'linked' => $programId !== null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        return ['lead' => $lead, 'created' => $created];
    }

    /**
     * Primer bot activo de la institución (el esquema exige bot_id). Un lead de captación
     * por formulario no nace de una conversación de bot; el origen real queda en `source`.
     */
    private function defaultBotId(): int
    {
        $botId = Bot::query()->where('status', 'active')->orderBy('id')->value('id');
        if ($botId === null) {
            throw new RuntimeException('No hay ningún bot activo para atribuir el lead.');
        }

        return (int) $botId;
    }
}
