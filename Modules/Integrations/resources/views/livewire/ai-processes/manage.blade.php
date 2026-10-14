<div class="mca-page">
    <x-ui.page-header :title="__('Procesos de IA')" :subtitle="__('Proveedor y modelo de IA que usa cada proceso del bot. Cada proceso puede usar uno distinto.')" :back="route('integrations.index')" :back-label="__('Volver a integraciones')" />

    @if (session('status'))
        <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif

    @if ($bots->isEmpty())
        <div class="ui-card">
            <div class="ui-empty">
                <div class="ui-empty-ic"><x-ui.icon name="bot" /></div>
                <b>{{ __('No hay bots configurados todavía.') }}</b>
            </div>
        </div>
    @else
        @php
            // Nombre legible de cada proceso (la clave técnica se conserva debajo).
            $processNames = [
                'conversation' => __('Conversación'),
                'classification' => __('Clasificación'),
                'summary' => __('Resumen'),
                'email_draft' => __('Borrador de correo'),
            ];
        @endphp

        <datalist id="known-ai-models">
            @foreach ($modelsByProvider as $prov => $models)
                @foreach ($models as $m)
                    <option value="{{ $m['id'] }}">{{ $prov }} · {{ $m['label'] }}{{ $m['status'] !== 'supported' ? ' ('.$m['status'].')' : '' }}</option>
                @endforeach
            @endforeach
        </datalist>

        <form class="ui-card" wire:submit="save" novalidate>
            <div class="ui-card-h"><x-ui.icon name="cpu" class="ui-card-ic" /><h2>{{ __('Proveedor y modelo por proceso') }}</h2>
                <span class="sp"></span>
                <div class="ui-owner-pick">
                    <label for="aip-bot">{{ __('Bot') }}</label>
                    <select id="aip-bot" class="ui-select" wire:model.live="botId">
                        @foreach ($bots as $bot)
                            <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="ui-keyrows">
                @foreach ($processes as $process)
                    @php($p = $this->profileFor($process))
                    <div class="ui-keyrow" wire:key="proc-{{ $process }}">
                        <div class="ui-keyrow-k">
                            <span class="ui-strong">{{ $processNames[$process] ?? $process }}</span>
                            <span class="ui-cell-sub ui-mono">{{ $process }}</span>
                        </div>
                        <div class="ui-keyrow-b">
                            <div class="ui-form-grid">
                                <x-ui.field :label="__('Proveedor de IA')" for="aip-{{ $process }}-provider" :error="$errors->first('rows.'.$process.'.integration_id')">
                                    <select id="aip-{{ $process }}-provider" wire:model.live="rows.{{ $process }}.integration_id" @class(['ui-select', 'is-invalid' => $errors->has('rows.'.$process.'.integration_id')])>
                                        <option value="">{{ __('— sin asignar —') }}</option>
                                        @foreach ($aiIntegrations as $ai)
                                            <option value="{{ $ai->id }}">{{ $ai->name }} ({{ $ai->provider }})</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field :label="__('Modelo')" for="aip-{{ $process }}-model" :error="$errors->first('rows.'.$process.'.model')">
                                    <input id="aip-{{ $process }}-model" type="text" list="known-ai-models" wire:model.live.debounce.500ms="rows.{{ $process }}.model" placeholder="{{ __('escribe o elige un modelo') }}" spellcheck="false" @class(['ui-input', 'is-invalid' => $errors->has('rows.'.$process.'.model')])>
                                </x-ui.field>
                            </div>

                            @if ($p !== null)
                                <div class="ui-box" wire:key="cap-{{ $process }}">
                                    {{-- Capacidades conocidas (informativas) --}}
                                    <div class="ui-record-meta">
                                        <x-ui.badge :tone="$p->known ? 'success' : 'neutral'">{{ $p->known ? $p->label : __('modelo no catalogado') }}</x-ui.badge>
                                        @if (! $p->known)
                                            <span>{{ __('Se usará un perfil genérico seguro (sin optimizaciones no confirmadas).') }}</span>
                                        @else
                                            <span>{{ __('Salida estructurada') }}: <b>{{ $p->supportsStructuredOutput() ? __('sí') : __('no') }}</b></span>
                                            <span>Thinking: <b>{{ $p->supportsThinking() ? __('sí') : __('no') }}</b></span>
                                            <span>{{ __('Caché de contexto') }}: <b>{{ $p->supportsImplicitCache() ? __('sí') : __('no') }}</b></span>
                                            @if ($p->contextWindow() !== null)<span>{{ __('Contexto') }}: <b>{{ number_format($p->contextWindow()) }}</b></span>@endif
                                            @if ($p->maxOutput() !== null)<span>{{ __('Salida máxima') }}: <b>{{ number_format($p->maxOutput()) }}</b></span>@endif
                                        @endif
                                    </div>
                                    {{-- Overrides avanzados: SOLO los que el modelo soporta --}}
                                    <div class="ui-inline-fields">
                                        @if ($p->supportsThinking())
                                            <label class="ui-check ui-check--field"><input type="checkbox" wire:model="rows.{{ $process }}.thinking"> Thinking</label>
                                        @endif
                                        <x-ui.field :label="__('Temperatura')" for="aip-{{ $process }}-temp">
                                            <input id="aip-{{ $process }}-temp" type="number" step="0.1" min="0" max="2" inputmode="decimal" wire:model="rows.{{ $process }}.temperature" placeholder="0.3" class="ui-input">
                                        </x-ui.field>
                                        <x-ui.field label="max_tokens" for="aip-{{ $process }}-max">
                                            <input id="aip-{{ $process }}-max" type="number" min="1" @if ($p->maxOutput() !== null) max="{{ $p->maxOutput() }}" @endif inputmode="numeric" wire:model="rows.{{ $process }}.max_tokens" placeholder="500" class="ui-input">
                                        </x-ui.field>
                                        <x-ui.field label="timeout (s)" for="aip-{{ $process }}-timeout">
                                            <input id="aip-{{ $process }}-timeout" type="number" min="1" max="120" inputmode="numeric" wire:model="rows.{{ $process }}.timeout" placeholder="30" class="ui-input">
                                        </x-ui.field>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ui-card-f ui-card-f--end">
                <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled">{{ __('Guardar') }}</x-ui.button>
            </div>
        </form>
    @endif
</div>
