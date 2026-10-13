<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Agenda\AvailabilityService;
use Modules\Crm\Services\Agenda\ScheduleConflictException;
use Modules\Crm\Services\Video\VideoCapacity;

/**
 * Crear y editar una actividad. La disponibilidad se comprueba al mostrar (aviso en vivo
 * y huecos libres sugeridos) y OTRA VEZ al guardar, en el servidor, con bloqueo. Si el
 * hueco está fuera de disponibilidad o en conflicto, no se guarda: hay que marcar la
 * confirmación de excepción (queda registrado quién la autorizó). Una clave de
 * idempotencia por formulario evita duplicados por doble clic o reintento.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    public ?int $activityId = null;

    public string $title = '';

    public string $type = 'call';

    public string $medium = 'google_meet';

    public string $date = '';

    public string $time = '';

    public int $duration = 30;

    public string $timezone = 'UTC';

    public ?int $assignedTo = null;

    public ?int $leadId = null;

    public ?int $contactId = null;

    public string $priority = 'normal';

    public string $description = '';

    public string $location = '';

    /** @var array<int,int|string> */
    public array $reminders = [];

    public string $contactSearch = '';

    public bool $confirmException = false;

    public string $exceptionReason = '';

    public string $rescheduleReason = '';

    /** @var array<int,string> */
    public array $serverWarnings = [];

    public string $idempotencyKey = '';

    public function mount(?Activity $activity = null): void
    {
        $user = auth()->user();
        $this->idempotencyKey = (string) Str::uuid();

        if ($activity !== null && $activity->exists) {
            $this->authorize('update', $activity);
            if (! $activity->isScheduled()) {
                abort(403, __('Solo se pueden editar actividades programadas.'));
            }
            $this->activityId = $activity->id;
            $start = $activity->localStart();
            $this->fill([
                'title' => $activity->title,
                'type' => $activity->type->value,
                'medium' => $activity->medium->value,
                'date' => $start->toDateString(),
                'time' => $start->format('H:i'),
                'duration' => $activity->durationMinutes(),
                'timezone' => $activity->timezone,
                'assignedTo' => $activity->assigned_to_user_id,
                'leadId' => $activity->lead_id,
                'contactId' => $activity->contact_id,
                'priority' => $activity->priority,
                'description' => (string) $activity->description,
                'location' => (string) $activity->location,
                'reminders' => $activity->reminders ?? [],
            ]);

            return;
        }

        $this->authorize('create', Activity::class);
        $this->assignedTo = $user->id;
        $profile = MeetingProfile::forUser($user->id);
        $this->timezone = $profile->timezone;
        $this->medium = $profile->default_medium->value;
        $this->duration = $profile->default_duration_minutes;
        $this->reminders = (array) config('crm.agenda.default_reminders', [60]);

        // Precarga desde la ficha del lead: lead, contacto y asesor responsable.
        $leadParam = request()->query('lead');
        if (is_string($leadParam) && ctype_digit($leadParam)) {
            $lead = Lead::query()->with('contact')->find((int) $leadParam);
            if ($lead !== null) {
                $this->leadId = $lead->id;
                $this->contactId = $lead->contact_id;
                $this->title = __('Llamada con :name', ['name' => $lead->contact?->fullName() ?? __('lead')]);
                if ($lead->assigned_to_user_id && (new ActivityPolicy)->manageOthers($user)
                    && app(AgendaDirectory::class)->assignableUsers()->contains('id', $lead->assigned_to_user_id)) {
                    $this->assignedTo = $lead->assigned_to_user_id;
                    $this->applyAssigneeDefaults();
                }
            }
        }
        $d = request()->query('date');
        $this->date = is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : now($this->timezone)->toDateString();
        $t = request()->query('time');
        $this->time = is_string($t) && preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '';
    }

    public function updatedAssignedTo(): void
    {
        $this->applyAssigneeDefaults();
        $this->resetWarnings();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'time', 'duration', 'timezone', 'assignedTo'], true)) {
            $this->resetWarnings();
        }
    }

    public function pickSlot(string $time): void
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $this->time = $time;
            $this->resetWarnings();
        }
    }

    public function pickContact(int $id): void
    {
        $contact = Contact::query()->find($id);
        if ($contact === null) {
            return;
        }
        $this->contactId = $contact->id;
        $this->leadId = Lead::query()->where('contact_id', $contact->id)->latest('id')->value('id');
        $this->contactSearch = '';
        if ($this->title === '') {
            $this->title = __('Llamada con :name', ['name' => $contact->fullName()]);
        }
    }

    public function clearContact(): void
    {
        $this->contactId = null;
        $this->leadId = null;
    }

    public function save(ActivityService $service): mixed
    {
        $user = auth()->user();
        $this->validate($this->formRules());
        [$start, $end] = $this->interval();

        $data = [
            'title' => $this->title,
            'type' => $this->type,
            'medium' => $this->medium,
            'starts_at' => $start,
            'ends_at' => $end,
            'timezone' => $this->timezone,
            'assigned_to_user_id' => $this->assignedTo ?? $user->id,
            'lead_id' => $this->leadId,
            'contact_id' => $this->contactId,
            'priority' => $this->priority,
            'description' => $this->description,
            'location' => $this->location,
            'reminders' => $this->reminders,
        ];

        try {
            if ($this->activityId !== null) {
                $activity = Activity::query()->findOrFail($this->activityId);
                $this->authorize('update', $activity);
                $activity = $service->update($activity, $data, $user, $this->confirmException, $this->exceptionReason, $this->rescheduleReason);
            } else {
                $this->authorize('create', Activity::class);
                $activity = $service->create($data + ['idempotency_key' => $this->idempotencyKey], $user, $this->confirmException, $this->exceptionReason);
            }
        } catch (ScheduleConflictException $e) {
            $this->serverWarnings = $e->check->messages();
            $this->addError('confirmException', __('El horario no está disponible. Revisa el aviso y confirma la excepción si quieres guardarlo igualmente.'));

            return null;
        }

        session()->flash('agenda_status', $this->activityId ? __('Actividad actualizada.') : __('Actividad creada.'));

        return $this->redirectRoute('crm.agenda.show', ['activity' => $activity->id], navigate: false);
    }

    public function render(AvailabilityService $availability): View
    {
        $user = auth()->user();
        $canAssign = (new ActivityPolicy)->manageOthers($user);
        $advisorId = $this->assignedTo ?? $user->id;

        $check = null;
        $slots = [];
        if ($this->date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && $this->validTimezone()) {
            if (preg_match('/^\d{2}:\d{2}$/', $this->time) && $this->duration >= 5) {
                [$start, $end] = $this->interval();
                $medium = ActivityMedium::tryFrom($this->medium) ?? ActivityMedium::None;
                $hostKey = app(VideoCapacity::class)->hostKeyForLink($medium, MeetingProfile::forUser($advisorId)->linkFor($medium));
                $check = $availability->check($advisorId, $start, $end, $this->activityId, $hostKey);
            }
            $profileTz = MeetingProfile::forUser($advisorId)->timezone;
            $slots = $profileTz === $this->timezone
                ? array_slice($availability->freeSlots($advisorId, $this->date, max(5, $this->duration), $this->activityId), 0, 24)
                : [];
        }

        $results = collect();
        $term = trim($this->contactSearch);
        if (mb_strlen($term) >= 2) {
            $results = Contact::query()
                ->where(fn ($q) => $q->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%'))
                ->orderBy('first_name')
                ->limit(8)
                ->get();
        }

        return view('crm::livewire.agenda.form', [
            'editing' => $this->activityId !== null,
            'types' => ActivityType::cases(),
            'media' => ActivityMedium::cases(),
            'canAssign' => $canAssign,
            'advisors' => $canAssign ? app(AgendaDirectory::class)->assignableUsers() : collect([$user]),
            'timezones' => DateTimeZone::listIdentifiers(),
            'check' => $check,
            'freeSlots' => $slots, // no «slots»: Livewire 4 reserva $slots en la vista y lo sobrescribe
            'results' => $results,
            'pickedContact' => $this->contactId ? Contact::query()->find($this->contactId) : null,
            'pickedLead' => $this->leadId ? Lead::query()->with('program')->find($this->leadId) : null,
            'profile' => MeetingProfile::forUser($advisorId),
        ]);
    }

    /** @return array<string,mixed> */
    private function formRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(ActivityType::values())],
            'medium' => ['required', Rule::in(ActivityMedium::values())],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:5', 'max:720'],
            'timezone' => ['required', 'timezone:all'],
            'priority' => ['required', Rule::in(Activity::PRIORITIES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'reminders' => ['array'],
            'reminders.*' => [Rule::in(Activity::REMINDER_OPTIONS)],
            'exceptionReason' => ['nullable', 'string', 'max:255'],
            'rescheduleReason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function interval(): array
    {
        try {
            $start = Carbon::createFromFormat('Y-m-d H:i', $this->date.' '.$this->time, $this->timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['time' => __('Fecha u hora no válidas.')]);
        }
        if (! $start instanceof Carbon) {
            throw ValidationException::withMessages(['time' => __('Fecha u hora no válidas.')]);
        }

        return [$start->copy()->utc(), $start->copy()->addMinutes(max(5, $this->duration))->utc()];
    }

    private function applyAssigneeDefaults(): void
    {
        if ($this->activityId !== null || $this->assignedTo === null) {
            return;
        }
        $profile = MeetingProfile::forUser($this->assignedTo);
        $this->timezone = $profile->timezone;
        $this->medium = $profile->default_medium->value;
        $this->duration = $profile->default_duration_minutes;
    }

    private function validTimezone(): bool
    {
        return in_array($this->timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function resetWarnings(): void
    {
        $this->serverWarnings = [];
        $this->confirmException = false;
        $this->resetErrorBag('confirmException');
    }
}
