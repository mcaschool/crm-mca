<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @php
        $c = $lead->contact;
        $first = $c->first_name ?? '';
        $last = $c->last_name ?? '';
        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1)) ?: '—';
        $fullName = trim($first.' '.$last) ?: '—';
    @endphp

    <div style="width:100%">
        <a href="{{ route('crm.leads.index') }}" style="display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-weight:600;font-size:13px;text-decoration:none;margin-bottom:14px">
            <x-ui.icon name="chevron-left" style="width:15px;height:15px" /> Volver a leads
        </a>

        @if (session('status'))
            <div class="toast ok"><x-ui.icon name="check" class="i16" /> {{ session('status') }}</div>
        @endif
        @error('status') <div class="toast err"><x-ui.icon name="x" class="i16" /> {{ $message }}</div> @enderror
        @error('transferTarget') <div class="toast err"><x-ui.icon name="x" class="i16" /> {{ $message }}</div> @enderror

        <div class="panel">
            {{-- Cabecera --}}
            <div class="d-head">
                <span class="ava">{{ $initials }}</span>
                <div>
                    <div class="nm">{{ $fullName }}</div>
                    <div class="sub">
                        <x-ui.icon name="bot" class="i13" /> Captado por {{ $lead->bot->assistant_name ?? '—' }}
                        · <x-ui.icon name="mail" class="i13 gray" /> {{ $c->email ?? '—' }}
                        @if ($lead->sourceLabel())
                            · <x-ui.icon name="activity" class="i13 gray" /> Motivo: {{ $lead->sourceLabel() }}
                        @endif
                    </div>
                </div>

                <div class="d-actions">
                    @if ($isTerminal || ! $canAct)
                        <span class="statuspick {{ $lead->status->badgeClass() }}" style="cursor:default">
                            <x-ui.icon name="{{ $isTerminal ? 'check' : 'clock' }}" class="i14" /> {{ $lead->status->label() }}
                        </span>
                    @else
                        <div class="statusmenu">
                            <button type="button" class="statuspick {{ $lead->status->badgeClass() }}" wire:click="$toggle('statusMenuOpen')">
                                <x-ui.icon name="clock" class="i14" /> {{ $lead->status->label() }}
                                <x-ui.icon name="chevron-down" class="i14" />
                            </button>
                            @if ($statusMenuOpen)
                                <div class="menu" wire:click.outside="$set('statusMenuOpen', false)">
                                    @foreach ($statuses as $s)
                                        <button type="button" wire:click="changeStatus('{{ $s->value }}')"
                                            @if ($s->value === $lead->status->value) disabled @endif>
                                            <span class="chip {{ $s->badgeClass() }}">{{ $s->label() }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($canEmail)
                        <button type="button" class="ghost" wire:click="openCompose"><x-ui.icon name="mail" class="i14" /> Enviar correo</button>
                    @endif
                    @if ($canAct)
                        <a href="#transfer" class="ghost"><x-ui.icon name="arrow-right-left" class="i14" /> Transferir</a>
                    @else
                        <span class="ro-tag"><x-ui.icon name="eye" class="i12" /> solo lectura</span>
                    @endif
                    <button type="button" class="ghost" wire:click="exportOne"><x-ui.icon name="download" class="i14" /> Exportar</button>
                    <span class="ghost" style="padding:8px 10px"><x-ui.icon name="more-horizontal" class="i14" /></span>
                </div>
            </div>

            <div class="d-body">
                {{-- Conversación --}}
                <div class="conv">
                    <h3><x-ui.icon name="message-circle" class="i14" /> Conversación</h3>
                    @forelse ($messages as $msg)
                        @php
                            $isUser = $msg->sender_type === 'user';
                            $label = $isUser ? ($first ?: 'Prospecto') : ($msg->sender_type === 'celia' ? ($lead->bot->assistant_name ?? 'Celia') : 'MCA School');
                            $html = e($msg->content);
                            $html = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html);
                        @endphp
                        <div class="m {{ $isUser ? 'me' : 'bot' }}">
                            <div class="lbl">{{ $label }}</div>
                            <div class="bub">{!! nl2br($html) !!}</div>
                        </div>
                    @empty
                        <div class="conv-empty">Este lead aún no tiene mensajes registrados.</div>
                    @endforelse
                </div>

                {{-- Barra lateral --}}
                <div class="side">
                    {{-- 1 · Datos personales --}}
                    <div class="block">
                        <h3><x-ui.icon name="user" class="i14" /> Datos personales</h3>
                        <div class="field"><x-ui.icon name="user" class="i15" /><span class="k">Nombre</span><span class="v">{{ $fullName }}</span></div>
                        <div class="field"><x-ui.icon name="mail" class="i15" /><span class="k">Correo</span><span class="v">{{ $c->email ?? '—' }}</span></div>
                        <div class="field">
                            <x-ui.icon name="phone" class="i15" /><span class="k">WhatsApp</span>
                            <span class="v">{{ $phoneDisplay ?? '—' }}</span>
                        </div>
                        <div class="field"><x-ui.icon name="globe" class="i15" /><span class="k">País</span><span class="v">{{ $c->country ?: '—' }}</span></div>
                        <div class="locknote"><x-ui.icon name="lock" class="i13" /> Acceso a datos personales registrado en auditoría.</div>
                    </div>

                    {{-- Correo: enviar + historial --}}
                    <div class="block" id="email">
                        <h3><x-ui.icon name="mail" class="i14" /> Correo</h3>

                        @if ($canEmail)
                            <button type="button" wire:click="openCompose" class="ghost" style="margin-bottom:10px"><x-ui.icon name="mail" class="i14" /> Enviar correo a {{ $first ?: 'este contacto' }}</button>
                        @endif

                        <div style="font-size:12px;color:var(--muted);font-weight:600;margin:4px 0 6px">Correos enviados</div>
                        @forelse ($emails as $em)
                            <button type="button" wire:click="openSentEmail({{ $em->id }})" wire:key="email-{{ $em->id }}"
                                style="display:block;width:100%;text-align:left;background:none;border:0;border-top:1px solid var(--line);padding:9px 2px;cursor:pointer">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                                    <div style="font-size:13px;color:var(--ink);font-weight:600">{{ $em->subject }}
                                        @if ($em->status !== 'sent')<span style="color:#B23B3B;font-weight:600;font-size:11.5px"> · falló</span>@endif
                                    </div>
                                    <span style="font-size:11.5px;color:var(--blue);font-weight:600;white-space:nowrap">Abrir ›</span>
                                </div>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px">
                                    Como <b>{{ $em->from_name ?: $em->from_address }}</b>
                                    · {{ ($em->sent_at ?? $em->created_at)?->translatedFormat('d M Y · H:i') }}
                                    · por {{ $em->sentByUser?->name ?? 'Equipo' }}
                                    @if ($em->files_count) · <x-ui.icon name="download" class="i12" /> {{ $em->files_count }} adjunto(s)@endif
                                </div>
                            </button>
                        @empty
                            <div class="conv-empty" style="font-size:12.5px">Aún no se han enviado correos.</div>
                        @endforelse
                    </div>

                    {{-- 2 · Resultado del emparejador --}}
                    <div class="block">
                        <h3><x-ui.icon name="sparkles" class="i14" /> Resultado del emparejador</h3>
                        <div class="mrow"><span class="k">Motivación</span><span class="v">{{ $motivacion ?: '—' }}</span></div>
                        <div class="mrow"><span class="k">Meta</span><span class="v">{{ $lead->goal ?: '—' }}</span></div>
                        <div class="mrow"><span class="k">Área</span><span class="v">{{ $lead->area ?: '—' }}</span></div>
                        <div class="mrow"><span class="k">Momento</span><span class="v">{{ $lead->level ?: '—' }}</span></div>
                        @if ($lead->program)
                            <div class="rec">Recomendado: <b>{{ $lead->program->name_es }}</b></div>
                        @endif
                    </div>

                    {{-- 3 · Eventos --}}
                    <div class="block">
                        <h3><x-ui.icon name="clock" class="i14" /> Eventos</h3>
                        @forelse ($events as $ev)
                            @php $evDetail = $ev->detail(); @endphp
                            <div class="ev">
                                <x-ui.icon name="{{ $ev->icon() }}" class="i15" />
                                <div>
                                    {{ $ev->label() }}@if ($evDetail)<span style="color:var(--muted);word-break:break-word">: {{ $evDetail }}</span>@endif
                                    <div class="t">{{ $ev->created_at?->translatedFormat('d M Y · H:i') }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="conv-empty" style="font-size:12.5px">Sin eventos registrados.</div>
                        @endforelse
                    </div>

                    {{-- 4 · Transferir seguimiento --}}
                    <div class="block" id="transfer">
                        <h3><x-ui.icon name="arrow-right-left" class="i14" /> Transferir seguimiento</h3>
                        <div class="transfer">
                            <x-ui.icon name="arrow-right-left" class="i14" /> Transferir a
                            @if ($canAct)
                                <select wire:model="transferTarget" wire:change="transfer" aria-label="Transferir seguimiento a">
                                    @foreach ($transferOptions as $value => $optLabel)
                                        <option value="{{ $value }}">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span style="margin-left:auto;font-weight:600">{{ $transferOptions[$transferTarget] ?? '—' }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- 5 · Notas internas --}}
                    <div class="block">
                        <h3><x-ui.icon name="sticky-note" class="i14" /> Notas internas</h3>
                        @forelse ($lead->leadNotes as $note)
                            <div class="note" wire:key="note-{{ $note->id }}">
                                {{ $note->body }}
                                <div class="meta">— {{ $note->author_name ?: 'Equipo' }} · {{ $note->created_at?->translatedFormat('d M') }}</div>
                            </div>
                        @empty
                            <div class="conv-empty" style="font-size:12.5px">Aún no hay notas.</div>
                        @endforelse
                        @if ($canAct)
                            <div class="addnote">
                                <input type="text" wire:model="newNote" wire:keydown.enter="addNote" placeholder="Añadir una nota…" aria-label="Añadir una nota">
                                <button type="button" wire:click="addNote" aria-label="Guardar nota"><x-ui.icon name="plus" class="i16" /></button>
                            </div>
                        @endif
                    </div>

                    {{-- 6 · Matrícula · Moodle (dormido) --}}
                    <div class="block">
                        <h3><x-ui.icon name="graduation-cap" class="i14" /> Matrícula · Moodle</h3>
                        <div class="moodle">
                            <span class="tag">Se activa con integración Moodle</span>
                            <div class="mrow"><span class="k">Course ID</span><span class="v">—</span></div>
                            <div class="mrow"><span class="k">Moodle User</span><span class="v">—</span></div>
                            <div class="mrow"><span class="k">Culminación</span><span class="v">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Editor de correo A PANTALLA COMPLETA --}}
    @if ($composeOpen)
        @php $att = config('crm.mail.attachments'); @endphp
        <div style="position:fixed;inset:0;z-index:1000;background:rgba(19,37,61,.45);display:flex;align-items:center;justify-content:center;padding:24px" wire:key="mail-overlay">
            <div style="background:#fff;width:100%;max-width:990px;max-height:92vh;height:auto;display:flex;flex-direction:column;border-radius:14px;overflow:hidden;box-shadow:0 24px 70px -20px rgba(0,0,0,.5)">
                {{-- Cabecera --}}
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 22px;border-bottom:1px solid var(--line)">
                    <div style="font-weight:700;font-size:16px;color:var(--ink)"><x-ui.icon name="mail" class="i16" /> Nuevo correo</div>
                    <button type="button" wire:click="$set('composeOpen', false)" class="ghost" aria-label="Cerrar"><x-ui.icon name="x" class="i16" /></button>
                </div>

                {{-- Cuerpo (scroll) --}}
                <div style="flex:1;overflow:auto;padding:18px 22px;display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label style="font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:4px">Enviar como</label>
                        <select wire:model="emailSenderId" aria-label="Enviar como" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                            <option value="">— Elige un remitente —</option>
                            @foreach ($senders as $s)
                                <option value="{{ $s->id }}">{{ $s->name }} · {{ $s->from_address }}</option>
                            @endforeach
                        </select>
                        @error('emailSenderId') <div class="toast err" style="font-size:12.5px;margin-top:4px">{{ $message }}</div> @enderror
                        @if ($senders->isEmpty())
                            <div style="font-size:12.5px;color:var(--muted);margin-top:4px">No hay remitentes. Un administrador debe registrar uno en Ajustes → Remitentes de correo.</div>
                        @endif
                    </div>

                    @if (! $templates->isEmpty())
                        <div>
                            <label style="font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:4px">Plantilla</label>
                            <select aria-label="Plantilla" x-on:change="$wire.loadTemplate($event.target.value)"
                                style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                                <option value="">— Redactar desde cero —</option>
                                @php $shared = $templates->whereNull('user_id'); $mine = $templates->whereNotNull('user_id'); @endphp
                                @if ($shared->isNotEmpty())
                                    <optgroup label="Compartidas">
                                        @foreach ($shared as $tpl)
                                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if ($mine->isNotEmpty())
                                    <optgroup label="Mías">
                                        @foreach ($mine as $tpl)
                                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                            <div style="font-size:12px;color:var(--muted);margin-top:4px">Carga asunto y cuerpo; puedes ajustarlo antes de enviar. Las etiquetas se rellenan al enviar.</div>
                        </div>
                    @endif

                    <div style="font-size:13px;color:var(--muted)">Para: <b style="color:var(--ink)">{{ $c->email ?? '—' }}</b></div>

                    <input type="text" wire:model="emailSubject" maxlength="200" placeholder="Asunto" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                    @error('emailSubject') <div class="toast err" style="font-size:12.5px">{{ $message }}</div> @enderror

                    {{-- Editor: toolbar + contenteditable. wire:ignore para que Livewire no lo re-pinte. --}}
                    <div wire:ignore
                        x-data="{ tagsOpen: false, savedRange: null, caret(ed){ ed.focus(); const s=window.getSelection(); if(!s.rangeCount || !ed.contains(s.anchorNode)){ const r=document.createRange(); r.selectNodeContents(ed); r.collapse(false); s.removeAllRanges(); s.addRange(r);} }, saveSel(){ const s=window.getSelection(); if(s.rangeCount && this.$refs.ed.contains(s.anchorNode)) this.savedRange = s.getRangeAt(0).cloneRange(); }, applyFont(f){ if(!f) return; const ed=this.$refs.ed; ed.focus(); if(this.savedRange){ const s=window.getSelection(); s.removeAllRanges(); s.addRange(this.savedRange); } document.execCommand('fontName',false,f); ed.dispatchEvent(new Event('input')); }, applySize(v){ if(!v) return; const ed=this.$refs.ed; ed.focus(); if(this.savedRange){ const s=window.getSelection(); s.removeAllRanges(); s.addRange(this.savedRange); } document.execCommand('fontSize',false,v); ed.dispatchEvent(new Event('input')); }, insertTag(t){ const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertText',false,'['+t+']'); ed.dispatchEvent(new Event('input')); this.tagsOpen=false; }, insertImage(d){ if(!d||!d.url||!d.cid) return; const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertHTML',false,'<img src=\''+d.url+'\' data-cid=\''+d.cid+'\' style=\'max-width:100%;height:auto\'><br>'); ed.dispatchEvent(new Event('input')); } }"
                        x-on:insert-inline-image.window="insertImage($event.detail)"
                        x-on:load-template.window="$refs.ed.innerHTML = $event.detail.html || ''; $refs.ed.dispatchEvent(new Event('input'))"
                        style="border:1px solid var(--line);border-radius:10px;overflow:hidden">
                        <div style="display:flex;gap:2px;padding:6px 8px;border-bottom:1px solid var(--line);background:var(--soft,#F1F6FC);flex-wrap:wrap;align-items:center">
                            @php
                                $tbtn = 'min-width:30px;height:28px;padding:0 8px;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:13px;cursor:pointer;color:var(--ink)';
                            @endphp
                            <button type="button" title="Negrita" onmousedown="event.preventDefault()" x-on:click="document.execCommand('bold'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};font-weight:700">B</button>
                            <button type="button" title="Cursiva" onmousedown="event.preventDefault()" x-on:click="document.execCommand('italic'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};font-style:italic">I</button>
                            <button type="button" title="Subrayado" onmousedown="event.preventDefault()" x-on:click="document.execCommand('underline'); $refs.ed.dispatchEvent(new Event('input'))" style="{{ $tbtn }};text-decoration:underline">U</button>
                            <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                            <select title="Tipografía" aria-label="Tipografía"
                                x-on:mousedown="saveSel()"
                                x-on:change="applyFont($event.target.value); $event.target.selectedIndex=0"
                                style="height:28px;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:12.5px;color:var(--ink);padding:0 6px;cursor:pointer">
                                <option value="">Fuente</option>
                                <option value="Arial" style="font-family:Arial">Arial</option>
                                <option value="Georgia" style="font-family:Georgia">Georgia</option>
                                <option value="Verdana" style="font-family:Verdana">Verdana</option>
                                <option value="Times New Roman" style="font-family:'Times New Roman'">Times New Roman</option>
                            </select>
                            <select title="Tamaño de texto" aria-label="Tamaño de texto"
                                x-on:mousedown="saveSel()"
                                x-on:change="applySize($event.target.value); $event.target.selectedIndex=0"
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
                            <button type="button" title="Insertar imagen (se ve dentro del correo)" onmousedown="event.preventDefault()" onclick="document.getElementById('inlineImageInput').click()" style="{{ $tbtn }}">🖼️ Imagen</button>

                            {{-- Etiquetas dinámicas: se rellenan con datos reales al enviar --}}
                            @if (! empty($emailTags))
                                <span style="width:1px;background:var(--line);margin:2px 4px"></span>
                                <div style="position:relative" x-on:click.outside="tagsOpen=false">
                                    <button type="button" title="Insertar etiqueta" onmousedown="event.preventDefault()" x-on:click="tagsOpen=!tagsOpen" style="{{ $tbtn }};display:inline-flex;align-items:center;gap:4px">🏷️ Etiqueta ▾</button>
                                    <div x-show="tagsOpen" x-cloak style="position:absolute;top:32px;left:0;z-index:5;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 12px 30px -12px rgba(19,37,61,.4);min-width:240px;padding:6px;max-height:240px;overflow:auto">
                                        @foreach ($emailTags as $t)
                                            <button type="button" onmousedown="event.preventDefault()" x-on:click="insertTag(@js($t['tag']))" style="display:flex;flex-direction:column;gap:1px;width:100%;text-align:left;background:none;border:0;border-radius:6px;padding:6px 8px;cursor:pointer">
                                                <span style="font-size:13px;font-weight:600;color:var(--ink)">[{{ $t['tag'] }}]</span>
                                                <span style="font-size:11.5px;color:var(--muted)">→ {{ str($t['preview'])->limit(40) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div contenteditable="true" id="mailEditorBody" x-ref="ed" class="mailbody-ed" data-ph="Escribe el mensaje…"
                            x-on:input="$wire.set('emailBody', $refs.ed.innerHTML, false)"
                            x-on:mouseup="saveSel()" x-on:keyup="saveSel()"
                            x-on:blur="$wire.set('emailBody', $refs.ed.innerHTML, false)"
                            style="min-height:240px;padding:12px 14px;font-size:14px;line-height:1.55;outline:none;color:var(--ink)"></div>
                    </div>
                    @error('emailBody') <div class="toast err" style="font-size:12.5px">{{ $message }}</div> @enderror

                    {{-- Imagen inline: input FUERA de wire:ignore para que Livewire procese la subida --}}
                    <input type="file" id="inlineImageInput" wire:model="inlineUpload" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">
                    <div wire:loading wire:target="inlineUpload" style="font-size:12.5px;color:var(--muted)"><span class="mca-spin"></span> Subiendo imagen…</div>
                    @error('inlineUpload') <div class="toast err" style="font-size:12.5px">{{ $message }}</div> @enderror

                    {{-- Adjuntos --}}
                    <div>
                        <label style="font-size:12px;color:var(--muted);font-weight:600;display:block;margin-bottom:6px">
                            Adjuntos <span style="font-weight:400">· máx {{ round($att['max_file_bytes'] / 1048576) }} MB por archivo, {{ round($att['max_total_bytes'] / 1048576) }} MB en total</span>
                        </label>
                        <label class="ghost" style="cursor:pointer"><x-ui.icon name="upload" class="i14" /> Adjuntar archivos
                            <input type="file" wire:model="emailAttachments" multiple accept=".{{ implode(',.', $att['allowed_extensions']) }}" style="display:none">
                        </label>
                        <div wire:loading wire:target="emailAttachments" style="font-size:12.5px;color:var(--muted);margin-top:4px"><span class="mca-spin"></span> Subiendo…</div>
                        @error('emailAttachments') <div class="toast err" style="font-size:12.5px;margin-top:4px">{{ $message }}</div> @enderror
                        @error('emailAttachments.*') <div class="toast err" style="font-size:12.5px;margin-top:4px">{{ $message }}</div> @enderror

                        @if (! empty($emailAttachments))
                            <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                                @foreach ($emailAttachments as $i => $file)
                                    <div wire:key="att-{{ $i }}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:7px 10px">
                                        <span><x-ui.icon name="file-text" class="i14" /> {{ $file->getClientOriginalName() }} <span style="color:var(--muted)">· {{ number_format($file->getSize() / 1024) }} KB</span></span>
                                        <button type="button" wire:click="removeAttachment({{ $i }})" class="ghost" style="padding:3px 8px;font-size:12px">Quitar</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Pie --}}
                <div style="display:flex;gap:10px;justify-content:flex-end;align-items:center;padding:14px 22px;border-top:1px solid var(--line)">
                    <button type="button" wire:click="$set('composeOpen', false)" class="ghost">Cancelar</button>
                    <button type="button" wire:click="sendEmail" wire:loading.attr="disabled" wire:target="sendEmail,emailAttachments" class="ghost solid">
                        <x-ui.icon name="mail" class="i14" /> Enviar
                        <span wire:loading wire:target="sendEmail">…</span>
                    </button>
                </div>
            </div>
        </div>
        <style>.mailbody-ed:empty:before{content:attr(data-ph);color:var(--muted);pointer-events:none}[x-cloak]{display:none!important}</style>
    @endif

    {{-- Ver un correo ENVIADO tal como se envió --}}
    @if ($viewingEmail)
        <div style="position:fixed;inset:0;z-index:1000;background:rgba(19,37,61,.45);display:flex;align-items:center;justify-content:center;padding:24px" wire:key="sent-overlay-{{ $viewingEmail->id }}">
            <div style="background:#fff;width:100%;max-width:800px;max-height:92vh;height:auto;display:flex;flex-direction:column;border-radius:14px;overflow:hidden;box-shadow:0 24px 70px -20px rgba(0,0,0,.5)">
                {{-- Cabecera --}}
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:16px 22px;border-bottom:1px solid var(--line)">
                    <div>
                        <div style="font-weight:700;font-size:16px;color:var(--ink)">{{ $viewingEmail->subject }}
                            @if ($viewingEmail->status !== 'sent')<span style="color:#B23B3B;font-size:12px;font-weight:600"> · falló</span>@endif
                        </div>
                        <div style="font-size:12.5px;color:var(--muted);margin-top:4px">
                            <b style="color:var(--ink)">{{ $viewingEmail->from_name ?: $viewingEmail->from_address }}</b> &lt;{{ $viewingEmail->from_address }}&gt;
                            → {{ $viewingEmail->to_address }}
                        </div>
                        <div style="font-size:12px;color:var(--muted);margin-top:2px">
                            {{ ($viewingEmail->sent_at ?? $viewingEmail->created_at)?->translatedFormat('d M Y · H:i') }}
                            · por {{ $viewingEmail->sentByUser?->name ?? 'Equipo' }}
                            · <span style="text-transform:capitalize">{{ $viewingEmail->status === 'sent' ? 'enviado' : 'falló' }}</span>
                        </div>
                    </div>
                    <button type="button" wire:click="closeSentEmail" class="ghost" aria-label="Cerrar"><x-ui.icon name="x" class="i16" /></button>
                </div>

                {{-- Cuerpo tal como se envió (formato + imágenes inline resueltas) --}}
                <div style="flex:1;overflow:auto;padding:22px 26px">
                    <div class="sent-body" style="font-size:14px;line-height:1.55;color:var(--ink);word-break:break-word">{!! $viewingBody !!}</div>

                    @if ($viewingEmail->files->isNotEmpty())
                        <div style="margin-top:22px;border-top:1px solid var(--line);padding-top:14px">
                            <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:8px">Adjuntos</div>
                            <div style="display:flex;flex-direction:column;gap:6px">
                                @foreach ($viewingEmail->files as $file)
                                    <button type="button" wire:click="downloadAttachment({{ $file->id }})" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;font-size:13px;border:1px solid var(--line);border-radius:8px;padding:8px 11px;background:#fff;cursor:pointer">
                                        <span><x-ui.icon name="file-text" class="i14" /> {{ $file->filename }} <span style="color:var(--muted)">· {{ number_format($file->size / 1024) }} KB</span></span>
                                        <span style="color:var(--blue);font-weight:600;white-space:nowrap"><x-ui.icon name="download" class="i13" /> Descargar</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div style="display:flex;justify-content:flex-end;padding:14px 22px;border-top:1px solid var(--line)">
                    <button type="button" wire:click="closeSentEmail" class="ghost">Cerrar</button>
                </div>
            </div>
        </div>
        <style>.sent-body img{max-width:100%;height:auto}.sent-body a{color:var(--blue)}.sent-body ul,.sent-body ol{padding-left:22px}</style>
    @endif
</div>
