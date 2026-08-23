<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Program;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Models\IncompanyLead;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Ingesta UPSERT de un lead InCompany (formación corporativa) recibido por el endpoint
 * público desde n8n. UN email = UN lead InCompany dentro de la institución: los dos
 * eventos del formulario (ruta_generada = vio su diagnóstico; solicita_contacto = además
 * pide contacto) caen sobre el MISMO lead, sin duplicar.
 *
 * El estado del EMBUDO vive en el perfil InCompany (`stage`), no en el pipeline global:
 * - ruta_generada → stage 'diagnostico' (si el lead no existía; si ya pidió contacto, NO
 *   se degrada).
 * - solicita_contacto → stage 'solicita_contacto' (el lead más caliente); si no existía,
 *   se crea directamente ahí.
 *
 * Todo en una transacción (contacto dedup por email + lead + perfil + eventos). El lead
 * lleva area='Corporativo', source='incompany_web', product_type='incompany' e interés
 * alto; se atribuye al primer bot activo (los leads exigen bot_id) — el origen real queda
 * claro por el source.
 */
class IncompanyLeadIntake
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly LeadService $leads,
        private readonly EventService $events,
    ) {}

    /**
     * Crea o actualiza el lead InCompany según el evento. Devuelve el lead y si fue
     * 'created' o 'updated' (para que n8n sepa qué pasó).
     *
     * @param  array<string,mixed>  $data  ya VALIDADO por el controlador (incluye 'evento')
     * @return array{lead: Lead, created: bool}
     */
    public function upsert(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $evento = (string) $data['evento'];
            $botId = $this->defaultBotId();

            $contact = $this->contacts->createOrUpdate([
                'email' => $data['email'],
                'first_name' => $data['nombre_contacto'],
                'phone' => $data['whatsapp'] ?? null,
            ]);

            // Enlace de los 3 programas al catálogo por course_idnumber (nullable si no matchea).
            $matches = [];
            foreach ([1, 2, 3] as $i) {
                $code = trim((string) ($data['programa_'.$i] ?? ''));
                $matches[$i] = $code === '' ? [null, null] : [$code, Program::findByCourseIdnumberOrCode($code)?->getKey()];
            }

            // DEDUP por email dentro de la institución (scope global). Un email = un lead.
            $existing = IncompanyLead::query()->where('email', $data['email'])->first();
            $created = $existing === null;

            $corporateArea = (string) config('crm.lead.corporate_area', 'Corporativo');

            if ($existing !== null) {
                // Lead ya existente: se reutiliza y se refresca (no se duplica).
                $lead = Lead::query()->findOrFail($existing->lead_id);
                $lead->interest_level = InterestLevel::High;
                if (($matches[1][1] ?? null) !== null) {
                    $lead->program_id = $matches[1][1];
                }
                $lead->save();
            } else {
                // No existía: se crea el lead corporativo.
                $lead = $this->leads->recordIntent($contact, [
                    'bot_id' => $botId,
                    'product_type' => 'incompany',
                    'source' => 'incompany_web',
                    'interest_level' => 'high',
                    'area' => $corporateArea,
                    'program_id' => $matches[1][1] ?? null,
                ]);
            }

            // Estado del embudo (nunca degrada) + marcas de tiempo por evento recibido.
            [$stage, $diagnosticoAt, $solicitaAt] = $this->resolveStage($evento, $existing);

            IncompanyLead::query()->updateOrCreate(
                ['lead_id' => $lead->getKey()],
                [
                    'nombre_empresa' => $data['nombre_empresa'],
                    'nombre_contacto' => $data['nombre_contacto'],
                    'email' => $data['email'],
                    'whatsapp' => $data['whatsapp'] ?? null,
                    'sector' => $data['sector'] ?? null,
                    'tamano_empresa' => $data['tamano_empresa'] ?? null,
                    'modalidad' => $data['modalidad'] ?? null,
                    'cantidad_personas' => (int) ($data['cantidad_personas'] ?? 1),
                    'programa_1_code' => $matches[1][0],
                    'programa_1_program_id' => $matches[1][1],
                    'programa_2_code' => $matches[2][0],
                    'programa_2_program_id' => $matches[2][1],
                    'programa_3_code' => $matches[3][0],
                    'programa_3_program_id' => $matches[3][1],
                    'area_desarrollo' => $data['area_desarrollo'] ?? null,
                    'stage' => $stage,
                    'diagnostico_at' => $diagnosticoAt,
                    'solicita_contacto_at' => $solicitaAt,
                    'origen' => 'incompany_web',
                ],
            );

            // Rastro del embudo: un evento por cada señal recibida (para la línea de tiempo).
            $this->events->record('incompany_'.$evento, [
                'contact_id' => $contact->getKey(),
                'bot_id' => $botId,
                'data' => ['empresa' => $data['nombre_empresa'], 'stage' => $stage],
            ]);

            // Enciende el chip "Empresa" y el KPI "Interés corporativo" (una vez, al crear).
            if ($created) {
                $this->events->record('corporate_interest', [
                    'contact_id' => $contact->getKey(),
                    'bot_id' => $botId,
                    'data' => ['empresa' => $data['nombre_empresa'], 'origen' => 'incompany_web'],
                ]);
            }

            return ['lead' => $lead, 'created' => $created];
        });
    }

    /**
     * Resuelve el stage final (sin degradar nunca) y las marcas de tiempo, según el
     * evento recibido y el estado previo.
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon|null, 2: \Illuminate\Support\Carbon|null}
     */
    private function resolveStage(string $evento, ?IncompanyLead $existing): array
    {
        $desired = $evento === 'solicita_contacto'
            ? IncompanyLead::STAGE_SOLICITA_CONTACTO
            : IncompanyLead::STAGE_DIAGNOSTICO;

        // Nunca degradar: si el previo está más avanzado, se conserva.
        $previous = $existing?->stage;
        $stage = IncompanyLead::stageRank($desired) >= IncompanyLead::stageRank($previous)
            ? $desired
            : (string) $previous;

        // Marca cada salto por el evento REAL recibido (aunque el stage ya estuviera alto).
        $diagnosticoAt = $existing?->diagnostico_at;
        $solicitaAt = $existing?->solicita_contacto_at;
        if ($evento === 'ruta_generada' && $diagnosticoAt === null) {
            $diagnosticoAt = now();
        }
        if ($evento === 'solicita_contacto' && $solicitaAt === null) {
            $solicitaAt = now();
        }

        return [$stage, $diagnosticoAt, $solicitaAt];
    }

    /**
     * Primer bot activo de la institución (los leads exigen bot_id). Un lead InCompany
     * no nace de un bot, pero se le atribuye uno para cumplir el esquema; el origen
     * real es source='incompany_web'.
     */
    private function defaultBotId(): int
    {
        $botId = Bot::query()->where('status', 'active')->orderBy('id')->value('id');
        if ($botId === null) {
            throw new RuntimeException('No hay ningún bot activo para atribuir el lead InCompany.');
        }

        return (int) $botId;
    }
}
