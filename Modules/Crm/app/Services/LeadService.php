<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use InvalidArgumentException;
use Modules\Crm\Enums\InterestLevel;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;

/**
 * Ciclo de vida del lead y granularidad D4.
 *
 * D4: un lead por conversacion con intencion. Al registrar intencion se busca el
 * lead vigente del contacto para ese product_type; si hay actividad reciente
 * (menos de N dias, config crm.lead.reopen_after_days) se ACTUALIZA; si no
 * (N+ dias inactivo, o distinto product_type) se crea uno NUEVO. Con solo
 * microcredentials hoy, el criterio de "distinto producto" queda listo pero no
 * se dispara aun (Sofia/otros productos en el futuro).
 */
class LeadService
{
    public function __construct(private readonly EventService $events) {}

    /**
     * Registra intencion comercial aplicando la granularidad D4.
     *
     * @param  array<string,mixed>  $attrs  bot_id (requerido al crear), program_id,
     *                                      area, goal, level, source, interest_level
     */
    public function recordIntent(Contact $contact, array $attrs): Lead
    {
        $productType = (string) ($attrs['product_type'] ?? 'microcredential');
        $reopenDays = (int) config('crm.lead.reopen_after_days', 30);

        // Lead vigente del contacto para ESTE product_type (un product_type distinto
        // no coincide -> se creara uno nuevo: criterio D4 "distinto producto").
        $current = Lead::query()
            ->where('contact_id', $contact->getKey())
            ->where('product_type', $productType)
            ->orderByDesc('updated_at')
            ->first();

        // Reciente (actividad dentro de la ventana): se actualiza el vigente.
        if ($current !== null && $current->updated_at->greaterThan(now()->subDays($reopenDays))) {
            $this->applyAttrs($current, $attrs);
            $current->save();

            return $current;
        }

        // Inactivo N+ dias o sin lead previo para ese producto: se crea uno nuevo.
        $lead = new Lead;
        $lead->contact_id = $contact->getKey();
        $lead->product_type = $productType;
        $lead->bot_id = (int) ($attrs['bot_id'] ?? throw new InvalidArgumentException('bot_id es requerido para crear un lead.'));
        $this->applyAttrs($lead, $attrs);
        $lead->save();

        return $lead;
    }

    /**
     * Cambio manual de estado desde el panel. 'enrolled' es TERMINAL: no admite
     * mas transiciones (la entrega al SGA es de un modulo futuro).
     */
    public function changeStatus(Lead $lead, LeadStatus $status): Lead
    {
        if ($lead->status->isTerminal()) {
            throw new InvalidArgumentException("El lead esta en estado terminal ({$lead->status->value}) y no puede cambiar.");
        }

        $lead->status = $status;
        $lead->save();

        return $lead;
    }

    public function changeInterest(Lead $lead, InterestLevel $level): Lead
    {
        $lead->interest_level = $level;
        $lead->save();

        return $lead;
    }

    /**
     * Transfiere el seguimiento del lead a un asesor humano (usuario), a un
     * departamento, o de vuelta a la IA. Deja rastro con un evento
     * `lead_transferred` (todo comportamiento deja rastro). No expone datos
     * personales: guarda solo la etiqueta legible del destino.
     *
     * Semantica segun el esquema (assigned_to_user_id + assigned_to_department):
     *  - humano  -> fija user_id, limpia department
     *  - depto   -> fija department, limpia user_id
     *  - IA      -> limpia ambos (el lead vuelve a manos de la IA, sin dueno humano)
     */
    public function transfer(Lead $lead, string $target, string $label, ?string $byName = null, ?string $byDept = null): Lead
    {
        [$kind, $ref] = array_pad(explode(':', $target, 2), 2, '');

        switch ($kind) {
            case 'user':
                $lead->assigned_to_user_id = (int) $ref;
                $lead->assigned_to_department = null;
                break;
            case 'dept':
                $lead->assigned_to_user_id = null;
                $lead->assigned_to_department = $ref;
                break;
            case 'ia':
                $lead->assigned_to_user_id = null;
                $lead->assigned_to_department = null;
                break;
            default:
                throw new InvalidArgumentException("Destino de transferencia no valido: {$target}.");
        }

        $lead->save();

        $this->events->record('lead_transferred', [
            'contact_id' => $lead->contact_id,
            'conversation_id' => null,
            'bot_id' => $lead->bot_id,
            'data' => array_filter([
                'to' => $label,
                'kind' => $kind,
                'by_name' => $byName,
                'by_dept' => $byDept,
            ], fn ($v) => $v !== null),
        ]);

        return $lead;
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function applyAttrs(Lead $lead, array $attrs): void
    {
        foreach (['program_id', 'area', 'goal', 'level', 'source'] as $field) {
            if (array_key_exists($field, $attrs) && $attrs[$field] !== null && $attrs[$field] !== '') {
                $lead->{$field} = $attrs[$field];
            }
        }

        if (isset($attrs['interest_level'])) {
            $lead->interest_level = $attrs['interest_level'] instanceof InterestLevel
                ? $attrs['interest_level']
                : InterestLevel::from((string) $attrs['interest_level']);
        }
    }
}
