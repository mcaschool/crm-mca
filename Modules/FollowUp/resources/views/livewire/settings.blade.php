<div class="mca-page">
    <x-ui.page-header :title="__('Políticas de seguimiento')" :subtitle="__('Configuración conservadora. Guardar nunca genera ni envía mensajes.')" />
    @if (session('followup_saved')) <div class="ui-card" role="status">{{ session('followup_saved') }}</div> @endif
    <div class="ui-grid ui-grid--2">
        <form wire:submit="saveInstitution" class="ui-card">
            <h2>{{ __('Institución') }}</h2>
            <label><input type="checkbox" wire:model="enabled"> {{ __('Seguimiento habilitado') }}</label>
            <label>{{ __('Modo') }}<select class="ui-input" wire:model="mode"><option value="manual">Manual</option><option value="supervised">Supervisado</option><option value="controlled_auto">Automático controlado (solo registro local)</option></select></label>
            <div class="ui-grid ui-grid--3">
                <label>{{ __('Primer seguimiento (h)') }}<input class="ui-input" type="number" min="1" wire:model="firstFollowUpHours"></label>
                <label>{{ __('Intervalo mínimo (h)') }}<input class="ui-input" type="number" min="1" wire:model="minimumIntervalHours"></label>
                <label>{{ __('Máximo acumulado') }}<input class="ui-input" type="number" min="1" max="20" wire:model="maximumAttempts"></label>
            </div>
            <div class="ui-grid ui-grid--3"><label>{{ __('Desde') }}<input class="ui-input" type="time" wire:model="businessHoursStart"></label><label>{{ __('Hasta') }}<input class="ui-input" type="time" wire:model="businessHoursEnd"></label><label>{{ __('Zona IANA') }}<input class="ui-input" wire:model="timezone"></label></div>
            <fieldset><legend>{{ __('Días ISO') }}</legend>@foreach ([1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'] as $day=>$label)<label><input type="checkbox" value="{{ $day }}" wire:model="allowedDays"> {{ $label }}</label> @endforeach</fieldset>
            <fieldset><legend>{{ __('Canales') }}</legend>@foreach (['whatsapp'=>'WhatsApp','instagram'=>'Instagram','messenger'=>'Messenger','email'=>'Email (futuro)'] as $channel=>$label)<label><input type="checkbox" value="{{ $channel }}" wire:model="allowedChannels"> {{ $label }}</label> @endforeach</fieldset>
            @error('*') <div class="t-3" role="alert">{{ $message }}</div> @enderror
            <x-ui.button type="submit">{{ __('Guardar institución') }}</x-ui.button>
        </form>
        <form wire:submit="saveAdvisor" class="ui-card">
            <h2>{{ __('Asesor') }}</h2>
            <label>{{ __('Asesor existente') }}<select class="ui-input" wire:model.live="botId"><option value="">{{ __('Seleccionar') }}</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->assistant_name ?: $bot->name }}</option>@endforeach</select></label>
            <p class="t-3">{{ __('Los valores vacíos heredan la institución; ningún valor puede relajarla.') }}</p>
            <label><input type="checkbox" wire:model="advisorEnabled"> {{ __('Seguimiento del asesor habilitado') }}</label>
            <div class="ui-grid ui-grid--3"><label>{{ __('Primer seguimiento (h)') }}<input class="ui-input" type="number" wire:model="advisorFirstHours"></label><label>{{ __('Intervalo (h)') }}<input class="ui-input" type="number" wire:model="advisorMinimumHours"></label><label>{{ __('Máximo') }}<input class="ui-input" type="number" wire:model="advisorMaximumAttempts"></label></div>
            <div class="ui-grid ui-grid--3"><label>{{ __('Desde') }}<input class="ui-input" type="time" wire:model="advisorHoursStart"></label><label>{{ __('Hasta') }}<input class="ui-input" type="time" wire:model="advisorHoursEnd"></label><label>{{ __('Zona IANA') }}<input class="ui-input" wire:model="advisorTimezone"></label></div>
            <fieldset><legend>{{ __('Días más restrictivos (vacío = heredar)') }}</legend>@foreach ([1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'] as $day=>$label)<label><input type="checkbox" value="{{ $day }}" wire:model="advisorAllowedDays"> {{ $label }}</label> @endforeach</fieldset>
            <fieldset><legend>{{ __('Canales más restrictivos (vacío = heredar)') }}</legend>@foreach (['whatsapp'=>'WhatsApp','instagram'=>'Instagram','messenger'=>'Messenger','email'=>'Email'] as $channel=>$label)<label><input type="checkbox" value="{{ $channel }}" wire:model="advisorAllowedChannels"> {{ $label }}</label> @endforeach</fieldset>
            <label><input type="checkbox" wire:model="pauseOnHuman" disabled> {{ __('Pausa obligatoria ante intervención humana') }}</label>
            @error('*') <div class="t-3" role="alert">{{ $message }}</div> @enderror
            <x-ui.button type="submit" :disabled="$botId === ''">{{ __('Guardar asesor') }}</x-ui.button>
        </form>
    </div>
    <a class="ui-link" href="{{ route('followup.opportunities') }}">{{ __('Volver a oportunidades y acciones') }}</a>
</div>
