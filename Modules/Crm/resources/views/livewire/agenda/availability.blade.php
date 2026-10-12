<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    <div style="max-width:900px;display:flex;flex-direction:column;gap:16px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a href="{{ route('crm.agenda.index') }}" class="ghost"><x-ui.icon name="chevron-left" class="i15" /> {{ __('Agenda') }}</a>
            @if ($canPickUser)
                <div style="margin-left:auto;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)">
                    {{ __('Disponibilidad de') }}
                    <select class="fbtn" onchange="window.location='{{ route('crm.agenda.availability') }}?user='+this.value" aria-label="{{ __('Usuario') }}">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected($u->id === $ownerId)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        @if ($notice !== '')
            <div class="toast ok" role="status" style="margin:0"><x-ui.icon name="check" class="i15" /> {{ $notice }}</div>
        @endif

        <form class="panel" wire:submit="save">
            <div class="ag-bar">
                <h2><x-ui.icon name="clock" class="i18" /> {{ __('Disponibilidad') }} · {{ $ownerName }}</h2>
            </div>
            <div class="ag-form">
                <div class="ag-f">
                    <label for="av-tz">{{ __('Zona horaria') }}</label>
                    <select id="av-tz" wire:model="timezone">
                        @foreach ($timezones as $tzOption)
                            <option value="{{ $tzOption }}">{{ $tzOption }}</option>
                        @endforeach
                    </select>
                    @error('timezone') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="av-dur">{{ __('Duración por defecto (min)') }}</label>
                    <input id="av-dur" type="number" min="5" max="720" step="5" wire:model="defaultDuration">
                    @error('defaultDuration') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label for="av-bb">{{ __('Margen antes (min)') }}</label>
                        <input id="av-bb" type="number" min="0" max="240" wire:model="bufferBefore">
                    </div>
                    <div>
                        <label for="av-ba">{{ __('Margen después (min)') }}</label>
                        <input id="av-ba" type="number" min="0" max="240" wire:model="bufferAfter">
                    </div>
                </div>
                <div class="ag-f" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label for="av-mn">{{ __('Antelación mínima (min)') }}</label>
                        <input id="av-mn" type="number" min="0" wire:model="minNotice">
                    </div>
                    <div>
                        <label for="av-mh">{{ __('Horizonte máximo (días)') }}</label>
                        <input id="av-mh" type="number" min="1" max="730" wire:model="maxHorizon">
                    </div>
                </div>

                <div class="full">
                    <h3 style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:6px 0 4px">{{ __('Horario semanal') }}</h3>
                    <p class="hint" style="font-size:12px;color:var(--muted);margin:0 0 6px">{{ __('Un día sin intervalos queda deshabilitado. Sin ningún intervalo, no se restringe el horario.') }}</p>
                    @foreach ($weekdays as $wd => $label)
                        <div class="ag-wd" wire:key="wd-{{ $wd }}">
                            <div class="nm">{{ $label }}</div>
                            <div class="ag-f">
                                @forelse ($weekly[$wd] ?? [] as $i => $int)
                                    <div class="ag-int" wire:key="int-{{ $wd }}-{{ $i }}">
                                        <input type="time" wire:model="weekly.{{ $wd }}.{{ $i }}.start" aria-label="{{ __('Inicio') }}">
                                        <span>–</span>
                                        <input type="time" wire:model="weekly.{{ $wd }}.{{ $i }}.end" aria-label="{{ __('Fin') }}">
                                        <button type="button" class="rm" wire:click="removeInterval({{ $wd }}, {{ $i }})">{{ __('Quitar') }}</button>
                                    </div>
                                @empty
                                    <div style="font-size:12.5px;color:#9AA9BC;padding:7px 0">{{ __('No disponible') }}</div>
                                @endforelse
                                <button type="button" class="ag-linkbtn" wire:click="addInterval({{ $wd }})">+ {{ __('Añadir intervalo') }}</button>
                                @error("weekly.$wd") <div class="err">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    @endforeach
                    @error('weekly.*.*.start') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="ag-actions">
                <button type="submit" class="ghost solid" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Guardar disponibilidad') }}</button>
            </div>
        </form>

        <div class="panel">
            <div class="ag-bar">
                <h2><x-ui.icon name="calendar" class="i18" /> {{ __('Excepciones, ausencias y vacaciones') }}</h2>
            </div>
            <div class="ag-list">
                @forelse ($exceptions as $ex)
                    <div class="ag-row" style="grid-template-columns:minmax(0,1fr) auto" wire:key="ex-{{ $ex->id }}">
                        <div class="what">
                            <div class="t">
                                {{ $ex->date_from->format('d/m/Y') }}@if (! $ex->date_from->eq($ex->date_to)) – {{ $ex->date_to->format('d/m/Y') }}@endif
                                · {{ $ex->kind === 'unavailable' ? __('No disponible') : __('Horario extra') }}
                            </div>
                            <div class="s">
                                <span>{{ $ex->isAllDay() ? __('Todo el día') : substr((string) $ex->start_time, 0, 5).'–'.substr((string) $ex->end_time, 0, 5) }}</span>
                                @if ($ex->reason)<span>{{ $ex->reason }}</span>@endif
                            </div>
                        </div>
                        <button type="button" class="ghost" wire:click="removeException({{ $ex->id }})" wire:confirm="{{ __('¿Eliminar esta excepción?') }}"><x-ui.icon name="trash" class="i14" /></button>
                    </div>
                @empty
                    <div class="empty">{{ __('Sin excepciones próximas.') }}</div>
                @endforelse
            </div>
            <form wire:submit="addException" class="ag-form" style="border-top:1px solid var(--line)">
                <div class="ag-f">
                    <label for="ex-from">{{ __('Desde') }}</label>
                    <input id="ex-from" type="date" wire:model="exFrom">
                    @error('exFrom') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="ex-to">{{ __('Hasta') }}</label>
                    <input id="ex-to" type="date" wire:model="exTo">
                    @error('exTo') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="ex-kind">{{ __('Tipo') }}</label>
                    <select id="ex-kind" wire:model="exKind">
                        <option value="unavailable">{{ __('Ausencia / vacaciones (no disponible)') }}</option>
                        <option value="available">{{ __('Horario extra (disponible)') }}</option>
                    </select>
                </div>
                <div class="ag-f">
                    <label for="ex-reason">{{ __('Motivo (opcional)') }}</label>
                    <input id="ex-reason" type="text" wire:model="exReason" maxlength="191">
                </div>
                <div class="ag-f full ag-checks">
                    <label><input type="checkbox" wire:model.live="exAllDay"> {{ __('Todo el día') }}</label>
                </div>
                @unless ($exAllDay)
                    <div class="ag-f">
                        <label for="ex-start">{{ __('Hora inicio') }}</label>
                        <input id="ex-start" type="time" wire:model="exStart">
                        @error('exStart') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="ag-f">
                        <label for="ex-end">{{ __('Hora fin') }}</label>
                        <input id="ex-end" type="time" wire:model="exEnd">
                        @error('exEnd') <div class="err">{{ $message }}</div> @enderror
                    </div>
                @endunless
                <div class="full" style="display:flex;justify-content:flex-end">
                    <button type="submit" class="ghost solid"><x-ui.icon name="plus" class="i15" /> {{ __('Añadir excepción') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
