<div>
    <x-ui.styles />
    <style>.mailbody-ed:empty:before{content:attr(data-ph);color:var(--muted);pointer-events:none}.mailbody-ed img{max-width:100%;height:auto}.mailbody-ed ul{list-style:disc;padding-left:1.6em;margin:.5em 0}.mailbody-ed ol{list-style:decimal;padding-left:1.6em;margin:.5em 0}.mailbody-ed li{margin:.2em 0}[x-cloak]{display:none!important}</style>
    <div class="mca-panel" style="padding:22px 26px 34px;max-width:900px">

        @if ($scope === 'shared')
            <x-ui.settings-tabs />
        @else
            <div class="mca-head">
                <div>
                    <h1 class="mca-h1">{{ __('Mis plantillas de correo') }}</h1>
                    <p class="mca-sub">{{ __('Tus plantillas privadas para redactar más rápido. Solo tú las ves.') }}</p>
                </div>
            </div>
        @endif

        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <h2 class="mca-h1" style="font-size:16px;margin:0">{{ $scope === 'shared' ? 'Plantillas compartidas' : 'Plantillas propias' }}</h2>
                <p class="mca-sub" style="margin:4px 0 0">
                    @if ($scope === 'shared')
                        Plantillas del equipo. Cualquiera que envíe correo puede usarlas; solo un Administrador las crea, edita o borra.
                    @else
                        Tus plantillas privadas. Puedes usarlas al redactar; nadie más las ve.
                    @endif
                    Admiten etiquetas como <code>[Nombre]</code> o <code>[Área]</code>, que se rellenan al enviar.
                </p>
            </div>
            @unless ($showForm)
                <button type="button" wire:click="newTemplate" class="btn btn-primary"><x-ui.icon name="plus" /> Nueva plantilla</button>
            @endunless
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade" style="margin-top:14px"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- Formulario alta/edición --}}
        @if ($showForm)
            <div class="card card-p fade" style="margin-top:16px">
                <h3 style="margin:0 0 14px;font-size:15px;font-weight:700">{{ $editingId ? 'Editar plantilla' : 'Nueva plantilla' }}</h3>

                <div class="field">
                    <label>Nombre de la plantilla</label>
                    <input type="text" wire:model="name" maxlength="150" placeholder="Ej. Bienvenida a Microcredenciales">
                    @error('name') <span class="mca-err">{{ $message }}</span> @enderror
                    <div class="mca-help">Solo para elegirla en la lista; no se envía.</div>
                </div>

                <div class="field">
                    <label>Asunto</label>
                    <input type="text" wire:model="subject" maxlength="200" placeholder="Ej. Hola [Nombre], sobre [Área]">
                    @error('subject') <span class="mca-err">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label>Cuerpo</label>
                    {{-- Editor enriquecido (mismo del compositor): formato + tipografía + tamaño + imágenes + etiquetas. --}}
                    <div wire:ignore
                        x-data="{ tagsOpen: false, savedRange: null,
                            caret(ed){ ed.focus(); const s=window.getSelection(); if(!s.rangeCount || !ed.contains(s.anchorNode)){ const r=document.createRange(); r.selectNodeContents(ed); r.collapse(false); s.removeAllRanges(); s.addRange(r);} },
                            saveSel(){ const s=window.getSelection(); if(s.rangeCount && this.$refs.ed.contains(s.anchorNode)) this.savedRange = s.getRangeAt(0).cloneRange(); },
                            restore(){ const ed=this.$refs.ed; ed.focus(); if(this.savedRange){ const s=window.getSelection(); s.removeAllRanges(); s.addRange(this.savedRange); } },
                            applyFont(f){ if(!f) return; this.restore(); document.execCommand('fontName',false,f); this.$refs.ed.dispatchEvent(new Event('input')); },
                            applySize(v){ if(!v) return; this.restore(); document.execCommand('fontSize',false,v); this.$refs.ed.dispatchEvent(new Event('input')); },
                            insertTag(t){ const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertText',false,'['+t+']'); ed.dispatchEvent(new Event('input')); this.tagsOpen=false; },
                            insertImage(d){ if(!d||!d.url||!d.cid) return; const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertHTML',false,'<img src=\''+d.url+'\' data-cid=\''+d.cid+'\' style=\'max-width:100%;height:auto\'><br>'); ed.dispatchEvent(new Event('input')); },
                            load(html){ this.$refs.ed.innerHTML = html || ''; this.$refs.ed.dispatchEvent(new Event('input')); this.mode='visual'; },
                            mode:'visual', toCode(){ this.$refs.code.value=this.$refs.ed.innerHTML; this.mode='code'; this.$nextTick(()=>this.preview()); }, toVisual(){ this.$refs.ed.innerHTML=this.$refs.code.value; this.$refs.ed.dispatchEvent(new Event('input')); this.mode='visual'; }, preview(){ if(this.$refs.pv) this.$refs.pv.srcdoc=this.$refs.code.value; } }"
                        x-on:insert-inline-image.window="insertImage($event.detail)"
                        x-on:template-editor-load.window="load($event.detail.html)"
                        style="border:1px solid var(--line);border-radius:10px;overflow:hidden">
                        <div style="display:flex;gap:2px;padding:6px 8px;border-bottom:1px solid var(--line);background:var(--soft,#F1F6FC);flex-wrap:wrap;align-items:center">
                            @php $tbtn = 'min-width:30px;height:28px;padding:0 8px;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:13px;cursor:pointer;color:var(--ink)'; @endphp
                            <span x-show="mode==='visual'" style="display:contents">
                            <button type="button" title="Negrita" onmousedown="event.preventDefault()" x-on:click="document.execCommand('bold'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};font-weight:700">B</button>
                            <button type="button" title="Cursiva" onmousedown="event.preventDefault()" x-on:click="document.execCommand('italic'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};font-style:italic">I</button>
                            <button type="button" title="Subrayado" onmousedown="event.preventDefault()" x-on:click="document.execCommand('underline'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};text-decoration:underline">U</button>
                            <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                            <select title="Tipografía" aria-label="Tipografía"
                                x-on:mousedown="saveSel()" x-on:change="applyFont($event.target.value); $event.target.selectedIndex=0"
                                style="height:28px;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:12.5px;color:var(--ink);padding:0 6px;cursor:pointer">
                                <option value="">Fuente</option>
                                <option value="Arial" style="font-family:Arial">Arial</option>
                                <option value="Georgia" style="font-family:Georgia">Georgia</option>
                                <option value="Verdana" style="font-family:Verdana">Verdana</option>
                                <option value="Times New Roman" style="font-family:'Times New Roman'">Times New Roman</option>
                            </select>
                            <select title="Tamaño de texto" aria-label="Tamaño de texto"
                                x-on:mousedown="saveSel()" x-on:change="applySize($event.target.value); $event.target.selectedIndex=0"
                                style="height:28px;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:12.5px;color:var(--ink);padding:0 6px;cursor:pointer">
                                <option value="">Tamaño</option>
                                <option value="2">Pequeño</option>
                                <option value="3">Normal</option>
                                <option value="4">Grande</option>
                                <option value="6">Título</option>
                                <option value="7">Título grande</option>
                            </select>
                            <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                            <button type="button" title="Lista con viñetas" onmousedown="event.preventDefault()" x-on:click="document.execCommand('insertUnorderedList'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }}">• —</button>
                            <button type="button" title="Lista numerada" onmousedown="event.preventDefault()" x-on:click="document.execCommand('insertOrderedList'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }}">1.</button>
                            <button type="button" title="Insertar enlace" onmousedown="event.preventDefault()" x-on:click="let u=prompt('URL (https://…)'); if(u){document.execCommand('createLink',false,u)}; $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }}">🔗</button>
                            <button type="button" title="Quitar formato" onmousedown="event.preventDefault()" x-on:click="document.execCommand('removeFormat'); document.execCommand('unlink'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }}">✕ fmt</button>
                            <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                            <button type="button" title="Insertar imagen (viaja dentro del correo)" onmousedown="event.preventDefault()" onclick="document.getElementById('tplInlineImageInput').click()" style="{{ $tbtn }}">🖼️ Imagen</button>

                            {{-- Etiquetas dinámicas (genéricas; se resuelven al enviar) --}}
                            <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                            <div style="position:relative" x-on:click.outside="tagsOpen=false">
                                <button type="button" title="Insertar etiqueta" onmousedown="event.preventDefault()" x-on:click="tagsOpen=!tagsOpen" style="{{ $tbtn }};display:inline-flex;align-items:center;gap:4px">🏷️ Etiqueta ▾</button>
                                <div x-show="tagsOpen" x-cloak style="position:absolute;top:32px;left:0;z-index:5;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 12px 30px -12px rgba(19,37,61,.4);min-width:240px;padding:6px;max-height:240px;overflow:auto">
                                    @foreach ($tagCatalog as $t)
                                        <button type="button" onmousedown="event.preventDefault()" x-on:click="insertTag(@js($t['tag']))" style="display:flex;flex-direction:column;gap:1px;width:100%;text-align:left;background:none;border:0;border-radius:6px;padding:6px 8px;cursor:pointer">
                                            <span style="font-size:13px;font-weight:600;color:var(--ink)">[{{ $t['tag'] }}]</span>
                                            <span style="font-size:11.5px;color:var(--muted)">{{ $t['hint'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            </span>
                            @if ($canCodeMode)
                                <span x-show="mode==='visual'" style="width:1px;background:var(--line);margin:2px 4px"></span>
                                <button type="button" title="Editar el código HTML/CSS" x-show="mode==='visual'" x-on:click="toCode()" style="{{ $tbtn }};display:inline-flex;align-items:center;gap:4px;font-family:ui-monospace,Menlo,Consolas,monospace">&lt;/&gt; Código</button>
                                <button type="button" title="Volver al editor visual" x-show="mode==='code'" x-cloak x-on:click="toVisual()" style="{{ $tbtn }};display:inline-flex;align-items:center;gap:4px">◱ Editor visual</button>
                            @endif
                        </div>
                        {{-- Vista VISUAL --}}
                        <div contenteditable="true" id="tplEditorBody" x-ref="ed" x-show="mode==='visual'" class="mailbody-ed" data-ph="Escribe el contenido de la plantilla…"
                            x-on:input="$wire.set('body', $refs.ed.innerHTML, false)"
                            x-on:mouseup="saveSel()" x-on:keyup="saveSel()"
                            x-on:blur="$wire.set('body', $refs.ed.innerHTML, false)"
                            style="min-height:200px;padding:12px 14px;font-size:14px;line-height:1.55;outline:none;color:var(--ink)"></div>
                        {{-- Vista CÓDIGO: HTML editable + vista previa (iframe sandbox, sin scripts) --}}
                        @if ($canCodeMode)
                            <div x-show="mode==='code'" x-cloak style="display:flex;flex-wrap:wrap">
                                <textarea x-ref="code" spellcheck="false" placeholder="Pega o escribe HTML/CSS de diseño (tablas, estilos inline)…"
                                    x-on:input="$wire.set('body', $refs.code.value, false); preview()"
                                    style="flex:1;min-width:280px;min-height:200px;border:0;border-right:1px solid var(--line);padding:12px 14px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5;outline:none;resize:vertical;color:var(--ink);background:#fbfdff"></textarea>
                                <div style="flex:1;min-width:280px;display:flex;flex-direction:column">
                                    <div style="font-size:11px;color:var(--muted);padding:6px 12px;border-bottom:1px solid var(--line);background:var(--soft,#F1F6FC)">Vista previa</div>
                                    <iframe x-ref="pv" sandbox="" title="Vista previa" style="flex:1;min-height:173px;border:0;width:100%;background:#fff"></iframe>
                                </div>
                            </div>
                        @endif
                    </div>
                    <input type="file" id="tplInlineImageInput" wire:model="inlineUpload" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">
                    <div wire:loading wire:target="inlineUpload" class="mca-help" style="margin-top:4px"><span class="mca-spin"></span> Subiendo imagen…</div>
                    @error('inlineUpload') <span class="mca-err">{{ $message }}</span> @enderror
                    @error('body') <span class="mca-err">{{ $message }}</span> @enderror
                </div>

                <div class="field" style="max-width:220px">
                    <label>Estado</label>
                    <select wire:model="tstatus">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                    <div class="mca-help">Solo las activas aparecen al redactar.</div>
                </div>

                <div style="display:flex;gap:10px;margin-top:8px">
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary"><x-ui.icon name="check" class="ic" /> Guardar</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn btn-ghost">Cancelar</button>
                </div>
            </div>
        @endif

        {{-- Listado --}}
        <div style="margin-top:18px;display:flex;flex-direction:column;gap:12px">
            @forelse ($templates as $template)
                <div class="card card-p fade" wire:key="tpl-{{ $template->id }}">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div>
                            <div style="font-weight:700;font-size:15px;color:var(--ink)">{{ $template->name }}
                                @if ($template->status !== 'active')<span class="mca-help" style="font-weight:600">· inactiva</span>@endif
                            </div>
                            <div class="mca-help" style="margin-top:2px"><x-ui.icon name="mail" class="ic" style="width:13px;height:13px" /> {{ $template->subject }}</div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="button" wire:click="edit({{ $template->id }})" class="btn btn-ghost btn-sm"><x-ui.icon name="pencil" class="ic" style="width:14px;height:14px" /> Editar</button>
                            <button type="button" wire:click="delete({{ $template->id }})" wire:confirm="¿Eliminar la plantilla “{{ $template->name }}”?" class="btn btn-ghost btn-sm"><x-ui.icon name="trash" class="ic" style="width:14px;height:14px" /> Eliminar</button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card card-p" style="text-align:center;color:var(--muted)">
                    {{ $scope === 'shared' ? 'Aún no hay plantillas compartidas. Crea la primera con “Nueva plantilla”.' : 'Aún no tienes plantillas propias. Crea la primera con “Nueva plantilla”.' }}
                </div>
            @endforelse
        </div>
    </div>
</div>
