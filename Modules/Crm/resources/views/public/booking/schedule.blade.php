@extends('crm::public.booking.layout', ['pageTitle' => $type->name])

@push('head')
<style>
    .bk-grid{display:grid;grid-template-columns:300px minmax(0,1fr);gap:0;overflow:hidden}
    .bk-info{padding:24px;border-right:1px solid var(--line)}
    .bk-info .adv{font-size:13px;color:var(--muted);font-weight:600;margin:0 0 4px}
    .bk-info h1{font-size:21px;margin:0 0 12px}
    .bk-info .desc{color:var(--muted);font-size:14px;margin:12px 0 0;white-space:pre-line}
    .bk-info .instr{background:var(--soft);border-radius:10px;padding:10px 12px;font-size:13.5px;margin-top:14px;white-space:pre-line}
    .bk-pick{padding:24px;min-width:0}
    .bk-pick h2{font-size:16px;margin:0 0 14px}
    .bk-cal-wrap{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:24px}
    .bk-month{display:flex;align-items:center;gap:8px;margin-bottom:10px}
    .bk-month b{flex:1;font-size:15px}
    .bk-nav{border:1px solid var(--line);background:#fff;border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;color:var(--ink)}
    .bk-nav[disabled]{opacity:.35;cursor:default}
    .bk-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;text-align:center}
    .bk-wd{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;padding:4px 0}
    .bk-day{aspect-ratio:1/1;border:none;border-radius:999px;background:transparent;font:inherit;font-size:14px;color:#B5C2D3;cursor:default}
    .bk-day.av{background:var(--soft);color:var(--blue);font-weight:700;cursor:pointer}
    .bk-day.av:hover{background:#DCE9F8}
    .bk-day.sel{background:var(--blue);color:#fff}
    .bk-times{display:flex;flex-direction:column;gap:8px;max-height:360px;overflow:auto}
    .bk-times .dt{font-weight:600;font-size:14px;margin:0 0 4px}
    .bk-time{border:1px solid #BBD3F0;background:#fff;color:var(--blue);border-radius:10px;padding:10px;font:inherit;font-weight:700;cursor:pointer}
    .bk-time:hover{background:var(--soft)}
    .bk-time.sel{background:var(--blue);color:#fff;border-color:var(--blue)}
    .bk-tz{margin-top:16px;font-size:13px;color:var(--muted)}
    .bk-tz select{width:100%;max-width:340px;margin-top:4px;border:1px solid var(--line);border-radius:8px;padding:7px 9px;font:inherit;font-size:13px;color:var(--ink);background:#fff}
    .bk-empty{color:var(--muted);font-size:14px;padding:8px 0}
    .bk-form{border-top:1px solid var(--line);margin-top:22px;padding-top:20px}
    .bk-sum{background:var(--soft);border-radius:10px;padding:10px 14px;font-weight:600;margin-bottom:16px}
    .bk-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
    .bk-fields .full{grid-column:1/-1}
    .bk-fields label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}
    .bk-fields input,.bk-fields textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit;color:var(--ink);background:#fff}
    .bk-fields textarea{min-height:80px;resize:vertical}
    .bk-fields input:focus,.bk-fields textarea:focus{outline:2px solid #BBD3F0;border-color:#BBD3F0}
    .bk-fields .ferr{color:var(--err);font-size:12.5px;margin-top:3px}
    .bk-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
    .bk-actions{display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap}
    .bk-note{font-size:12.5px;color:var(--muted)}
    @media(max-width:860px){.bk-grid{grid-template-columns:1fr}.bk-info{border-right:none;border-bottom:1px solid var(--line)}}
    @media(max-width:640px){.bk-cal-wrap{grid-template-columns:1fr}.bk-fields{grid-template-columns:1fr}.bk-info,.bk-pick{padding:18px}.bk-times{max-height:none;display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.bk-times .dt{grid-column:1/-1}}
</style>
@endpush

@section('content')
    @if ($backUrl)
        <p style="margin:0 0 14px"><a href="{{ $backUrl }}?lang={{ app()->getLocale() }}" style="text-decoration:none;font-weight:600">← {{ $mode === 'reschedule' ? __('Volver a mi reserva') : __('Otros tipos de reunión') }}</a></p>
    @endif

    @if ($errors->any())
        <div class="bk-err" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="bk-card bk-grid">
        <aside class="bk-info">
            <p class="adv">{{ $advisorName }}</p>
            <h1>{{ $type->name }}</h1>
            <div class="bk-meta">
                <span><b>{{ __('Duración') }}:</b> {{ $type->duration_minutes }} min</span>
                <span><b>{{ __('Medio') }}:</b> {{ $type->medium->labelIn(app()->getLocale()) }}</span>
                @if ($mode === 'reschedule' && $booking)
                    <span><b>{{ __('Reserva actual') }}:</b> {{ \Modules\Crm\Services\Booking\BookingMailer::readableDate($booking->activity->starts_at, $booking->visitor_timezone, app()->getLocale()) }}, {{ $booking->activity->starts_at->copy()->setTimezone($booking->visitor_timezone)->format('H:i') }}</span>
                @endif
            </div>
            @if ($type->description)<p class="desc">{{ $type->description }}</p>@endif
            @if ($type->instructions)<div class="instr">{{ $type->instructions }}</div>@endif
        </aside>

        <section class="bk-pick" id="bk-app">
            <h2>{{ $mode === 'reschedule' ? __('Elige el nuevo día y horario') : __('Elige un día y un horario') }}</h2>
            <div class="bk-cal-wrap">
                <div>
                    <div class="bk-month">
                        <button type="button" class="bk-nav" data-nav="-1" aria-label="{{ __('Mes anterior') }}">‹</button>
                        <b id="bk-month-label"></b>
                        <button type="button" class="bk-nav" data-nav="1" aria-label="{{ __('Mes siguiente') }}">›</button>
                    </div>
                    <div class="bk-days" id="bk-days" aria-live="polite"></div>
                </div>
                <div class="bk-times" id="bk-times" aria-live="polite"><p class="bk-empty">{{ __('Cargando horarios…') }}</p></div>
            </div>
            <div class="bk-tz">
                <label for="bk-tz">{{ __('Zona horaria') }}</label><br>
                <select id="bk-tz">
                    @foreach (DateTimeZone::listIdentifiers() as $tzId)
                        <option value="{{ $tzId }}">{{ str_replace('_', ' ', $tzId) }}</option>
                    @endforeach
                </select>
            </div>

            <form method="POST" action="{{ $formAction }}" class="bk-form" id="bk-form" hidden>
                @csrf
                <input type="hidden" name="start" id="bk-start" value="">
                <input type="hidden" name="timezone" id="bk-timezone" value="">
                <input type="hidden" name="form_token" value="{{ $formToken }}">
                <input type="hidden" name="lang" value="{{ app()->getLocale() }}">
                @if ($idempotencyKey)<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">@endif
                @if ($ref)<input type="hidden" name="ref" value="{{ $ref }}">@endif
                <div class="bk-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                <div class="bk-sum" id="bk-summary"></div>

                @if ($mode === 'book')
                    <div class="bk-fields">
                        <div>
                            <label for="bk-fn">{{ __('Nombre') }} *</label>
                            <input id="bk-fn" name="first_name" value="{{ old('first_name') }}" required maxlength="80" autocomplete="given-name">
                            @error('first_name')<div class="ferr">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="bk-ln">{{ __('Apellidos') }} *</label>
                            <input id="bk-ln" name="last_name" value="{{ old('last_name') }}" required maxlength="80" autocomplete="family-name">
                            @error('last_name')<div class="ferr">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="bk-em">{{ __('Correo electrónico') }} *</label>
                            <input id="bk-em" type="email" name="email" value="{{ old('email') }}" required maxlength="191" autocomplete="email">
                            @error('email')<div class="ferr">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="bk-ph">{{ __('Teléfono') }}{{ $type->medium === \Modules\Crm\Enums\ActivityMedium::Phone ? ' *' : '' }}</label>
                            <input id="bk-ph" type="tel" name="phone" value="{{ old('phone') }}" maxlength="30" placeholder="+34 600 000 000" autocomplete="tel" @required($type->medium === \Modules\Crm\Enums\ActivityMedium::Phone)>
                            @error('phone')<div class="ferr">{{ $message }}</div>@enderror
                        </div>
                        <div class="full">
                            <label for="bk-nt">{{ __('Observaciones') }}</label>
                            <textarea id="bk-nt" name="notes" maxlength="1000">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                @endif
                <div class="bk-actions">
                    <button type="submit" class="bk-btn" id="bk-submit">{{ $mode === 'reschedule' ? __('Confirmar nuevo horario') : __('Confirmar reserva') }}</button>
                    <span class="bk-note">{{ __('Recibirás la confirmación por correo electrónico.') }}</span>
                </div>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const cfg = {
        url: @json($slotsUrl),
        locale: @json(app()->getLocale() === 'en' ? 'en-US' : 'es-ES'),
        t: {
            none: @json(__('No hay horarios disponibles este día.')),
            noneMonth: @json(__('No hay horarios disponibles este mes.')),
            pick: @json(__('Selecciona un día disponible.')),
            error: @json(__('No se pudieron cargar los horarios. Inténtalo de nuevo.')),
            at: @json(__('a las')),
        },
    };
    const $ = (id) => document.getElementById(id);
    const daysEl = $('bk-days'), timesEl = $('bk-times'), tzSel = $('bk-tz'), form = $('bk-form');
    let tz = 'UTC';
    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) {}
    if (![...tzSel.options].some(o => o.value === tz)) tz = 'UTC';
    tzSel.value = tz;
    const todayIn = (zone) => new Intl.DateTimeFormat('en-CA', {timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
    let month = todayIn(tz).slice(0, 7), minMonth = month, data = {}, selDay = null, selStart = null;

    const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);
    const fmtDay = (d, opts) => cap(new Intl.DateTimeFormat(cfg.locale, Object.assign({timeZone: 'UTC'}, opts)).format(new Date(d + 'T12:00:00Z')));

    async function load(autoAdvance) {
        timesEl.innerHTML = '<p class="bk-empty">…</p>';
        daysEl.innerHTML = '';
        try {
            const r = await fetch(cfg.url + '?month=' + month + '&tz=' + encodeURIComponent(tz), {headers: {Accept: 'application/json'}});
            if (!r.ok) throw new Error(r.status);
            data = (await r.json()).days || {};
        } catch (e) { data = {}; timesEl.innerHTML = '<p class="bk-empty">' + cfg.t.error + '</p>'; return; }
        const keys = Object.keys(data).sort();
        if (!keys.length && autoAdvance > 0) { month = shift(month, 1); return load(autoAdvance - 1); }
        renderMonth();
        selDay = keys.includes(selDay) ? selDay : (keys[0] || null);
        renderDays(); renderTimes();
        if (!keys.length) timesEl.innerHTML = '<p class="bk-empty">' + cfg.t.noneMonth + '</p>';
    }
    function shift(m, n) { const [y, mo] = m.split('-').map(Number); const d = new Date(Date.UTC(y, mo - 1 + n, 1)); return d.toISOString().slice(0, 7); }
    function renderMonth() {
        $('bk-month-label').textContent = fmtDay(month + '-01', {month: 'long', year: 'numeric'});
        document.querySelector('[data-nav="-1"]').disabled = month <= minMonth;
    }
    function renderDays() {
        const [y, mo] = month.split('-').map(Number);
        const first = new Date(Date.UTC(y, mo - 1, 1)), total = new Date(Date.UTC(y, mo, 0)).getUTCDate();
        const lead = (first.getUTCDay() + 6) % 7;
        let html = '';
        for (let i = 0; i < 7; i++) html += '<div class="bk-wd">' + fmtDay('2026-10-' + String(19 + i).padStart(2, '0'), {weekday: 'short'}) + '</div>';
        for (let i = 0; i < lead; i++) html += '<span></span>';
        for (let d = 1; d <= total; d++) {
            const key = month + '-' + String(d).padStart(2, '0');
            const av = !!data[key];
            html += '<button type="button" class="bk-day' + (av ? ' av' : '') + (key === selDay ? ' sel' : '') + '" data-day="' + key + '"' + (av ? '' : ' disabled') + ' aria-label="' + fmtDay(key, {weekday: 'long', day: 'numeric', month: 'long'}) + '">' + d + '</button>';
        }
        daysEl.innerHTML = html;
    }
    function renderTimes() {
        if (!selDay || !data[selDay]) { timesEl.innerHTML = '<p class="bk-empty">' + cfg.t.pick + '</p>'; return; }
        let html = '<p class="dt">' + fmtDay(selDay, {weekday: 'long', day: 'numeric', month: 'long'}) + '</p>';
        for (const s of data[selDay]) html += '<button type="button" class="bk-time' + (s.start === selStart ? ' sel' : '') + '" data-start="' + s.start + '" data-time="' + s.time + '">' + s.time + '</button>';
        timesEl.innerHTML = html;
    }
    daysEl.addEventListener('click', (e) => { const b = e.target.closest('[data-day]'); if (!b) return; selDay = b.dataset.day; selStart = null; form.hidden = true; renderDays(); renderTimes(); });
    timesEl.addEventListener('click', (e) => {
        const b = e.target.closest('[data-start]'); if (!b) return;
        selStart = b.dataset.start; renderTimes();
        $('bk-start').value = selStart; $('bk-timezone').value = tz;
        $('bk-summary').textContent = fmtDay(selDay, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}) + ' · ' + cfg.t.at + ' ' + b.dataset.time + ' (' + tz.replace(/_/g, ' ') + ')';
        form.hidden = false;
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
    document.querySelectorAll('[data-nav]').forEach((b) => b.addEventListener('click', () => { month = shift(month, Number(b.dataset.nav)); selDay = null; selStart = null; form.hidden = true; load(0); }));
    tzSel.addEventListener('change', () => { tz = tzSel.value; selStart = null; form.hidden = true; load(0); });
    form.addEventListener('submit', () => { const s = $('bk-submit'); setTimeout(() => { s.disabled = true; }, 0); });
    load(2);
})();
</script>
@endpush
