@php
    $back = $editing ? route('crm.agenda.show', $activityId) : route('crm.agenda.index');
    $warnings = $serverWarnings !== [] ? $serverWarnings : ($check && ! $check->ok() ? $check->messages() : []);
@endphp
<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header :title="$editing ? __('Editar actividad') : __('Nueva actividad')" :back="$back" :back-label="$editing ? __('Volver a la actividad') : __('Volver al calendario')"
            :subtitle="$editing ? __('Los cambios de fecha u hora se registran como reprogramación y se avisa a los invitados.') : __('Programa una llamada, reunión, seguimiento o tarea. Se comprueba la disponibilidad del asesor al elegir la hora.')" />

        <form wire:submit="save" novalidate class="ui-card">
            {{-- Qué --}}
            <div class="ui-card-h"><x-ui.icon name="file-text" class="ui-card-ic" /><h2>{{ __('Actividad') }}</h2><span class="sp"></span><span class="ui-card-sub"><span class="ui-req" aria-hidden="true">*</span> {{ __('obligatorio') }}</span></div>
            <div class="ui-card-p">
                <div class="ui-form-grid ui-form-grid--3">
                    <x-ui.field class="ui-span-full" :label="__('Título')" for="ag-title" required :error="$errors->first('title')">
                        <input id="ag-title" type="text" wire:model="title" maxlength="200" required placeholder="{{ __('p. ej. Llamada de seguimiento') }}"
                            @class(['ui-input', 'is-invalid' => $errors->has('title')]) @error('title') aria-invalid="true" aria-describedby="ag-title-error" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('Tipo')" for="ag-type" :error="$errors->first('type')">
                        <select id="ag-type" wire:model="type" @class(['ui-select', 'is-invalid' => $errors->has('type')])>
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('Medio')" for="ag-medium" :error="$errors->first('medium')">
                        <select id="ag-medium" wire:model.live="medium" @class(['ui-select', 'is-invalid' => $errors->has('medium')]) aria-describedby="ag-medium-hint">
                            @foreach ($media as $m)
                                <option value="{{ $m->value }}">{{ $m->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('Prioridad')" for="ag-priority">
                        <select id="ag-priority" wire:model="priority" class="ui-select">
                            <option value="low">{{ __('Baja') }}</option>
                            <option value="normal">{{ __('Normal') }}</option>
                            <option value="high">{{ __('Alta') }}</option>
                        </select>
                    </x-ui.field>
                    @if (in_array($medium, ['google_meet', 'zoom', 'whatsapp', 'phone'], true))
                        @php
                            $has = match ($medium) {
                                'google_meet' => (bool) $profile->meet_url,
                                'zoom' => (bool) $profile->zoom_url,
                                'whatsapp' => (bool) $profile->whatsapp_number,
                                default => (bool) $profile->phone,
                            };
                            $linked = $has && $profile->is_active;
                        @endphp
                        <div @class(['ui-callout', 'ui-callout--sm', 'ui-span-full', $linked ? 'ui-tone--blue' : 'ui-tone--warning']) id="ag-medium-hint">
                            <x-ui.icon :name="$linked ? 'video' : 'alert-triangle'" />
                            <span>{{ $linked ? __('Se copiará el dato del perfil de reuniones del asesor en esta actividad.') : __('El asesor no tiene este dato en su perfil de reuniones: la actividad quedará sin enlace.') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Cuándo --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="clock" class="ui-card-ic" /><h2>{{ __('Fecha y hora') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid ui-form-grid--3">
                    <x-ui.field :label="__('Fecha')" for="ag-date" required :error="$errors->first('date')">
                        <input id="ag-date" type="date" wire:model.live="date" required @class(['ui-input', 'is-invalid' => $errors->has('date')]) @error('date') aria-invalid="true" aria-describedby="ag-date-error" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('Hora')" for="ag-time" required :error="$errors->first('time')">
                        <input id="ag-time" type="time" wire:model.live="time" required @class(['ui-input', 'is-invalid' => $errors->has('time')]) @error('time') aria-invalid="true" aria-describedby="ag-time-error" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('Duración (min)')" for="ag-duration" required :error="$errors->first('duration')">
                        <input id="ag-duration" type="number" min="5" max="720" step="5" inputmode="numeric" wire:model.live.debounce.400ms="duration" required
                            @class(['ui-input', 'is-invalid' => $errors->has('duration')]) @error('duration') aria-invalid="true" aria-describedby="ag-duration-error" @enderror>
                    </x-ui.field>

                    @if ($freeSlots !== [])
                        <div class="ui-field ui-span-full">
                            <span class="ui-label" id="ag-slots-l">{{ __('Huecos libres ese día') }} <span class="opt">· {{ __('elige uno para rellenar la hora') }}</span></span>
                            <div class="ui-chips" role="group" aria-labelledby="ag-slots-l">
                                @foreach ($freeSlots as $freeSlot)
                                    <button type="button" class="ui-chip" wire:click="pickSlot('{{ $freeSlot }}')" aria-pressed="{{ $time === $freeSlot ? 'true' : 'false' }}">{{ $freeSlot }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <x-ui.field :label="__('Zona horaria')" for="ag-tz" :error="$errors->first('timezone')">
                        <select id="ag-tz" wire:model.live="timezone" @class(['ui-select', 'is-invalid' => $errors->has('timezone')])>
                            @foreach ($timezones as $tzOption)
                                <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    @if ($editing)
                        <x-ui.field class="ui-span-2" :label="__('Motivo de la reprogramación')" for="ag-rr" :hint="__('si cambias la hora')">
                            <input id="ag-rr" type="text" wire:model="rescheduleReason" maxlength="255" class="ui-input">
                        </x-ui.field>
                    @endif

                    <div class="ui-span-full" aria-live="polite">
                        @if ($warnings !== [])
                            <div class="ui-callout ui-tone--warning ui-callout--stack" role="alert">
                                <b><x-ui.icon name="alert-triangle" /> {{ __('Este horario no está disponible') }}</b>
                                <ul class="ui-callout-list">
                                    @foreach ($warnings as $w)
                                        <li>{{ $w }}</li>
                                    @endforeach
                                </ul>
                                <label class="ui-check"><input type="checkbox" wire:model="confirmException"> {{ __('Confirmo la excepción y quiero guardar igualmente') }}</label>
                                <input type="text" wire:model="exceptionReason" maxlength="255" class="ui-input" placeholder="{{ __('Motivo de la excepción (opcional)') }}" aria-label="{{ __('Motivo de la excepción (opcional)') }}">
                                @error('confirmException') <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            </div>
                        @elseif ($check)
                            <div class="ui-callout ui-callout--sm ui-tone--success"><x-ui.icon name="check" /> <span>{{ __('Horario disponible.') }}</span></div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Con quién --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="users" class="ui-card-ic" /><h2>{{ __('Participantes') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid">
                    <x-ui.field :label="__('Asesor responsable')" for="ag-assigned" :help="$canAssign ? null : __('Solo la administración puede asignar actividades a otra persona.')">
                        <select id="ag-assigned" wire:model.live="assignedTo" class="ui-select" @disabled(! $canAssign)>
                            @foreach ($advisors as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('Lead / contacto')" for="ag-contact" optional :help="$pickedContact ? null : __('Los invitados se añaden desde la actividad, una vez creada.')">
                        @if ($pickedContact)
                            <div class="ui-picked">
                                <x-ui.icon name="user" />
                                <span class="ui-picked-text"><b>{{ $pickedContact->fullName() }}</b>
                                    @if ($pickedLead)<span>{{ __('Lead #:id', ['id' => $pickedLead->id]) }}@if ($pickedLead->program) · {{ $pickedLead->program->name }}@endif</span>@endif
                                </span>
                                <x-ui.button size="sm" variant="ghost" icon="x" wire:click="clearContact" :aria-label="__('Quitar')" />
                            </div>
                        @else
                            <div class="ui-search">
                                <x-ui.icon name="search" />
                                <input id="ag-contact" type="search" class="ui-input" wire:model.live.debounce.300ms="contactSearch" autocomplete="off"
                                    placeholder="{{ __('Buscar por nombre o correo…') }}">
                            </div>
                            @if ($results->isNotEmpty())
                                <ul class="ui-list ui-list--boxed ui-results">
                                    @foreach ($results as $c)
                                        <li wire:key="res-{{ $c->id }}">
                                            <button type="button" class="ui-list-item" wire:click="pickContact({{ $c->id }})">
                                                <span class="ui-list-main"><span class="ui-list-title">{{ $c->fullName() }}</span><span class="ui-list-meta">{{ $c->email }}</span></span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </x-ui.field>
                </div>
            </div>

            {{-- Detalles --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="sticky-note" class="ui-card-ic" /><h2>{{ __('Detalles y recordatorios') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-form-grid">
                    <x-ui.field class="ui-span-full" :label="__('Ubicación (presencial)')" for="ag-location" optional :error="$errors->first('location')">
                        <input id="ag-location" type="text" wire:model="location" maxlength="255" placeholder="{{ $medium === 'in_person' && $profile->address ? $profile->address : '' }}"
                            @class(['ui-input', 'is-invalid' => $errors->has('location')])>
                    </x-ui.field>
                    <x-ui.field class="ui-span-full" :label="__('Descripción / notas')" for="ag-desc" optional :error="$errors->first('description')">
                        <textarea id="ag-desc" wire:model="description" maxlength="5000" rows="4" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('description')])></textarea>
                    </x-ui.field>
                    <div class="ui-field ui-span-full">
                        <span class="ui-label" id="ag-rem-l">{{ __('Recordatorios') }}</span>
                        <div class="ui-checks" role="group" aria-labelledby="ag-rem-l">
                            @foreach ([1440 => __('24 horas antes'), 60 => __('1 hora antes'), 15 => __('15 minutos antes')] as $mins => $label)
                                <label class="ui-check"><input type="checkbox" value="{{ $mins }}" wire:model="reminders"> {{ $label }}</label>
                            @endforeach
                        </div>
                        <p class="ui-help">{{ __('Al asesor (correo y aviso interno) y a los invitados activos (correo).') }}</p>
                    </div>
                </div>
            </div>

            <div class="ui-card-f ui-card-f--end">
                <x-ui.button :href="$back">{{ __('Cancelar') }}</x-ui.button>
                <button type="submit" class="ui-btn ui-btn--primary" wire:loading.attr="disabled" wire:target="save">
                    <span class="ui-btn-label" wire:loading.remove wire:target="save"><x-ui.icon name="check" /> {{ $editing ? __('Guardar cambios') : __('Crear actividad') }}</span>
                    <span wire:loading wire:target="save"><span class="ui-spin"></span> {{ __('Guardando…') }}</span>
                </button>
            </div>
        </form>
    </div>
</div>
