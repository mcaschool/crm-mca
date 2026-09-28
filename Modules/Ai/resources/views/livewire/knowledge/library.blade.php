<div>
    <x-ui.styles />

    <div class="mca-head">
        <div>
            <h1 class="mca-h1">{{ __('Centro de Conocimiento') }}</h1>
            <p class="mca-sub">{{ __('Biblioteca central de fuentes de conocimiento, compartible entre agentes.') }}</p>
        </div>
    </div>

    @include('ai::livewire.knowledge._tabs')

    @if (session('status'))
        <div class="mca-toast ok"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
    @endif

    {{-- 1) Resumen: total, activas, inactivas y por categoría (clic = filtrar) --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:16px">
        <button type="button" wire:click="clearFilters" class="card" style="text-align:left;padding:14px 16px;cursor:pointer;border:1px solid var(--line)">
            <div class="t-mut" style="font-size:12px">{{ __('Total de fuentes') }}</div>
            <div class="t-strong" style="font-size:24px">{{ $summary['total'] }}</div>
        </button>
        <button type="button" wire:click="$set('filterStatus','active')" class="card" style="text-align:left;padding:14px 16px;cursor:pointer;border:1px solid var(--line)">
            <div class="t-mut" style="font-size:12px">{{ __('Activas') }}</div>
            <div class="t-strong" style="font-size:24px;color:#1a7f4b">{{ $summary['active'] }}</div>
        </button>
        <button type="button" wire:click="$set('filterStatus','inactive')" class="card" style="text-align:left;padding:14px 16px;cursor:pointer;border:1px solid var(--line)">
            <div class="t-mut" style="font-size:12px">{{ __('Inactivas') }}</div>
            <div class="t-strong" style="font-size:24px;color:#9a6b00">{{ $summary['inactive'] }}</div>
        </button>
        @foreach ($byCategory as $cat => $count)
            <button type="button" wire:click="filterByCategory('{{ $cat ?? 'sin_categoria' }}')" class="card" style="text-align:left;padding:14px 16px;cursor:pointer;border:1px solid var(--line)">
                <div class="t-mut" style="font-size:12px;text-transform:capitalize">{{ $cat ? str_replace('_', ' ', $cat) : __('Sin categoría') }}</div>
                <div class="t-strong" style="font-size:24px">{{ $count }}</div>
            </button>
        @endforeach
    </div>

    {{-- 3/6) Subida + Sincronizar --}}
    <div class="card" style="padding:16px 18px;margin-bottom:16px">
        <div class="mca-toolbar" style="align-items:center">
            <div>
                <div class="t-strong">{{ __('Subir conocimiento (.md)') }}</div>
                <div class="t-mut" style="font-size:12.5px">{{ __('Cada archivo requiere comentario con «Codigo», título «# » y al menos una sección «## ». Máx. 512 KB.') }}</div>
            </div>
            <div class="sp"></div>
            <button type="button" wire:click="syncLibrary" wire:loading.attr="disabled" class="btn btn-sm">
                <span wire:loading.remove wire:target="syncLibrary"><x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> {{ __('Sincronizar biblioteca') }}</span>
                <span wire:loading wire:target="syncLibrary"><span class="mca-spin"></span> {{ __('Sincronizando…') }}</span>
            </button>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap">
            <input type="file" wire:model="docs" accept=".md" multiple
                style="font-size:13px;border:1px solid var(--line);border-radius:8px;padding:7px 9px;background:#fff">
            <button type="button" wire:click="uploadDocs" wire:loading.attr="disabled" wire:target="uploadDocs,docs" class="btn btn-primary btn-sm" @disabled(empty($docs))>
                <span wire:loading.remove wire:target="uploadDocs"><x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> {{ __('Subir y sincronizar') }}</span>
                <span wire:loading wire:target="uploadDocs"><span class="mca-spin"></span> {{ __('Procesando…') }}</span>
            </button>
            <span wire:loading wire:target="docs" class="t-mut" style="font-size:12px">{{ __('Cargando archivos…') }}</span>
        </div>
        @error('docs') <div class="t-mut" style="color:#b42318;font-size:12.5px;margin-top:8px">{{ $message }}</div> @enderror

        {{-- Resultado por archivo --}}
        @if ($uploadResults !== [])
            <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
                <div class="t-strong" style="font-size:13px;margin-bottom:8px">{{ __('Resultado de la subida') }}</div>
                @foreach ($uploadResults as $r)
                    @php $ok = $r['result'] !== 'Rechazado'; @endphp
                    <div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;font-size:13px">
                        <x-ui.icon name="{{ $ok ? 'check' : 'alert-triangle' }}" class="ic" style="width:15px;height:15px;margin-top:2px;color:{{ $ok ? '#1a7f4b' : '#b42318' }}" />
                        <div>
                            <span style="font-family:ui-monospace,monospace">{{ $r['file'] }}</span>
                            — <strong style="color:{{ $ok ? '#1a7f4b' : '#b42318' }}">{{ $r['result'] }}</strong>
                            <span class="t-mut">· {{ $r['reason'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- 2) Filtros + búsqueda --}}
    <div class="mca-toolbar" style="margin-bottom:10px;gap:8px;flex-wrap:wrap">
        <div style="position:relative">
            <input type="text" wire:model.live.debounce.350ms="search" placeholder="{{ __('Buscar por código o nombre…') }}"
                style="font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px 10px 8px 30px;min-width:240px">
            <x-ui.icon name="search" class="ic" style="width:15px;height:15px;position:absolute;left:9px;top:9px;color:var(--muted)" />
        </div>
        <select wire:model.live="filterCategory" style="font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px 10px">
            <option value="">{{ __('Todas las categorías') }}</option>
            @foreach ($byCategory as $cat => $count)
                <option value="{{ $cat ?? 'sin_categoria' }}">{{ $cat ? str_replace('_', ' ', $cat) : __('Sin categoría') }} ({{ $count }})</option>
            @endforeach
        </select>
        <select wire:model.live="filterStatus" style="font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px 10px">
            <option value="">{{ __('Todos los estados') }}</option>
            <option value="active">{{ __('Activas') }}</option>
            <option value="inactive">{{ __('Inactivas') }}</option>
        </select>
        @if ($search !== '' || $filterCategory !== '' || $filterStatus !== '')
            <button type="button" wire:click="clearFilters" class="btn btn-sm"><x-ui.icon name="x" class="ic" style="width:14px;height:14px" /> {{ __('Limpiar') }}</button>
        @endif
    </div>

    {{-- 2) Tabla --}}
    <div class="card" style="overflow:hidden">
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Código') }}</th>
                        <th>{{ __('Nombre') }}</th>
                        <th>{{ __('Categoría') }}</th>
                        <th>{{ __('Prioridad') }}</th>
                        <th>{{ __('Secciones') }}</th>
                        <th>{{ __('Agentes') }}</th>
                        <th>{{ __('Estado') }}</th>
                        <th>{{ __('Sincronizado') }}</th>
                        <th style="text-align:right">{{ __('Acciones') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sources as $s)
                        <tr wire:key="ks-{{ $s['id'] }}">
                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $s['code'] }}</td>
                            <td class="t-strong">{{ $s['name'] }}</td>
                            <td class="t-mut" style="text-transform:capitalize">{{ $s['category'] ? str_replace('_', ' ', $s['category']) : __('Sin categoría') }}</td>
                            <td class="t-mut">{{ $s['priority'] }}</td>
                            <td class="t-mut">{{ $s['sections'] }}</td>
                            <td class="t-mut" title="{{ $s['agents'] ? implode(', ', $s['agents']) : __('Sin agentes') }}">
                                <x-ui.icon name="users" class="ic" style="width:14px;height:14px;vertical-align:-2px" /> {{ $s['agents_count'] }}
                                @if ($s['agents'] !== [])
                                    <span style="font-size:12px"> · {{ implode(', ', array_slice($s['agents'], 0, 2)) }}@if (count($s['agents']) > 2) +{{ count($s['agents']) - 2 }}@endif</span>
                                @endif
                            </td>
                            <td><span class="badge {{ $s['status'] === 'active' ? 'badge-on' : 'badge-off' }}">{{ __($s['status']) }}</span></td>
                            <td class="t-mut">{{ $s['last_synced_at'] ? $s['last_synced_at']->diffForHumans() : __('nunca') }}</td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" wire:click="view({{ $s['id'] }})" class="btn btn-sm" title="{{ __('Ver contenido') }}"><x-ui.icon name="eye" class="ic" style="width:14px;height:14px" /></button>
                                <button type="button" wire:click="toggleStatus({{ $s['id'] }})" class="btn btn-sm" title="{{ $s['status'] === 'active' ? __('Desactivar') : __('Activar') }}">
                                    <x-ui.icon name="{{ $s['status'] === 'active' ? 'power' : 'check' }}" class="ic" style="width:14px;height:14px" />
                                </button>
                                <button type="button" wire:click="confirmDelete({{ $s['id'] }})" class="btn btn-sm" title="{{ __('Borrar') }}"><x-ui.icon name="trash" class="ic" style="width:14px;height:14px;color:#b42318" /></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="t-empty">{{ __('No hay fuentes que coincidan. Sube archivos .md o ajusta los filtros.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 4) Drawer de contenido --}}
    @if ($viewingId !== null)
        <div style="position:fixed;inset:0;background:rgba(19,37,61,.35);z-index:50" wire:click="closeDrawer"></div>
        <aside style="position:fixed;top:0;right:0;bottom:0;width:min(680px,94vw);background:#fff;z-index:51;box-shadow:-8px 0 30px rgba(0,0,0,.18);display:flex;flex-direction:column">
            <div style="display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--line)">
                <x-ui.icon name="file-text" class="ic" style="width:18px;height:18px;color:var(--mca,#1E5AA8)" />
                <strong style="flex:1">{{ $viewingName }}</strong>
                <button type="button" wire:click="closeDrawer" class="btn btn-sm"><x-ui.icon name="x" class="ic" style="width:15px;height:15px" /></button>
            </div>
            <div style="display:flex;gap:0;flex:1;min-height:0">
                @if ($viewingSections !== [])
                    <nav style="width:210px;border-right:1px solid var(--line);padding:14px;overflow:auto;background:#f8fafc">
                        <div class="t-mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">{{ __('Secciones') }}</div>
                        @foreach ($viewingSections as $sec)
                            <div class="t-mut" style="font-size:12.5px;padding:4px 0;border-bottom:1px dashed var(--line)">{{ $sec }}</div>
                        @endforeach
                    </nav>
                @endif
                <div class="prose" style="flex:1;overflow:auto;padding:18px 22px;font-size:14px;line-height:1.6">
                    {!! $viewingHtml !!}
                </div>
            </div>
        </aside>
    @endif

    {{-- 5) Modal de borrado --}}
    @if ($deletingId !== null)
        <div style="position:fixed;inset:0;background:rgba(19,37,61,.45);z-index:60;display:flex;align-items:center;justify-content:center;padding:16px" wire:click="cancelDelete">
            <div style="background:#fff;border-radius:14px;max-width:460px;width:100%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25)" wire:click.stop>
                <div style="display:flex;gap:10px;align-items:flex-start">
                    <x-ui.icon name="alert-triangle" class="ic" style="width:22px;height:22px;color:#b42318;flex:none;margin-top:2px" />
                    <div>
                        <h2 style="margin:0 0 6px;font-size:16px;font-weight:700">{{ __('Borrar fuente de conocimiento') }}</h2>
                        <p class="t-mut" style="margin:0 0 10px;font-size:13.5px">
                            {{ __('Vas a eliminar «:name». Se borrará la fila, sus asignaciones a agentes y su archivo .md. Esta acción no se puede deshacer.', ['name' => $deletingName]) }}
                        </p>
                        @if ($deletingAgents !== [])
                            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px 12px;font-size:13px">
                                <strong>{{ __('La usan :n agente(s):', ['n' => count($deletingAgents)]) }}</strong>
                                {{ implode(', ', $deletingAgents) }}
                            </div>
                        @else
                            <div class="t-mut" style="font-size:13px">{{ __('Ningún agente la tiene asignada.') }}</div>
                        @endif
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
                    <button type="button" wire:click="cancelDelete" class="btn btn-sm">{{ __('Cancelar') }}</button>
                    <button type="button" wire:click="delete" wire:loading.attr="disabled" class="btn btn-sm" style="background:#b42318;color:#fff;border-color:#b42318">
                        <span wire:loading.remove wire:target="delete"><x-ui.icon name="trash" class="ic" style="width:14px;height:14px" /> {{ __('Borrar definitivamente') }}</span>
                        <span wire:loading wire:target="delete"><span class="mca-spin"></span> {{ __('Borrando…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
