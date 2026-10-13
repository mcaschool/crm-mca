<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header :title="__('Perfil de reuniones')" :subtitle="__('Enlaces y datos de contacto que se copian en las actividades de :name, salas institucionales y reservas públicas.', ['name' => $ownerName])"
            :back="route('crm.agenda.index')" :back-label="__('Calendario')">
            @if ($canPickUser)
                <x-slot:actions>
                    <label class="ui-owner-pick">
                        <span>{{ __('Perfil de') }}</span>
                        <x-ui.select onchange="window.location='{{ route('crm.agenda.profile') }}?user='+this.value" :aria-label="__('Usuario')">
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}" @selected($u->id === $ownerId)>{{ $u->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </label>
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        @if ($saved)
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ __('Perfil guardado. Las actividades ya creadas conservan su enlace.') }}</div>
        @endif

        <div class="ui-stack">
            <form class="ui-card" wire:submit="save" novalidate>
                <div class="ui-card-h"><x-ui.icon name="video" class="ui-card-ic" /><h2>{{ __('Enlaces y contacto') }}</h2>
                    <span class="sp"></span><span class="ui-card-sub"><x-ui.icon name="lock" class="ui-card-ic" /> {{ __('Los enlaces y números se guardan cifrados.') }}</span></div>
                <div class="ui-card-p">
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Zona horaria')" for="pf-tz" :error="$errors->first('timezone')">
                            <select id="pf-tz" wire:model="timezone" @class(['ui-select', 'is-invalid' => $errors->has('timezone')])>
                                @foreach ($timezones as $tzOption)
                                    <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('Medio por defecto')" for="pf-medium">
                            <select id="pf-medium" wire:model="defaultMedium" class="ui-select">
                                @foreach ($media as $m)
                                    <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Enlace permanente de Google Meet (participante)')" for="pf-meet" :error="$errors->first('meetUrl')">
                            <input id="pf-meet" type="url" inputmode="url" wire:model="meetUrl" placeholder="https://meet.google.com/abc-defg-hij" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('meetUrl')])>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Enlace permanente de Zoom (participante)')" for="pf-zoom" :error="$errors->first('zoomUrl')"
                            :help="__('Nunca pegues el enlace de anfitrión ni de administración: solo el que reciben los participantes.')">
                            <input id="pf-zoom" type="url" inputmode="url" wire:model="zoomUrl" placeholder="https://zoom.us/j/0000000000?pwd=…" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('zoomUrl')])>
                        </x-ui.field>
                        <x-ui.field :label="__('WhatsApp (formato internacional)')" for="pf-wa" :error="$errors->first('whatsappNumber')">
                            <input id="pf-wa" type="tel" wire:model="whatsappNumber" placeholder="+34 600 000 000" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('whatsappNumber')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Teléfono (formato internacional)')" for="pf-phone" :error="$errors->first('phone')">
                            <input id="pf-phone" type="tel" wire:model="phone" placeholder="+34 900 000 000" autocomplete="off" @class(['ui-input', 'is-invalid' => $errors->has('phone')])>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Dirección presencial por defecto')" for="pf-address" :error="$errors->first('address')">
                            <input id="pf-address" type="text" wire:model="address" maxlength="255" @class(['ui-input', 'is-invalid' => $errors->has('address')])>
                        </x-ui.field>
                        <div class="ui-span-full">
                            <label class="ui-check"><input type="checkbox" wire:model="isActive"> {{ __('Perfil activo (si está inactivo, las actividades nuevas no copian enlaces)') }}</label>
                        </div>
                    </div>
                </div>
                <div class="ui-card-f">
                    <a class="ui-link" href="{{ route('crm.agenda.availability', $ownerId !== auth()->id() ? ['user' => $ownerId] : []) }}"><x-ui.icon name="clock" /> {{ __('Editar disponibilidad') }}</a>
                    <span class="sp"></span>
                    <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar perfil') }}</x-ui.button>
                </div>
            </form>

            <livewire:crm.agenda.video-rooms :owner-id="$ownerId" :key="'video-rooms-'.$ownerId" />

            <livewire:crm.agenda.booking-settings :owner-id="$ownerId" :key="'booking-settings-'.$ownerId" />
        </div>
    </div>
</div>
