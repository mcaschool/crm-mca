<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use Illuminate\Support\Carbon;
use Modules\Crm\Models\Activity;

/**
 * Genera el .ics (RFC 5545) de una actividad para un invitado. Compatible con Google,
 * Outlook y Apple: UID estable, DTSTART/DTEND en UTC («Z», instante absoluto: cada
 * calendario lo muestra en la zona del lector), SEQUENCE que sube al reprogramar,
 * METHOD REQUEST/CANCEL con STATUS CANCELLED, ORGANIZER, ATTENDEE (sin RSVP) y una alarma
 * de 15 minutos. Líneas CRLF, plegadas a 75 octetos y texto escapado.
 */
final class IcsBuilder
{
    /**
     * @param  array{organizer_name:string, organizer_email:string, attendee_name:string, attendee_email:string, description?:string, location?:string|null, url?:string|null}  $p
     */
    public function build(Activity $activity, array $p, bool $cancel = false): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MCA CRM//Agenda//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:'.($cancel ? 'CANCEL' : 'REQUEST'),
            'BEGIN:VEVENT',
            'UID:'.$activity->uid,
            'DTSTAMP:'.$this->utc(now()),
            'SEQUENCE:'.$activity->ics_sequence,
            'DTSTART:'.$this->utc($activity->starts_at),
            'DTEND:'.$this->utc($activity->ends_at),
            'SUMMARY:'.$this->text($activity->title),
        ];
        if (($p['description'] ?? '') !== '') {
            $lines[] = 'DESCRIPTION:'.$this->text((string) $p['description']);
        }
        $location = $p['location'] ?? null;
        if (($location === null || $location === '') && ($p['url'] ?? null)) {
            $location = (string) $p['url'];
        }
        if ($location !== null && $location !== '') {
            $lines[] = 'LOCATION:'.$this->text($location);
        }
        if (($p['url'] ?? null) !== null && $p['url'] !== '') {
            $lines[] = 'URL:'.$this->uri((string) $p['url']);
        }
        $lines[] = 'ORGANIZER;CN='.$this->param($p['organizer_name']).':mailto:'.$this->uri($p['organizer_email']);
        $lines[] = 'ATTENDEE;CN='.$this->param($p['attendee_name']).';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:'.$this->uri($p['attendee_email']);
        $lines[] = 'STATUS:'.($cancel ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:OPAQUE';
        if (! $cancel) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'TRIGGER:-PT15M';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'DESCRIPTION:'.$this->text($activity->title);
            $lines[] = 'END:VALARM';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn (string $l) => $this->fold($l), $lines))."\r\n";
    }

    private function utc(Carbon $date): string
    {
        return $date->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Escapado de TEXT (RFC 5545 §3.3.11). */
    private function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /** Valor de parámetro (CN): entre comillas, sin comillas ni saltos internos. */
    private function param(string $value): string
    {
        return '"'.str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value).'"';
    }

    private function uri(string $value): string
    {
        return str_replace(["\r", "\n", ' '], '', $value);
    }

    /** Plegado a 75 octetos sin partir caracteres multibyte. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // la continuación empieza con un espacio
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
