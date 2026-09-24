<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('integrations.index') }}" class="btn btn-ghost btn-sm" title="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <h1 class="mca-h1">{{ __('Procesos de IA') }}</h1>
            </div>
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="card card-p fade">
            @if ($bots->isEmpty())
                <p class="mca-muted" style="font-size:13.5px;margin:0">{{ __('No hay bots configurados todavía.') }}</p>
            @else
                <div class="field">
                    <label>{{ __('Bot') }}</label>
                    <select wire:model.live="botId">
                        @foreach ($bots as $bot)
                            <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                        @endforeach
                    </select>
                </div>

                @php($inp = 'width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff')
                <datalist id="known-ai-models">
                    @foreach ($modelsByProvider as $prov => $models)
                        @foreach ($models as $m)
                            <option value="{{ $m['id'] }}">{{ $prov }} · {{ $m['label'] }}{{ $m['status'] !== 'supported' ? ' ('.$m['status'].')' : '' }}</option>
                        @endforeach
                    @endforeach
                </datalist>

                <form wire:submit="save">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Proceso') }}</th>
                                <th>{{ __('Proveedor de IA') }}</th>
                                <th>{{ __('Modelo') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($processes as $process)
                                @php($p = $this->profileFor($process))
                                <tr wire:key="proc-{{ $process }}">
                                    <td class="t-strong">{{ $process }}</td>
                                    <td>
                                        <select wire:model.live="rows.{{ $process }}.integration_id" style="{{ $inp }}">
                                            <option value="">{{ __('— sin asignar —') }}</option>
                                            @foreach ($aiIntegrations as $ai)
                                                <option value="{{ $ai->id }}">{{ $ai->name }} ({{ $ai->provider }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" list="known-ai-models" wire:model.live.debounce.500ms="rows.{{ $process }}.model" placeholder="{{ __('escribe o elige un modelo') }}" style="{{ $inp }}">
                                    </td>
                                </tr>
                                @if ($p !== null)
                                    <tr wire:key="cap-{{ $process }}">
                                        <td></td>
                                        <td colspan="2" style="padding-top:2px">
                                            {{-- Capacidades conocidas (informativas) --}}
                                            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:11.5px;color:var(--muted);margin-bottom:8px">
                                                <span class="badge {{ $p->known ? 'badge-on' : 'badge-off' }}">{{ $p->known ? $p->label : __('modelo no catalogado') }}</span>
                                                @if (! $p->known)
                                                    <span>{{ __('Se usará un perfil genérico seguro (sin optimizaciones no confirmadas).') }}</span>
                                                @else
                                                    <span>{{ __('Salida estructurada') }}: <strong>{{ $p->supportsStructuredOutput() ? __('sí') : __('no') }}</strong></span>
                                                    <span>· Thinking: <strong>{{ $p->supportsThinking() ? __('sí') : __('no') }}</strong></span>
                                                    <span>· {{ __('Caché de contexto') }}: <strong>{{ $p->supportsImplicitCache() ? __('sí') : __('no') }}</strong></span>
                                                    @if ($p->contextWindow() !== null)<span>· {{ __('Contexto') }}: {{ number_format($p->contextWindow()) }}</span>@endif
                                                    @if ($p->maxOutput() !== null)<span>· max out: {{ number_format($p->maxOutput()) }}</span>@endif
                                                @endif
                                            </div>
                                            {{-- Overrides avanzados: SOLO los que el modelo soporta --}}
                                            <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center">
                                                @if ($p->supportsThinking())
                                                    <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer">
                                                        <input type="checkbox" wire:model="rows.{{ $process }}.thinking" style="width:15px;height:15px"> Thinking
                                                    </label>
                                                @endif
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px">
                                                    {{ __('Temperatura') }}
                                                    <input type="number" step="0.1" min="0" max="2" wire:model="rows.{{ $process }}.temperature" placeholder="0.3" style="width:80px;{{ $inp }}">
                                                </label>
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px">
                                                    max_tokens
                                                    <input type="number" min="1" @if ($p->maxOutput() !== null) max="{{ $p->maxOutput() }}" @endif wire:model="rows.{{ $process }}.max_tokens" placeholder="500" style="width:100px;{{ $inp }}">
                                                </label>
                                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px">
                                                    timeout (s)
                                                    <input type="number" min="1" max="120" wire:model="rows.{{ $process }}.timeout" placeholder="30" style="width:80px;{{ $inp }}">
                                                </label>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>

                    <div style="margin-top:18px"><button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button></div>
                </form>
            @endif
        </div>
    </div>
</div>
