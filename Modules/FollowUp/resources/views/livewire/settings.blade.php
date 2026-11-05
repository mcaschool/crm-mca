<div class="mca-page fu-shell">
    <x-ui.page-header :title="__('Políticas de seguimiento')" :subtitle="__('Configuración conservadora. Guardar nunca genera ni envía mensajes.')" />
    @include('followup::partials.navigation', ['active'=>'settings'])
    @if (session('followup_saved')) <div class="ui-card" role="status">{{ session('followup_saved') }}</div> @endif
    <div class="fu-columns">
        <form wire:submit="saveInstitution" class="ui-card">
            <h2>{{ __('Institución') }}</h2>
            <label><input type="checkbox" wire:model="enabled"> {{ __('Seguimiento habilitado') }}</label>
            <label>{{ __('Modo') }}<select class="ui-input" wire:model="mode"><option value="manual">Manual</option><option value="supervised">Supervisado</option><option value="controlled_auto">Automático controlado · no activa envíos</option></select></label>
            <div class="ui-grid ui-grid--3">
                <label>{{ __('Primer seguimiento (h)') }}<input class="ui-input" type="number" min="1" wire:model="firstFollowUpHours"></label>
                <label>{{ __('Intervalo mínimo (h)') }}<input class="ui-input" type="number" min="1" wire:model="minimumIntervalHours"></label>
                <label>{{ __('Máximo acumulado') }}<input class="ui-input" type="number" min="1" max="20" wire:model="maximumAttempts"></label>
            </div>
            <div class="ui-grid ui-grid--3"><label>{{ __('Desde') }}<input class="ui-input" type="time" wire:model="businessHoursStart"></label><label>{{ __('Hasta') }}<input class="ui-input" type="time" wire:model="businessHoursEnd"></label><label>{{ __('Zona IANA') }}<input class="ui-input" wire:model="timezone"></label></div>
            <fieldset><legend>{{ __('Días ISO') }}</legend>@foreach ([1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'] as $day=>$label)<label><input type="checkbox" value="{{ $day }}" wire:model="allowedDays"> {{ $label }}</label> @endforeach</fieldset>
            <fieldset><legend>{{ __('Canales') }}</legend>@foreach (['whatsapp'=>'WhatsApp','instagram'=>'Instagram','messenger'=>'Messenger','email'=>'Email (futuro)'] as $channel=>$label)<label><input type="checkbox" value="{{ $channel }}" wire:model="allowedChannels"> {{ $label }}</label> @endforeach</fieldset>
            @error('*') <div class="t-3" role="alert">{{ $message }}</div> @enderror
            <x-ui.button type="submit" wire:confirm="¿Confirmas estos cambios de política? Las acciones incompatibles se invalidarán. Esto no activa los envíos." wire:loading.attr="disabled">{{ __('Guardar institución') }}</x-ui.button>
        </form>
        <form wire:submit="saveAdvisor" class="ui-card">
            <h2>{{ __('Asesor') }}</h2>
            <label>{{ __('Asesor existente') }}<select class="ui-input" wire:model.live="botId"><option value="">{{ __('Seleccionar') }}</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->assistant_name ?: $bot->name }}</option>@endforeach</select></label>
            <p class="t-3">{{ __('Los valores vacíos heredan la institución; ningún valor puede relajarla.') }}</p>
            <p class="fu-muted">El modo operativo se hereda de la institución. Las reglas comerciales y las exclusiones obligatorias no pueden desactivarse.</p>
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
    <section class="ui-card"><h2>Seguridad operativa</h2><div class="fu-notice">Ejecución global por configuración: <b>{{ config('followup.execution_enabled',false) ? 'Habilitada' : 'Deshabilitada' }}</b>. La ausencia de cualquier interruptor requerido bloquea los envíos. Esta pantalla no ofrece activación de envíos.</div>
        <div class="fu-actions"><span>Borradores pendientes en cola: <b>{{ $queuedCount }}</b></span><span>Errores o resultados desconocidos: <b>{{ $errorCount }}</b></span><a class="ui-link" href="{{ route('followup.dashboard',['vista'=>'actions']) }}">Revisar acciones</a></div>
        @forelse($switches as $switch)<p>{{ ['global'=>'Control global','institution'=>'Institución','advisor'=>'Asesor','channel'=>'Canal'][$switch->scope] ?? 'Control' }} #{{ $switch->subject_id }}: <b>{{ $switch->enabled ? 'Habilitado' : 'Deshabilitado' }}</b> · Límite diario {{ $switch->daily_limit }}@if($switch->verified_until) · Verificación hasta {{ $switch->verified_until }} @endif</p>@empty<p>No hay activaciones registradas. Los envíos permanecen bloqueados.</p>@endforelse
        <x-ui.button wire:click="stopExecution" wire:confirm="¿Detener los nuevos envíos de esta institución?">Detener nuevos envíos</x-ui.button>
    </section>
    <section class="ui-card"><h2>Políticas y permisos que protegen el seguimiento</h2><div class="fu-columns"><div><h3>Canales y consentimiento</h3><p>WhatsApp requiere consentimiento aplicable y una plantilla aprobada fuera de la ventana permitida. Instagram y Messenger no autorizan envíos proactivos con los contratos actuales.</p><p>Un consentimiento desconocido no equivale a permiso. Registra procedencia y evidencia verificable desde el detalle de la oportunidad.</p></div><div><h3>Control administrativo</h3><p>Solo administradores activos gestionan FollowUp. Marketing, Admissions y otros roles conservan sus permisos habituales de Leads, Inbox y Agenda; no obtienen acceso nuevo a borradores, consentimiento o aprobaciones.</p><p>Las bajas, matrículas confirmadas, descartes, intervención humana y límites acumulados prevalecen sobre cualquier recomendación automática.</p></div></div></section>
    <a class="ui-link" href="{{ route('followup.opportunities') }}">{{ __('Volver a oportunidades y acciones') }}</a>
</div>
