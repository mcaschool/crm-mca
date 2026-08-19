<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;

/**
 * Regla de conversion contacto -> lead (tema central del CRM). Un contacto se
 * convierte en LEAD cuando ocurre CUALQUIERA de los disparadores configurados en
 * crm.lead.triggers (interes corporativo/InCompany, interes en un programa o area,
 * solicitud de precio/inscripcion/pago). Si solo hubo saludo o preguntas generales
 * (ningun disparador), se queda como CONTACTO.
 *
 * Es el punto UNICO de la regla: reutiliza LeadService::recordIntent (granularidad
 * D4, idempotente dentro de la ventana) para no duplicar leads, y deja el motivo en
 * `source` + el detalle relevante (programa/area/meta) para el seguimiento. El
 * emparejador NO pasa por aqui: MatcherService ya crea el lead con el detalle
 * completo.
 */
class LeadConversionService
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly EventService $events,
    ) {}

    /** ¿Este event_type es un disparador de conversion configurado? */
    public function isTrigger(?string $eventType): bool
    {
        return $eventType !== null && isset(((array) config('crm.lead.triggers', []))[$eventType]);
    }

    /**
     * Convierte a lead si `$eventType` es un disparador configurado. Devuelve el lead
     * (nuevo o actualizado) o null si el evento no dispara conversion / falta el bot.
     *
     * @param  array<string,mixed>  $detail  program_id, area, goal (lo que se sepa)
     */
    public function convert(?Contact $contact, ?int $botId, string $eventType, array $detail = []): ?Lead
    {
        if ($contact === null || $botId === null) {
            return null;
        }

        /** @var array<string, array{source: string, interest_level: string, area?: string}> $triggers */
        $triggers = (array) config('crm.lead.triggers', []);
        if (! isset($triggers[$eventType])) {
            return null;
        }

        $trigger = $triggers[$eventType];

        $lead = $this->leads->recordIntent($contact, array_filter([
            'bot_id' => $botId,
            'source' => $trigger['source'],
            'interest_level' => $trigger['interest_level'],
            'program_id' => $detail['program_id'] ?? null,
            // La categoria del disparador (p. ej. "Corporativo") marca el lead como
            // una area mas; el detalle explicito de la conversacion tiene prioridad.
            'area' => $detail['area'] ?? ($trigger['area'] ?? null),
            'goal' => $detail['goal'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== ''));

        // Solo se deja rastro "Lead creado" cuando REALMENTE nace un lead (no al
        // actualizar el vigente), con el motivo del disparador para el seguimiento.
        if ($lead->wasRecentlyCreated) {
            $this->events->record('lead_created', [
                'contact_id' => $contact->getKey(),
                'bot_id' => $botId,
                'data' => array_filter([
                    'trigger' => $eventType,
                    'source' => $trigger['source'],
                    'program_id' => $detail['program_id'] ?? null,
                ], static fn ($v): bool => $v !== null && $v !== ''),
            ]);
        }

        return $lead;
    }
}
