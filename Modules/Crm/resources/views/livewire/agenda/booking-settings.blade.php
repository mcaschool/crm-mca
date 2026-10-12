<div style="display:flex;flex-direction:column;gap:16px;margin-top:16px">
    @if ($notice !== '')
        <div class="toast ok" role="status" style="margin:0"><x-ui.icon name="check" class="i15" /> {{ $notice }}</div>
    @endif

    {{-- Mi enlace de reservas --}}
    <form class="panel" wire:submit="saveLink">
        <div class="ag-bar">
            <h2 style="text-transform:none"><x-ui.icon name="external-link" class="i18" /> {{ __('Mi enlace de reservas') }}</h2>
            @if ($bookingsCount > 0)<span class="count">{{ __('Reservas próximas: :n', ['n' => $bookingsCount]) }}</span>@endif
        </div>
        <div class="ag-form">
            <div class="ag-f full ag-checks">
                <label><input type="checkbox" wire:model="bookingEnabled"> {{ __('Recibir reservas públicas en mi Calendario') }}</label>
                @error('bookingEnabled') <div class="err">{{ $message }}</div> @enderror
            </div>
            <div class="ag-f">
                <label for="bk-slug">{{ __('Alias del enlace') }}</label>
                <input id="bk-slug" type="text" wire:model="bookingSlug" maxlength="80" placeholder="ana-garcia">
                <div class="hint">{{ __('Solo minúsculas, números y guiones. Cambiarlo invalida el enlace anterior.') }}</div>
                @error('bookingSlug') <div class="err">{{ $message }}</div> @enderror
            </div>
            <div class="ag-f">
                <label>{{ __('Enlace público') }}</label>
                @if ($publicUrl)
                    <div class="ag-picked" x-data="{ copied: false }">
                        <span style="overflow-wrap:anywhere;font-size:12.5px">{{ $publicUrl }}</span>
                        <button type="button" title="{{ __('Copiar') }}" aria-label="{{ __('Copiar') }}"
                                x-on:click="navigator.clipboard.writeText(@js($publicUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-show="!copied"><x-ui.icon name="file-text" class="i14" /></span><span x-show="copied" style="font-size:11px;color:#1E8E5A">✓</span>
                        </button>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:6px">
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="ag-linkbtn">{{ __('Vista previa') }} ↗</a>
                    </div>
                @else
                    <div class="hint">{{ __('Guarda un alias para obtener tu enlace.') }}</div>
                @endif
            </div>
            @if ($profileProblems !== [])
                <div class="full ag-warn" style="font-size:12.5px">
                    <b>{{ __('Para recibir reservas:') }}</b>
                    <ul>@foreach ($profileProblems as $p)<li>{{ $p }}</li>@endforeach</ul>
                </div>
            @endif
        </div>
        <div class="ag-actions">
            <button type="submit" class="ghost solid" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Guardar enlace') }}</button>
        </div>
    </form>

    {{-- Tipos de reunión --}}
    <div class="panel">
        <div class="ag-bar">
            <h2 style="text-transform:none"><x-ui.icon name="calendar" class="i18" /> {{ __('Tipos de reunión') }}</h2>
            <div style="flex:1"></div>
            @unless ($showTypeForm)
                <button type="button" class="ghost solid" wire:click="newType"><x-ui.icon name="plus" class="i15" /> {{ __('Nuevo tipo') }}</button>
            @endunless
        </div>

        @if ($showTypeForm)
            <form wire:submit="saveType" class="ag-form" style="border-bottom:1px solid var(--line)">
                <div class="ag-f">
                    <label for="mt-name">{{ __('Nombre') }}</label>
                    <input id="mt-name" type="text" wire:model="name" maxlength="120" placeholder="{{ __('Consulta de admisiones') }}">
                    @error('name') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="mt-slug">{{ __('Alias del enlace (opcional)') }}</label>
                    <input id="mt-slug" type="text" wire:model="slug" maxlength="80" placeholder="consulta-admisiones">
                    @error('slug') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="mt-dur">{{ __('Duración (min)') }}</label>
                    <input id="mt-dur" type="number" min="10" max="240" step="5" wire:model="duration">
                    @error('duration') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f">
                    <label for="mt-medium">{{ __('Medio') }}</label>
                    <select id="mt-medium" wire:model="medium">
                        @foreach ($media as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                    </select>
                    @error('medium') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f full">
                    <label for="mt-desc">{{ __('Descripción') }}</label>
                    <textarea id="mt-desc" wire:model="description" maxlength="2000"></textarea>
                </div>
                <div class="ag-f full">
                    <label for="mt-instr">{{ __('Instrucciones para el visitante') }}</label>
                    <input id="mt-instr" type="text" wire:model="instructions" maxlength="500">
                </div>
                <div class="ag-f">
                    <label for="mt-prog">{{ __('Programa de interés (opcional)') }}</label>
                    <select id="mt-prog" wire:model="programId">
                        <option value="">—</option>
                        @foreach ($programs as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                    @error('programId') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="ag-f ag-checks" style="align-items:flex-end">
                    <label><input type="checkbox" wire:model="typeActive"> {{ __('Activo') }}</label>
                    <label><input type="checkbox" wire:model="typePublic"> {{ __('Público') }}</label>
                </div>
                @error('typePublic') <div class="full ag-warn" style="font-size:12.5px">{{ $message }}</div> @enderror
                <div class="full" style="display:flex;gap:8px;justify-content:flex-end">
                    <button type="button" class="ghost" wire:click="cancelType">{{ __('Cancelar') }}</button>
                    <button type="submit" class="ghost solid" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Guardar tipo') }}</button>
                </div>
            </form>
        @endif

        <div class="ag-list">
            @forelse ($types as $t)
                @php $url = $typeUrl($t); $problems = $typeProblems[$t->id] ?? []; @endphp
                <div class="ag-row" style="grid-template-columns:minmax(0,1fr) auto" wire:key="mt-{{ $t->id }}">
                    <div class="what">
                        <div class="t" style="font-weight:600;font-size:14px">{{ $t->name }}</div>
                        <div class="s">
                            <span>{{ $t->duration_minutes }} min</span>
                            <span>{{ $t->medium->label() }}</span>
                            <span class="ag-badge {{ $t->is_active ? '' : 'cancelled' }}">{{ $t->is_active ? __('Activo') : __('Inactivo') }}</span>
                            <span class="ag-badge {{ $t->is_public ? 'completed' : 'cancelled' }}">{{ $t->is_public ? __('Público') : __('No público') }}</span>
                        </div>
                        @if ($problems !== [])
                            <div style="font-size:12px;color:#B7791F;margin-top:4px">{{ implode(' ', $problems) }}</div>
                        @elseif ($url && $t->is_public && $t->is_active)
                            <div x-data="{ copied: false }" style="display:flex;align-items:center;gap:8px;margin-top:4px;font-size:12px;min-width:0">
                                <span style="color:var(--muted);overflow-wrap:anywhere">{{ $url }}</span>
                                <button type="button" class="ag-linkbtn" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="!copied">{{ __('Copiar') }}</span><span x-show="copied">{{ __('Copiado') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                        <button type="button" class="ghost" wire:click="toggleType({{ $t->id }}, 'is_public')">{{ $t->is_public ? __('Ocultar') : __('Publicar') }}</button>
                        <button type="button" class="ghost" wire:click="editType({{ $t->id }})"><x-ui.icon name="pencil" class="i14" /></button>
                        <button type="button" class="ghost" wire:click="deleteType({{ $t->id }})" wire:confirm="{{ __('¿Eliminar este tipo de reunión? Las reservas existentes se conservan.') }}"><x-ui.icon name="trash" class="i14" /></button>
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('Aún no hay tipos de reunión. Crea el primero para publicarlo en tu enlace.') }}</div>
            @endforelse
        </div>
    </div>
</div>
