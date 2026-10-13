<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.page-header :title="__('Disponibilidad')" :subtitle="__('Horario semanal, márgenes entre reuniones y ausencias de :name. Se usan al programar actividades y en las reservas públicas.', ['name' => $ownerName])"
            :back="route('crm.agenda.index')" :back-label="__('Calendario')">
            @if ($canPickUser)
                <x-slot:actions>
                    <label class="ui-owner-pick">
                        <span>{{ __('Disponibilidad de') }}</span>
                        <x-ui.select onchange="window.location='{{ route('crm.agenda.availability') }}?user='+this.value" :aria-label="__('Usuario')">
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}" @selected($u->id === $ownerId)>{{ $u->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </label>
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        @if ($notice !== '')
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ $notice }}</div>
        @endif

        <div class="ui-stack">
            <form class="ui-card" wire:submit="save" novalidate>
                <div class="ui-card-h"><x-ui.icon name="clock" class="ui-card-ic" /><h2>{{ __('Preferencias') }}</h2></div>
                <div class="ui-card-p">
                    <div class="ui-form-grid ui-form-grid--3">
                        <x-ui.field :label="__('Zona horaria')" for="av-tz" :error="$errors->first('timezone')">
                            <select id="av-tz" wire:model="timezone" @class(['ui-select', 'is-invalid' => $errors->has('timezone')])>
                                @foreach ($timezones as $tzOption)
                                    <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('Duración por defecto (min)')" for="av-dur" :error="$errors->first('defaultDuration')">
                            <input id="av-dur" type="number" min="5" max="720" step="5" inputmode="numeric" wire:model="defaultDuration" @class(['ui-input', 'is-invalid' => $errors->has('defaultDuration')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Antelación mínima (min)')" for="av-mn" :error="$errors->first('minNotice')">
                            <input id="av-mn" type="number" min="0" inputmode="numeric" wire:model="minNotice" @class(['ui-input', 'is-invalid' => $errors->has('minNotice')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Margen antes (min)')" for="av-bb" :error="$errors->first('bufferBefore')">
                            <input id="av-bb" type="number" min="0" max="240" inputmode="numeric" wire:model="bufferBefore" @class(['ui-input', 'is-invalid' => $errors->has('bufferBefore')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Margen después (min)')" for="av-ba" :error="$errors->first('bufferAfter')">
                            <input id="av-ba" type="number" min="0" max="240" inputmode="numeric" wire:model="bufferAfter" @class(['ui-input', 'is-invalid' => $errors->has('bufferAfter')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Horizonte máximo (días)')" for="av-mh" :error="$errors->first('maxHorizon')">
                            <input id="av-mh" type="number" min="1" max="730" inputmode="numeric" wire:model="maxHorizon" @class(['ui-input', 'is-invalid' => $errors->has('maxHorizon')])>
                        </x-ui.field>
                    </div>
                </div>

                <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="calendar" class="ui-card-ic" /><h2>{{ __('Horario semanal') }}</h2></div>
                <p class="ui-card-kicker">{{ __('Un día sin intervalos queda deshabilitado. Sin ningún intervalo, no se restringe el horario.') }}</p>
                <div class="ui-weekrows">
                    @foreach ($weekdays as $wd => $label)
                        <div class="ui-weekrow" wire:key="wd-{{ $wd }}" role="group" aria-labelledby="wd-l-{{ $wd }}">
                            <div class="ui-weekrow-day" id="wd-l-{{ $wd }}">{{ $label }}</div>
                            <div class="ui-weekrow-body">
                                @forelse ($weekly[$wd] ?? [] as $i => $int)
                                    <div class="ui-interval" wire:key="int-{{ $wd }}-{{ $i }}">
                                        <input type="time" class="ui-input" wire:model="weekly.{{ $wd }}.{{ $i }}.start" aria-label="{{ __(':day, inicio del intervalo :n', ['day' => $label, 'n' => $loop->iteration]) }}">
                                        <span class="sep" aria-hidden="true">–</span>
                                        <input type="time" class="ui-input" wire:model="weekly.{{ $wd }}.{{ $i }}.end" aria-label="{{ __(':day, fin del intervalo :n', ['day' => $label, 'n' => $loop->iteration]) }}">
                                        <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="removeInterval({{ $wd }}, {{ $i }})" :title="__('Quitar')" :aria-label="__('Quitar el intervalo :n de :day', ['n' => $loop->iteration, 'day' => $label])" />
                                    </div>
                                @empty
                                    <span class="ui-weekrow-off">{{ __('No disponible') }}</span>
                                @endforelse
                                <button type="button" class="ui-link ui-link--btn" wire:click="addInterval({{ $wd }})"><x-ui.icon name="plus" /> {{ __('Añadir intervalo') }}</button>
                                @error("weekly.$wd") <p class="ui-error"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endforeach
                    @error('weekly.*.*.start') <p class="ui-error ui-card-p"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <button type="submit" class="ui-btn ui-btn--primary" wire:loading.attr="disabled" wire:target="save">
                        <span class="ui-btn-label" wire:loading.remove wire:target="save"><x-ui.icon name="check" /> {{ __('Guardar disponibilidad') }}</span>
                        <span wire:loading wire:target="save"><span class="ui-spin"></span> {{ __('Guardando…') }}</span>
                    </button>
                </div>
            </form>

            <section class="ui-card" aria-labelledby="av-ex">
                <div class="ui-card-h"><x-ui.icon name="calendar" class="ui-card-ic" /><h2 id="av-ex">{{ __('Excepciones, ausencias y vacaciones') }}</h2><x-ui.badge tone="neutral" class="ui-badge--count">{{ $exceptions->count() }}</x-ui.badge></div>
                <ul class="ui-list">
                    @forelse ($exceptions as $ex)
                        <li wire:key="ex-{{ $ex->id }}">
                            <div class="ui-list-item">
                                <span @class(['ui-tl-ic', $ex->kind === 'unavailable' ? 'ui-tone--warning' : 'ui-tone--success']) aria-hidden="true"><x-ui.icon :name="$ex->kind === 'unavailable' ? 'x' : 'plus'" /></span>
                                <span class="ui-list-main">
                                    <span class="ui-list-title">{{ $ex->date_from->format('d/m/Y') }}@if (! $ex->date_from->eq($ex->date_to)) – {{ $ex->date_to->format('d/m/Y') }}@endif</span>
                                    <span class="ui-list-meta">
                                        <b>{{ $ex->kind === 'unavailable' ? __('No disponible') : __('Horario extra') }}</b>
                                        · {{ $ex->isAllDay() ? __('Todo el día') : substr((string) $ex->start_time, 0, 5).'–'.substr((string) $ex->end_time, 0, 5) }}
                                        @if ($ex->reason) · {{ $ex->reason }}@endif
                                    </span>
                                </span>
                                <span class="ui-list-aside">
                                    <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="removeException({{ $ex->id }})" wire:confirm="{{ __('¿Eliminar esta excepción?') }}" :title="__('Eliminar')" :aria-label="__('Eliminar la excepción del :date', ['date' => $ex->date_from->format('d/m/Y')])" />
                                </span>
                            </div>
                        </li>
                    @empty
                        <li class="ui-list-empty">{{ __('Sin excepciones próximas.') }}</li>
                    @endforelse
                </ul>
                <form wire:submit="addException" novalidate>
                    <div class="ui-card-p ui-card-p--sep">
                        <p class="ui-card-kicker ui-card-kicker--flush">{{ __('Añadir una excepción') }}</p>
                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Desde')" for="ex-from" :error="$errors->first('exFrom')">
                                <input id="ex-from" type="date" wire:model="exFrom" @class(['ui-input', 'is-invalid' => $errors->has('exFrom')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Hasta')" for="ex-to" :error="$errors->first('exTo')">
                                <input id="ex-to" type="date" wire:model="exTo" @class(['ui-input', 'is-invalid' => $errors->has('exTo')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Tipo')" for="ex-kind">
                                <select id="ex-kind" wire:model="exKind" class="ui-select">
                                    <option value="unavailable">{{ __('Ausencia / vacaciones (no disponible)') }}</option>
                                    <option value="available">{{ __('Horario extra (disponible)') }}</option>
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('Motivo')" for="ex-reason" optional>
                                <input id="ex-reason" type="text" wire:model="exReason" maxlength="191" class="ui-input">
                            </x-ui.field>
                            <div class="ui-span-full">
                                <label class="ui-check"><input type="checkbox" wire:model.live="exAllDay"> {{ __('Todo el día') }}</label>
                            </div>
                            @unless ($exAllDay)
                                <x-ui.field :label="__('Hora inicio')" for="ex-start" :error="$errors->first('exStart')">
                                    <input id="ex-start" type="time" wire:model="exStart" @class(['ui-input', 'is-invalid' => $errors->has('exStart')])>
                                </x-ui.field>
                                <x-ui.field :label="__('Hora fin')" for="ex-end" :error="$errors->first('exEnd')">
                                    <input id="ex-end" type="time" wire:model="exEnd" @class(['ui-input', 'is-invalid' => $errors->has('exEnd')])>
                                </x-ui.field>
                            @endunless
                        </div>
                    </div>
                    <div class="ui-card-f ui-card-f--end">
                        <x-ui.button type="submit" icon="plus" wire:loading.attr="disabled">{{ __('Añadir excepción') }}</x-ui.button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
