<?php

declare(strict_types=1);

namespace Modules\Crm\Http\Controllers;

use App\Models\User;
use DateTimeZone;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\PhoneNumber;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Booking;
use Modules\Crm\Models\BookingLink;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\MeetingType;
use Modules\Crm\Services\Agenda\AgendaMailer;
use Modules\Crm\Services\Booking\BookingMailer;
use Modules\Crm\Services\Booking\BookingPublisher;
use Modules\Crm\Services\Booking\BookingService;
use Modules\Crm\Services\Booking\BookingSlots;
use Modules\Crm\Services\Booking\CalendarLinks;
use Modules\Crm\Services\Booking\SlotUnavailableException;
use Modules\Institutions\Models\Institution;
use Throwable;

/**
 * Páginas PÚBLICAS de reservas (sin sesión del CRM). Solo muestran datos públicos: institución,
 * asesor, tipo de reunión y huecos libres; nunca actividades, otros visitantes ni datos de leads.
 * El enlace de la reunión solo se revela tras reservar (página de gestión con token).
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly CurrentInstitution $context,
        private readonly BookingPublisher $publisher,
        private readonly BookingSlots $slots,
    ) {}

    public function advisor(string $institution, string $advisor): View
    {
        [$inst, $profile, $user] = $this->resolve($institution, $advisor);
        $types = $this->bookableTypes($profile);

        return view('crm::public.booking.advisor', [
            'institution' => $inst, 'profile' => $profile, 'advisorName' => $user->name, 'types' => $types,
            'ref' => $this->refParam(),
        ]);
    }

    public function type(string $institution, string $advisor, string $type): View
    {
        [$inst, $profile, $user] = $this->resolve($institution, $advisor);
        $meetingType = $this->resolveType($profile, $type);

        return view('crm::public.booking.schedule', [
            'institution' => $inst, 'advisorName' => $user->name, 'type' => $meetingType, 'mode' => 'book',
            'slotsUrl' => route('booking.slots', [$institution, $advisor, $type]),
            'formAction' => route('booking.store', [$institution, $advisor, $type]),
            'backUrl' => count($this->bookableTypes($profile)) > 1 ? route('booking.advisor', [$institution, $advisor]) : null,
            'ref' => $this->refParam(),
            'formToken' => Crypt::encryptString((string) now()->getTimestamp()),
            'idempotencyKey' => (string) Str::uuid(),
            'booking' => null,
        ]);
    }

    public function slotsForType(Request $request, string $institution, string $advisor, string $type): JsonResponse
    {
        [, $profile] = $this->resolve($institution, $advisor);
        $meetingType = $this->resolveType($profile, $type);

        return response()->json($this->monthSlots($request, $profile->user_id, $meetingType->duration_minutes, $this->roomHash($meetingType, $profile)));
    }

    public function store(Request $request, string $institution, string $advisor, string $type, BookingService $bookings): RedirectResponse
    {
        [, $profile] = $this->resolve($institution, $advisor);
        $meetingType = $this->resolveType($profile, $type);
        $this->guardAgainstBots($request);
        $data = $this->validateVisitor($request, $meetingType);

        $emailKey = 'booking-email:'.sha1(Str::lower($data['email']));
        if (RateLimiter::tooManyAttempts($emailKey, 5)) {
            throw ValidationException::withMessages(['email' => __('Demasiadas reservas con este correo. Inténtalo más tarde.')]);
        }

        $link = null;
        if (is_string($data['ref'] ?? null) && $data['ref'] !== '') {
            $link = BookingLink::query()->where('token_hash', Booking::hashToken($data['ref']))
                ->where('user_id', $profile->user_id)->where('expires_at', '>', now())->first();
        }

        try {
            $result = $bookings->book($profile, $meetingType, $data + ['language' => app()->getLocale()], $link);
        } catch (SlotUnavailableException|LockTimeoutException) {
            return back()->withInput()->withErrors(['start' => __('Ese horario acaba de dejar de estar disponible. Elige otro, por favor.')]);
        }
        if ($result['created']) {
            RateLimiter::hit($emailKey, 3600);
        }

        return redirect()->route('booking.manage', ['token' => $result['token'], 'lang' => app()->getLocale(), 'ok' => 1]);
    }

    public function manage(Request $request, string $token): View
    {
        $booking = $this->bookingByToken($token);
        $activity = $booking->activity;
        $tz = $booking->visitor_timezone;
        $locale = app()->getLocale();
        $access = null;
        if ($activity->isScheduled()) {
            $access = app(AgendaMailer::class)->accessLink($activity, $locale);
            if ($activity->medium === ActivityMedium::Phone) {
                $access = ['link' => $activity->meeting_link ? 'tel:'.$activity->meeting_link : null, 'label' => $activity->meeting_link];
            }
        }
        $title = ($booking->meetingType->name ?? $activity->title).' — '.($activity->assignee->name ?? '');
        $links = app(CalendarLinks::class)->for($activity, $title, $access['link'] ?? '', $activity->location);

        return view('crm::public.booking.manage', [
            'booking' => $booking, 'activity' => $activity, 'token' => $token,
            'institution' => Institution::query()->find($booking->institution_id),
            'advisorName' => $activity->assignee->name ?? '',
            'date' => BookingMailer::readableDate($activity->starts_at, $tz, $locale),
            'time' => $activity->starts_at->copy()->setTimezone($tz)->format('H:i').'–'.$activity->ends_at->copy()->setTimezone($tz)->format('H:i'),
            'tz' => $tz, 'access' => $access, 'calendarLinks' => $links,
            'manageable' => $booking->isManageable(),
            'justBooked' => $request->boolean('ok'),
            'confirmCancel' => $request->boolean('cancelar'),
        ]);
    }

    public function ics(string $token): Response
    {
        $booking = $this->bookingByToken($token);
        $activity = $booking->activity;
        $content = app(AgendaMailer::class)->icsFor($activity, null, $booking->visitor_name, $booking->visitor_email,
            ($booking->meetingType->name ?? $activity->title), ! $activity->isScheduled());

        return response($content, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reunion.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function cancel(string $token, BookingService $bookings): RedirectResponse
    {
        $booking = $this->bookingByToken($token);
        $bookings->cancelByVisitor($booking);

        return redirect()->route('booking.manage', ['token' => $token, 'lang' => app()->getLocale()]);
    }

    public function rescheduleForm(string $token): View
    {
        $booking = $this->bookingByToken($token);
        abort_unless($booking->isManageable() && $booking->meetingType !== null, 410);

        return view('crm::public.booking.schedule', [
            'institution' => Institution::query()->find($booking->institution_id),
            'advisorName' => $booking->activity->assignee->name ?? '',
            'type' => $booking->meetingType, 'mode' => 'reschedule',
            'slotsUrl' => route('booking.reschedule.slots', ['token' => $token]),
            'formAction' => route('booking.reschedule.store', ['token' => $token]),
            'backUrl' => route('booking.manage', ['token' => $token]),
            'ref' => null, 'formToken' => Crypt::encryptString((string) now()->getTimestamp()),
            'idempotencyKey' => null, 'booking' => $booking,
        ]);
    }

    public function rescheduleSlots(Request $request, string $token): JsonResponse
    {
        $booking = $this->bookingByToken($token);
        abort_unless($booking->isManageable() && $booking->meetingType !== null, 410);
        $activity = $booking->activity;

        return response()->json($this->monthSlots($request, $activity->assigned_to_user_id, $activity->durationMinutes(), $activity->meeting_room_hash, $activity->id));
    }

    public function reschedule(Request $request, string $token, BookingService $bookings): RedirectResponse
    {
        $booking = $this->bookingByToken($token);
        abort_unless($booking->isManageable(), 410);
        $this->guardAgainstBots($request);
        $data = $request->validate(['start' => ['required', 'date_format:Y-m-d\TH:i:s\Z']]);

        try {
            $bookings->rescheduleByVisitor($booking, $data['start']);
        } catch (SlotUnavailableException|LockTimeoutException) {
            return back()->withErrors(['start' => __('Ese horario acaba de dejar de estar disponible. Elige otro, por favor.')]);
        }

        return redirect()->route('booking.manage', ['token' => $token, 'lang' => app()->getLocale(), 'ok' => 1]);
    }

    /** @return array{0:Institution,1:MeetingProfile,2:User} */
    private function resolve(string $institutionSlug, string $advisorSlug): array
    {
        $institution = $this->context->runGlobally(fn () => Institution::query()->where('slug', $institutionSlug)->first());
        abort_if($institution === null || ($institution->status ?? 'active') !== 'active', 404);
        $this->context->set((int) $institution->id);

        $profile = MeetingProfile::query()->where('booking_slug', $advisorSlug)->first();
        abort_if($profile === null, 404);
        $user = User::query()->whereKey($profile->user_id)->where('institution_id', $institution->id)->first();
        abort_unless($this->publisher->advisorCanReceive($user, $profile), 404);

        return [$institution, $profile, $user];
    }

    private function resolveType(MeetingProfile $profile, string $slug): MeetingType
    {
        $type = MeetingType::query()->where('user_id', $profile->user_id)->where('slug', $slug)->first();
        abort_unless($type !== null && $this->publisher->isBookable($type, $profile), 404);

        return $type;
    }

    /** @return \Illuminate\Support\Collection<int,MeetingType> */
    private function bookableTypes(MeetingProfile $profile): \Illuminate\Support\Collection
    {
        return MeetingType::query()->where('user_id', $profile->user_id)->where('is_active', true)->where('is_public', true)
            ->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (MeetingType $t) => $this->publisher->isBookable($t, $profile))->values();
    }

    private function bookingByToken(string $token): Booking
    {
        $booking = $this->context->runGlobally(fn () => Booking::query()->where('token_hash', Booking::hashToken($token))->first());
        abort_if($booking === null, 404);
        $this->context->set((int) $booking->institution_id);
        $booking->load(['activity.assignee', 'meetingType']);
        if (! request()->has('lang')) {
            app()->setLocale($booking->visitor_language === 'en' ? 'en' : 'es');
        }

        return $booking;
    }

    /** @return array{timezone:string,month:string,days:array<string,array<int,array{time:string,start:string}>>} */
    private function monthSlots(Request $request, int $userId, int $duration, ?string $roomHash, ?int $ignoreId = null): array
    {
        $tz = (string) $request->query('tz', 'UTC');
        if (! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        $month = (string) $request->query('month', '');
        try {
            $first = preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::createFromFormat('Y-m-d', $month.'-01', $tz) : now($tz);
        } catch (Throwable) {
            $first = now($tz);
        }
        $first = ($first ?: now($tz))->copy()->startOfMonth();
        $from = $first->copy()->utc();
        $to = $first->copy()->addMonth()->utc();

        return [
            'timezone' => $tz,
            'month' => $first->format('Y-m'),
            'days' => $this->slots->groupedForVisitor($userId, $from, $to, $duration, $tz, $roomHash, $ignoreId),
        ];
    }

    private function roomHash(MeetingType $type, MeetingProfile $profile): ?string
    {
        return match ($type->medium) {
            ActivityMedium::GoogleMeet => \Modules\Crm\Models\Activity::roomHash($profile->meet_url),
            ActivityMedium::Zoom => \Modules\Crm\Models\Activity::roomHash($profile->zoom_url),
            default => null,
        };
    }

    /** Campo trampa + tiempo mínimo de rellenado (formulario firmado): defensas ligeras anti-automatismos. */
    private function guardAgainstBots(Request $request): void
    {
        $issued = null;
        try {
            $issued = (int) Crypt::decryptString((string) $request->input('form_token', ''));
        } catch (Throwable) {
            $issued = null;
        }
        if ((string) $request->input('website', '') !== '' || $issued === null || now()->getTimestamp() - $issued < 3) {
            throw ValidationException::withMessages(['start' => __('No se pudo procesar la solicitud. Inténtalo de nuevo.')]);
        }
    }

    /** @return array<string,mixed> */
    private function validateVisitor(Request $request, MeetingType $type): array
    {
        $phoneRequired = $type->medium === ActivityMedium::Phone;
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'timezone' => ['required', 'timezone:all'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{20,64}$/'],
            'ref' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
        ]);
        if (($data['phone'] ?? null) !== null && $data['phone'] !== '' && PhoneNumber::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('Usa el formato internacional con prefijo de país, por ejemplo +34 600 000 000.')]);
        }

        return $data;
    }

    private function refParam(): ?string
    {
        $ref = request()->query('ref');

        return is_string($ref) && preg_match('/^[A-Za-z0-9]{48}$/', $ref) ? $ref : null;
    }
}
