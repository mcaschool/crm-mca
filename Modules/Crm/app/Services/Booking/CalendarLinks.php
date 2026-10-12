<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use Modules\Crm\Models\Activity;

/**
 * Enlaces web «Agregar a Google Calendar / Outlook» con los datos prellenados (sin OAuth ni API).
 * Apple Calendar usa el .ics descargable. No hay sincronización: es una copia del evento.
 */
class CalendarLinks
{
    /** @return array{google:string,outlook:string} */
    public function for(Activity $activity, string $title, string $details, ?string $location): array
    {
        $start = $activity->starts_at->copy()->utc();
        $end = $activity->ends_at->copy()->utc();
        $location = (string) $location;

        $google = 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $start->format('Ymd\THis\Z').'/'.$end->format('Ymd\THis\Z'),
            'details' => $details,
            'location' => $location,
        ], '', '&', PHP_QUERY_RFC3986);

        $outlook = 'https://outlook.office.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'startdt' => $start->format('Y-m-d\TH:i:s\Z'),
            'enddt' => $end->format('Y-m-d\TH:i:s\Z'),
            'body' => $details,
            'location' => $location,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['google' => $google, 'outlook' => $outlook];
    }
}
