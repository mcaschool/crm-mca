<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    <div style="max-width:860px">
        <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a href="{{ route('crm.agenda.index') }}" class="ghost"><x-ui.icon name="chevron-left" class="i15" /> {{ __('Calendario') }}</a>
            @if ($canPickUser)
                <div style="margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)">
                    {{ __('Perfil de') }}
                    <select class="fbtn" onchange="window.location='{{ route('crm.agenda.profile') }}?user='+this.value" aria-label="{{ __('Usuario') }}">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected($u->id === $ownerId)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        @if ($saved)
            <div class="toast ok" role="status"><x-ui.icon name="check" class="i15" /> {{ __('Perfil guardado. Las actividades ya creadas conservan su enlace.') }}</div>
        @endif

        <form class="panel" wire:submit="save">
            <div class="ag-bar">
                <h2><x-ui.icon name="video" class="i18" /> {{ __('Perfil de reuniones') }} · {{ $ownerName }}</h2>
            </div>
            <div class="ag-form">
                <div class="ag-f">
                    <label for="pf-tz">{{ __('Zona horaria') }}</label>
                    <select id="pf-tz" wire:model="timezone">
                        @foreach ($timezones as $tzOption)
                            <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                        @endforeach
                    </select>
                    @error('timezone') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="pf-medium">{{ __('Medio por defecto') }}</label>
                    <select id="pf-medium" wire:model="defaultMedium">
                        @foreach ($media as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ag-f full">
                    <label for="pf-meet">{{ __('Enlace permanente de Google Meet (participante)') }}</label>
                    <input id="pf-meet" type="url" wire:model="meetUrl" placeholder="https://meet.google.com/abc-defg-hij" autocomplete="off">
                    @error('meetUrl') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f full">
                    <label for="pf-zoom">{{ __('Enlace permanente de Zoom (participante)') }}</label>
                    <input id="pf-zoom" type="url" wire:model="zoomUrl" placeholder="https://zoom.us/j/0000000000?pwd=…" autocomplete="off">
                    <div class="hint">{{ __('Nunca pegues el enlace de anfitrión ni de administración: solo el que reciben los participantes.') }}</div>
                    @error('zoomUrl') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="pf-wa">{{ __('WhatsApp (formato internacional)') }}</label>
                    <input id="pf-wa" type="tel" wire:model="whatsappNumber" placeholder="+34 600 000 000" autocomplete="off">
                    @error('whatsappNumber') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="pf-phone">{{ __('Teléfono (formato internacional)') }}</label>
                    <input id="pf-phone" type="tel" wire:model="phone" placeholder="+34 900 000 000" autocomplete="off">
                    @error('phone') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f full">
                    <label for="pf-address">{{ __('Dirección presencial por defecto') }}</label>
                    <input id="pf-address" type="text" wire:model="address" maxlength="255">
                    @error('address') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f full ag-checks">
                    <label><input type="checkbox" wire:model="isActive"> {{ __('Perfil activo (si está inactivo, las actividades nuevas no copian enlaces)') }}</label>
                </div>
                <div class="full hint" style="font-size:12px;color:var(--muted)">
                    <x-ui.icon name="lock" class="i12" /> {{ __('Los enlaces y números se guardan cifrados.') }}
                    <a href="{{ route('crm.agenda.availability', $ownerId !== auth()->id() ? ['user' => $ownerId] : []) }}" style="color:var(--blue);font-weight:600;text-decoration:none;margin-left:6px">{{ __('Editar disponibilidad') }}</a>
                </div>
            </div>
            <div class="ag-actions">
                <button type="submit" class="ghost solid" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Guardar perfil') }}</button>
            </div>
        </form>

        <livewire:crm.agenda.booking-settings :owner-id="$ownerId" :key="'booking-settings-'.$ownerId" />
    </div>
</div>
