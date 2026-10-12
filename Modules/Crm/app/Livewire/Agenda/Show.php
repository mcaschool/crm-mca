<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Agenda\ScheduleConflictException;
use Modules\Crm\Services\Agenda\WhatsappLink;

/**
 * Detalle de una actividad: abrir lead/contacto, unirse (Meet/Zoom), abrir WhatsApp,
 * completar, registrar resultado, reprogramar, cancelar, editar y gestionar invitados
 * (añadir/reenviar/quitar, con estado y fecha del último correo). Toda acción re-autoriza
 * en el servidor; los perfiles de solo lectura solo ven.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public int $activityId;

    public string $outcome = '';

    public string $cancelReason = '';

    public string $rsDate = '';

    public string $rsTime = '';

    public int $rsDuration = 30;

    public string $rsReason = '';

    public bool $rsConfirm = false;

    /** @var array<int,string> */
    public array $rsWarnings = [];

    public string $panel = '';

    public string $guestSearch = '';

    public string $guestName = '';

    public string $guestEmail = '';

    public string $guestLanguage = 'es';

    public string $notice = '';

    public bool $noticeError = false;

    public function mount(Activity $activity): void
    {
        $this->authorize('view', $activity);
        $this->activityId = $activity->id;
        $this->outcome = (string) $activity->outcome;
        $start = $activity->localStart();
        $this->rsDate = $start->toDateString();
        $this->rsTime = $start->format('H:i');
        $this->rsDuration = $activity->durationMinutes();
        $this->notice = (string) session('agenda_status', '');
        $panel = request()->query('panel');
        if (in_array($panel, ['reschedule', 'cancel'], true) && auth()->user()->can('update', $activity)) {
            $this->panel = $panel;
        }
    }

    public function open(string $panel): void
    {
        $this->panel = $this->panel === $panel ? '' : $panel;
        $this->rsWarnings = [];
        $this->rsConfirm = false;
    }

    public function complete(ActivityService $service): void
    {
        $activity = $this->authorized();
        $service->complete($activity, auth()->user(), $this->outcome);
        $this->flash(__('Actividad completada.'));
    }

    public function noShow(ActivityService $service): void
    {
        $activity = $this->authorized();
        $service->markNoShow($activity, auth()->user(), $this->outcome);
        $this->flash(__('Marcada como «no asistió».'));
    }

    public function saveOutcome(ActivityService $service): void
    {
        $activity = $this->authorized();
        $this->validate(['outcome' => ['nullable', 'string', 'max:5000']]);
        $service->recordOutcome($activity, $this->outcome);
        $this->flash(__('Resultado guardado.'));
    }

    public function cancelActivity(ActivityService $service): void
    {
        $activity = $this->authorized();
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:1000']]);
        $service->cancel($activity, auth()->user(), $this->cancelReason);
        $this->flash(__('Actividad cancelada. Se avisó a los invitados activos.'));
    }

    public function reschedule(ActivityService $service): void
    {
        $activity = $this->authorized();
        $this->validate([
            'rsDate' => ['required', 'date_format:Y-m-d'],
            'rsTime' => ['required', 'date_format:H:i'],
            'rsDuration' => ['required', 'integer', 'min:5', 'max:720'],
            'rsReason' => ['nullable', 'string', 'max:255'],
        ]);
        $start = Carbon::createFromFormat('Y-m-d H:i', $this->rsDate.' '.$this->rsTime, $activity->timezone);
        if (! $start instanceof Carbon) {
            throw ValidationException::withMessages(['rsTime' => __('Fecha u hora no válidas.')]);
        }

        try {
            $service->reschedule($activity, $start->copy()->utc(), $start->copy()->addMinutes($this->rsDuration)->utc(), auth()->user(), $this->rsConfirm, null, $this->rsReason);
        } catch (ScheduleConflictException $e) {
            $this->rsWarnings = $e->check->messages();

            return;
        }
        $this->rsWarnings = [];
        $this->rsConfirm = false;
        $this->flash(__('Actividad reprogramada. Se envió la actualización a los invitados activos.'));
    }

    public function addContactGuest(int $contactId, ActivityService $service): void
    {
        $activity = $this->authorized();
        $service->addGuest($activity, $contactId, null, null);
        $this->guestSearch = '';
        $this->flash(__('Invitado añadido. Se le envió la invitación.'));
    }

    public function addManualGuest(ActivityService $service): void
    {
        $activity = $this->authorized();
        $this->validate([
            'guestName' => ['required', 'string', 'max:191'],
            'guestEmail' => ['required', 'email', 'max:191'],
            'guestLanguage' => ['required', 'in:es,en'],
        ]);
        $service->addGuest($activity, null, $this->guestName, $this->guestEmail, $this->guestLanguage);
        $this->reset('guestName', 'guestEmail');
        $this->flash(__('Invitado añadido. Se le envió la invitación.'));
    }

    public function resendGuest(int $guestId, ActivityService $service): void
    {
        $this->authorized();
        $guest = ActivityGuest::query()->where('activity_id', $this->activityId)->findOrFail($guestId);
        $ok = $service->resendInvitation($guest);
        $this->flash($ok ? __('Invitación reenviada.') : __('No se reenvió: espera un minuto o revisa el estado del invitado.'), ! $ok);
    }

    public function removeGuest(int $guestId, ActivityService $service): void
    {
        $this->authorized();
        $guest = ActivityGuest::query()->where('activity_id', $this->activityId)->findOrFail($guestId);
        $service->removeGuest($guest);
        $this->flash(__('Invitado quitado. Se le envió la cancelación.'));
    }

    public function render(WhatsappLink $whatsapp): View
    {
        $activity = Activity::query()
            ->with(['lead.contact', 'lead.program', 'contact', 'assignee', 'creator', 'exceptionBy', 'guests', 'reschedules'])
            ->findOrFail($this->activityId);
        $user = auth()->user();
        $viewerTz = app(AgendaDirectory::class)->timezoneFor((int) $user->id);

        $results = collect();
        $term = trim($this->guestSearch);
        if (mb_strlen($term) >= 2) {
            $results = Contact::query()
                ->whereNotNull('email')
                ->where(fn ($q) => $q->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%'))
                ->orderBy('first_name')
                ->limit(6)
                ->get();
        }

        $contact = $activity->contact ?? $activity->lead?->contact;

        return view('crm::livewire.agenda.show', [
            'a' => $activity,
            'canAct' => $user->can('update', $activity),
            'viewerTz' => $viewerTz,
            'contact' => $contact,
            'joinUrl' => in_array($activity->medium, [ActivityMedium::GoogleMeet, ActivityMedium::Zoom], true) ? $activity->meeting_link : null,
            'waUrl' => $whatsapp->forActivity($activity),
            'phone' => $activity->medium === ActivityMedium::Phone ? $activity->meeting_link : null,
            'results' => $results,
        ]);
    }

    private function authorized(): Activity
    {
        $activity = Activity::query()->findOrFail($this->activityId);
        $this->authorize('update', $activity);

        return $activity;
    }

    private function flash(string $message, bool $error = false): void
    {
        $this->notice = $message;
        $this->noticeError = $error;
        $this->panel = '';
    }
}
