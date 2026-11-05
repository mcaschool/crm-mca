<div class="mca-page fu-shell fu-settings">
    <x-ui.page-header title="Configuración de seguimiento" subtitle="Define quién puede preparar seguimientos, en qué canales y bajo qué límites." />
    @include('followup::partials.navigation', ['active'=>'settings'])
    @php($modes = ['manual'=>'Manual', 'supervised'=>'Supervisado', 'controlled_auto'=>'Automático controlado'])
    @php($days = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'])
    <section class="ui-card fu-safety" aria-labelledby="execution-heading">
        <div><h2 id="execution-heading">Control de ejecución</h2><p><strong>{{ config('followup.execution_enabled',false) ? 'Control global habilitado' : 'Envíos deshabilitados globalmente' }}</strong></p><p class="fu-muted">Guardar reglas no activa envíos. Todos los controles de ejecución y las políticas deben autorizar cada acción.</p></div>
        <x-ui.button wire:click="stopExecution" wire:confirm="¿Detener los nuevos envíos de esta institución? Los ya despachados conservarán su conciliación." wire:loading.attr="disabled">Detener nuevos envíos</x-ui.button>
    </section>
    @if (session('followup_saved')) <div class="fu-notice" role="status" aria-live="polite">{{ session('followup_saved') }}</div> @endif
    @if ($errors->any())<div class="ui-card fu-validation" role="alert" tabindex="-1" x-data x-init="$el.focus()"><strong>Revisa los datos antes de guardar</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="fu-notice">La institución fija los límites. Cada asesor puede restringirlos, nunca ampliarlos. El consentimiento y las restricciones de cada canal son obligatorios.</div>
    <section class="ui-card" aria-labelledby="advisor-heading">
        <div class="fu-section-heading"><div><h2 id="advisor-heading">Asesores y canales</h2><p class="fu-muted">Selecciona una identidad existente. Su atención reactiva y sus conexiones no se modificarán.</p></div><a href="#institution-rules" class="ui-link">Ver límites institucionales</a></div>
        <label for="followup-advisor">Asesor existente</label><select id="followup-advisor" class="ui-input" wire:model.live="botId"><option value="">Seleccionar asesor</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->assistant_name ?: $bot->name }} · {{ $bot->status === 'active' ? 'Activo' : 'Inactivo' }} · {{ ($advisorSettings[$bot->id] ?? null)?->enabled ? 'Seguimiento configurado' : 'Seguimiento desactivado' }}</option>@endforeach</select>
        @if($bots->isEmpty())<div class="fu-empty">No hay asesores en esta institución. La configuración reutiliza los asesores existentes del CRM.</div>
        @elseif(!$selectedBot)<div class="fu-empty">Selecciona un asesor para ver sus canales, reglas y estado de seguimiento.</div>@endif
        @if($selectedBot)
        <div wire:key="advisor-settings-{{ $selectedBot->id }}">
            <div class="fu-advisor-identity">
                @if($selectedBot->avatarUrl())<img src="{{ $selectedBot->avatarUrl() }}" alt="" width="48" height="48">@else<span class="fu-avatar" aria-hidden="true">{{ mb_substr($selectedBot->assistant_name ?: $selectedBot->name,0,1) }}</span>@endif
                <div><h3>{{ $selectedBot->assistant_name ?: $selectedBot->name }}</h3><p class="fu-muted">{{ $selectedBot->status === 'active' ? 'Asesor activo' : 'Asesor inactivo · no puede ejecutar seguimientos' }} · {{ $channels->where('advisor_bot_id',$selectedBot->id)->count() }} canales asignados</p></div>
            </div>
            <div class="fu-notice" role="status"><strong>Configuración guardada:</strong> {{ $effective->advisorEnabled ? 'Seguimiento del asesor habilitado' : 'Seguimiento del asesor deshabilitado' }} · Modo efectivo: {{ $modes[$effective->mode] ?? 'No reconocido' }} · Intervalo general inicial: {{ $effective->firstFollowUpHours }} h · {{ $effective->maximumAttempts }} intentos acumulados como máximo. @if($effective->cadence['enabled'] ?? false) WhatsApp con texto libre: primer momento a las {{ $effective->cadence['first'] }} h desde el prospecto; hasta {{ $effective->cadence['maximum'] }} seguimientos por ventana. @endif @if(!$effective->institutionEnabled) La institución mantiene el seguimiento deshabilitado. @endif Los envíos dependen de los controles de ejecución.</div>
            <form wire:submit="saveAdvisor">
                <p wire:dirty class="fu-muted" role="status">Hay cambios sin guardar. El resumen superior muestra la configuración guardada.</p>
                @if($effective->allowedChannelIds === [])<p class="fu-notice">No hay canales autorizados en la configuración guardada. Este asesor no podrá realizar seguimientos.</p>@endif
                <div class="fu-rule-grid">
                    <div><label class="fu-check"><input type="checkbox" wire:model="advisorEnabled"> Habilitar reglas de seguimiento del asesor</label><label>Modo del asesor<select class="ui-input" aria-label="Modo del asesor" wire:model="advisorMode"><option value="">Heredar institución · {{ $modes[$institutionSetting->mode] ?? 'No reconocido' }}</option>@foreach($modes as $value=>$label)<option value="{{ $value }}" @disabled(array_search($value,array_keys($modes)) > array_search($institutionSetting->mode,array_keys($modes)))>{{ $label }}</option>@endforeach</select></label><p class="fu-muted">El modo automático controlado no activa envíos. Solo admite la autonomía que permita la institución.</p></div>
                    <div class="fu-pauses"><h3>Pausas obligatorias</h3><p>Respuesta del prospecto, intervención humana, baja de consentimiento, Lead cerrado, canal no disponible y límites alcanzados.</p><p class="fu-muted">Estas protecciones no se pueden desactivar.</p></div>
                </div>
                <h3>Canales asignados y permiso de seguimiento</h3><p class="fu-muted">Seleccionar un canal solo configura el seguimiento. No lo conecta, no lo reasigna y no activa envíos. Sin canales seleccionados, este asesor no podrá hacer seguimientos.</p>
                <div class="fu-channel-list">
                @forelse($channels as $channel)
                    @php($restriction = \Modules\FollowUp\Support\AdvisorChannels::restriction($channel,$selectedBot,$institutionSetting->allowed_channels))
                    <div class="fu-channel" wire:key="channel-{{ $selectedBot->id }}-{{ $channel->id }}">
                        <div><strong>{{ $channel->display_name }}</strong><p class="fu-muted">{{ $channel->provider === 'whatsapp' ? ($channel->connection_status === 'connected_coexistence' ? 'WhatsApp Coexistence' : 'WhatsApp Cloud API') : (\Modules\Social\Models\SocialChannel::PROVIDERS[$channel->provider] ?? $channel->provider) }} · {{ \Modules\Social\Models\SocialChannel::CONNECTION_STATUSES[$channel->connection_status ?? 'connected_cloud_api'] ?? 'Estado no reconocido' }}</p><p>Asesor asignado: {{ $channel->advisorBot?->assistant_name ?: ($channel->advisorBot?->name ?: 'Sin asignar') }}</p><p class="fu-muted">{{ $restriction ?? 'Disponible para configurar. Cada envío requiere consentimiento, política vigente y, cuando corresponda, plantilla aprobada.' }}</p></div>
                        <label class="fu-check"><input type="checkbox" value="{{ $channel->id }}" wire:model="advisorChannelIds" @disabled($restriction !== null && !in_array($channel->id,$advisorChannelIds)) aria-label="Permitir seguimiento en {{ $channel->display_name }}"> {{ $restriction ? 'No disponible' : 'Permitir seguimiento' }}</label>
                    </div>
                @empty<div class="fu-empty">No hay canales conectados en esta institución. No se crearán conexiones desde esta pantalla.</div>@endforelse
                </div>
                @include('followup::partials.cadence-settings', ['model'=>'advisorCadence','scope'=>'asesor'])
                <h3>Frecuencia y límites del asesor</h3><p class="fu-muted">Los campos vacíos heredan la institución. Los valores personalizados solo pueden ser más restrictivos.</p>
                <div class="ui-grid ui-grid--3">
                    <label>Primer seguimiento del asesor (h)<input class="ui-input" type="number" min="{{ $institutionSetting->first_follow_up_hours }}" max="2160" placeholder="Heredar: {{ $institutionSetting->first_follow_up_hours }}" wire:model="advisorFirstHours"></label>
                    <label>Intervalo mínimo del asesor (h)<input class="ui-input" type="number" min="{{ $institutionSetting->minimum_interval_hours }}" max="2160" placeholder="Heredar: {{ $institutionSetting->minimum_interval_hours }}" wire:model="advisorMinimumHours"></label>
                    <label>Máximo de intentos del asesor<input class="ui-input" type="number" min="1" max="{{ $institutionSetting->maximum_attempts }}" placeholder="Heredar: {{ $institutionSetting->maximum_attempts }}" wire:model="advisorMaximumAttempts"></label>
                </div>
                <fieldset><legend>Días del asesor · sin selección se heredan los institucionales</legend>@foreach($days as $day=>$label)<label class="fu-check"><input type="checkbox" value="{{ $day }}" wire:model="advisorAllowedDays"> {{ $label }}</label>@endforeach</fieldset>
                <div class="ui-grid ui-grid--3"><label>Desde · asesor<input class="ui-input" type="time" wire:model="advisorHoursStart"></label><label>Hasta · asesor<input class="ui-input" type="time" wire:model="advisorHoursEnd"></label><div><span class="fu-field-label">Zona horaria institucional</span><p><strong>{{ $institutionSetting->timezone }}</strong></p><p class="fu-muted">La franja del asesor se expresa en esta zona para respetar el horario institucional.</p></div></div>
                <div class="fu-form-footer"><p class="fu-muted">Guardar invalida las acciones pendientes de este asesor. Conserva el historial y los resultados ya despachados.</p><x-ui.button type="submit" wire:loading.attr="disabled">Guardar asesor</x-ui.button></div>
            </form>
        </div>
        @endif
    </section>
    <section class="ui-card" id="institution-rules" aria-labelledby="institution-heading">
        <h2 id="institution-heading">Límites institucionales</h2><p class="fu-muted">Se aplican a todos los asesores. Cambiarlos invalida las acciones pendientes incompatibles.</p>
        <form wire:submit="saveInstitution">
            @include('followup::partials.cadence-settings', ['model'=>'cadence','scope'=>'institución'])
            <div class="fu-rule-grid"><label class="fu-check"><input type="checkbox" wire:model="enabled"> Seguimiento habilitado en la institución</label><label>Modo institucional<select class="ui-input" aria-label="Modo institucional" wire:model="mode">@foreach($modes as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label></div>
            <div class="ui-grid ui-grid--3"><label>Primer seguimiento institucional (h)<input class="ui-input" type="number" min="1" max="2160" wire:model="firstFollowUpHours"></label><label>Intervalo mínimo institucional (h)<input class="ui-input" type="number" min="1" max="2160" wire:model="minimumIntervalHours"></label><label>Máximo de intentos institucional<input class="ui-input" type="number" min="1" max="20" wire:model="maximumAttempts"></label></div>
            <div class="ui-grid ui-grid--3"><label>Desde · institución<input class="ui-input" type="time" wire:model="businessHoursStart"></label><label>Hasta · institución<input class="ui-input" type="time" wire:model="businessHoursEnd"></label><label>Zona horaria · institución<input class="ui-input" placeholder="America/New_York" wire:model="timezone"></label></div>
            <fieldset><legend>Días institucionales permitidos</legend>@foreach($days as $day=>$label)<label class="fu-check"><input type="checkbox" value="{{ $day }}" wire:model="allowedDays"> {{ $label }}</label>@endforeach</fieldset>
            <fieldset><legend>Tipos de canal permitidos</legend><label class="fu-check"><input type="checkbox" value="whatsapp" wire:model="allowedChannels"> WhatsApp</label></fieldset><p class="fu-muted">Instagram y Messenger no admiten seguimiento proactivo bajo los contratos actuales. Email todavía no dispone de ejecución proactiva. No se pueden habilitar desde esta pantalla.</p>
            <div class="fu-form-footer"><p class="fu-muted">Guardar nunca genera ni envía mensajes. El consentimiento y las restricciones obligatorias permanecen vigentes.</p><x-ui.button type="submit" wire:confirm="¿Confirmas estos cambios institucionales? Las acciones incompatibles se invalidarán. Esto no activa los envíos." wire:loading.attr="disabled">Guardar institución</x-ui.button></div>
        </form>
    </section>
    <section class="ui-card"><h2>Supervisión y seguridad</h2><div class="fu-actions"><span>Borradores en cola: <b>{{ $queuedCount }}</b></span><span>Errores o resultados desconocidos: <b>{{ $errorCount }}</b></span><a class="ui-link" href="{{ route('followup.dashboard',['vista'=>'actions']) }}">Revisar acciones</a><a class="ui-link" href="{{ route('followup.dashboard',['vista'=>'history']) }}">Consultar historial</a></div>
        <details><summary>Estado de los controles de ejecución</summary>@forelse($switches as $switch)<p>{{ ['global'=>'Control global','institution'=>'Institución','advisor'=>'Asesor','channel'=>'Canal'][$switch->scope] ?? 'Control' }} #{{ $switch->subject_id }}: <b>{{ $switch->enabled ? 'Habilitado' : 'Deshabilitado' }}</b> · Límite diario {{ $switch->daily_limit }}</p>@empty<p>No hay activaciones registradas. Los envíos permanecen bloqueados.</p>@endforelse</details>
        <p class="fu-muted">Solo administradores activos pueden cambiar estas reglas. Un consentimiento desconocido no es una autorización. Las bajas, matrículas confirmadas y descartes prevalecen sobre recomendaciones automáticas.</p>
    </section>
</div>
