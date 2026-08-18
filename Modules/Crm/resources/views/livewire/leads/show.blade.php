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
                        <x-ui.icon name="sparkles" class="i13" /> Captado por {{ $lead->bot->assistant_name ?? '—' }}
                        · <x-ui.icon name="mail" class="i13 gray" /> {{ $c->email ?? '—' }}
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
                            @php
                                [$evIcon, $evLabel] = match ($ev->event_type) {
                                    'corporate_interest' => ['building-2', 'Interés corporativo detectado'],
                                    'used_matcher' => ['sparkles', 'Usó el emparejador'],
                                    'viewed_program' => ['graduation-cap', 'Vio un programa'],
                                    'viewed_certification' => ['graduation-cap', 'Vio la certificación'],
                                    'started_celia' => ['message-circle', 'Inició conversación con Celia'],
                                    'clicked_enrollment' => ['download', 'Fue a inscripciones'],
                                    'widget_opened' => ['message-circle', 'Abrió el widget'],
                                    'contact_created' => ['user', 'Se registró'],
                                    'lead_transferred' => ['arrow-right-left', 'Seguimiento transferido'],
                                    'unresolved_question' => ['sticky-note', 'Pregunta no resuelta'],
                                    default => ['clock', $ev->event_type],
                                };
                            @endphp
                            <div class="ev">
                                <x-ui.icon name="{{ $evIcon }}" class="i15" />
                                <div>{{ $evLabel }}<div class="t">{{ $ev->created_at?->translatedFormat('d M Y · H:i') }}</div></div>
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
</div>
