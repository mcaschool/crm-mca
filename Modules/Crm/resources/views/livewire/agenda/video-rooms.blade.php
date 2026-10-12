<div class="panel" style="margin-top:16px">
    <style>
        .vr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:0}
        .vr-box{padding:14px 18px;border-top:1px solid var(--line);min-width:0}
        .vr-box h3{font-size:14px;font-weight:700;margin:0 0 8px;display:flex;align-items:center;gap:8px;color:var(--ink)}
        .vr-line{font-size:13px;color:var(--muted);margin-top:4px;overflow-wrap:anywhere}
        .vr-line b{color:var(--ink);font-weight:600}
        .vr-link{font-family:ui-monospace,monospace;font-size:12px;color:var(--ink);overflow-wrap:anywhere}
        .vr-btns{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
        .vr-warn{font-size:12px;margin-top:6px;padding:8px 10px;border-radius:9px;background:#FBF1DC;color:#8A5A12}
        .vr-tag{display:inline-flex;align-items:center;font-size:11px;font-weight:600;border-radius:999px;padding:2px 8px;background:#EEF1F5;color:#6B7C93;white-space:nowrap}
        .vr-tag.on{background:#E3F5EA;color:#1E8E5A}
        .vr-tag.err{background:#FCECEC;color:#b3261e}
    </style>
    <div class="ag-bar">
        <h2 style="text-transform:none"><x-ui.icon name="video" class="i18" /> {{ __('Salas institucionales') }}</h2>
    </div>
    @if ($videoNotice !== '')
        <div class="toast {{ $videoNoticeError ? 'err' : 'ok' }}" role="status" style="margin:12px 18px 0">
            <x-ui.icon name="{{ $videoNoticeError ? 'alert-triangle' : 'check' }}" class="i15" /> {{ $videoNotice }}
        </div>
    @endif
    <div class="vr-grid">
        @foreach ($blocks as $provider => $b)
            @php($room = $b['room'])
            @php($isZoom = $provider === 'zoom')
            <div class="vr-box" wire:key="vr-{{ $provider }}">
                <h3>{{ $isZoom ? 'Zoom' : 'Google Meet' }}
                    @if ($room === null)
                        <span class="vr-tag">{{ __('Sin sala') }}</span>
                    @elseif ($room->status === 'active')
                        <span class="vr-tag on">{{ $isZoom ? __('Reunión activa') : __('Sala activa') }}</span>
                    @elseif ($room->status === 'missing')
                        <span class="vr-tag err">{{ __('No existe en el proveedor') }}</span>
                    @else
                        <span class="vr-tag err">{{ __('Con error') }}</span>
                    @endif
                </h3>

                <div class="vr-line">{{ __('Enlace permanente') }}:
                    @if ($b['link'])
                        <span class="vr-link">{{ $b['link'] }}</span>
                        <span class="vr-tag" style="margin-left:4px">{{ $b['inProfile'] ? __('sala institucional') : __('enlace manual') }}</span>
                    @else
                        <b>{{ __('sin enlace') }}</b>
                    @endif
                </div>

                @if ($room)
                    <div class="vr-line">
                        @if ($isZoom)
                            {{ __('Permiso para dirigirla') }}:
                            <b>{{ $room->host_status === 'advisor_host' ? __('Sí: el asesor es el anfitrión') : __('No: anfitrión institucional') }}</b>
                        @else
                            {{ __('Asignación') }}:
                            <b>{{ $room->host_status === 'assigned' ? __('Coanfitrión asignado') : __('Sin coanfitrión') }}</b>
                        @endif
                    </div>
                    @if ($room->host_note && ! $room->advisorCanHost())
                        <div class="vr-warn">{{ $room->host_note }}</div>
                    @endif
                    @if ($room->last_checked_at)
                        <div class="vr-line" style="font-size:12px">{{ __('Revisada') }} {{ $room->last_checked_at->diffForHumans() }}</div>
                    @endif
                    @if ($b['manual'])
                        <div class="vr-warn">{{ __('El perfil usa un enlace manual. Se conserva hasta que la administración lo reemplace por la sala institucional.') }}</div>
                    @endif
                @endif

                <div class="vr-btns">
                    @if ($b['link'])
                        <a href="{{ $b['link'] }}" target="_blank" rel="noopener noreferrer" class="ghost solid"><x-ui.icon name="external-link" class="i15" /> {{ $isZoom ? __('Abrir Zoom') : __('Abrir Google Meet') }}</a>
                    @endif
                    @if ($isAdmin && $b['connected'])
                        @if ($room === null || $room->status !== 'active')
                            <button type="button" class="ghost" wire:click="generateFor('{{ $provider }}')" wire:loading.attr="disabled">{{ $isZoom ? __('Generar reunión de Zoom') : __('Generar sala de Google Meet') }}</button>
                        @else
                            <button type="button" class="ghost" wire:click="checkRoom({{ $room->id }})" wire:loading.attr="disabled">{{ __('Revisar') }}</button>
                            @if ($b['manual'])
                                <button type="button" class="ghost" wire:click="assignRoom({{ $room->id }})" wire:confirm="{{ __('¿Reemplazar el enlace manual del perfil por la sala institucional? Las actividades ya creadas conservan su enlace.') }}">{{ __('Usar la sala en el perfil') }}</button>
                            @endif
                            @if ($b['pending'] > 0)
                                <button type="button" class="ghost" wire:click="applyRoom({{ $room->id }})" wire:confirm="{{ __('¿Poner esta sala en las actividades futuras que aún no tienen enlace? Se avisará a sus invitados.') }}">{{ __('Aplicar a actividades sin enlace (:n)', ['n' => $b['pending']]) }}</button>
                            @endif
                            <button type="button" class="ghost" wire:click="generateFor('{{ $provider }}', true)" wire:confirm="{{ __('¿Regenerar la sala? Las actividades ya creadas conservan el enlace anterior.') }}" wire:loading.attr="disabled">{{ __('Regenerar') }}</button>
                        @endif
                    @elseif ($isAdmin)
                        <a href="{{ route('integrations.video') }}" class="ghost">{{ __('Conectar la cuenta institucional') }}</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
