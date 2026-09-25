<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Program;
use Modules\Core\Support\CountryResolver;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Ingesta GENERAL de leads de captación recibidos por el endpoint público
 * POST /api/v1/leads/intake (n8n → CRM). Un solo punto de entrada para todos los
 * formularios/flujos de n8n que hoy escriben en Google Sheets.
 *
 * REUTILIZA las abstracciones del CRM (no las duplica):
 *  - ContactService::createOrUpdate → dedup por (institution_id, email), enriquece sin borrar.
 *  - EventService → deja rastro (lead_intake), incluida la marca de programa pendiente.
 *  - CountryResolver → país a ISO-2 (acepta nombre o código); el crudo se conserva en el evento.
 *
 * Resolución de programa (code → course_idnumber → nombre oficial → alias controlado) vive
 * aquí como método privado del intake, para no acoplar el catálogo a este flujo.
 *
 * DEDUP (regla de esta ingesta): el PROGRAMA prevalece. Si el programa se resuelve, el
 * lead se deduplica por (contacto + programa); product_type es solo agrupador SECUNDARIO
 * cuando no hay programa (contacto + product_type dentro de la ventana de reapertura).
 * product_type llega SIEMPRE resuelto por el controlador (nunca se asume microcredencial).
 *
 * Idempotencia: el `request_id` (obligatorio, del evento original) hace que los reintentos
 * dentro de la ventana devuelvan el MISMO lead sin duplicar (action='duplicate'); el candado
 * atómico serializa reintentos concurrentes.
 *
 * Aislamiento: la institución la fija el middleware (token → institución); aquí solo se lee.
 */
class LeadIntake
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly EventService $events,
        private readonly CurrentInstitution $context,
    ) {}

    /**
     * Crea o actualiza el lead de forma idempotente.
     *
     * @param  array<string,mixed>  $data  ya VALIDADO por el controlador (product_type resuelto)
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
     * Contacto (dedup email + consentimiento) + lead (dedup programa-primero) + evento.
     *
     * @param  array<string,mixed>  $data
     * @return array{lead: Lead, created: bool}
     */
    private function upsert(array $data): array
    {
        // País: se normaliza a ISO-2; el valor crudo se conserva (en el evento) si no casa.
        $countryRaw = isset($data['country']) ? trim((string) $data['country']) : '';
        $countryIso2 = $countryRaw !== '' ? CountryResolver::toIso2($countryRaw) : null;

        $contact = $this->contacts->createOrUpdate([
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            // Solo se escribe country si se resolvió a ISO-2 (nunca se copia a nacionalidad).
            'country' => $countryIso2,
            'preferred_language' => $data['preferred_language'] ?? null,
            // Consentimiento: solo se sella si llega verdadero (nunca se inventa).
            'consent' => $data['consent'] ?? null,
            'consent_at' => $data['consent_at'] ?? null,
            'consent_source' => $data['consent_source'] ?? null,
        ]);

        // Programa: code → course_idnumber → nombre → alias. Null si no casa (no asociar mal).
        $programRaw = isset($data['program']) ? trim((string) $data['program']) : '';
        $program = $programRaw !== '' ? $this->resolveProgram($programRaw) : null;
        $programId = $program?->getKey();
        $programPending = $programRaw !== '' && $programId === null;

        $productType = (string) $data['product_type']; // resuelto por el controlador; nunca vacío

        $lead = $this->findOrNewLead($contact->getKey(), $productType, $programId);
        $created = ! $lead->exists;

        if ($created) {
            $lead->contact_id = $contact->getKey();
            $lead->bot_id = $this->defaultBotId();
            $lead->product_type = $productType; // el tipo solo se fija al crear
        }
        // El programa prevalece: si se resolvió, se fija/actualiza.
        if ($programId !== null) {
            $lead->program_id = $programId;
        }
        foreach (['area', 'goal', 'level', 'source'] as $field) {
            if (isset($data[$field]) && trim((string) $data[$field]) !== '') {
                $lead->{$field} = $data[$field];
            }
        }
        if (! empty($data['interest_level'])) {
            $lead->interest_level = InterestLevel::from((string) $data['interest_level']);
        }
        $lead->save();

        // Rastro: toda llegada deja evento. Metadatos NO personales + preservación del crudo
        // (país no reconocido, programa sin enlazar) para no perder el interés original.
        $this->events->record('lead_intake', [
            'contact_id' => $contact->getKey(),
            'bot_id' => $lead->bot_id,
            'data' => array_filter([
                'source' => $data['source'] ?? null,
                'channel' => $data['channel'] ?? null,
                'form' => $data['form'] ?? null,
                'product_type' => $productType,
                'program' => $programRaw !== '' ? $programRaw : null,
                'program_linked' => $programId !== null,
                'program_pending' => $programPending ?: null,
                'country_raw' => ($countryRaw !== '' && $countryIso2 === null) ? $countryRaw : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ]);

        return ['lead' => $lead, 'created' => $created];
    }

    /**
     * Selección del lead a actualizar (o uno nuevo) con el PROGRAMA como criterio primario:
     *  - si hay programa resuelto → lead del contacto con ese program_id (el más reciente);
     *  - si no → lead vigente del contacto para ese product_type dentro de la ventana D4.
     * Devuelve un modelo nuevo (no persistido) si no hay coincidencia.
     */
    private function findOrNewLead(int $contactId, string $productType, ?int $programId): Lead
    {
        $base = Lead::query()->where('contact_id', $contactId);

        if ($programId !== null) {
            $existing = (clone $base)->where('program_id', $programId)->orderByDesc('updated_at')->first();
        } else {
            $reopenDays = (int) config('crm.lead.reopen_after_days', 30);
            $existing = (clone $base)
                ->where('product_type', $productType)
                ->where('updated_at', '>', now()->subDays($reopenDays))
                ->orderByDesc('updated_at')
                ->first();
        }

        return $existing ?? new Lead;
    }

    /**
     * Resuelve el programa probando, EN ORDEN: code del catálogo → course_idnumber de Moodle
     * → nombre oficial (es/en, sin distinguir mayúsculas) → alias CONTROLADO
     * (config crm.lead_intake.program_aliases: alias→code). Acotado a la institución activa
     * por el scope global; null si nada casa (no se asocia a un programa incorrecto).
     */
    private function resolveProgram(string $value): ?Program
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $byCode = Program::query()->where('code', $value)->first();
        if ($byCode !== null) {
            return $byCode;
        }

        $byIdnumber = Program::query()->where('course_idnumber', $value)->first();
        if ($byIdnumber !== null) {
            return $byIdnumber;
        }

        $lower = mb_strtolower($value);
        $byName = Program::query()
            ->where(function ($q) use ($lower): void {
                // Agrupado: la condición OR no debe romper el filtro de institución del scope.
                $q->whereRaw('LOWER(name_es) = ?', [$lower])
                    ->orWhereRaw('LOWER(name_en) = ?', [$lower]);
            })
            ->first();
        if ($byName !== null) {
            return $byName;
        }

        // Alias controlado (último recurso): alias → code del catálogo.
        /** @var array<string,string> $aliases */
        $aliases = (array) config('crm.lead_intake.program_aliases', []);
        $aliasCode = $aliases[$lower] ?? ($aliases[$value] ?? null);

        return $aliasCode !== null ? Program::query()->where('code', (string) $aliasCode)->first() : null;
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
