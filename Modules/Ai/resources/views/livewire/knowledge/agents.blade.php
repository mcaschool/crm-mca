<div>
    <x-ui.styles />

    <div class="mca-head">
        <div>
            <h1 class="mca-h1">{{ __('Centro de Conocimiento') }}</h1>
            <p class="mca-sub">{{ __('Elige un agente y decide qué fuentes de la biblioteca usa.') }}</p>
        </div>
    </div>

    @include('ai::livewire.knowledge._tabs')

    <div class="mca-toolbar" style="margin-bottom:14px">
        <livewire:ai.advisor-selector />
        <div class="sp"></div>
        @if ($bot)
            <span class="t-mut" style="font-size:12.5px">{{ __('Las fuentes se gestionan en la pestaña Biblioteca; aquí solo se asignan.') }}</span>
        @endif
    </div>

    @if (session('status'))
        <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
    @endif

    @if ($bot === null)
        <div class="card" style="padding:28px;text-align:center">
            <x-ui.icon name="bot" class="ic" style="width:28px;height:28px;color:var(--muted)" />
            <p class="t-mut" style="margin:8px 0 0">{{ __('No hay agentes activos. Crea o activa un asesor en «Asesores Inteligentes».') }}</p>
        </div>
    @elseif ($groups->isEmpty())
        <div class="card" style="padding:28px;text-align:center">
            <x-ui.icon name="book-open" class="ic" style="width:28px;height:28px;color:var(--muted)" />
            <p class="t-mut" style="margin:8px 0 0">{{ __('La biblioteca está vacía. Sube fuentes .md en la pestaña Biblioteca.') }}</p>
        </div>
    @else
        @foreach ($groups as $g)
            <div class="card" style="margin-bottom:14px;overflow:hidden" wire:key="grp-{{ $g['key'] }}">
                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);background:#f8fafc">
                    <x-ui.icon name="layers" class="ic" style="width:16px;height:16px;color:var(--mca,#1E5AA8)" />
                    <strong style="text-transform:capitalize">{{ $g['label'] }}</strong>
                    <span class="t-mut" style="font-size:12.5px">{{ __(':a de :t activas para :bot', ['a' => $g['active_count'], 't' => count($g['rows']), 'bot' => $bot->assistant_name]) }}</span>
                    <div class="sp" style="flex:1"></div>
                    <button type="button" wire:click="toggleCategory('{{ $g['key'] }}')" wire:loading.attr="disabled"
                        role="switch" aria-checked="{{ $g['full'] ? 'true' : 'false' }}"
                        style="display:inline-flex;align-items:center;gap:8px;border:0;background:transparent;cursor:pointer;font-size:12.5px;font-weight:600;color:var(--ink,#13253D)">
                        <span style="position:relative;width:36px;height:20px;border-radius:999px;background:{{ $g['full'] ? '#1E5AA8' : '#cbd5e1' }};transition:background .15s">
                            <span style="position:absolute;top:2px;left:{{ $g['full'] ? '18px' : '2px' }};width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s"></span>
                        </span>
                        {{ __('Usar toda la categoría') }}
                    </button>
                </div>

                @foreach ($g['rows'] as $r)
                    <div wire:key="src-{{ $r['id'] }}" style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--line)">
                        <button type="button" wire:click="toggleSource({{ $r['id'] }})" role="switch" aria-checked="{{ $r['assigned'] === true ? 'true' : 'false' }}"
                            title="{{ $r['assigned'] === true ? __('Pausar para este agente') : __('Activar para este agente') }}"
                            style="border:0;background:transparent;cursor:pointer;padding:0">
                            <span style="display:inline-block;position:relative;width:32px;height:18px;border-radius:999px;background:{{ $r['assigned'] === true ? '#1E5AA8' : '#cbd5e1' }}">
                                <span style="position:absolute;top:2px;left:{{ $r['assigned'] === true ? '16px' : '2px' }};width:14px;height:14px;border-radius:50%;background:#fff"></span>
                            </span>
                        </button>
                        <div style="flex:1;min-width:0">
                            <div class="t-strong" style="font-size:13.5px">{{ $r['name'] }}</div>
                            <div class="t-mut" style="font-size:12px;font-family:ui-monospace,monospace">{{ $r['code'] }}</div>
                        </div>
                        @if ($r['assigned'] === true)
                            <span class="badge badge-on">{{ __('Activa para :bot', ['bot' => $bot->assistant_name]) }}</span>
                        @elseif ($r['assigned'] === false)
                            <span class="badge" style="background:#fff7ed;color:#9a6b00">{{ __('Pausada') }}</span>
                        @else
                            <span class="badge badge-off">{{ __('No asignada') }}</span>
                        @endif
                        @if (! $r['global_active'])
                            <span class="badge" style="background:#fef3f2;color:#b42318" title="{{ __('Desactivada en la Biblioteca: ningún agente la usa') }}">{{ __('Inactiva global') }}</span>
                        @endif
                        @if ($r['shared_with'] !== [])
                            <span class="t-mut" style="font-size:12px" title="{{ implode(', ', $r['shared_with']) }}">
                                <x-ui.icon name="users" class="ic" style="width:13px;height:13px;vertical-align:-2px" /> {{ __('Compartida con: :names', ['names' => implode(', ', $r['shared_with'])]) }}
                            </span>
                        @endif
                        @if ($r['assigned'] !== null)
                            <button type="button" wire:click="detachSource({{ $r['id'] }})" class="btn btn-sm" title="{{ __('Quitar solo de este agente') }}">
                                <x-ui.icon name="x" class="ic" style="width:13px;height:13px" /> {{ __('Quitar') }}
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>
