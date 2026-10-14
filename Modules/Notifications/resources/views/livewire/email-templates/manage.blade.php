<div class="mca-page">
    <div class="ui-narrow">
        @if ($scope === 'shared')
            <x-ui.settings-tabs />
        @else
            <x-ui.page-header :title="__('Mis plantillas de correo')" :subtitle="__('Tus plantillas privadas para redactar más rápido. Solo tú las ves.')" />
        @endif

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <section class="ui-card" aria-labelledby="tpl-title">
            <div class="ui-card-h">
                <x-ui.icon name="file-text" class="ui-card-ic" /><h2 id="tpl-title">{{ $scope === 'shared' ? __('Plantillas compartidas') : __('Plantillas propias') }}</h2>
                <x-ui.badge tone="neutral" class="ui-badge--count">{{ $templates->count() }}</x-ui.badge>
                <span class="sp"></span>
                @unless ($showForm)
                    <x-ui.button variant="primary" size="sm" icon="plus" wire:click="newTemplate">{{ __('Nueva plantilla') }}</x-ui.button>
                @endunless
            </div>
            <p class="ui-card-intro">
                @if ($scope === 'shared')
                    {{ __('Plantillas del equipo. Cualquiera que envíe correo puede usarlas; solo un Administrador las crea, edita o borra.') }}
                @else
                    {{ __('Tus plantillas privadas. Puedes usarlas al redactar; nadie más las ve.') }}
                @endif
                {{ __('Admiten etiquetas como [Nombre] o [Área], que se rellenan al enviar.') }}
            </p>

            {{-- Formulario alta/edición --}}
            @if ($showForm)
                <div class="ui-subform" role="group" aria-labelledby="tpl-form-title">
                    <div class="ui-card-h">
                        <h3 id="tpl-form-title">{{ $editingId ? __('Editar plantilla') : __('Nueva plantilla') }}</h3>
                    </div>
                    <div class="ui-card-p ui-stack">
                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Nombre de la plantilla')" for="tpl-name" :error="$errors->first('name')" :help="__('Solo para elegirla en la lista; no se envía.')">
                                <input id="tpl-name" type="text" wire:model="name" maxlength="150" placeholder="{{ __('Ej. Bienvenida a Microcredenciales') }}"
                                    @if ($errors->has('name')) aria-describedby="tpl-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Asunto')" for="tpl-subject" :error="$errors->first('subject')">
                                <input id="tpl-subject" type="text" wire:model="subject" maxlength="200" placeholder="{{ __('Ej. Hola [Nombre], sobre [Área]') }}"
                                    @if ($errors->has('subject')) aria-describedby="tpl-subject-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('subject')])>
                            </x-ui.field>
                        </div>

                        <div class="ui-field">
                            <span class="ui-label" id="tpl-body-label">{{ __('Cuerpo') }}</span>
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
                                    mode:'visual', toCode(){ this.$refs.code.value=this.$refs.ed.innerHTML; this.mode='code'; this.$nextTick(()=>this.preview()); }, toVisual(){ this.$refs.ed.innerHTML=this.$refs.code.value; this.$refs.ed.dispatchEvent(new Event('input')); this.mode='visual'; }, preview(){ if(this.$refs.pv) this.$refs.pv.srcdoc=this.$refs.code.value; }, enc(s){ return '__B64__'+btoa(unescape(encodeURIComponent(s))); } }"
                                x-on:insert-inline-image.window="insertImage($event.detail)"
                                x-on:template-editor-load.window="load($event.detail.html)"
                                class="ui-editor">
                                <div class="ui-editor-tb">
                                    <div x-show="mode==='visual'" class="ui-tb-group">
                                        <button type="button" class="ui-tb-btn" title="{{ __('Negrita') }}" aria-label="{{ __('Negrita') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('bold'); $refs.ed.dispatchEvent(new Event('input'))"><b>B</b></button>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Cursiva') }}" aria-label="{{ __('Cursiva') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('italic'); $refs.ed.dispatchEvent(new Event('input'))"><i>I</i></button>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Subrayado') }}" aria-label="{{ __('Subrayado') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('underline'); $refs.ed.dispatchEvent(new Event('input'))"><u>U</u></button>
                                        <span class="ui-tb-sep"></span>
                                        <select title="{{ __('Tipografía') }}" aria-label="{{ __('Tipografía') }}" class="ui-tb-select"
                                            x-on:mousedown="saveSel()" x-on:change="applyFont($event.target.value); $event.target.selectedIndex=0">
                                            <option value="">{{ __('Fuente') }}</option>
                                            <option value="Arial" style="font-family:Arial">Arial</option>
                                            <option value="Georgia" style="font-family:Georgia">Georgia</option>
                                            <option value="Verdana" style="font-family:Verdana">Verdana</option>
                                            <option value="Times New Roman" style="font-family:'Times New Roman'">Times New Roman</option>
                                        </select>
                                        <select title="{{ __('Tamaño de texto') }}" aria-label="{{ __('Tamaño de texto') }}" class="ui-tb-select"
                                            x-on:mousedown="saveSel()" x-on:change="applySize($event.target.value); $event.target.selectedIndex=0">
                                            <option value="">{{ __('Tamaño') }}</option>
                                            <option value="2">{{ __('Pequeño') }}</option>
                                            <option value="3">{{ __('Normal') }}</option>
                                            <option value="4">{{ __('Grande') }}</option>
                                            <option value="6">{{ __('Título') }}</option>
                                            <option value="7">{{ __('Título grande') }}</option>
                                        </select>
                                        <span class="ui-tb-sep"></span>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Lista con viñetas') }}" aria-label="{{ __('Lista con viñetas') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('insertUnorderedList'); $refs.ed.dispatchEvent(new Event('input'))">• —</button>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Lista numerada') }}" aria-label="{{ __('Lista numerada') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('insertOrderedList'); $refs.ed.dispatchEvent(new Event('input'))">1.</button>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Insertar enlace') }}" aria-label="{{ __('Insertar enlace') }}" onmousedown="event.preventDefault()" x-on:click="let u=prompt('URL (https://…)'); if(u){document.execCommand('createLink',false,u)}; $refs.ed.dispatchEvent(new Event('input'))">🔗</button>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Quitar formato') }}" aria-label="{{ __('Quitar formato') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('removeFormat'); document.execCommand('unlink'); $refs.ed.dispatchEvent(new Event('input'))">✕<span class="ui-tb-label"> fmt</span></button>
                                        <span class="ui-tb-sep"></span>
                                        <button type="button" class="ui-tb-btn" title="{{ __('Insertar imagen (viaja dentro del correo)') }}" aria-label="{{ __('Insertar imagen') }}" onmousedown="event.preventDefault()" onclick="document.getElementById('tplInlineImageInput').click()">🖼️<span class="ui-tb-label"> {{ __('Imagen') }}</span></button>

                                        {{-- Etiquetas dinámicas (genéricas; se resuelven al enviar) --}}
                                        <span class="ui-tb-sep"></span>
                                        <div class="ui-dropdown" x-on:click.outside="tagsOpen=false">
                                            <button type="button" class="ui-tb-btn" title="{{ __('Insertar etiqueta') }}" aria-label="{{ __('Insertar etiqueta') }}" :aria-expanded="tagsOpen ? 'true' : 'false'" onmousedown="event.preventDefault()" x-on:click="tagsOpen=!tagsOpen">🏷️<span class="ui-tb-label"> {{ __('Etiqueta') }}</span> ▾</button>
                                            <div x-show="tagsOpen" x-cloak class="ui-menu ui-menu--scroll">
                                                @foreach ($tagCatalog as $t)
                                                    <button type="button" class="ui-menu-item ui-menu-item--stack" onmousedown="event.preventDefault()" x-on:click="insertTag(@js($t['tag']))">
                                                        <span class="ui-strong">[{{ $t['tag'] }}]</span>
                                                        <span class="ui-list-meta">{{ $t['hint'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                        @if ($canCodeMode)
                                            <button type="button" class="ui-tb-btn ui-mono" title="{{ __('Editar el código HTML/CSS') }}" aria-label="{{ __('Editar el código HTML/CSS') }}" x-on:click="toCode()">&lt;/&gt;<span class="ui-tb-label"> {{ __('Código') }}</span></button>
                                        @endif
                                    </div>
                                    @if ($canCodeMode)
                                        <button type="button" class="ui-tb-btn" title="{{ __('Volver al editor visual') }}" x-show="mode==='code'" x-cloak x-on:click="toVisual()">◱ {{ __('Editor visual') }}</button>
                                    @endif
                                </div>
                                {{-- Vista VISUAL --}}
                                <div contenteditable="true" id="tplEditorBody" x-ref="ed" x-show="mode==='visual'" class="mailbody-ed ui-editor-body" data-ph="{{ __('Escribe el contenido de la plantilla…') }}"
                                    role="textbox" aria-multiline="true" aria-labelledby="tpl-body-label"
                                    x-on:input="$wire.set('body', enc($refs.ed.innerHTML), false)"
                                    x-on:mouseup="saveSel()" x-on:keyup="saveSel()"
                                    x-on:blur="$wire.set('body', enc($refs.ed.innerHTML), false)"></div>
                                {{-- Vista CÓDIGO: HTML editable (ancho completo) + vista previa debajo (iframe sandbox, sin scripts) --}}
                                @if ($canCodeMode)
                                    <div x-show="mode==='code'" x-cloak class="ui-editor-codewrap">
                                        <textarea x-ref="code" spellcheck="false" class="ui-editor-code" aria-label="{{ __('Código HTML de la plantilla') }}" placeholder="{{ __('Pega o escribe HTML/CSS de diseño (tablas, estilos inline)…') }}"
                                            x-on:input="$wire.set('body', enc($refs.code.value), false); preview()"></textarea>
                                        <div class="ui-editor-preview">
                                            <div class="ui-editor-pvh">{{ __('Vista previa') }}</div>
                                            <iframe x-ref="pv" sandbox="" title="{{ __('Vista previa') }}"></iframe>
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <input type="file" id="tplInlineImageInput" wire:model="inlineUpload" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                            <div wire:loading wire:target="inlineUpload" class="ui-help"><span class="ui-spin"></span> {{ __('Subiendo imagen…') }}</div>
                            @error('inlineUpload') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            @error('body') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        </div>

                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Estado')" for="tpl-status" :help="__('Solo las activas aparecen al redactar.')">
                                <select id="tpl-status" wire:model="tstatus" class="ui-select">
                                    <option value="active">{{ __('Activa') }}</option>
                                    <option value="inactive">{{ __('Inactiva') }}</option>
                                </select>
                            </x-ui.field>
                        </div>
                    </div>
                    <div class="ui-card-f ui-card-f--end">
                        <x-ui.button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancelar') }}</x-ui.button>
                        <x-ui.button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('Guardar') }}</x-ui.button>
                    </div>
                </div>
            @endif

            {{-- Listado --}}
            <ul class="ui-list">
                @forelse ($templates as $template)
                    <li wire:key="tpl-{{ $template->id }}">
                        <div class="ui-list-item">
                            <div class="ui-list-main">
                                <span class="ui-person-line">
                                    <span class="ui-list-title">{{ $template->name }}</span>
                                    @if ($template->status !== 'active')
                                        <x-ui.badge tone="neutral" dot>{{ __('Inactiva') }}</x-ui.badge>
                                    @endif
                                </span>
                                <span class="ui-list-meta"><x-ui.icon name="mail" />{{ $template->subject }}</span>
                            </div>
                            <div class="ui-list-aside">
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $template->id }})" :title="__('Editar')" :aria-label="__('Editar :name', ['name' => $template->name])" />
                                <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('¿Eliminar la plantilla “:name”?', ['name' => $template->name]) }}" :title="__('Eliminar')" :aria-label="__('Eliminar :name', ['name' => $template->name])" />
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="ui-empty">
                        <div class="ui-empty-ic"><x-ui.icon name="file-text" /></div>
                        <b>{{ $scope === 'shared' ? __('Aún no hay plantillas compartidas.') : __('Aún no tienes plantillas propias.') }}</b>
                        {{ __('Crea la primera con “Nueva plantilla”.') }}
                    </li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
