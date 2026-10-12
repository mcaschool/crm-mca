@extends('crm::public.booking.layout', ['pageTitle' => __('Tu reserva')])

@push('head')
<style>
    .bk-conf{max-width:680px;margin:0 auto;padding:26px}
    .bk-conf .row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:6px 14px;font-size:15px;margin:18px 0}
    .bk-conf .row .k{color:var(--muted)}
    .bk-conf .row .v{font-weight:600;overflow-wrap:anywhere}
    .bk-status{display:inline-block;font-size:12.5px;font-weight:700;border-radius:999px;padding:3px 10px;background:var(--soft);color:var(--blue)}
    .bk-status.cancelled{background:#F1F3F6;color:#6B7C93}
    .bk-status.completed{background:#E8F6EE;color:var(--ok)}
    .bk-add{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
    .bk-add a{font-size:13.5px;font-weight:600;text-decoration:none;border:1px solid #CFE0F5;border-radius:9px;padding:8px 12px}
    .bk-sec{border-top:1px solid var(--line);margin-top:20px;padding-top:18px}
    .bk-sec h2{font-size:15px;margin:0 0 6px}
    .bk-sec p{color:var(--muted);font-size:13.5px;margin:0 0 10px}
    @media(max-width:560px){.bk-conf{padding:18px}.bk-conf .row{grid-template-columns:1fr;gap:2px}.bk-conf .row .v{margin-bottom:8px}}
</style>
@endpush

@section('content')
    <div class="bk-card bk-conf">
        @if ($justBooked && $activity->isScheduled())
            <div class="bk-ok" role="status">{{ __('¡Listo! Tu reunión está confirmada. Recibirás los detalles por correo electrónico.') }}</div>
        @endif
        @if ($errors->any())
            <div class="bk-err" role="alert">{{ $errors->first() }}</div>
        @endif

        <h1 class="bk-h1">{{ $booking->meetingType->name ?? $activity->title }}</h1>
        @php $st = $activity->status->value; @endphp
        <span class="bk-status {{ $st }}">{{ ['scheduled' => __('Confirmada'), 'cancelled' => __('Cancelada'), 'completed' => __('Realizada'), 'no_show' => __('Finalizada')][$st] ?? $st }}</span>

        <div class="row">
            <div class="k">{{ __('Con') }}</div><div class="v">{{ $advisorName }} · {{ $institution->name ?? '' }}</div>
            <div class="k">{{ __('Fecha') }}</div><div class="v">{{ $date }}</div>
            <div class="k">{{ __('Hora') }}</div><div class="v">{{ $time }} ({{ str_replace('_', ' ', $tz) }})</div>
            <div class="k">{{ __('Duración') }}</div><div class="v">{{ $activity->durationMinutes() }} min</div>
            <div class="k">{{ __('Medio') }}</div><div class="v">{{ $activity->medium->labelIn(app()->getLocale()) }}</div>
            @if ($activity->isScheduled() && $activity->medium === \Modules\Crm\Enums\ActivityMedium::InPerson && $activity->location)
                <div class="k">{{ __('Ubicación') }}</div><div class="v">{{ $activity->location }}</div>
            @endif
            <div class="k">{{ __('A nombre de') }}</div><div class="v">{{ $booking->visitor_name }}</div>
        </div>

        @if ($activity->isScheduled() && ($access['link'] ?? null))
            <p style="margin:0 0 6px">
                <a class="bk-btn" href="{{ $access['link'] }}" target="_blank" rel="noopener noreferrer">
                    {{ $activity->medium === \Modules\Crm\Enums\ActivityMedium::WhatsApp ? __('Abrir WhatsApp') : ($activity->medium === \Modules\Crm\Enums\ActivityMedium::Phone ? __('Llamar') : __('Unirse a la reunión')) }}
                </a>
            </p>
            <p class="bk-note" style="color:var(--muted);font-size:12.5px;margin:0;overflow-wrap:anywhere">{{ $access['link'] }}</p>
        @endif

        @if ($activity->isScheduled())
            <div class="bk-sec">
                <h2>{{ __('Agregar a tu calendario') }}</h2>
                <p>{{ __('Se añade una copia del evento; no se sincroniza automáticamente si la reunión cambia (te avisaremos por correo).') }}</p>
                <div class="bk-add">
                    <a href="{{ $calendarLinks['google'] }}" target="_blank" rel="noopener noreferrer">Google Calendar</a>
                    <a href="{{ $calendarLinks['outlook'] }}" target="_blank" rel="noopener noreferrer">Outlook</a>
                    <a href="{{ route('booking.ics', ['token' => $token]) }}">Apple Calendar</a>
                    <a href="{{ route('booking.ics', ['token' => $token]) }}" download>{{ __('Descargar .ics') }}</a>
                </div>
            </div>
        @endif

        @if ($manageable)
            <div class="bk-sec">
                <h2>{{ __('¿Necesitas cambiarla?') }}</h2>
                @if ($confirmCancel)
                    <p>{{ __('¿Seguro que quieres cancelar esta reunión? El horario quedará libre para otras personas.') }}</p>
                    <form method="POST" action="{{ route('booking.cancel', ['token' => $token]) }}" style="display:flex;gap:10px;flex-wrap:wrap">
                        @csrf
                        <input type="hidden" name="lang" value="{{ app()->getLocale() }}">
                        <button type="submit" class="bk-btn danger">{{ __('Sí, cancelar la reunión') }}</button>
                        <a class="bk-btn ghost" href="{{ route('booking.manage', ['token' => $token, 'lang' => app()->getLocale()]) }}">{{ __('No, mantenerla') }}</a>
                    </form>
                @else
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <a class="bk-btn ghost" href="{{ route('booking.reschedule', ['token' => $token, 'lang' => app()->getLocale()]) }}">{{ __('Reprogramar') }}</a>
                        <a class="bk-btn danger" href="{{ route('booking.manage', ['token' => $token, 'lang' => app()->getLocale(), 'cancelar' => 1]) }}">{{ __('Cancelar reunión') }}</a>
                    </div>
                @endif
            </div>
        @elseif ($activity->status->value === 'cancelled')
            <div class="bk-sec"><p>{{ __('Esta reunión está cancelada. Si lo necesitas, puedes hacer una nueva reserva.') }}</p></div>
        @endif
    </div>
@endsection
