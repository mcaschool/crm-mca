<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Enums\EventType;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Crm\Models\Booking;
use Modules\Crm\Models\BookingLink;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;
use Modules\Crm\Notifications\AgendaNotification;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\ScheduleConflictException;
use Modules\Crm\Services\ContactService;
use Modules\Crm\Services\EventService;
use Modules\Institutions\Models\Bot;

/**
 * Reservas públicas sobre el Calendario de la Fase 1. La actividad se crea con ActivityService
 * (bloqueo del asesor, re-comprobación de disponibilidad SIN excepción manual) dentro de un bloqueo
 * por institución y una transacción: dos visitantes no pueden quedarse el mismo hueco ni la misma
 * sala. Una clave de idempotencia evita duplicados por doble clic, recarga o reintento. Los correos
 * salen DESPUÉS de confirmar la transacción, se reclaman de forma atómica y se pueden reintentar.
 */
class BookingService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly BookingSlots $slots,
        private readonly BookingPublisher $publisher,
        private readonly BookingMailer $mailer,
        private readonly ContactService $contacts,
        private readonly EventService $events,
        private readonly CurrentInstitution $context,
    ) {}

    /**
     * @param  array{start:string, timezone:string, first_name:string, last_name:string, email:string, phone?:?string, notes?:?string, language:string, idempotency_key:string}  $input
     * @return array{booking:Booking, token:string, created:bool}
     */
    public function book(MeetingProfile $profile, MeetingType $type, array $input, ?BookingLink $link = null): array
    {
        $advisor = User::query()->find($profile->user_id);
        if (! $this->publisher->advisorCanReceive($advisor, $profile) || ! $this->publisher->isBookable($type, $profile)) {
            throw new SlotUnavailableException(__('Reservas no disponibles.'));
        }
        $start = Carbon::parse($input['start'])->utc()->startOfMinute();
        $end = $start->copy()->addMinutes($type->duration_minutes);
        $key = mb_substr((string) $input['idempotency_key'], 0, 64);
        $institutionId = $this->context->idOrFail('reserva');

        if (($existing = $this->bookingByKey($key)) !== null) {
            return ['booking' => $existing, 'token' => (string) $existing->token, 'created' => false];
        }

        $result = Cache::lock('crm-booking:'.$institutionId, 20)->block(10, function () use ($profile, $type, $input, $link, $advisor, $start, $end, $key): array {
            if (($existing = $this->bookingByKey($key)) !== null) {
                return ['booking' => $existing, 'token' => (string) $existing->token, 'created' => false];
            }
            $roomHash = $this->roomHashFor($type, $profile);
            if (! $this->slots->offers($profile->user_id, $start, $type->duration_minutes, $roomHash)) {
                throw new SlotUnavailableException(__('Horario no disponible.'));
            }

            try {
                return DB::transaction(function () use ($profile, $type, $input, $link, $advisor, $start, $end, $key): array {
                    [$contactId, $leadId] = $this->resolveParty($input, $profile->user_id, $type, $link);
                    $email = Str::lower(trim($input['email']));
                    $name = trim($input['first_name'].' '.$input['last_name']);

                    $activity = $this->activities->create([
                        'title' => mb_substr($type->name.' — '.$name, 0, 200),
                        'type' => $this->activityType($type->medium)->value,
                        'medium' => $type->medium->value,
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'timezone' => $profile->timezone,
                        'assigned_to_user_id' => $profile->user_id,
                        'lead_id' => $leadId,
                        'contact_id' => $contactId,
                        'description' => trim(__('Reserva pública').': '.$type->name."\n".($input['notes'] ?? '')),
                        'reminders' => config('crm.agenda.default_reminders', [60]),
                        'idempotency_key' => 'booking:'.$key,
                    ], $advisor, false);

                    ActivityGuest::query()->create([
                        'activity_id' => $activity->id,
                        'contact_id' => $contactId,
                        'name' => mb_substr($name, 0, 191),
                        'email' => $email,
                        'language' => $input['language'] === 'en' ? 'en' : 'es',
                        'status' => 'pending',
                    ]);
                    $this->activities->syncReminders($activity);

                    $token = Str::random(48);
                    $booking = Booking::query()->create([
                        'activity_id' => $activity->id,
                        'meeting_type_id' => $type->id,
                        'token_hash' => Booking::hashToken($token),
                        'token' => $token,
                        'idempotency_key' => $key,
                        'visitor_name' => mb_substr($name, 0, 191),
                        'visitor_email' => $email,
                        'visitor_phone' => ($input['phone'] ?? null) ?: null,
                        'visitor_timezone' => $input['timezone'],
                        'visitor_language' => $input['language'] === 'en' ? 'en' : 'es',
                        'notes' => ($input['notes'] ?? null) ?: null,
                        'booking_link_id' => $link?->id,
                    ]);

                    return ['booking' => $booking, 'token' => $token, 'created' => true];
                });
            } catch (ScheduleConflictException) {
                throw new SlotUnavailableException(__('Horario no disponible.'));
            } catch (UniqueConstraintViolationException $e) {
                $existing = $this->bookingByKey($key);
                if ($existing === null) {
                    throw $e;
                }

                return ['booking' => $existing, 'token' => (string) $existing->token, 'created' => false];
            }
        });

        if ($result['created']) {
            $this->events->record('booking_created', [
                'contact_id' => $result['booking']->activity->contact_id,
                'data' => ['activity_id' => $result['booking']->activity_id, 'meeting_type_id' => $type->id],
            ]);
        }
        $this->sendPostBookingMails($result['booking']);

        return $result;
    }

    /** Cancelación por el visitante (con su token). Devuelve false si ya no era gestionable. */
    public function cancelByVisitor(Booking $booking): bool
    {
        if (! $booking->isManageable()) {
            return false;
        }
        $activity = $booking->activity;
        $advisor = $activity->assignee;
        $this->activities->cancel($activity, $advisor ?? new User, __('Cancelada por el visitante desde su enlace de reserva.'));
        $advisor?->notify(AgendaNotification::for('booking_cancelled', $activity->fresh() ?? $activity));

        return true;
    }

    /** Reprogramación por el visitante: mismo asesor, tipo y actividad; nuevo hueco validado. */
    public function rescheduleByVisitor(Booking $booking, string $startIso): void
    {
        if (! $booking->isManageable() || $booking->meetingType === null) {
            throw new SlotUnavailableException(__('Reserva no gestionable.'));
        }
        $activity = $booking->activity;
        $type = $booking->meetingType;
        $profile = MeetingProfile::forUser($activity->assigned_to_user_id);
        $advisor = $activity->assignee;
        $start = Carbon::parse($startIso)->utc()->startOfMinute();
        $end = $start->copy()->addMinutes($activity->durationMinutes());

        Cache::lock('crm-booking:'.$activity->institution_id, 20)->block(10, function () use ($activity, $type, $profile, $advisor, $start, $end): void {
            if (! $this->slots->offers($activity->assigned_to_user_id, $start, $activity->durationMinutes(), $activity->meeting_room_hash ?? $this->roomHashFor($type, $profile), $activity->id)) {
                throw new SlotUnavailableException(__('Horario no disponible.'));
            }
            try {
                $this->activities->reschedule($activity, $start, $end, $advisor ?? new User, false, null, __('Reprogramada por el visitante desde su enlace de reserva.'));
            } catch (ScheduleConflictException) {
                throw new SlotUnavailableException(__('Horario no disponible.'));
            }
        });
        $advisor?->notify(AgendaNotification::for('booking_rescheduled', $activity->fresh() ?? $activity));
    }

    /**
     * Correos posteriores a la reserva, idempotentes: cada envío se reclama (null → ahora) antes de
     * salir y, si falla, se libera para reintentarlo (máximo MAX_MAIL_ATTEMPTS). Nunca crea otra reserva.
     */
    public function sendPostBookingMails(Booking $booking): void
    {
        $errors = [];
        if (Booking::query()->whereKey($booking->id)->whereNull('confirmation_sent_at')->update(['confirmation_sent_at' => now()]) === 1) {
            $ok = $this->mailer->toVisitor($booking, 'booking_confirmation');
            $guest = ActivityGuest::query()->where('activity_id', $booking->activity_id)->where('email', $booking->visitor_email)->first();
            $guest?->forceFill($ok
                ? ['status' => 'sent', 'last_email_kind' => 'invitation', 'last_emailed_at' => now()]
                : ['status' => 'failed', 'last_email_kind' => 'invitation'])->save();
            if (! $ok) {
                Booking::query()->whereKey($booking->id)->update(['confirmation_sent_at' => null]);
                $errors[] = $this->mailer->lastError();
            }
        }

        $activity = $booking->activity;
        if (Booking::query()->whereKey($booking->id)->whereNull('advisor_notified_at')->update(['advisor_notified_at' => now()]) === 1) {
            $activity->assignee?->notify(AgendaNotification::for('booking', $activity));
        }
        if (Booking::query()->whereKey($booking->id)->whereNull('advisor_mailed_at')->update(['advisor_mailed_at' => now()]) === 1) {
            if (! $this->mailer->toAdvisor($booking)) {
                Booking::query()->whereKey($booking->id)->update(['advisor_mailed_at' => null]);
                $errors[] = $this->mailer->lastError();
            }
        }

        if ($errors !== []) {
            Booking::query()->whereKey($booking->id)->update([
                'mail_attempts' => DB::raw('mail_attempts + 1'),
                'mail_error' => Str::limit(implode(' | ', array_filter($errors)), 250),
            ]);
            Log::warning('booking.mail_failed', ['booking_id' => $booking->id]);
        }
        $booking->refresh();
    }

    /**
     * Contacto y lead de la reserva, solo dentro de la institución activa. Nunca modifica datos de
     * un contacto existente ni la etapa de sus leads; solo crea lo que falta (fuente booking_link).
     *
     * @param  array<string,mixed>  $input
     * @return array{0:int,1:int|null}
     */
    private function resolveParty(array $input, int $advisorId, MeetingType $type, ?BookingLink $link): array
    {
        if ($link !== null) {
            $lead = Lead::query()->find($link->lead_id);
            if ($lead !== null) {
                return [(int) $lead->contact_id, (int) $lead->id];
            }
        }

        $email = Str::lower(trim((string) $input['email']));
        $contact = Contact::query()->where('email', $email)->first();
        if ($contact === null) {
            $contact = $this->contacts->createOrUpdate([
                'email' => $email,
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'phone' => ($input['phone'] ?? null) ?: null,
                'preferred_language' => $input['language'] === 'en' ? 'en' : 'es',
            ]);
        }

        $active = Lead::query()->where('contact_id', $contact->id)
            ->whereNotIn('status', [LeadStatus::Enrolled->value, LeadStatus::Discarded->value])
            ->get(['id', 'assigned_to_user_id']);
        if ($active->count() === 1) {
            return [(int) $contact->id, (int) $active->first()->id];
        }
        if ($active->count() > 1) {
            $mine = $active->where('assigned_to_user_id', $advisorId);

            return [(int) $contact->id, $mine->count() === 1 ? (int) $mine->first()->id : null]; // ambiguo: solo contacto
        }

        $botId = Bot::query()->where('status', 'active')->orderBy('id')->value('id');
        if ($botId === null) {
            return [(int) $contact->id, null];
        }
        $lead = Lead::query()->create([
            'contact_id' => $contact->id,
            'bot_id' => (int) $botId,
            'program_id' => $type->program_id,
            'source' => 'booking_link',
            'status' => LeadStatus::New->value,
            'assigned_to_user_id' => $advisorId,
        ]);
        $this->events->record(EventType::LeadCaptured, ['contact_id' => $contact->id, 'data' => ['lead_id' => $lead->id, 'source' => 'booking_link']]);

        return [(int) $contact->id, (int) $lead->id];
    }

    /**
     * Reserva ya creada con esta clave de idempotencia (puede aparecer entre dos lecturas por
     * otra petición concurrente: por eso no se puede dar por fijo su resultado).
     *
     * @phpstan-impure
     */
    private function bookingByKey(string $key): ?Booking
    {
        return Booking::query()->where('idempotency_key', $key)->first();
    }

    private function roomHashFor(MeetingType $type, MeetingProfile $profile): ?string
    {
        return match ($type->medium) {
            ActivityMedium::GoogleMeet => Activity::roomHash($profile->meet_url),
            ActivityMedium::Zoom => Activity::roomHash($profile->zoom_url),
            default => null,
        };
    }

    private function activityType(ActivityMedium $medium): ActivityType
    {
        return match ($medium) {
            ActivityMedium::GoogleMeet, ActivityMedium::Zoom => ActivityType::VideoCall,
            ActivityMedium::WhatsApp, ActivityMedium::Phone => ActivityType::Call,
            ActivityMedium::InPerson => ActivityType::InPersonMeeting,
            default => ActivityType::Other,
        };
    }
}
