<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <a href="{{ route('catalog.programs.index') }}" class="btn btn-ghost btn-sm" style="margin-bottom:14px">
            <x-ui.icon name="chevron-left" class="ic" style="width:15px;height:15px" /> {{ __('Volver al catálogo') }}
        </a>

        <div style="max-width:900px">
            <h2 style="font-size:18px;font-weight:700;color:var(--ink);margin:0 0 4px">{{ __('Importar programas') }}</h2>
            <div style="font-size:12.5px;color:var(--mca-ink-3,#8A99B2);margin:0 0 16px">
                {{ __('Archivo .xlsx o .csv con tres columnas: course_id, Nombre del Programa y Categoría (de formación). La llave es course_id: si existe, se actualiza; si no, se crea. Las categorías nuevas se crean solas.') }}
            </div>

            {{-- ETAPA 1 · SUBIR --}}
            @if ($stage === 'upload')
                <div class="card card-p fade">
                    <label class="field" style="margin-bottom:0">
                        <span style="font-weight:600;font-size:13px;color:var(--ink);display:block;margin-bottom:8px">{{ __('Selecciona el archivo') }}</span>
                        <input type="file" wire:model="file" accept=".xlsx,.csv">
                    </label>
                    <div wire:loading wire:target="file" style="font-size:12.5px;color:var(--muted);margin-top:10px">
                        <span class="mca-spin"></span> {{ __('Analizando archivo…') }}
                    </div>
                    @error('file') <div class="mca-toast err" style="margin-top:10px"><x-ui.icon name="x" class="ic" /> {{ $message }}</div> @enderror
                </div>
            @endif

            {{-- ETAPA 2 · VISTA PREVIA --}}
            @if ($stage === 'preview')
                @php $c = $preview['counts']; @endphp
                <div class="card card-p fade">
                    {{-- Resumen en números --}}
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:6px">
                        <span class="badge badge-on">{{ $c['create'] }} {{ __('nuevos') }}</span>
                        <span class="badge" style="background:#EAF1FA;color:var(--mca-blue)">{{ $c['update'] }} {{ __('a actualizar') }}</span>
                        <span class="badge" style="background:#FBF4DE;color:#B08A22">{{ $c['new_categories'] }} {{ __('categoría(s) nueva(s)') }}</span>
                        @if ($c['error'] > 0)
                            <span class="badge badge-off" style="background:#FBEAEA;color:#B23B3B">{{ $c['error'] }} {{ __('con error') }}</span>
                        @endif
                    </div>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:16px">{{ __('Nada se ha aplicado todavía. Revisa el detalle y confirma abajo.') }}</div>

                    {{-- Categorías nuevas --}}
                    @if (! empty($preview['new_categories']))
                        <div class="import-block">
                            <div class="import-h">{{ __('Categorías de formación que se crearán') }}</div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                @foreach ($preview['new_categories'] as $cat)
                                    <span class="badge" style="background:#FBF4DE;color:#B08A22">{{ $cat }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- A crear --}}
                    @if (! empty($preview['creates']))
                        <div class="import-block">
                            <div class="import-h">{{ __('Se crearán') }} ({{ count($preview['creates']) }})</div>
                            <table>
                                <thead><tr><th>course_id</th><th>{{ __('Nombre') }}</th><th>{{ __('Categoría') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($preview['creates'] as $r)
                                        <tr>
                                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $r['course_id'] }}</td>
                                            <td class="t-strong">{{ $r['name'] }}</td>
                                            <td class="t-mut">{{ $r['category'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- A actualizar --}}
                    @if (! empty($preview['updates']))
                        <div class="import-block">
                            <div class="import-h">{{ __('Se actualizarán') }} ({{ count($preview['updates']) }})</div>
                            <table>
                                <thead><tr><th>course_id</th><th>{{ __('Nombre') }}</th><th>{{ __('Cambios') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($preview['updates'] as $r)
                                        <tr>
                                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $r['course_id'] }}</td>
                                            <td class="t-strong">{{ $r['name'] }}</td>
                                            <td style="font-size:12.5px">
                                                @if (empty($r['changes']))
                                                    <span class="t-mut">{{ __('sin cambios') }}</span>
                                                @else
                                                    @isset($r['changes']['name'])
                                                        <div>{{ __('Nombre') }}: <span class="t-mut">{{ $r['changes']['name']['from'] }}</span> → <b>{{ $r['changes']['name']['to'] }}</b></div>
                                                    @endisset
                                                    @isset($r['changes']['category'])
                                                        <div>{{ __('Categoría') }}: <span class="t-mut">{{ $r['changes']['category']['from'] }}</span> → <b>{{ $r['changes']['category']['to'] }}</b></div>
                                                    @endisset
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- Errores --}}
                    @if (! empty($preview['errors']))
                        <div class="import-block">
                            <div class="import-h" style="color:#B23B3B">{{ __('Filas con error (no se importarán)') }} ({{ count($preview['errors']) }})</div>
                            <table>
                                <thead><tr><th>{{ __('Fila') }}</th><th>course_id</th><th>{{ __('Motivo') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($preview['errors'] as $e)
                                        <tr>
                                            <td>{{ $e['row'] }}</td>
                                            <td style="font-family:ui-monospace,monospace;font-size:12px">{{ $e['course_id'] ?: '—' }}</td>
                                            <td style="color:#B23B3B;font-size:12.5px">{{ $e['reason'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div style="display:flex;gap:10px;margin-top:18px">
                        <button type="button" class="btn btn-primary" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                            @if ($c['create'] === 0 && $c['update'] === 0) disabled @endif>
                            <x-ui.icon name="check" class="ic" style="width:15px;height:15px" /> {{ __('Confirmar e importar') }}
                            <span wire:loading wire:target="confirm">…</span>
                        </button>
                        <button type="button" class="btn btn-ghost" wire:click="startOver">{{ __('Cancelar') }}</button>
                    </div>
                </div>
            @endif

            {{-- ETAPA 3 · RESUMEN FINAL --}}
            @if ($stage === 'done')
                <div class="card card-p fade">
                    <div class="mca-toast ok" style="margin-bottom:14px"><x-ui.icon name="check" class="ic" /> {{ __('Importación aplicada.') }}</div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px">
                        <span class="badge badge-on">{{ $result['created'] ?? 0 }} {{ __('creados') }}</span>
                        <span class="badge" style="background:#EAF1FA;color:var(--mca-blue)">{{ $result['updated'] ?? 0 }} {{ __('actualizados') }}</span>
                        <span class="badge" style="background:#FBF4DE;color:#B08A22">{{ $result['new_categories'] ?? 0 }} {{ __('categorías nuevas') }}</span>
                        @if (($result['skipped'] ?? 0) > 0)
                            <span class="badge badge-off">{{ $result['skipped'] }} {{ __('omitidos') }}</span>
                        @endif
                    </div>
                    <div style="display:flex;gap:10px;margin-top:18px">
                        <a href="{{ route('catalog.programs.index') }}" class="btn btn-primary btn-sm">{{ __('Ver catálogo') }}</a>
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="startOver">{{ __('Importar otro archivo') }}</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <style>
        .import-block{border-top:1px solid var(--line);margin-top:16px;padding-top:14px}
        .import-block:first-of-type{border-top:none;margin-top:4px;padding-top:0}
        .import-h{font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:10px}
    </style>
</div>
