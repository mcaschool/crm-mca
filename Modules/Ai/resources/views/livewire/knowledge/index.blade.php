<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        {{-- Aviso: esta vista es solo de consulta; la gestión completa está en el Centro. --}}
        <div style="display:flex;gap:10px;align-items:flex-start;background:#eef4fb;border:1px solid #c8daf0;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:13.5px">
            <x-ui.icon name="book-open" class="ic" style="width:18px;height:18px;color:var(--mca,#1E5AA8);flex:none;margin-top:1px" />
            <div>
                <strong>{{ __('Vista de solo consulta.') }}</strong>
                {{ __('La gestión completa del conocimiento (subir, activar, borrar y asignar fuentes a agentes) está en el') }}
                <a href="{{ route('ai.knowledge.library') }}" style="color:var(--mca,#1E5AA8);font-weight:600">{{ __('Centro de Conocimiento') }}</a>.
            </div>
        </div>

        <div class="mca-toolbar">
            <livewire:ai.advisor-selector />
            <p class="mca-sub" style="margin:0 0 0 12px">{{ __('Hechos transversales que el agente responde con sus palabras. Las reglas de conducta no se editan aquí.') }}</p>
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
