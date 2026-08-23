<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Program;
use Modules\Crm\Models\IncompanyLead;
use Modules\Crm\Models\Lead;
use Modules\Institutions\Models\Bot;
use RuntimeException;

/**
 * Ingesta de un lead InCompany (formación corporativa) recibido por el endpoint
 * público desde n8n. TODO en una transacción: contacto (dedupe por email) + lead
 * corporativo + perfil InCompany (con enlace al catálogo por course_idnumber) +
 * evento corporate_interest (para el chip "Empresa" y el KPI). Si algo falla, no
 * queda nada a medias.
 *
 * Reutiliza el modelo actual: el lead lleva area='Corporativo', source='incompany_web'
 * y product_type='incompany'; se atribuye al primer bot activo de la institución
 * (los leads exigen bot_id) — el origen real queda claro por el source.
 */
class IncompanyLeadIntake
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly LeadService $leads,
        private readonly EventService $events,
    ) {}

    /**
     * @param  array<string,mixed>  $data  ya VALIDADO por el controlador
     */
    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data): Lead {
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
                $matches[$i] = $code === '' ? [null, null] : [$code, Program::findByCourseIdnumber($code)?->getKey()];
            }

            $corporateArea = (string) config('crm.lead.corporate_area', 'Corporativo');

            $lead = $this->leads->recordIntent($contact, [
                'bot_id' => $botId,
                'product_type' => 'incompany',
                'source' => 'incompany_web',
                'interest_level' => 'high',
                'area' => $corporateArea,
                // El programa principal del lead = primer programa de la ruta (si enlazó).
                'program_id' => $matches[1][1] ?? null,
            ]);

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
                    'origen' => 'incompany_web',
                ],
            );

            // Enciende el chip "Empresa" y el KPI "Interés corporativo".
            $this->events->record('corporate_interest', [
                'contact_id' => $contact->getKey(),
                'bot_id' => $botId,
                'data' => ['empresa' => $data['nombre_empresa'], 'origen' => 'incompany_web'],
            ]);

            return $lead;
        });
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
