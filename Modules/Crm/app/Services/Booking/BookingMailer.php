<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use Illuminate\Support\Carbon;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Booking;
use Modules\Crm\Services\Agenda\AgendaMailer;
use Modules\Institutions\Models\Institution;

/**
 * Correos de las reservas públicas a partir de las plantillas del Calendario de la institución:
 * confirmación, reprogramación y cancelación al visitante (con .ics y «Agregar al calendario»),
 * y aviso de nueva reserva al asesor. Salen por AgendaMailer (remitente de la institución).
 */
class BookingMailer
{
    private ?string $lastError = null;

    public function __construct(
        private readonly AgendaMailer $mailer,
        private readonly CalendarTemplates $templates,
        private readonly CalendarLinks $links,
    ) {}

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @param  string  $key  booking_confirmation · booking_rescheduled · booking_cancelled */
    public function toVisitor(Booking $booking, string $key): bool
    {
        $activity = $booking->activity;
        $locale = $booking->visitor_language === 'en' ? 'en' : 'es';
        $vars = $this->vars($booking, $locale, $booking->visitor_timezone);
        $cancel = $key === 'booking_cancelled';
        if ($cancel) {
            $vars['enlace'] = '';
        }
        $rendered = $this->templates->render($key, $locale, $vars);
        $html = $rendered['html'].($cancel ? '' : $this->calendarButtons($booking, $vars, $locale));

        $ok = $this->mailer->deliver($activity, $booking->visitor_email, $booking->visitor_name, $rendered['subject'], $html,
            $cancel ? 'cancel' : 'request', $this->icsDescription($vars, $locale), $key);
        $this->lastError = $this->mailer->lastError();

        return $ok;
    }

    public function toAdvisor(Booking $booking): bool
    {
        $activity = $booking->activity;
        $advisor = $activity->assignee;
        if ($advisor === null || ! $advisor->email) {
            $this->lastError = 'El asesor no tiene correo.';

            return false;
        }
        $locale = ($advisor->preferred_language ?? 'es') === 'en' ? 'en' : 'es';
        $rendered = $this->templates->render('advisor_new_booking', $locale, $this->vars($booking, $locale, $activity->timezone));
        $ok = $this->mailer->deliver($activity, $advisor->email, $advisor->name, $rendered['subject'], $rendered['html'], null, '', 'advisor_new_booking');
        $this->lastError = $this->mailer->lastError();

        return $ok;
    }

    /** @return array<string,string> */
    public function vars(Booking $booking, string $locale, string $tz): array
    {
        $activity = $booking->activity;
        $start = $activity->starts_at->copy()->setTimezone($tz)->locale($locale);
        $access = $this->mailer->accessLink($activity, $locale);
        $institution = Institution::query()->find($activity->institution_id);
        $program = $booking->meetingType->program ?? $activity->lead?->program;
        $token = $booking->token;

        return [
            'nombre' => $booking->visitor_name,
            'institucion' => (string) ($institution->name ?? config('app.name')),
            'asesor' => (string) ($activity->assignee->name ?? ''),
            'tipo_reunion' => (string) ($booking->meetingType->name ?? $activity->title),
            'fecha' => $start->translatedFormat($locale === 'en' ? 'l, F j, Y' : 'l j \d\e F \d\e Y'),
            'hora' => $start->format('H:i'),
            'zona_horaria' => $tz,
            'duracion' => $activity->durationMinutes().' min',
            'medio' => $activity->medium->labelIn($locale),
            'enlace' => (string) ($activity->medium === ActivityMedium::Phone ? ($activity->meeting_link ?? '') : ($access['link'] ?? '')),
            'ubicacion' => $activity->medium === ActivityMedium::InPerson && $activity->location ? (($locale === 'en' ? 'Location: ' : 'Ubicación: ').$activity->location) : '',
            'programa' => $program !== null ? (string) $program->translate('name', $locale) : '',
            'enlace_gestion' => $token ? route('booking.manage', ['token' => $token]) : '',
            'correo_visitante' => $booking->visitor_email,
            'telefono_visitante' => (string) ($booking->visitor_phone ?? ''),
            'notas' => (string) ($booking->notes ?? ''),
            'enlace_crm' => route('crm.agenda.show', $activity),
        ];
    }

    /** @param  array<string,string>  $vars */
    private function calendarButtons(Booking $booking, array $vars, string $locale): string
    {
        $links = $this->links->for($booking->activity, $vars['tipo_reunion'].' — '.$vars['asesor'], $this->icsDescription($vars, $locale), $booking->activity->location);
        $items = [
            ['Google Calendar', $links['google']],
            ['Outlook', $links['outlook']],
            ['Apple Calendar', $booking->token ? route('booking.ics', ['token' => $booking->token]) : null],
            [$locale === 'en' ? 'Download .ics' : 'Descargar .ics', $booking->token ? route('booking.ics', ['token' => $booking->token]) : null],
        ];
        $html = '';
        foreach ($items as [$label, $url]) {
            if ($url) {
                $html .= '<a href="'.e($url).'" style="display:inline-block;margin:0 6px 6px 0;padding:7px 12px;border:1px solid #CFE0F5;border-radius:8px;color:#1E5AA8;text-decoration:none;font-size:13px">'.e($label).'</a>';
            }
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;margin-top:18px"><p style="font-size:13px;color:#61748F;margin:0 0 8px">'
            .e($locale === 'en' ? 'Add to your calendar (a copy of the event; it does not sync automatically):' : 'Agregar a tu calendario (copia del evento; no se sincroniza automáticamente):')
            .'</p>'.$html.'</div>';
    }

    /** @param  array<string,string>  $vars */
    private function icsDescription(array $vars, string $locale): string
    {
        $lines = array_filter([
            $vars['tipo_reunion'].' — '.$vars['asesor'].' ('.$vars['institucion'].')',
            $vars['enlace'] !== '' ? ($locale === 'en' ? 'Access: ' : 'Acceso: ').$vars['enlace'] : null,
            $vars['ubicacion'] !== '' ? $vars['ubicacion'] : null,
            $vars['enlace_gestion'] !== '' ? ($locale === 'en' ? 'Manage your booking: ' : 'Gestionar la reserva: ').$vars['enlace_gestion'] : null,
        ]);

        return implode("\n", $lines);
    }

    /** Fecha legible para pantallas públicas. */
    public static function readableDate(Carbon $utc, string $tz, string $locale): string
    {
        return \Illuminate\Support\Str::ucfirst($utc->copy()->setTimezone($tz)->locale($locale)->translatedFormat($locale === 'en' ? 'l, F j, Y' : 'l j \d\e F \d\e Y'));
    }
}
