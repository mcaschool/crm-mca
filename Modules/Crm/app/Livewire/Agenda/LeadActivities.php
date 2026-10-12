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

    public string $bookingUrl = '';

    public string $bookingNotice = '';

    public function mount(int $leadId): void
    {
        $this->leadId = $leadId;
    }

    /**
     * Enlace de reserva CONTEXTUAL para este lead: la reserva hecha con él queda vinculada al lead
     * sin exponer su id ni sus datos (token aleatorio; solo se guarda su hash; caduca).
     */
    public function generateBookingLink(\Modules\Crm\Services\Booking\BookingPublisher $publisher): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('create', Activity::class), 403);
        $lead = Lead::query()->findOrFail($this->leadId);
        abort_unless($user->can('view', $lead), 403);

        $profile = \Modules\Crm\Models\MeetingProfile::forUser($user->id);
        $institution = \Modules\Institutions\Models\Institution::query()->find($lead->institution_id);
        if ($institution === null || ! $publisher->advisorCanReceive($user, $profile)) {
            $this->bookingUrl = '';
            $this->bookingNotice = __('Activa tu enlace de reservas en Calendario › Perfil de reuniones para generar enlaces.');

            return;
        }

        $token = \Illuminate\Support\Str::random(48);
        \Modules\Crm\Models\BookingLink::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'token_hash' => \Modules\Crm\Models\Booking::hashToken($token),
            'expires_at' => now()->addDays(\Modules\Crm\Models\BookingLink::TTL_DAYS),
        ]);
        $this->bookingUrl = route('booking.advisor', [$institution->getAttribute('slug'), $profile->booking_slug]).'?ref='.$token;
        $this->bookingNotice = __('Enlace válido durante :n días. Las reservas hechas con él se vinculan a este lead.', ['n' => \Modules\Crm\Models\BookingLink::TTL_DAYS]);
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
