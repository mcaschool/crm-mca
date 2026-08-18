<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-toolbar">
            <p class="mca-sub" style="margin:0">{{ __('Hechos transversales que Celia responde con sus palabras. Las reglas de conducta no se editan aquí.') }}</p>
            <div class="sp"></div>
            <button type="button" wire:click="sync" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                <span wire:loading.remove wire:target="sync"><x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> {{ __('Sincronizar') }}</span>
                <span wire:loading wire:target="sync"><span class="mca-spin"></span> {{ __('Sincronizando…') }}</span>
            </button>
        </div>

        @if (session('status'))
            <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="card" style="overflow:hidden">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Código') }}</th>
                            <th>{{ __('Nombre') }}</th>
                            <th>{{ __('Categoría') }}</th>
                            <th>{{ __('Prioridad') }}</th>
                            <th>{{ __('Estado') }}</th>
                            <th>{{ __('Sincronizado') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sources as $source)
                            <tr wire:key="ks-{{ $source->id }}">
                                <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $source->code }}</td>
                                <td class="t-strong">{{ $source->name }}</td>
                                <td class="t-mut">{{ $source->category ?? '—' }}</td>
                                <td class="t-mut">{{ $source->priority }}</td>
                                <td><span class="badge {{ $source->status === 'active' ? 'badge-on' : 'badge-off' }}">{{ __($source->status) }}</span></td>
                                <td class="t-mut">{{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : __('nunca') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="t-empty">{{ __('Sin fuentes de conocimiento. Coloca archivos .md en storage/app/knowledge y pulsa Sincronizar.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
