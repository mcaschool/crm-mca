@extends('crm::public.booking.layout', ['pageTitle' => __('Reservar con :name', ['name' => $advisorName])])

@push('head')
<style>
    .bk-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
    .bk-type{display:block;padding:18px;text-decoration:none;color:inherit;transition:border-color .12s,transform .12s}
    .bk-type:hover{border-color:#BBD3F0;transform:translateY(-1px)}
    .bk-type h2{font-size:17px;margin:0 0 6px}
    .bk-type p{margin:0 0 12px;color:var(--muted);font-size:14px}
    .bk-chips{display:flex;gap:8px;flex-wrap:wrap}
    .bk-chip{font-size:12.5px;font-weight:600;color:var(--blue);background:var(--soft);border-radius:999px;padding:3px 10px}
</style>
@endpush

@section('content')
    <h1 class="bk-h1">{{ __('Reserva una reunión con :name', ['name' => $advisorName]) }}</h1>
    <p class="bk-sub">{{ __('Elige el tipo de reunión. Después verás los días y horarios disponibles.') }}</p>

    @if ($types->isEmpty())
        <div class="bk-card" style="padding:22px">{{ __('Ahora mismo no hay reuniones disponibles para reservar.') }}</div>
    @else
        <div class="bk-types">
            @foreach ($types as $t)
                <a class="bk-card bk-type" href="{{ route('booking.type', [$institution->slug, $profile->booking_slug, $t->slug]) }}?{{ http_build_query(array_filter(['lang' => app()->getLocale(), 'ref' => $ref])) }}">
                    <h2>{{ $t->name }}</h2>
                    @if ($t->description)<p>{{ \Illuminate\Support\Str::limit($t->description, 160) }}</p>@endif
                    <div class="bk-chips">
                        <span class="bk-chip">{{ $t->duration_minutes }} min</span>
                        <span class="bk-chip">{{ $t->medium->labelIn(app()->getLocale()) }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
