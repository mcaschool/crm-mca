<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ActivityGuest;
use Modules\Institutions\Models\Institution;
use Modules\Notifications\Mail\OutboundEmail;
use Modules\Notifications\Models\EmailSender;
use Modules\Notifications\Services\SenderMailer;
use Throwable;

/**
 * Correos de la agenda, en el idioma del destinatario (es/en), sin editor de plantillas:
 * invitación, actualización (reprogramación), cancelación, retirada de invitado,
 * recordatorio y aviso al asesor. Adjunta un .ics válido (REQUEST o CANCEL).
 *
 * Sale por el primer remitente activo de la institución (su SMTP propio) o, si no hay,
 * por el correo por defecto del sistema. Un fallo NUNCA se propaga: devuelve false y el
 * detalle técnico queda solo en lastError()/log interno (jamás llega al invitado).
 */
class AgendaMailer
{
    private ?string $lastError = null;

    public function __construct(
        private readonly SenderMailer $senders,
        private readonly IcsBuilder $ics,
        private readonly WhatsappLink $whatsapp,
    ) {}

    /** @param  string  $kind  invitation · update · cancelled · removed · reminder */
    public function toGuest(Activity $activity, ActivityGuest $guest, string $kind): bool
    {
        $locale = $guest->language === 'en' ? 'en' : 'es';

        return $this->send($activity, $guest->email, $guest->name, $locale, $kind);
    }

    /** @param  string  $kind  assigned · reminder */
    public function toAdvisor(Activity $activity, User $advisor, string $kind): bool
    {
        $locale = ($advisor->preferred_language ?? 'es') === 'en' ? 'en' : 'es';

        return $this->send($activity, $advisor->email, $advisor->name, $locale, $kind);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function send(Activity $activity, string $to, string $toName, string $locale, string $kind): bool
    {
        $this->lastError = null;
        $tmp = null;

        try {
            $sender = EmailSender::query()->where('status', 'active')->orderBy('name')->first();
            $institution = Institution::query()->find($activity->institution_id);
            $institutionName = (string) ($institution->name ?? config('app.name'));
            $fromAddress = $sender !== null ? $sender->from_address : (string) config('mail.from.address');
            $advisorName = (string) ($activity->assignee->name ?? $institutionName);

            $t = $this->strings($locale);
            $data = $this->details($activity, $locale, $toName);
            $when = $data['date'].' '.$data['time'];
            $subject = str_replace([':title', ':when'], [$activity->title, $when], $t['subject_'.$kind]);
            $html = $this->html($t, $kind, $institutionName, $advisorName, $activity, $data);

            $files = [];
            if ($kind !== 'reminder') {
                $cancel = in_array($kind, ['cancelled', 'removed'], true);
                $content = $this->ics->build($activity, [
                    'organizer_name' => $advisorName,
                    'organizer_email' => $fromAddress,
                    'attendee_name' => $toName,
                    'attendee_email' => $to,
                    'description' => $this->icsDescription($activity, $data, $t),
                    'location' => $activity->location,
                    'url' => $data['link'],
                ], $cancel);
                $tmp = tempnam(sys_get_temp_dir(), 'ics');
                if ($tmp === false) {
                    throw new \RuntimeException('No se pudo crear el .ics temporal.');
                }
                file_put_contents($tmp, $content);
                $files[] = [
                    'path' => $tmp,
                    'name' => $cancel ? 'cancelacion.ics' : 'invitacion.ics',
                    'mime' => 'text/calendar; charset=UTF-8; method='.($cancel ? 'CANCEL' : 'REQUEST'),
                ];
            }

            if ($sender !== null) {
                $this->senders->send($sender, $to, $subject, $html, $files);
            } else {
                Mail::to($to)->send(new OutboundEmail($fromAddress, $institutionName, $subject, $html, $files));
            }

            return true;
        } catch (Throwable $e) {
            $this->lastError = Str::limit(class_basename($e).': '.$e->getMessage(), 240);
            Log::warning('agenda.mail_failed', ['activity_id' => $activity->getKey(), 'kind' => $kind, 'error' => $this->lastError]);

            return false;
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return array{date:string,time:string,timezone:string,duration:string,medium:string,link:?string,link_label:?string,related:?string,program:?string}
     */
    private function details(Activity $activity, string $locale, string $recipientName): array
    {
        $start = $activity->localStart();
        $end = $activity->localEnd();
        $link = null;
        $linkLabel = null;
        if ($activity->medium === ActivityMedium::WhatsApp) {
            $link = $this->whatsapp->forActivity($activity, $locale);
            $linkLabel = $locale === 'en' ? 'Open WhatsApp' : 'Abrir WhatsApp';
        } elseif (in_array($activity->medium, [ActivityMedium::GoogleMeet, ActivityMedium::Zoom], true) && $activity->meeting_link) {
            $link = $activity->meeting_link;
            $linkLabel = ($locale === 'en' ? 'Join on ' : 'Unirse en ').$activity->medium->label();
        } elseif ($activity->medium === ActivityMedium::Phone && $activity->meeting_link) {
            $link = 'tel:'.$activity->meeting_link;
            $linkLabel = $activity->meeting_link;
        }

        $program = $activity->lead?->program?->translate('name', $locale);

        return [
            'date' => $start->format('d/m/Y'),
            'time' => $start->format('H:i').'–'.$end->format('H:i'),
            'timezone' => $activity->timezone,
            'duration' => $activity->durationMinutes().' min',
            'medium' => $activity->medium->labelIn($locale),
            'link' => $link,
            'link_label' => $linkLabel,
            'related' => $activity->relatedName(),
            'program' => $program ?: null,
        ];
    }

    /**
     * @param  array<string,string>  $t
     * @param  array<string,mixed>  $d
     */
    private function html(array $t, string $kind, string $institution, string $advisor, Activity $activity, array $d): string
    {
        $rows = [
            [$t['institution'], $institution],
            [$t['activity'], $activity->title],
            [$t['advisor'], $advisor],
            [$t['date'], $d['date']],
            [$t['time'], $d['time']],
            [$t['timezone'], $d['timezone']],
            [$t['duration'], $d['duration']],
            [$t['medium'], $d['medium']],
        ];
        if ($activity->location) {
            $rows[] = [$t['location'], $activity->location];
        }
        if ($d['program']) {
            $rows[] = [$t['program'], $d['program']];
        } elseif ($d['related']) {
            $rows[] = [$t['contact'], $d['related']];
        }

        $table = '';
        foreach ($rows as [$label, $value]) {
            $table .= '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap;vertical-align:top">'.e($label).'</td>'
                .'<td style="padding:4px 0;color:#0f172a">'.e((string) $value).'</td></tr>';
        }

        $link = '';
        if ($d['link'] && ! in_array($kind, ['cancelled', 'removed'], true)) {
            $link = '<p style="margin:16px 0"><a href="'.e($d['link']).'" style="background:#1d4ed8;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">'
                .e((string) $d['link_label']).'</a></p><p style="font-size:12px;color:#64748b;word-break:break-all">'.e($d['link']).'</p>';
        }

        $description = $activity->description
            ? '<p style="margin:12px 0 0;color:#334155"><strong>'.e($t['description']).':</strong><br>'.nl2br(e($activity->description)).'</p>'
            : '';

        $ics = $kind === 'reminder' ? '' : '<p style="font-size:12px;color:#64748b;margin-top:16px">'.e($t['ics_hint']).'</p>';

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;max-width:560px">'
            .'<p style="margin:0 0 12px">'.e($t['intro_'.$kind]).'</p>'
            .'<table style="border-collapse:collapse">'.$table.'</table>'
            .$link.$description.$ics
            .'</div>';
    }

    /**
     * @param  array<string,mixed>  $d
     * @param  array<string,string>  $t
     */
    private function icsDescription(Activity $activity, array $d, array $t): string
    {
        $parts = [];
        if ($d['link']) {
            $parts[] = $d['link_label'].': '.$d['link'];
        }
        if ($activity->description) {
            $parts[] = $activity->description;
        }
        $parts[] = $t['timezone'].': '.$d['timezone'];

        return implode("\n\n", $parts);
    }

    /** @return array<string,string> */
    private function strings(string $locale): array
    {
        if ($locale === 'en') {
            return [
                'subject_invitation' => 'Invitation: :title — :when',
                'subject_update' => 'Updated: :title — :when',
                'subject_cancelled' => 'Cancelled: :title — :when',
                'subject_removed' => 'Cancelled: :title — :when',
                'subject_reminder' => 'Reminder: :title — :when',
                'subject_assigned' => 'New activity assigned: :title — :when',
                'intro_invitation' => 'You have been invited to the following meeting.',
                'intro_update' => 'This meeting has been rescheduled. These are the updated details.',
                'intro_cancelled' => 'This meeting has been cancelled.',
                'intro_removed' => 'You are no longer a guest of this meeting.',
                'intro_reminder' => 'Reminder of your upcoming meeting.',
                'intro_assigned' => 'A new activity has been scheduled for you in the agenda.',
                'institution' => 'Institution', 'activity' => 'Activity', 'advisor' => 'Advisor',
                'date' => 'Date', 'time' => 'Time', 'timezone' => 'Time zone', 'duration' => 'Duration',
                'medium' => 'Medium', 'location' => 'Location', 'description' => 'Description',
                'contact' => 'Contact', 'program' => 'Program',
                'ics_hint' => 'The attached .ics file adds or updates the event in your calendar (Google, Outlook or Apple).',
            ];
        }

        return [
            'subject_invitation' => 'Invitación: :title — :when',
            'subject_update' => 'Actualizada: :title — :when',
            'subject_cancelled' => 'Cancelada: :title — :when',
            'subject_removed' => 'Cancelada: :title — :when',
            'subject_reminder' => 'Recordatorio: :title — :when',
            'subject_assigned' => 'Nueva actividad asignada: :title — :when',
            'intro_invitation' => 'Has sido invitado/a a la siguiente reunión.',
            'intro_update' => 'Esta reunión se ha reprogramado. Estos son los datos actualizados.',
            'intro_cancelled' => 'Esta reunión se ha cancelado.',
            'intro_removed' => 'Ya no figuras como invitado/a de esta reunión.',
            'intro_reminder' => 'Recordatorio de tu próxima reunión.',
            'intro_assigned' => 'Se ha programado una nueva actividad para ti en la agenda.',
            'institution' => 'Institución', 'activity' => 'Actividad', 'advisor' => 'Asesor/a',
            'date' => 'Fecha', 'time' => 'Hora', 'timezone' => 'Zona horaria', 'duration' => 'Duración',
            'medium' => 'Medio', 'location' => 'Ubicación', 'description' => 'Descripción',
            'contact' => 'Contacto', 'program' => 'Programa',
            'ics_hint' => 'El archivo .ics adjunto añade o actualiza el evento en tu calendario (Google, Outlook o Apple).',
        ];
    }
}
