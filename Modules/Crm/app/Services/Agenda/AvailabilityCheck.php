<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use Illuminate\Support\Collection;
use Modules\Crm\Models\Activity;

/**
 * Resultado de comprobar un hueco: problemas de disponibilidad (fuera de horario,
 * ausencia, antelación, horizonte) y actividades programadas con las que se solapa
 * (incluidos los márgenes). Vacío = se puede guardar sin excepción.
 */
final class AvailabilityCheck
{
    /**
     * @param  array<int,string>  $issues  códigos: outside_hours · absence · notice · horizon · past · video_capacity
     * @param  Collection<int,Activity>  $conflicts
     */
    public function __construct(
        public readonly array $issues,
        public readonly Collection $conflicts,
    ) {}

    public function ok(): bool
    {
        return $this->issues === [] && $this->conflicts->isEmpty();
    }

    /** @return array<int,string> mensajes legibles para el aviso */
    public function messages(): array
    {
        $map = [
            'outside_hours' => __('Fuera del horario disponible del asesor.'),
            'absence' => __('El asesor tiene una ausencia o vacaciones en ese horario.'),
            'notice' => __('No respeta la antelación mínima configurada.'),
            'horizon' => __('Supera el horizonte máximo de programación.'),
            'past' => __('La hora de inicio ya pasó.'),
            'video_capacity' => __('Supera la capacidad simultánea de Zoom del anfitrión institucional.'),
        ];
        $out = array_map(fn (string $c) => $map[$c] ?? $c, $this->issues);

        foreach ($this->conflicts as $c) {
            $out[] = __('Se solapa (con márgenes) con «:title» (:time).', [
                'title' => $c->title,
                'time' => $c->localStart()->format('d/m H:i').'–'.$c->localEnd()->format('H:i'),
            ]);
        }

        return $out;
    }
}
