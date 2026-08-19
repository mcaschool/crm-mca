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
                                <tr wire:key="proc-{{ $process }}">
                                    <td class="t-strong">{{ $process }}</td>
                                    <td>
                                        <select wire:model="rows.{{ $process }}.integration_id" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff">
                                            <option value="">{{ __('— sin asignar —') }}</option>
                                            @foreach ($aiIntegrations as $ai)
                                                <option value="{{ $ai->id }}">{{ $ai->name }} ({{ $ai->provider }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" wire:model="rows.{{ $process }}.model" placeholder="p. ej. gpt-5-mini" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;font-family:inherit;color:var(--ink);background:#fff">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div style="margin-top:18px"><button type="submit" class="btn btn-primary">{{ __('Guardar') }}</button></div>
                </form>
            @endif
        </div>
    </div>
</div>
