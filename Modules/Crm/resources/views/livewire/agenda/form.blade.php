<div class="crm-page mca-page">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    <div style="max-width:960px">
        <div style="margin-bottom:14px">
            <a href="{{ $editing ? route('crm.agenda.show', $activityId) : route('crm.agenda.index') }}" class="ghost"><x-ui.icon name="chevron-left" class="i15" /> {{ __('Volver') }}</a>
        </div>

        <form class="panel" wire:submit="save">
            <div class="ag-bar">
                <h2><x-ui.icon name="calendar" class="i18" /> {{ $editing ? __('Editar actividad') : __('Nueva actividad') }}</h2>
            </div>

            <div class="ag-form">
                <div class="ag-f full">
                    <label for="ag-title">{{ __('Título') }}</label>
                    <input id="ag-title" type="text" wire:model="title" maxlength="200" required>
                    @error('title') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f">
                    <label for="ag-type">{{ __('Tipo') }}</label>
                    <select id="ag-type" wire:model="type">
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                    @error('type') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f">
                    <label for="ag-medium">{{ __('Medio') }}</label>
                    <select id="ag-medium" wire:model.live="medium">
                        @foreach ($media as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                    @if (in_array($medium, ['google_meet', 'zoom', 'whatsapp', 'phone'], true))
                        @php
                            $has = match ($medium) {
                                'google_meet' => (bool) $profile->meet_url,
                                'zoom' => (bool) $profile->zoom_url,
                                'whatsapp' => (bool) $profile->whatsapp_number,
                                default => (bool) $profile->phone,
                            };
                        @endphp
                        <div class="hint">
                            @if ($has && $profile->is_active)
                                {{ __('Se copiará el dato del perfil de reuniones del asesor en esta actividad.') }}
                            @else
                                {{ __('El asesor no tiene este dato en su perfil de reuniones: la actividad quedará sin enlace.') }}
                            @endif
                        </div>
                    @endif
                    @error('medium') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f">
                    <label for="ag-date">{{ __('Fecha') }}</label>
                    <input id="ag-date" type="date" wire:model.live="date" required>
                    @error('date') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label for="ag-time">{{ __('Hora') }}</label>
                        <input id="ag-time" type="time" wire:model.live="time" required>
                        @error('time') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="ag-duration">{{ __('Duración (min)') }}</label>
                        <input id="ag-duration" type="number" min="5" max="720" step="5" wire:model.live.debounce.400ms="duration" required>
                        @error('duration') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>

                @if ($slots !== [])
                    <div class="ag-f full">
                        <label>{{ __('Huecos libres ese día') }}</label>
                        <div class="ag-slots">
                            @foreach ($slots as $slot)
                                <button type="button" wire:click="pickSlot('{{ $slot }}')">{{ $slot }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="ag-f">
                    <label for="ag-tz">{{ __('Zona horaria') }}</label>
                    <select id="ag-tz" wire:model.live="timezone">
                        @foreach ($timezones as $tzOption)
                            <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                        @endforeach
                    </select>
                    @error('timezone') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f">
                    <label for="ag-assigned">{{ __('Asesor responsable') }}</label>
                    <select id="ag-assigned" wire:model.live="assignedTo" @disabled(! $canAssign)>
                        @foreach ($advisors as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ag-f full">
                    <label>{{ __('Lead / contacto') }}</label>
                    @if ($pickedContact)
                        <div class="ag-picked">
                            <x-ui.icon name="user" class="i14" />
                            <span><b>{{ $pickedContact->fullName() }}</b>
                                @if ($pickedLead) · {{ __('Lead #:id', ['id' => $pickedLead->id]) }}@if ($pickedLead->program) · {{ $pickedLead->program->name }}@endif @endif
                            </span>
                            <button type="button" wire:click="clearContact" aria-label="{{ __('Quitar') }}"><x-ui.icon name="x" class="i14" /></button>
                        </div>
                    @else
                        <input type="text" wire:model.live.debounce.300ms="contactSearch" placeholder="{{ __('Buscar contacto por nombre o correo… (opcional)') }}">
                        @if ($results->isNotEmpty())
                            <div class="ag-results">
                                @foreach ($results as $c)
                                    <button type="button" wire:click="pickContact({{ $c->id }})" wire:key="res-{{ $c->id }}">{{ $c->fullName() }} <small>{{ $c->email }}</small></button>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>

                <div class="ag-f">
                    <label for="ag-priority">{{ __('Prioridad') }}</label>
                    <select id="ag-priority" wire:model="priority">
                        <option value="low">{{ __('Baja') }}</option>
                        <option value="normal">{{ __('Normal') }}</option>
                        <option value="high">{{ __('Alta') }}</option>
                    </select>
                </div>

                <div class="ag-f">
                    <label for="ag-location">{{ __('Ubicación (presencial)') }}</label>
                    <input id="ag-location" type="text" wire:model="location" maxlength="255" placeholder="{{ $medium === 'in_person' && $profile->address ? $profile->address : '' }}">
                    @error('location') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f full">
                    <label for="ag-desc">{{ __('Descripción / notas') }}</label>
                    <textarea id="ag-desc" wire:model="description" maxlength="5000"></textarea>
                    @error('description') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="ag-f full">
                    <label>{{ __('Recordatorios') }}</label>
                    <div class="ag-checks">
                        @foreach ([1440 => __('24 horas antes'), 60 => __('1 hora antes'), 15 => __('15 minutos antes')] as $mins => $label)
                            <label><input type="checkbox" value="{{ $mins }}" wire:model="reminders"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <div class="hint">{{ __('Al asesor (correo y aviso interno) y a los invitados activos (correo).') }}</div>
                </div>

                @if ($editing)
                    <div class="ag-f full">
                        <label for="ag-rr">{{ __('Motivo de la reprogramación (si cambias la hora)') }}</label>
                        <input id="ag-rr" type="text" wire:model="rescheduleReason" maxlength="255">
                    </div>
                @endif

                <div class="full">
                    @php $warnings = $serverWarnings !== [] ? $serverWarnings : ($check && ! $check->ok() ? $check->messages() : []); @endphp
                    @if ($warnings !== [])
                        <div class="ag-warn" role="alert">
                            <b><x-ui.icon name="alert-triangle" class="i14" /> {{ __('Este horario no está disponible') }}</b>
                            <ul>
                                @foreach ($warnings as $w)
                                    <li>{{ $w }}</li>
                                @endforeach
                            </ul>
                            <label><input type="checkbox" wire:model="confirmException"> {{ __('Confirmo la excepción y quiero guardar igualmente') }}</label>
                            <div class="ag-f" style="margin-top:8px">
                                <input type="text" wire:model="exceptionReason" maxlength="255" placeholder="{{ __('Motivo de la excepción (opcional)') }}">
                            </div>
                            @error('confirmException') <div class="err" style="color:#B23B3B;margin-top:6px">{{ $message }}</div> @enderror
                        </div>
                    @elseif ($check)
                        <div class="ag-ok"><x-ui.icon name="check" class="i14" /> {{ __('Horario disponible.') }}</div>
                    @endif
                </div>
            </div>

            <div class="ag-actions">
                <a href="{{ $editing ? route('crm.agenda.show', $activityId) : route('crm.agenda.index') }}" class="ghost">{{ __('Cancelar') }}</a>
                <button type="submit" class="ghost solid" wire:loading.attr="disabled" wire:target="save">
                    <x-ui.icon name="check" class="i15" /> {{ $editing ? __('Guardar cambios') : __('Crear actividad') }}
                </button>
            </div>
        </form>
    </div>
</div>
