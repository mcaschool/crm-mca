<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\PhoneNumber;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityStatus;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\ActivityReminder;
use Modules\Crm\Models\ActivityReschedule;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Notifications\AgendaNotification;
use Modules\Crm\Services\EventService;

/**
 * Reglas de negocio de la agenda. Toda escritura que afecta al horario de un asesor ocurre
 * en una transacción con bloqueo de su fila de usuario (serializa altas/reprogramaciones
 * concurrentes del mismo asesor) y RE-COMPRUEBA la disponibilidad en el servidor: un hueco
 * fuera de horario o en conflicto solo se guarda con confirmación explícita, que queda
 * registrada (quién, cuándo, motivo). Nunca cambia el estado comercial del lead.
 */
class ActivityService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AgendaMailer $mailer,
        private readonly EventService $events,
        private readonly CurrentInstitution $context,
    ) {}

    /**
     * @param  array<string,mixed>  $data  title, type, medium, starts_at, ends_at, timezone, assigned_to_user_id,
     *                                     lead_id?, contact_id?, priority?, description?, location?, reminders?, idempotency_key?
     */
    public function create(array $data, User $actor, bool $confirmException = false, ?string $exceptionReason = null): Activity
    {
        $assignee = $this->guardAssignee($actor, (int) $data['assigned_to_user_id']);
        [$leadId, $contactId] = $this->resolveLinks($data['lead_id'] ?? null, $data['contact_id'] ?? null);
        [$start, $end] = $this->times($data['starts_at'], $data['ends_at']);
        $type = ActivityType::from((string) $data['type']);
        $medium = ActivityMedium::from((string) ($data['medium'] ?? 'none'));
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? (string) $data['idempotency_key'] : null;

        if ($key !== null && ($existing = Activity::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing; // doble envío / reintento: no duplica
        }

        try {
            [$activity, $created] = DB::transaction(function () use ($data, $actor, $assignee, $leadId, $contactId, $start, $end, $type, $medium, $key, $confirmException, $exceptionReason): array {
                $this->lockAdvisor($assignee->id);
                if ($key !== null && ($existing = Activity::query()->where('idempotency_key', $key)->first()) !== null) {
                    return [$existing, false];
                }

                $check = $this->availability->check($assignee->id, $start, $end);
                $activity = new Activity;
                $this->applyException($activity, $check, $actor, $confirmException, $exceptionReason);

                $profile = MeetingProfile::forUser($assignee->id);
                $activity->fill([
                    'lead_id' => $leadId,
                    'contact_id' => $contactId,
                    'assigned_to_user_id' => $assignee->id,
                    'created_by_user_id' => $actor->id,
                    'title' => trim((string) $data['title']),
                    'type' => $type,
                    'medium' => $medium,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'timezone' => (string) ($data['timezone'] ?? $profile->timezone),
                    'priority' => in_array($data['priority'] ?? null, Activity::PRIORITIES, true) ? $data['priority'] : 'normal',
                    'description' => $this->nullable($data['description'] ?? null),
                    'location' => $this->nullable($data['location'] ?? null) ?? ($medium === ActivityMedium::InPerson ? $profile->address : null),
                    'reminders' => $this->reminderOffsets($data['reminders'] ?? config('crm.agenda.default_reminders', [])),
                    'idempotency_key' => $key,
                ]);
                $activity->meeting_link = $this->snapshotLink($medium, $profile);
                $activity->meeting_room_hash = $this->roomHashFor($medium, $activity->meeting_link);
                $activity->uid = Str::uuid()->toString().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'crm.local');
                $activity->save();
                $this->syncReminders($activity);

                return [$activity, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key !== null && ($existing = Activity::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }
            throw $e;
        }

        if ($created) {
            $this->recordEvent(EventType::ActivityScheduled, $activity);
            if ($assignee->id !== $actor->id) {
                $assignee->notify(AgendaNotification::for('assigned', $activity));
                $this->mailer->toAdvisor($activity, $assignee, 'assigned');
            }
        }

        return $activity;
    }

    /**
     * Edita una actividad programada. Si cambian las horas, es una REPROGRAMACIÓN: sigue
     * «programada», se registra, sube la SEQUENCE del .ics y se avisa a los invitados.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(Activity $activity, array $data, User $actor, bool $confirmException = false, ?string $exceptionReason = null, ?string $rescheduleReason = null): Activity
    {
        $this->guardScheduled($activity);

        $assigneeId = (int) ($data['assigned_to_user_id'] ?? $activity->assigned_to_user_id);
        $assignee = $assigneeId !== $activity->assigned_to_user_id
            ? $this->guardAssignee($actor, $assigneeId)
            : $activity->assignee;
        [$start, $end] = $this->times($data['starts_at'] ?? $activity->starts_at, $data['ends_at'] ?? $activity->ends_at);
        $links = array_key_exists('lead_id', $data) || array_key_exists('contact_id', $data)
            ? $this->resolveLinks($data['lead_id'] ?? null, $data['contact_id'] ?? null)
            : [$activity->lead_id, $activity->contact_id];

        $outcome = DB::transaction(function () use ($activity, $data, $actor, $assignee, $start, $end, $links, $confirmException, $exceptionReason, $rescheduleReason): array {
            $this->lockAdvisor((int) $assignee?->id);
            /** @var Activity $fresh */
            $fresh = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            $this->guardScheduled($fresh);

            $timesChanged = ! $fresh->starts_at->eq($start) || ! $fresh->ends_at->eq($end);
            $assigneeChanged = (int) $assignee?->id !== $fresh->assigned_to_user_id;
            if ($timesChanged || $assigneeChanged) {
                $check = $this->availability->check((int) $assignee?->id, $start, $end, $fresh->id);
                $this->applyException($fresh, $check, $actor, $confirmException, $exceptionReason);
            }

            $before = ['title' => $fresh->title, 'medium' => $fresh->medium, 'location' => $fresh->location];
            $medium = isset($data['medium']) ? ActivityMedium::from((string) $data['medium']) : $fresh->medium;
            $fresh->fill(array_filter([
                'title' => isset($data['title']) ? trim((string) $data['title']) : null,
                'type' => isset($data['type']) ? ActivityType::from((string) $data['type']) : null,
                'timezone' => $data['timezone'] ?? null,
                'priority' => in_array($data['priority'] ?? null, Activity::PRIORITIES, true) ? $data['priority'] : null,
            ], fn ($v) => $v !== null));
            foreach (['description', 'location'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $this->nullable($data[$field]);
                }
            }
            if (array_key_exists('reminders', $data)) {
                $fresh->reminders = $this->reminderOffsets($data['reminders']);
            }
            [$fresh->lead_id, $fresh->contact_id] = $links;
            $fresh->medium = $medium;
            $fresh->assigned_to_user_id = (int) $assignee?->id;
            if ($medium !== $before['medium'] || $assigneeChanged) {
                $profile = MeetingProfile::forUser($fresh->assigned_to_user_id);
                $fresh->meeting_link = $this->snapshotLink($medium, $profile);
                $fresh->meeting_room_hash = $this->roomHashFor($medium, $fresh->meeting_link);
                if ($medium === ActivityMedium::InPerson && ! $fresh->location) {
                    $fresh->location = $profile->address;
                }
            }

            if ($timesChanged) {
                ActivityReschedule::query()->create([
                    'activity_id' => $fresh->id,
                    'previous_starts_at' => $fresh->starts_at,
                    'previous_ends_at' => $fresh->ends_at,
                    'new_starts_at' => $start,
                    'new_ends_at' => $end,
                    'by_user_id' => $actor->id,
                    'reason' => $this->nullable($rescheduleReason),
                ]);
                $fresh->starts_at = $start;
                $fresh->ends_at = $end;
                $fresh->reschedule_count++;
            }

            $guestRelevant = $timesChanged || $fresh->title !== $before['title'] || $medium !== $before['medium'] || $fresh->location !== $before['location'];
            if ($guestRelevant) {
                $fresh->ics_sequence++;
            }
            $fresh->save();
            $this->syncReminders($fresh);

            return [$fresh, $timesChanged, $guestRelevant];
        });

        [$fresh, $timesChanged, $guestRelevant] = $outcome;
        if ($timesChanged) {
            $this->recordEvent(EventType::ActivityRescheduled, $fresh, ['starts_at' => $fresh->starts_at->toIso8601String()]);
        }
        if ($guestRelevant) {
            $this->notifyGuests($fresh, 'update');
        }
        $activity->setRawAttributes($fresh->getAttributes(), true);

        return $activity;
    }

    public function reschedule(Activity $activity, Carbon $start, Carbon $end, User $actor, bool $confirmException = false, ?string $exceptionReason = null, ?string $reason = null): Activity
    {
        return $this->update($activity, ['starts_at' => $start, 'ends_at' => $end], $actor, $confirmException, $exceptionReason, $reason);
    }

    public function complete(Activity $activity, User $actor, ?string $outcome = null): Activity
    {
        return $this->close($activity, ActivityStatus::Completed, $outcome);
    }

    public function markNoShow(Activity $activity, User $actor, ?string $outcome = null): Activity
    {
        return $this->close($activity, ActivityStatus::NoShow, $outcome);
    }

    public function cancel(Activity $activity, User $actor, ?string $reason = null): Activity
    {
        return $this->close($activity, ActivityStatus::Cancelled, $reason);
    }

    public function recordOutcome(Activity $activity, ?string $outcome): Activity
    {
        $activity->outcome = $this->nullable($outcome);
        $activity->save();

        return $activity;
    }

    /**
     * Añade un invitado (contacto existente o nombre+correo) y le envía la invitación al
     * momento. Idempotente: si el correo ya está activo en la actividad no duplica ni
     * reenvía; si estaba retirado, se reactiva la misma fila. Un invitado externo nunca
     * crea contacto ni lead.
     */
    public function addGuest(Activity $activity, ?int $contactId, ?string $name, ?string $email, ?string $language = null): ActivityGuest
    {
        $this->guardScheduled($activity);
        if (! $activity->type->isMeeting()) {
            throw ValidationException::withMessages(['guest' => __('Este tipo de actividad no admite invitados.')]);
        }

        $contact = null;
        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId); // scope de institución: otro tenant = no encontrado
            if ($contact === null || ! $contact->email) {
                throw ValidationException::withMessages(['guest' => __('El contacto no existe o no tiene correo.')]);
            }
            $email = $contact->email;
            $name = $contact->fullName();
            $language ??= $contact->preferred_language;
        }

        $email = Str::lower(trim((string) $email));
        $name = trim((string) $name);
        if ($email === '' || mb_strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['guest' => __('Indica un correo válido.')]);
        }
        if ($name === '') {
            $name = Str::before($email, '@');
        }
        $language = $language === 'en' ? 'en' : 'es';

        [$guest, $send] = DB::transaction(function () use ($activity, $contact, $email, $name, $language): array {
            Activity::query()->whereKey($activity->id)->lockForUpdate()->first();
            $guest = ActivityGuest::query()->where('activity_id', $activity->id)->where('email', $email)->first();
            if ($guest !== null && $guest->isActive()) {
                return [$guest, false];
            }
            $guest ??= new ActivityGuest(['activity_id' => $activity->id, 'email' => $email]);
            $guest->fill([
                'contact_id' => $contact?->id,
                'name' => mb_substr($name, 0, 191),
                'language' => $language,
                'status' => 'pending',
                'removed_at' => null,
            ])->save();

            return [$guest, true];
        });

        if ($send) {
            $this->syncReminders($activity);
            $this->mailGuest($activity, $guest, 'invitation');
        }

        return $guest;
    }

    /** Retira un invitado y le envía la cancelación (solo una vez). */
    public function removeGuest(ActivityGuest $guest): ActivityGuest
    {
        $removed = DB::transaction(function () use ($guest): bool {
            $fresh = ActivityGuest::query()->whereKey($guest->id)->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->isActive()) {
                return false;
            }
            $fresh->forceFill(['removed_at' => now(), 'status' => 'removed'])->save();

            return true;
        });
        $guest->refresh();

        if ($removed) {
            $activity = $guest->activity;
            $this->syncReminders($activity);
            if ($activity->isScheduled()) {
                $this->mailGuest($activity, $guest, 'removed', keepStatus: true);
            }
        }

        return $guest;
    }

    /** Reenvía la invitación (o la actualización) a un invitado activo. Anti doble clic: 60 s. */
    public function resendInvitation(ActivityGuest $guest): bool
    {
        $activity = $guest->activity;
        if (! $guest->isActive() || ! $activity->isScheduled()) {
            return false;
        }
        if ($guest->last_emailed_at !== null && $guest->last_emailed_at->gt(now()->subSeconds(60))) {
            return false;
        }

        return $this->mailGuest($activity, $guest, $activity->ics_sequence > 0 ? 'update' : 'invitation');
    }

    /**
     * Genera/ajusta las filas de recordatorio (desfase × destinatario × canal). Las ya
     * enviadas para la misma hora no se tocan (idempotencia); al reprogramar se rearman;
     * las que sobran o ya no caben quedan «skipped».
     */
    public function syncReminders(Activity $activity): void
    {
        $pendingStates = ['pending', 'failed', 'sending'];
        if (! $activity->isScheduled()) {
            ActivityReminder::query()->where('activity_id', $activity->id)->whereIn('status', $pendingStates)->update(['status' => 'skipped']);

            return;
        }

        $targets = [['advisor', $activity->assigned_to_user_id, 'mail'], ['advisor', $activity->assigned_to_user_id, 'internal']];
        foreach ($activity->activeGuests()->get() as $g) {
            $targets[] = ['guest', $g->id, 'mail'];
        }

        $keep = [];
        $now = now();
        foreach ($this->reminderOffsets($activity->reminders ?? []) as $offset) {
            $due = $activity->starts_at->copy()->subMinutes($offset);
            foreach ($targets as [$type, $id, $channel]) {
                $row = ActivityReminder::query()->firstOrNew([
                    'activity_id' => $activity->id,
                    'offset_minutes' => $offset,
                    'recipient_type' => $type,
                    'recipient_id' => $id,
                    'channel' => $channel,
                ]);
                if (! $row->exists && $due->lte($now)) {
                    continue; // ya no hay tiempo: no se crea
                }
                $keep[] = $row->exists ? $row->id : null;
                if ($row->exists && $row->due_at->eq($due) && $row->status !== 'skipped') {
                    continue; // misma hora: se conserva tal cual (enviado o pendiente)
                }
                $row->forceFill([
                    'due_at' => $due,
                    'status' => $due->gt($now) ? 'pending' : 'skipped',
                    'attempts' => 0,
                    'last_error' => null,
                    'sent_at' => null,
                ])->save();
                $keep[] = $row->id;
            }
        }

        ActivityReminder::query()
            ->where('activity_id', $activity->id)
            ->whereIn('status', $pendingStates)
            ->whereNotIn('id', array_values(array_filter($keep)))
            ->update(['status' => 'skipped']);
    }

    /** Envía un correo de agenda a todos los invitados activos. */
    public function notifyGuests(Activity $activity, string $kind): void
    {
        foreach ($activity->activeGuests()->get() as $guest) {
            $this->mailGuest($activity, $guest, $kind);
        }
    }

    private function mailGuest(Activity $activity, ActivityGuest $guest, string $kind, bool $keepStatus = false): bool
    {
        $ok = $this->mailer->toGuest($activity, $guest, $kind);
        $changes = ['last_email_kind' => $kind];
        if ($ok) {
            $changes['last_emailed_at'] = now();
        }
        if (! $keepStatus) {
            $changes['status'] = $ok ? 'sent' : 'failed';
        }
        $guest->forceFill($changes)->save();

        return $ok;
    }

    private function close(Activity $activity, ActivityStatus $status, ?string $text): Activity
    {
        $changed = DB::transaction(function () use ($activity, $status, $text): bool {
            $fresh = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->isScheduled()) {
                return false; // ya cerrada (doble clic / reintento): no repite efectos
            }
            $fresh->status = $status;
            if ($status === ActivityStatus::Cancelled) {
                $fresh->cancelled_at = now();
                $fresh->ics_sequence++;
                if ($text !== null && trim($text) !== '') {
                    $fresh->outcome = trim($text);
                }
            } else {
                $fresh->completed_at = now();
                if ($text !== null && trim($text) !== '') {
                    $fresh->outcome = trim($text);
                }
            }
            $fresh->save();
            $this->syncReminders($fresh);
            $activity->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if ($changed) {
            match ($status) {
                ActivityStatus::Cancelled => $this->recordEvent(EventType::ActivityCancelled, $activity),
                ActivityStatus::Completed => $this->recordEvent(EventType::ActivityCompleted, $activity),
                default => $this->recordEvent(EventType::ActivityCompleted, $activity, ['result' => $status->value]),
            };
            if ($status === ActivityStatus::Cancelled) {
                $this->notifyGuests($activity, 'cancelled');
            }
        }

        return $activity;
    }

    private function applyException(Activity $activity, AvailabilityCheck $check, User $actor, bool $confirm, ?string $reason): void
    {
        if ($check->ok()) {
            return;
        }
        if (! $confirm) {
            throw new ScheduleConflictException($check);
        }
        $activity->exception_by_user_id = $actor->id;
        $activity->exception_at = now();
        $activity->exception_reason = mb_substr($this->nullable($reason) ?? implode(' ', $check->messages()), 0, 255);
    }

    private function guardAssignee(User $actor, int $assigneeId): User
    {
        if ($assigneeId !== $actor->id && ! $actor->canManageUsers()) {
            throw new AuthorizationException(__('Solo un administrador puede asignar actividades a otra persona.'));
        }
        if ($assigneeId === $actor->id) {
            return $actor;
        }
        $assignee = User::query()->whereKey($assigneeId)->where('institution_id', $this->context->idOrFail('agenda'))->first();
        if ($assignee === null || ! $assignee->isActive() || ! $assignee->canWorkCrm() || $assignee->crmReadOnly()) {
            throw ValidationException::withMessages(['assigned_to_user_id' => __('Asesor no válido.')]);
        }

        return $assignee;
    }

    private function guardScheduled(Activity $activity): void
    {
        if (! $activity->isScheduled()) {
            throw ValidationException::withMessages(['activity' => __('Solo se pueden modificar actividades programadas.')]);
        }
    }

    /** Serializa las escrituras de horario de un mismo asesor. */
    private function lockAdvisor(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first();
    }

    /** @return array{0:int|null,1:int|null} */
    private function resolveLinks(mixed $leadId, mixed $contactId): array
    {
        $lead = null;
        if ($leadId !== null && $leadId !== '') {
            $lead = Lead::query()->find((int) $leadId);
            if ($lead === null) {
                throw ValidationException::withMessages(['lead_id' => __('Lead no encontrado.')]);
            }
        }
        $contact = null;
        if ($contactId !== null && $contactId !== '') {
            $contact = Contact::query()->find((int) $contactId);
            if ($contact === null) {
                throw ValidationException::withMessages(['contact_id' => __('Contacto no encontrado.')]);
            }
        }

        return [$lead?->id, $contact->id ?? $lead?->contact_id];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function times(mixed $start, mixed $end): array
    {
        $s = Carbon::parse($start)->utc()->startOfMinute();
        $e = Carbon::parse($end)->utc()->startOfMinute();
        if (! $e->gt($s)) {
            throw ValidationException::withMessages(['ends_at' => __('La hora de fin debe ser posterior a la de inicio.')]);
        }
        if ($s->diffInHours($e) > 24) {
            throw ValidationException::withMessages(['ends_at' => __('Una actividad no puede durar más de 24 horas.')]);
        }

        return [$s, $e];
    }

    /** Foto del enlace/número del perfil del asesor según el medio. */
    private function snapshotLink(ActivityMedium $medium, MeetingProfile $profile): ?string
    {
        if (! $profile->exists || ! $profile->is_active) {
            return null;
        }

        return match ($medium) {
            ActivityMedium::GoogleMeet, ActivityMedium::Zoom => $profile->linkFor($medium),
            ActivityMedium::WhatsApp => PhoneNumber::normalize($profile->whatsapp_number),
            ActivityMedium::Phone => $profile->phone ? (PhoneNumber::normalize($profile->phone) ?? $profile->phone) : null,
            default => null,
        };
    }

    private function roomHashFor(ActivityMedium $medium, ?string $link): ?string
    {
        return in_array($medium, [ActivityMedium::GoogleMeet, ActivityMedium::Zoom], true) ? Activity::roomHash($link) : null;
    }

    /** @return array<int,int> */
    private function reminderOffsets(mixed $value): array
    {
        $values = array_map('intval', (array) $value);

        return array_values(array_intersect(Activity::REMINDER_OPTIONS, $values));
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @param  array<string,mixed>  $extra */
    private function recordEvent(EventType $type, Activity $activity, array $extra = []): void
    {
        $contactId = $activity->contact_id ?? $activity->lead?->contact_id;
        if ($contactId === null) {
            return;
        }
        $this->events->record($type, [
            'contact_id' => $contactId,
            'data' => array_merge(['activity_id' => $activity->id, 'lead_id' => $activity->lead_id, 'type' => $activity->type->value], $extra),
        ]);
    }
}
