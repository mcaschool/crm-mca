<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px">
        <div class="mca-head" style="justify-content:flex-end">
            @if ($canManage)
                <a href="{{ route('advisors.create') }}" class="btn btn-primary">
                    <x-ui.icon name="plus" /> Crear asesor
                </a>
            @endif
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        <div class="mca-grid">
            @foreach ($advisors as $a)
                <div class="card card-p fade" wire:key="adv-{{ $a['id'] }}">
                    <div style="display:flex;align-items:center;gap:14px">
                        <span class="mca-av">
                            @if ($a['avatar'])
                                <img src="{{ $a['avatar'] }}" alt="{{ $a['name'] }}">
                            @else
                                <x-ui.icon name="graduation-cap" />
                            @endif
                        </span>
                        <div style="min-width:0;flex:1">
                            <div style="font-size:16px;font-weight:700;color:var(--ink);line-height:1.2">{{ $a['name'] }}</div>
                            <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
                                @if ($a['type'] === 'human')
                                    <span class="badge badge-human"><x-ui.icon name="user" class="ic" style="width:13px;height:13px" /> Humano</span>
                                @else
                                    <span class="badge badge-ai"><x-ui.icon name="sparkles" class="ic" style="width:13px;height:13px" /> IA</span>
                                @endif
                                <span class="badge {{ $a['status'] === 'active' ? 'badge-on' : 'badge-off' }}">
                                    {{ $a['status'] === 'active' ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:16px;margin-top:16px;flex-wrap:wrap;font-size:13px" class="mca-muted">
                        <span title="Idioma"><x-ui.icon name="globe" class="ic" style="width:15px;height:15px" /> {{ $a['language'] }}</span>
                        @if ($a['type'] !== 'human')
                            <span title="Modelo"><x-ui.icon name="cpu" class="ic" style="width:15px;height:15px" /> {{ $a['model'] ?? 'sin proceso' }}</span>
                        @endif
                        <span title="Documentos de conocimiento"><x-ui.icon name="file-text" class="ic" style="width:15px;height:15px" /> {{ $a['docs'] }} {{ $a['docs'] === 1 ? 'documento' : 'documentos' }}</span>
                    </div>

                    @if ($canManage)
                        <div style="margin-top:18px;display:flex;gap:8px">
                            <a href="{{ route('advisors.edit', $a['id']) }}" class="btn btn-ghost btn-sm">
                                <x-ui.icon name="pencil" class="ic" style="width:15px;height:15px" /> Configurar
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
