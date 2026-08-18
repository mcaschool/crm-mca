<div class="py-10">
    <x-ui.styles />
    <div class="mca-panel max-w-3xl mx-auto sm:px-6 lg:px-8 px-4">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('advisors.index') }}" class="btn btn-ghost btn-sm" title="Volver"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ $editing ? 'Configurar asesor' : 'Crear asesor' }}</h1>
                    <p class="mca-sub">Identidad, tipo, foto{{ $editing ? ', proceso de IA y conocimiento' : ' y proceso de IA' }}.</p>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif
        @if (session('status_error'))
            <div class="mca-toast err fade"><x-ui.icon name="x" class="ic" /> {{ session('status_error') }}</div>
        @endif

        {{-- Identidad --}}
        <div class="card card-p fade">
            <div style="display:flex;flex-wrap:wrap;gap:24px">
                {{-- Avatar (solo en edicion; en creacion se sube tras guardar) --}}
                <div style="text-align:center">
                    <span class="mca-av lg" style="margin:0 auto">
                        @if ($avatar)
                            <img src="{{ $avatar->temporaryUrl() }}" alt="preview">
                        @elseif ($avatarUrl)
                            <img src="{{ $avatarUrl }}" alt="{{ $name }}">
                        @else
                            <x-ui.icon name="graduation-cap" />
                        @endif
                    </span>
                    @if ($editing)
                        <div style="margin-top:10px;display:flex;flex-direction:column;align-items:center;gap:6px">
                            <label class="mca-filebtn">
                                <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> Elegir foto
                                <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                            </label>
                            <span wire:loading wire:target="avatar" class="mca-help"><span class="mca-spin"></span> Cargando…</span>
                            @error('avatar') <span class="mca-err">{{ $message }}</span> @enderror
                            <div style="display:flex;gap:6px">
                                @if ($avatar)
                                    <button type="button" wire:click="saveAvatar" class="btn btn-primary btn-sm">Guardar foto</button>
                                @endif
                                @if ($avatarUrl)
                                    <button type="button" wire:click="removeAvatar" class="btn btn-ghost btn-sm">Quitar</button>
                                @endif
                            </div>
                            <span class="mca-help">PNG, JPG, SVG o WebP · máx 1 MB</span>
                        </div>
                    @else
                        <span class="mca-help" style="display:block;margin-top:8px;max-width:9rem">La foto se sube al guardar</span>
                    @endif
                </div>

                {{-- Campos --}}
                <div style="flex:1;min-width:240px">
                    <div class="field">
                        <label>Nombre del asesor</label>
                        <input type="text" wire:model="name" maxlength="60" placeholder="Ej. Celia">
                        @error('name') <span class="mca-err">{{ $message }}</span> @enderror
                        <div class="mca-help">El widget y los saludos leen este valor.</div>
                    </div>

                    <div class="field">
                        <label>Tipo</label>
                        <div class="mca-seg">
                            <button type="button" wire:click="$set('type','ia')" class="{{ $type === 'ia' ? 'active' : '' }}">
                                <x-ui.icon name="sparkles" class="ic" style="width:15px;height:15px" /> IA
                            </button>
                            <button type="button" wire:click="$set('type','human')" class="{{ $type === 'human' ? 'active' : '' }}">
                                <x-ui.icon name="user" class="ic" style="width:15px;height:15px" /> Humano
                            </button>
                        </div>
                        <div class="mca-help">“IA” opera hoy. “Humano” queda como ficha etiqueta para intervención en vivo (futuro).</div>
                    </div>

                    <div class="field">
                        <label>Estado</label>
                        <div class="mca-seg">
                            <button type="button" wire:click="$set('status','active')" class="{{ $status === 'active' ? 'active' : '' }}">Activo</button>
                            <button type="button" wire:click="$set('status','inactive')" class="{{ $status === 'inactive' ? 'active' : '' }}">Inactivo</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Configuración de IA (solo tipo IA) --}}
        @if ($type === 'ia')
            <div class="card card-p fade" style="margin-top:16px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3>Configuración de IA</h3>
                    <p class="mca-sub">Proceso de conversación e idioma. Las credenciales se gestionan en
                        <a href="{{ route('integrations.index') }}" style="color:var(--mca);font-weight:600">Integraciones</a> (no se duplican aquí).</p>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:16px">
                    <div class="field" style="flex:1;min-width:160px;margin-bottom:0">
                        <label>Idioma principal</label>
                        <select wire:model="language">
                            <option value="es">Español</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                    <div class="field" style="flex:1;min-width:200px;margin-bottom:0">
                        <label>Proveedor (integración)</label>
                        <select wire:model="integrationId">
                            <option value="">— Elegir —</option>
                            @foreach ($integrations as $int)
                                <option value="{{ $int->id }}">{{ $int->name }} ({{ $int->provider }})</option>
                            @endforeach
                        </select>
                        @if ($integrations->isEmpty())
                            <div class="mca-help">No hay proveedores de IA. <a href="{{ route('integrations.index') }}" style="color:var(--mca)">Configura uno</a>.</div>
                        @endif
                    </div>
                    <div class="field" style="flex:1;min-width:160px;margin-bottom:0">
                        <label>Modelo</label>
                        <input type="text" wire:model="model" maxlength="100" placeholder="Ej. qwen3.7-plus">
                    </div>
                </div>
            </div>
        @endif

        {{-- Guardar --}}
        <div style="margin-top:18px;display:flex;align-items:center;gap:12px">
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">
                <span wire:loading.remove wire:target="save"><x-ui.icon name="check" class="ic" style="width:16px;height:16px" /> {{ $editing ? 'Guardar cambios' : 'Crear asesor' }}</span>
                <span wire:loading wire:target="save"><span class="mca-spin"></span> Guardando…</span>
            </button>
            <a href="{{ route('advisors.index') }}" class="btn btn-ghost">Cancelar</a>
        </div>

        {{-- Base de conocimiento (solo IA en edicion) --}}
        @if ($editing && $type === 'ia')
            <div class="card card-p fade" style="margin-top:22px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                    <div>
                        <h3>Base de conocimiento</h3>
                        <p class="mca-sub" style="margin-bottom:0">Sube uno o varios .md: se hace upsert por código y se sincroniza solo.</p>
                    </div>
                    <button type="button" wire:click="sync" wire:loading.attr="disabled" wire:target="sync" class="btn btn-ghost btn-sm">
                        <span wire:loading.remove wire:target="sync"><x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> Re-sincronizar</span>
                        <span wire:loading wire:target="sync"><span class="mca-spin"></span> …</span>
                    </button>
                </div>

                <div class="mca-drop" style="margin-bottom:14px">
                    <label class="mca-filebtn"><x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> Elegir archivos .md
                        <input type="file" wire:model="docs" accept=".md" multiple class="hidden">
                    </label>
                    <span wire:loading wire:target="docs" class="mca-help" style="margin-left:8px"><span class="mca-spin"></span> Cargando…</span>
                    @error('docs') <div class="mca-err">{{ $message }}</div> @enderror
                    @if (count($docs))
                        <div style="margin-top:12px">
                            <div class="mca-help">{{ count($docs) }} archivo(s) listo(s):</div>
                            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px" class="mca-muted">
                                @foreach ($docs as $d)<li>{{ $d->getClientOriginalName() }}</li>@endforeach
                            </ul>
                            <button type="button" wire:click="uploadKnowledge" class="btn btn-primary btn-sm" style="margin-top:10px">Subir y sincronizar</button>
                        </div>
                    @endif
                </div>

                @forelse ($sources as $source)
                    <div class="mca-doc fade" wire:key="ks-{{ $source->id }}">
                        <span class="di"><x-ui.icon name="file-text" class="ic" style="width:18px;height:18px" /></span>
                        <div class="dm">
                            <b>{{ $source->name }}</b>
                            <span>{{ $source->code }} · {{ $source->last_synced_at ? 'sincronizado '.$source->last_synced_at->diffForHumans() : 'sin sincronizar' }}</span>
                        </div>
                        <button type="button" wire:click="removeKnowledge({{ $source->id }})"
                                wire:confirm="¿Quitar este documento de conocimiento?" class="btn btn-danger btn-sm" title="Quitar">
                            <x-ui.icon name="trash" class="ic" style="width:15px;height:15px" />
                        </button>
                    </div>
                @empty
                    <div class="mca-help">Sin documentos. Sube uno o varios .md para cargar el conocimiento del asesor.</div>
                @endforelse
            </div>
        @endif

        {{-- Incrustar widget (solo edicion): snippet listo para pegar en la web publica --}}
        @if ($editing && $embedSnippet)
            <div class="card card-p fade" style="margin-top:22px"
                 x-data="{
                     copied: false,
                     snippet: @js($embedSnippet),
                     done() { this.copied = true; setTimeout(() => this.copied = false, 2000) },
                     copy() {
                         if (navigator.clipboard && window.isSecureContext) {
                             navigator.clipboard.writeText(this.snippet).then(() => this.done()).catch(() => this.fallback());
                         } else {
                             this.fallback();
                         }
                     },
                     fallback() {
                         const ta = document.createElement('textarea');
                         ta.value = this.snippet; ta.style.position = 'fixed'; ta.style.opacity = '0';
                         document.body.appendChild(ta); ta.focus(); ta.select();
                         try { document.execCommand('copy'); this.done(); } catch (e) {}
                         document.body.removeChild(ta);
                     }
                 }">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="globe" class="ic" style="width:17px;height:17px;vertical-align:-3px" /> Incrustar widget</h3>
                    <p class="mca-sub" style="margin-bottom:14px">Copia este código y pégalo en la web pública, justo antes de <code>&lt;/body&gt;</code>. Lleva la <b>clave pública</b> de este asesor; no contiene secretos.</p>
                </div>

                <div style="position:relative">
                    <pre style="background:var(--mca-blue-deep,#13253D);color:#E7EEF7;border-radius:12px;padding:16px 16px 16px 18px;margin:0;font-size:12.5px;line-height:1.55;overflow-x:auto;white-space:pre;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"><code>{{ $embedSnippet }}</code></pre>
                    <button type="button" @click="copy()"
                            class="btn btn-primary btn-sm"
                            style="position:absolute;top:10px;right:10px"
                            :class="{ 'btn-ok': copied }">
                        <span x-show="!copied"><x-ui.icon name="file-text" class="ic" style="width:14px;height:14px" /> Copiar</span>
                        <span x-show="copied" x-cloak><x-ui.icon name="check" class="ic" style="width:14px;height:14px" /> ¡Copiado!</span>
                    </button>
                </div>

                <div class="mca-help" style="margin-top:12px">
                    Clave pública de <b>{{ $bot->assistant_name }}</b>: <code>{{ $bot->public_key }}</code>. El widget se sirve desde <code>{{ config('crm.widget_embed_url') }}</code>.
                </div>
            </div>
        @endif

        {{-- Zona de peligro: eliminar (solo edicion) --}}
        @if ($editing)
            <div class="mca-danger fade" style="margin-top:22px">
                <h3>Eliminar asesor</h3>
                @if ($deleteBlockReason)
                    <p class="mca-sub" style="margin:0 0 4px">El borrado es <b>permanente</b> y distinto de desactivar.</p>
                    <div class="mca-toast err" style="margin:10px 0 0"><x-ui.icon name="x" class="ic" /> {{ $deleteBlockReason }}</div>
                @else
                    <p class="mca-sub" style="margin:0 0 12px">Borra permanentemente el asesor y su configuración (conocimiento y proceso). El histórico de conversaciones/leads/eventos NO se borra. Esta acción no se puede deshacer.</p>
                    <button type="button" wire:click="confirmDelete" class="btn btn-danger">
                        <x-ui.icon name="trash" class="ic" style="width:16px;height:16px" /> Eliminar asesor
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- Modal de confirmacion (exige teclear el nombre exacto) --}}
    @if ($confirmingDelete && $bot)
        <div class="mca-panel">
            <div class="mca-modal-bg" wire:key="del-modal">
                <div class="mca-modal">
                    <div class="mm-ic"><x-ui.icon name="trash" class="ic" style="width:22px;height:22px" /></div>
                    <h2>Eliminar a {{ $bot->assistant_name }}</h2>
                    <p>Esta acción es <b>permanente</b> y no se puede deshacer. Se borrarán el asesor, su foto, su base de conocimiento (archivos incluidos) y su configuración de IA.</p>
                    <div class="warn">El histórico de conversaciones, leads y eventos NO se borra.</div>
                    <div class="field">
                        <label class="mca-lbl">Escribe <b>{{ $bot->assistant_name }}</b> para confirmar</label>
                        <input type="text" wire:model.live="deleteConfirmName" placeholder="{{ $bot->assistant_name }}" autocomplete="off">
                        @error('deleteConfirmName') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
                        <button type="button" wire:click="cancelDelete" class="btn btn-ghost">Cancelar</button>
                        <button type="button" wire:click="deleteAdvisor" @disabled(! $deleteNameMatches) class="btn btn-danger-solid">
                            <x-ui.icon name="trash" class="ic" style="width:15px;height:15px" /> Eliminar definitivamente
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
