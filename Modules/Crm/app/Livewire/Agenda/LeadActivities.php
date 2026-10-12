<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\AgendaDirectory;

/**
 * Bloque «Agenda» de la ficha del lead: próxima actividad, próximas, completadas
 * recientes, «Programar actividad» (precarga lead, contacto y asesor) y «Abrir Agenda».
 * Respeta la visibilidad de la agenda (un asesor ve las suyas; admin y solo lectura, todas).
 */
class LeadActivities extends Component
{
    #[Locked]
    public int $leadId;

    public function mount(int $leadId): void
    {
        $this->leadId = $leadId;
    }

    public function render(): View
    {
        $user = auth()->user();
        $lead = Lead::query()->findOrFail($this->leadId);
        $visible = $user->can('viewAny', Activity::class);

        $base = fn () => ActivityPolicy::visibleQuery(Activity::query(), $user)
            ->with('assignee')
            ->where(fn ($q) => $q->where('lead_id', $lead->id)->orWhere(fn ($q2) => $q2->whereNull('lead_id')->where('contact_id', $lead->contact_id)));

        $upcoming = $visible
            ? $base()->where('status', ActivityStatus::Scheduled->value)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(5)->get()
            : collect();
        $recent = $visible
            ? $base()->where('status', ActivityStatus::Completed->value)->orderByDesc('completed_at')->limit(3)->get()
            : collect();

        return view('crm::livewire.agenda.lead-activities', [
            'lead' => $lead,
            'visible' => $visible,
            'next' => $upcoming->first(),
            'upcoming' => $upcoming->slice(1),
            'recent' => $recent,
            'canCreate' => $user->can('create', Activity::class),
            'tz' => app(AgendaDirectory::class)->timezoneFor((int) $user->id),
        ]);
    }
}
