<div class="mca-page">
    @php
        $c = $lead->contact;
        $first = $c->first_name ?? '';
        $last = $c->last_name ?? '';
        $fullName = trim($first.' '.$last) ?: '—';
        $notesList = $lead->leadNotes;
        if ($incompany) {
            $modalidadLabel = match ($incompany->modalidad) {
                'grupo' => __('Grupo'),
                'persona' => __('Individual'),
                default => $incompany->modalidad ?: '—',
            };
            $ruta = $incompany->programRoute();
            $incHot = $incompany->hasRequestedContact();
        }
        // Tono de cada evento en la línea de tiempo (el mismo criterio que el Inicio).
        $evTone = fn ($ev) => match ($ev->event_type) {
            'corporate_interest', 'corporate_contact', 'corporate_form', 'recontacted' => 'gold',
            'used_matcher', 'started_celia', 'contact_created' => 'blue',
            'viewed_program', 'viewed_certification', 'clicked_enrollment', 'program_interest' => 'success',
            'unresolved_question' => 'warning',
            default => 'neutral',
        };
    @endphp

    <div class="ui-record">
        <a href="{{ route('crm.leads.index') }}" class="ui-back"><x-ui.icon name="chevron-left" /> {{ __('Volver a leads') }}</a>

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif
        @error('status') <div class="ui-alert ui-tone--danger" role="alert"><x-ui.icon name="x" /> {{ $message }}</div> @enderror
        @error('transferTarget') <div class="ui-alert ui-tone--danger" role="alert"><x-ui.icon name="x" /> {{ $message }}</div> @enderror

        {{-- CABECERA · identidad del lead y acciones comerciales --}}
        <header class="ui-card ui-record-h">
            <x-ui.avatar :name="$fullName" :seed="$lead->contact_id" size="xl" />
            <div class="ui-record-id">
                <div class="ui-person-line">
                    <h1 class="ui-record-title">{{ $fullName }}</h1>
                    @if ($incompany)
                        <x-ui.badge tone="gold" icon="building-2">{{ __('Empresa') }}</x-ui.badge>
                    @endif
                </div>
                <div class="ui-record-meta">
                    <span><x-ui.icon name="{{ $incompany ? 'briefcase' : 'bot' }}" /> {{ __('Captado por') }} <b>{{ ($lead->capturedByLabel() === 'Recomendador InCompany' ? __('Recomendador InCompany') : $lead->capturedByLabel()) }}</b></span>
                    <span><x-ui.icon name="mail" /> {!! str_replace('@', '@<wbr>', e($c->email ?? '—')) !!}</span>
                    @if ($lead->sourceLabel())
                        <span><x-ui.icon name="activity" /> {{ __('Motivo:') }} <b>{{ $lead->sourceLabel() }}</b></span>
                    @endif
                </div>
            </div>
            <div class="ui-record-actions">
                @if ($isTerminal || ! $canAct)
                    <x-ui.badge :tone="['new' => 'blue', 'contacted' => 'teal', 'qualified' => 'warning', 'enrolled' => 'success'][$lead->status->value] ?? 'neutral'" :icon="$isTerminal ? 'check' : 'clock'">{{ __($lead->status->label()) }}</x-ui.badge>
                @else
                    <div class="ui-dropdown">
                        <button type="button" class="ui-btn ui-btn--secondary" wire:click="$toggle('statusMenuOpen')" aria-haspopup="menu" aria-expanded="{{ $statusMenuOpen ? 'true' : 'false' }}" title="{{ __('Cambiar estado') }}">
                            <x-ui.lead-status :status="$lead->status" />
                            <x-ui.icon name="chevron-down" />
                        </button>
                        @if ($statusMenuOpen)
                            <div class="ui-menu" role="menu" wire:click.outside="$set('statusMenuOpen', false)">
                                @foreach ($statuses as $s)
                                    <button type="button" role="menuitem" class="ui-menu-item" wire:click="changeStatus('{{ $s->value }}')"
                                        @if ($s->value === $lead->status->value) disabled aria-current="true" @endif>
                                        <x-ui.lead-status :status="$s" />
                                        @if ($s->value === $lead->status->value)<x-ui.icon name="check" />@endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @can('create', \Modules\Crm\Models\Activity::class)
                    <x-ui.button icon="calendar" :href="route('crm.agenda.create', ['lead' => $lead->id])">{{ __('Programar actividad') }}</x-ui.button>
                @endcan
                @if ($canAct)
                    <x-ui.button icon="arrow-right-left" href="#transfer">{{ __('Transferir') }}</x-ui.button>
                @else
                    <x-ui.badge tone="neutral" icon="eye">{{ __('solo lectura') }}</x-ui.badge>
                @endif
                <x-ui.button icon="download" wire:click="exportOne">{{ __('Exportar') }}</x-ui.button>
                @if ($canEmail)
                    <x-ui.button variant="primary" icon="mail" wire:click="openCompose">{{ __('Enviar correo') }}</x-ui.button>
                @endif
            </div>
        </header>

        @if ($incompany)
            <div class="ui-callout ui-tone--gold">
                <x-ui.icon name="briefcase" />
                <span><b>{{ __('Lead de formulario · Recomendador InCompany.') }}</b> {{ __('No tuvo conversación de chat; su diagnóstico está en el Perfil InCompany.') }}</span>
            </div>
        @endif

        <div class="ui-record-grid">
            {{-- PRINCIPAL · perfil (InCompany) e historial de interacciones --}}
            <div class="ui-record-main">
                @if ($incompany)
                    <section class="ui-card" aria-labelledby="h-incompany">
                        <div class="ui-card-h">
                            <x-ui.icon name="briefcase" class="ui-card-ic" /><h2 id="h-incompany">{{ __('Perfil InCompany') }}</h2><span class="sp"></span>
                            <x-ui.badge tone="gold">{{ __('EMPRESA') }}</x-ui.badge>
                        </div>
                        <div class="ui-card-p">
                            <div class="ui-person-line">
                                @if ($incHot)
                                    <x-ui.badge tone="danger" icon="alert-triangle">{{ __('Solicitó contacto') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="blue" icon="sparkles">{{ __('Diagnóstico') }}</x-ui.badge>
                                @endif
                                @if ($incompany->diagnostico_at)
                                    <span class="ui-list-meta">{{ __('Vio su diagnóstico:') }} {{ $incompany->diagnostico_at->translatedFormat('d M Y · H:i') }}</span>
                                @endif
                                @if ($incompany->solicita_contacto_at)
                                    <span class="ui-list-meta ui-text-danger">{{ __('Pidió contacto:') }} {{ $incompany->solicita_contacto_at->translatedFormat('d M Y · H:i') }}</span>
                                @endif
                            </div>
                            <dl class="ui-meta ui-meta--spaced">
                                <dt>{{ __('Empresa') }}</dt><dd class="ui-strong">{{ $incompany->nombre_empresa }}</dd>
                                <dt>{{ __('Contacto') }}</dt><dd>{{ $incompany->nombre_contacto }}</dd>
                                <dt>{{ __('Sector') }}</dt><dd>@if ($incompany->sector){{ $incompany->sector }}@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Tamaño') }}</dt><dd>@if ($incompany->tamano_empresa){{ $incompany->tamano_empresa }}@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Modalidad') }}</dt><dd>{{ $modalidadLabel }} · {{ $incompany->cantidad_personas }} {{ $incompany->cantidad_personas === 1 ? __('persona') : __('personas') }}</dd>
                                <dt>{{ __('Área a desarrollar') }}</dt><dd>@if ($incompany->area_desarrollo){{ $incompany->area_desarrollo }}@else<span class="t-nil">—</span>@endif</dd>
                            </dl>
                        </div>
                    </section>

                    {{-- Ruta formativa propuesta (nombre enlazado al catálogo, sin precio) --}}
                    <section class="ui-card" aria-labelledby="h-ruta">
                        <div class="ui-card-h"><x-ui.icon name="book-open" class="ui-card-ic" /><h2 id="h-ruta">{{ __('Ruta formativa propuesta') }}</h2></div>
                        <ol class="ui-list">
                            @forelse ($ruta as $r)
                                <li wire:key="ic-prog-{{ $r['position'] }}">
                                    <div class="ui-list-item">
                                        <span class="ui-step-n">{{ $r['position'] }}</span>
                                        <div class="ui-list-main">
                                            @if ($r['linked'])
                                                <span class="ui-list-title">
                                                    @if ($r['program']->url)
                                                        <a class="ui-link" href="{{ $r['program']->url }}" target="_blank" rel="noopener">{{ $r['program']->name }} <x-ui.icon name="external-link" /></a>
                                                    @else
                                                        {{ $r['program']->name }}
                                                    @endif
                                                </span>
                                                <span class="ui-list-meta ui-mono">{{ $r['program']->course_idnumber ?: $r['code'] }}</span>
                                            @else
                                                <span class="ui-list-title ui-mono">{{ $r['code'] }}</span>
                                                <span class="ui-list-meta">{{ __('Código recibido') }}</span>
                                            @endif
                                        </div>
                                        <div class="ui-list-aside">
                                            @if ($r['linked'])
                                                <x-ui.badge tone="success" icon="check">{{ __('En catálogo') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge tone="danger" icon="alert-triangle">{{ __('Sin enlazar') }}</x-ui.badge>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @empty
                                <li class="ui-list-empty">{{ __('Sin programas indicados.') }}</li>
                            @endforelse
                        </ol>
                        <p class="ui-card-note">{{ __('Los nombres se enlazan al catálogo por su código. Sin precio: para InCompany se negocia por propuesta.') }}</p>
                    </section>
                @endif

                {{-- Navegación por secciones del historial (anclas: nada queda oculto) --}}
                <nav class="ui-subnav" aria-label="{{ __('Secciones de la ficha') }}">
                    @unless ($incompany)
                        <a href="#conversation"><x-ui.icon name="message-circle" /> {{ __('Conversación') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $messages->count() }}</span></a>
                    @endunless
                    <a href="#email"><x-ui.icon name="mail" /> {{ __('Correo') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $emails->count() }}</span></a>
                    <a href="#notes"><x-ui.icon name="sticky-note" /> {{ __('Notas') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $notesList->count() }}</span></a>
                    <a href="#events"><x-ui.icon name="clock" /> {{ __('Eventos') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ $events->count() }}</span></a>
                    <a href="#enrollment"><x-ui.icon name="graduation-cap" /> {{ __('Matrícula') }}</a>
                </nav>

                @unless ($incompany)
                    {{-- Conversación (solo si de verdad hubo mensajes; si no, una nota compacta) --}}
                    <section class="ui-card" id="conversation" aria-labelledby="h-conv">
                        <div class="ui-card-h"><x-ui.icon name="message-circle" class="ui-card-ic" /><h2 id="h-conv">{{ __('Conversación') }}</h2>
                            @if ($messages->isNotEmpty())<span class="ui-badge ui-badge--count ui-tone--neutral">{{ $messages->count() }}</span>@endif
                        </div>
                        @if ($messages->isNotEmpty())
                            <div class="ui-chat">
                                @foreach ($messages as $msg)
                                    @php
                                        $isUser = $msg->sender_type === 'user';
                                        $label = $isUser ? ($first ?: __('Prospecto')) : ($msg->sender_type === 'celia' ? ($lead->bot->assistant_name ?? 'Celia') : 'MCA School');
                                        $html = e($msg->content);
                                        $html = preg_replace('~(https?://[^\s<]+)~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html);
                                    @endphp
                                    <div class="ui-msg {{ $isUser ? 'ui-msg--in' : '' }}">
                                        <div class="ui-msg-from">{{ $label }}</div>
                                        <div class="ui-msg-bubble">{!! nl2br($html) !!}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="ui-card-p">
                                <div class="ui-callout"><x-ui.icon name="message-circle" /><span>{{ __('Este lead no tiene conversación registrada.') }}</span></div>
                            </div>
                        @endif
                    </section>
                @endunless

                {{-- Correo: enviar + historial --}}
                <section class="ui-card" id="email" aria-labelledby="h-email">
                    <div class="ui-card-h">
                        <x-ui.icon name="mail" class="ui-card-ic" /><h2 id="h-email">{{ __('Correo') }}</h2><span class="sp"></span>
                        @if ($canEmail)
                            <x-ui.button size="sm" icon="mail" wire:click="openCompose">{{ __('Enviar correo a :name', ['name' => $first ?: __('este contacto')]) }}</x-ui.button>
                        @endif
                    </div>
                    <p class="ui-card-kicker">{{ __('Correos enviados') }}</p>
                    <ul class="ui-list">
                        @forelse ($emails as $em)
                            <li wire:key="email-{{ $em->id }}">
                                <button type="button" class="ui-list-item" wire:click="openSentEmail({{ $em->id }})">
                                    <span class="ui-tl-ic ui-tone--blue"><x-ui.icon name="mail" /></span>
                                    <span class="ui-list-main">
                                        <span class="ui-list-title">{{ $em->subject }}
                                            @if ($em->status !== 'sent') <x-ui.badge tone="danger">{{ __('falló') }}</x-ui.badge>@endif
                                        </span>
                                        <span class="ui-list-meta">
                                            {{ __('Como') }} <b>{{ $em->from_name ?: $em->from_address }}</b>
                                            · {{ ($em->sent_at ?? $em->created_at)?->translatedFormat('d M Y · H:i') }}
                                            · {{ __('por :name', ['name' => $em->sentByUser?->name ?? __('Equipo')]) }}
                                            @if ($em->files_count) · {{ $em->files_count }} {{ __('adjunto(s)') }}@endif
                                        </span>
                                    </span>
                                    <span class="ui-list-aside ui-link">{{ __('Abrir') }} ›</span>
                                </button>
                            </li>
                        @empty
                            <li class="ui-list-empty">{{ __('Aún no se han enviado correos.') }}</li>
                        @endforelse
                    </ul>
                </section>

                {{-- Notas internas --}}
                <section class="ui-card" id="notes" aria-labelledby="h-notes">
                    <div class="ui-card-h"><x-ui.icon name="sticky-note" class="ui-card-ic" /><h2 id="h-notes">{{ __('Notas internas') }}</h2>
                        @if ($notesList->isNotEmpty())<span class="ui-badge ui-badge--count ui-tone--neutral">{{ $notesList->count() }}</span>@endif
                    </div>
                    <ul class="ui-list">
                        @forelse ($notesList as $note)
                            <li wire:key="note-{{ $note->id }}">
                                <div class="ui-list-item">
                                    <x-ui.avatar :name="$note->author_name ?: __('Equipo')" :seed="$note->author_name ?: 'Equipo'" />
                                    <div class="ui-list-main">
                                        <span class="ui-note-body">{{ $note->body }}</span>
                                        <span class="ui-list-meta">— {{ $note->author_name ?: __('Equipo') }} · {{ $note->created_at?->translatedFormat('d M') }}</span>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="ui-list-empty">{{ __('Aún no hay notas.') }}</li>
                        @endforelse
                    </ul>
                    @if ($canAct)
                        <div class="ui-inline-form">
                            <input type="text" class="ui-input" wire:model="newNote" wire:keydown.enter="addNote" placeholder="{{ __('Añadir una nota…') }}" aria-label="{{ __('Añadir una nota') }}">
                            <x-ui.button variant="primary" icon="plus" wire:click="addNote" :aria-label="__('Guardar nota')" />
                        </div>
                    @endif
                </section>

                {{-- Eventos --}}
                <section class="ui-card" id="events" aria-labelledby="h-events">
                    <div class="ui-card-h"><x-ui.icon name="clock" class="ui-card-ic" /><h2 id="h-events">{{ __('Eventos') }}</h2>
                        @if ($events->isNotEmpty())<span class="ui-badge ui-badge--count ui-tone--neutral">{{ $events->count() }}</span>@endif
                    </div>
                    @if ($events->isNotEmpty())
                        <ol class="ui-timeline">
                            @foreach ($events as $ev)
                                @php $evDetail = $ev->detail(); @endphp
                                <li>
                                    <span class="ui-tl-ic ui-tone--{{ $evTone($ev) }}"><x-ui.icon name="{{ $ev->icon() }}" /></span>
                                    <div class="ui-tl-body">
                                        {{ $ev->label() }}@if ($evDetail)<span class="ui-tl-detail">: {{ $evDetail }}</span>@endif
                                        <div class="ui-tl-time">{{ $ev->created_at?->translatedFormat('d M Y · H:i') }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="ui-list-empty">{{ __('Sin eventos registrados.') }}</p>
                    @endif
                </section>

                {{-- Matrícula · Moodle (dormido) --}}
                <section class="ui-card" id="enrollment" aria-labelledby="h-moodle">
                    <div class="ui-card-h"><x-ui.icon name="graduation-cap" class="ui-card-ic" /><h2 id="h-moodle">{{ __('Matrícula · Moodle') }}</h2><span class="sp"></span>
                        <x-ui.badge tone="neutral">{{ __('Se activa con integración Moodle') }}</x-ui.badge>
                    </div>
                    <div class="ui-card-p">
                        <dl class="ui-meta ui-meta--muted">
                            <dt>Course ID</dt><dd>—</dd>
                            <dt>Moodle User</dt><dd>—</dd>
                            <dt>{{ __('Culminación') }}</dt><dd>—</dd>
                        </dl>
                    </div>
                </section>
            </div>

            {{-- LATERAL · datos del contacto, resultado del emparejador y seguimiento --}}
            <aside class="ui-record-side">
                <section class="ui-card" aria-labelledby="h-personal">
                    <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="h-personal">{{ __('Datos personales') }}</h2></div>
                    <div class="ui-card-p">
                        <dl class="ui-meta ui-meta--spaced">
                            <dt>{{ __('Nombre') }}</dt><dd class="ui-strong">{{ $fullName }}</dd>
                            <dt>{{ __('Correo') }}</dt><dd>{!! str_replace('@', '@<wbr>', e($c->email ?? '—')) !!}</dd>
                            <dt>WhatsApp</dt><dd>@if ($phoneDisplay){{ $phoneDisplay }}@else<span class="t-nil">—</span>@endif</dd>
                            <dt>{{ __('País') }}</dt><dd>@if ($c->country){{ $c->country }}@else<span class="t-nil">—</span>@endif</dd>
                        </dl>
                        <div class="ui-callout ui-callout--sm"><x-ui.icon name="lock" /><span>{{ __('Acceso a datos personales registrado en auditoría.') }}</span></div>
                    </div>
                </section>

                @unless ($incompany)
                    <section class="ui-card" aria-labelledby="h-matcher">
                        <div class="ui-card-h"><x-ui.icon name="sparkles" class="ui-card-ic" /><h2 id="h-matcher">{{ __('Resultado del emparejador') }}</h2></div>
                        <div class="ui-card-p">
                            <dl class="ui-meta ui-meta--spaced">
                                <dt>{{ __('Motivación') }}</dt><dd>@if ($motivacion){{ $motivacion }}@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Meta') }}</dt><dd>@if ($lead->goal){{ $lead->goal }}@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Área') }}</dt><dd>@if ($lead->area){{ $lead->area }}@else<span class="t-nil">—</span>@endif</dd>
                                <dt>{{ __('Momento') }}</dt><dd>@if ($lead->level){{ $lead->level }}@else<span class="t-nil">—</span>@endif</dd>
                            </dl>
                            @if ($lead->program)
                                <div class="ui-callout ui-callout--sm ui-tone--success"><x-ui.icon name="graduation-cap" /><span>{{ __('Recomendado:') }} <b>{{ $lead->program->name_es }}</b></span></div>
                            @endif
                        </div>
                    </section>
                @endunless

                {{-- Transferir seguimiento --}}
                <section class="ui-card" id="transfer" aria-labelledby="h-transfer">
                    <div class="ui-card-h"><x-ui.icon name="arrow-right-left" class="ui-card-ic" /><h2 id="h-transfer">{{ __('Transferir seguimiento') }}</h2></div>
                    <div class="ui-card-p">
                        @if ($canAct)
                            <x-ui.field :label="__('Transferir a')" for="lead-transfer">
                                <select id="lead-transfer" class="ui-select" wire:model="transferTarget" wire:change="transfer" aria-label="{{ __('Transferir seguimiento a') }}">
                                    @foreach ($transferOptions as $value => $optLabel)
                                        <option value="{{ $value }}">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @else
                            <dl class="ui-meta"><dt>{{ __('Transferir a') }}</dt><dd class="ui-strong">{{ $transferOptions[$transferTarget] ?? '—' }}</dd></dl>
                        @endif
                    </div>
                </section>

                {{-- Calendario (componente compartido de la agenda) --}}
                <section class="ui-card" aria-labelledby="h-agenda">
                    <div class="ui-card-h"><x-ui.icon name="calendar" class="ui-card-ic" /><h2 id="h-agenda">{{ __('Calendario') }}</h2></div>
                    <div class="ui-card-p">
                        <livewire:crm.agenda.lead-activities :lead-id="$lead->id" :key="($incompany ? 'lead-agenda-ic-' : 'lead-agenda-').$lead->id" />
                    </div>
                </section>
            </aside>
        </div>
    </div>

    {{-- Editor de correo A PANTALLA COMPLETA --}}
    @if ($composeOpen)
        @php $att = config('crm.mail.attachments'); @endphp
        <div x-data="{ destroy(){ document.body.style.overflow='' } }" x-init="document.body.style.overflow='hidden'" class="ui-modal-bg" wire:key="mail-overlay">
            <div class="ui-modal ui-modal--lg" role="dialog" aria-modal="true" aria-labelledby="mail-title">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="mail-title"><x-ui.icon name="mail" /> {{ __('Nuevo correo') }}</h2>
                    <x-ui.button variant="ghost" icon="x" wire:click="$set('composeOpen', false)" :aria-label="__('Cerrar')" />
                </div>

                <div class="ui-modal-b mail-scrollbody">
                    <div class="ui-form-grid">
                        <x-ui.field :class="$templates->isEmpty() ? 'ui-span-full' : null" :label="__('Enviar como')" for="mail-sender" :error="$errors->first('emailSenderId')"
                            :help="$senders->isEmpty() ? __('No hay remitentes. Un administrador debe registrar uno en Ajustes → Remitentes de correo.') : null">
                            <select id="mail-sender" wire:model="emailSenderId" aria-label="{{ __('Enviar como') }}" @class(['ui-select', 'is-invalid' => $errors->has('emailSenderId')])>
                                <option value="">— {{ __('Elige un remitente') }} —</option>
                                @foreach ($senders as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }} · {{ $s->from_address }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        @if (! $templates->isEmpty())
                            <x-ui.field :label="__('Plantilla')" for="mail-template" :help="__('Carga asunto y cuerpo; puedes ajustarlo antes de enviar. Las etiquetas se rellenan al enviar.')">
                                <select id="mail-template" class="ui-select" aria-label="{{ __('Plantilla') }}" x-on:change="$wire.loadTemplate($event.target.value)">
                                    <option value="">— {{ __('Redactar desde cero') }} —</option>
                                    @php $shared = $templates->whereNull('user_id'); $mine = $templates->whereNotNull('user_id'); @endphp
                                    @if ($shared->isNotEmpty())
                                        <optgroup label="{{ __('Compartidas') }}">
                                            @foreach ($shared as $tpl)
                                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if ($mine->isNotEmpty())
                                        <optgroup label="{{ __('Mías') }}">
                                            @foreach ($mine as $tpl)
                                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </x-ui.field>
                        @endif
                    </div>

                    <div class="ui-mail-to">{{ __('Para:') }} <b>{{ $c->email ?? '—' }}</b></div>

                    <x-ui.field :label="__('Asunto')" for="mail-subject" :error="$errors->first('emailSubject')">
                        <input id="mail-subject" type="text" wire:model="emailSubject" maxlength="200" placeholder="{{ __('Asunto') }}" @class(['ui-input', 'is-invalid' => $errors->has('emailSubject')])>
                    </x-ui.field>

                    {{-- Editor: toolbar + contenteditable. wire:ignore para que Livewire no lo re-pinte. --}}
                    <div wire:ignore
                        x-data="{ tagsOpen: false, savedRange: null, caret(ed){ ed.focus(); const s=window.getSelection(); if(!s.rangeCount || !ed.contains(s.anchorNode)){ const r=document.createRange(); r.selectNodeContents(ed); r.collapse(false); s.removeAllRanges(); s.addRange(r);} }, saveSel(){ const s=window.getSelection(); if(s.rangeCount && this.$refs.ed.contains(s.anchorNode)) this.savedRange = s.getRangeAt(0).cloneRange(); }, applyFont(f){ if(!f) return; const ed=this.$refs.ed; ed.focus(); if(this.savedRange){ const s=window.getSelection(); s.removeAllRanges(); s.addRange(this.savedRange); } document.execCommand('fontName',false,f); ed.dispatchEvent(new Event('input')); }, applySize(v){ if(!v) return; const ed=this.$refs.ed; ed.focus(); if(this.savedRange){ const s=window.getSelection(); s.removeAllRanges(); s.addRange(this.savedRange); } document.execCommand('fontSize',false,v); ed.dispatchEvent(new Event('input')); }, insertTag(t){ const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertText',false,'['+t+']'); ed.dispatchEvent(new Event('input')); this.tagsOpen=false; }, insertImage(d){ if(!d||!d.url||!d.cid) return; const ed=this.$refs.ed; this.caret(ed); document.execCommand('insertHTML',false,'<img src=\''+d.url+'\' data-cid=\''+d.cid+'\' style=\'max-width:100%;height:auto\'><br>'); ed.dispatchEvent(new Event('input')); }, mode:'visual', toCode(){ this.$refs.code.value=this.$refs.ed.innerHTML; this.mode='code'; this.$nextTick(()=>this.preview()); }, toVisual(){ this.$refs.ed.innerHTML=this.$refs.code.value; this.$refs.ed.dispatchEvent(new Event('input')); this.mode='visual'; }, preview(){ if(this.$refs.pv) this.$refs.pv.srcdoc=this.$refs.code.value; }, enc(s){ return '__B64__'+btoa(unescape(encodeURIComponent(s))); } }"
                        x-on:insert-inline-image.window="insertImage($event.detail)"
                        x-on:load-template.window="$refs.ed.innerHTML = $event.detail.html || ''; $refs.ed.dispatchEvent(new Event('input')); mode='visual'"
                        class="ui-editor">
                        <div class="ui-editor-tb">
                            <div x-show="mode==='visual'" class="ui-tb-group">
                                <button type="button" class="ui-tb-btn" title="{{ __('Negrita') }}" aria-label="{{ __('Negrita') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('bold'); $refs.ed.dispatchEvent(new Event('input'))"><b>B</b></button>
                                <button type="button" class="ui-tb-btn" title="{{ __('Cursiva') }}" aria-label="{{ __('Cursiva') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('italic'); $refs.ed.dispatchEvent(new Event('input'))"><i>I</i></button>
                                <button type="button" class="ui-tb-btn" title="{{ __('Subrayado') }}" aria-label="{{ __('Subrayado') }}" onmousedown="event.preventDefault()" x-on:click="document.execCommand('underline'); $refs.ed.dispatchEvent(new Event('input'))"><u>U</u></button>
                                <span class="ui-tb-sep"></span>
                                <select title="{{ __('Tipografía') }}" aria-label="{{ __('Tipografía') }}" class="ui-tb-select"
                                    x-on:mousedown="saveSel()"
                                    x-on:change="applyFont($event.target.value); $event.target.selectedIndex=0">
                                    <option value="">{{ __('Fuente') }}</option>
                                    <option value="Arial" style="font-family:Arial">Arial</option>
                                    <option value="Georgia" style="font-family:Georgia">Georgia</option>
                                    <option value="Verdana" style="font-family:Verdana">Verdana</option>
                                    <option value="Times New Roman" style="font-family:'Times New Roman'">Times New Roman</option>
                                </select>
                                <select title="{{ __('Tamaño de texto') }}" aria-label="{{ __('Tamaño de texto') }}" class="ui-tb-select"
                                    x-on:mousedown="saveSel()"
                                    x-on:change="applySize($event.target.value); $event.target.selectedIndex=0">
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
                                <button type="button" class="ui-tb-btn" title="{{ __('Insertar imagen (se ve dentro del correo)') }}" aria-label="{{ __('Insertar imagen') }}" onmousedown="event.preventDefault()" onclick="document.getElementById('inlineImageInput').click()">🖼️<span class="ui-tb-label"> {{ __('Imagen') }}</span></button>

                                {{-- Etiquetas dinámicas: se rellenan con datos reales al enviar --}}
                                @if (! empty($emailTags))
                                    <span class="ui-tb-sep"></span>
                                    <div class="ui-dropdown" x-on:click.outside="tagsOpen=false">
                                        <button type="button" class="ui-tb-btn" title="{{ __('Insertar etiqueta') }}" aria-label="{{ __('Insertar etiqueta') }}" onmousedown="event.preventDefault()" x-on:click="tagsOpen=!tagsOpen">🏷️<span class="ui-tb-label"> {{ __('Etiqueta') }}</span> ▾</button>
                                        <div x-show="tagsOpen" x-cloak class="ui-menu ui-menu--scroll">
                                            @foreach ($emailTags as $t)
                                                <button type="button" class="ui-menu-item ui-menu-item--stack" onmousedown="event.preventDefault()" x-on:click="insertTag(@js($t['tag']))">
                                                    <span class="ui-strong">[{{ $t['tag'] }}]</span>
                                                    <span class="ui-list-meta">→ {{ str($t['preview'])->limit(40) }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                @if ($canCodeMode)
                                    <button type="button" class="ui-tb-btn ui-mono" title="{{ __('Editar el código HTML/CSS') }}" aria-label="{{ __('Editar el código HTML/CSS') }}" x-on:click="toCode()">&lt;/&gt;<span class="ui-tb-label"> {{ __('Código') }}</span></button>
                                @endif
                            </div>
                            @if ($canCodeMode)
                                <button type="button" class="ui-tb-btn" title="{{ __('Volver al editor visual') }}" x-show="mode==='code'" x-cloak x-on:click="toVisual()">◱ {{ __('Editor visual') }}</button>
                            @endif
                        </div>
                        {{-- Vista VISUAL --}}
                        <div contenteditable="true" id="mailEditorBody" x-ref="ed" x-show="mode==='visual'" class="mailbody-ed ui-editor-body" data-ph="{{ __('Escribe el mensaje…') }}"
                            x-on:input="$wire.set('emailBody', enc($refs.ed.innerHTML), false)"
                            x-on:mouseup="saveSel()" x-on:keyup="saveSel()"
                            x-on:blur="$wire.set('emailBody', enc($refs.ed.innerHTML), false)"></div>
                        {{-- Vista CÓDIGO: HTML editable (ancho completo) + vista previa debajo (iframe sandbox, sin scripts) --}}
                        @if ($canCodeMode)
                            <div x-show="mode==='code'" x-cloak class="ui-editor-codewrap">
                                <textarea x-ref="code" spellcheck="false" class="ui-editor-code" placeholder="{{ __('Pega o escribe HTML/CSS de diseño (tablas, estilos inline)…') }}"
                                    x-on:input="$wire.set('emailBody', enc($refs.code.value), false); preview()"></textarea>
                                <div class="ui-editor-preview">
                                    <div class="ui-editor-pvh">{{ __('Vista previa') }}</div>
                                    <iframe x-ref="pv" sandbox="" title="{{ __('Vista previa') }}"></iframe>
                                </div>
                            </div>
                        @endif
                    </div>
                    @error('emailBody') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror

                    {{-- Imagen inline: input FUERA de wire:ignore para que Livewire procese la subida --}}
                    <input type="file" id="inlineImageInput" wire:model="inlineUpload" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                    <div wire:loading wire:target="inlineUpload" class="ui-help"><span class="ui-spin"></span> {{ __('Subiendo imagen…') }}</div>
                    @error('inlineUpload') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror

                    {{-- Adjuntos --}}
                    <div class="ui-field">
                        <span class="ui-label">{{ __('Adjuntos') }} <span class="opt">· {{ __('máx :file MB por archivo, :total MB en total', ['file' => round($att['max_file_bytes'] / 1048576), 'total' => round($att['max_total_bytes'] / 1048576)]) }}</span></span>
                        <div>
                            <label class="ui-btn ui-btn--secondary ui-btn--sm"><x-ui.icon name="upload" /> {{ __('Adjuntar archivos') }}
                                <input type="file" wire:model="emailAttachments" multiple accept=".{{ implode(',.', $att['allowed_extensions']) }}" hidden>
                            </label>
                        </div>
                        <div wire:loading wire:target="emailAttachments" class="ui-help"><span class="ui-spin"></span> {{ __('Subiendo…') }}</div>
                        @error('emailAttachments') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        @error('emailAttachments.*') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror

                        @if (! empty($emailAttachments))
                            <ul class="ui-list ui-list--boxed">
                                @foreach ($emailAttachments as $i => $file)
                                    <li wire:key="att-{{ $i }}">
                                        <div class="ui-list-item">
                                            <x-ui.icon name="file-text" class="ui-card-ic" />
                                            <span class="ui-list-main"><span class="ui-list-title">{{ $file->getClientOriginalName() }}</span><span class="ui-list-meta">{{ number_format($file->getSize() / 1024) }} KB</span></span>
                                            <x-ui.button size="sm" variant="ghost" wire:click="removeAttachment({{ $i }})">{{ __('Quitar') }}</x-ui.button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="ui-modal-f">
                    <x-ui.button wire:click="$set('composeOpen', false)">{{ __('Cancelar') }}</x-ui.button>
                    <button type="button" class="ui-btn ui-btn--primary" wire:click="sendEmail" wire:loading.attr="disabled" wire:target="sendEmail,emailAttachments">
                        <x-ui.icon name="mail" /> {{ __('Enviar') }}
                        <span wire:loading wire:target="sendEmail">…</span>
                    </button>
                </div>
            </div>
        </div>
        <style>.mailbody-ed:empty:before{content:attr(data-ph);color:var(--mca-text-3);pointer-events:none}.mailbody-ed img{max-width:100%}.mailbody-ed ul{list-style:disc;padding-left:1.6em;margin:.5em 0}.mailbody-ed ol{list-style:decimal;padding-left:1.6em;margin:.5em 0}.mailbody-ed li{margin:.2em 0}[x-cloak]{display:none!important}</style>
    @endif

    {{-- Ver un correo ENVIADO tal como se envió --}}
    @if ($viewingEmail)
        <div x-data="{ destroy(){ document.body.style.overflow='' } }" x-init="document.body.style.overflow='hidden'" class="ui-modal-bg" wire:key="sent-overlay-{{ $viewingEmail->id }}">
            <div class="ui-modal ui-modal--md" role="dialog" aria-modal="true" aria-labelledby="sent-title">
                <div class="ui-modal-h">
                    <div>
                        <h2 class="ui-modal-title" id="sent-title">{{ $viewingEmail->subject }}
                            @if ($viewingEmail->status !== 'sent') <x-ui.badge tone="danger">{{ __('falló') }}</x-ui.badge>@endif
                        </h2>
                        <p class="ui-modal-sub">
                            <b>{{ $viewingEmail->from_name ?: $viewingEmail->from_address }}</b> &lt;{{ $viewingEmail->from_address }}&gt;
                            → {{ $viewingEmail->to_address }}
                        </p>
                        <p class="ui-modal-sub">
                            {{ ($viewingEmail->sent_at ?? $viewingEmail->created_at)?->translatedFormat('d M Y · H:i') }}
                            · {{ __('por :name', ['name' => $viewingEmail->sentByUser?->name ?? __('Equipo')]) }}
                            · {{ $viewingEmail->status === 'sent' ? __('Enviado') : __('Falló') }}
                        </p>
                    </div>
                    <x-ui.button variant="ghost" icon="x" wire:click="closeSentEmail" :aria-label="__('Cerrar')" />
                </div>

                {{-- Cuerpo tal como se envió (formato + imágenes inline resueltas) --}}
                <div class="ui-modal-b">
                    <div class="sent-body">{!! $viewingBody !!}</div>

                    @if ($viewingEmail->files->isNotEmpty())
                        <div class="ui-field">
                            <span class="ui-label">{{ __('Adjuntos') }}</span>
                            <ul class="ui-list ui-list--boxed">
                                @foreach ($viewingEmail->files as $file)
                                    <li>
                                        <button type="button" class="ui-list-item" wire:click="downloadAttachment({{ $file->id }})">
                                            <x-ui.icon name="file-text" class="ui-card-ic" />
                                            <span class="ui-list-main"><span class="ui-list-title">{{ $file->filename }}</span><span class="ui-list-meta">{{ number_format($file->size / 1024) }} KB</span></span>
                                            <span class="ui-list-aside ui-link"><x-ui.icon name="download" /> {{ __('Descargar') }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="ui-modal-f">
                    <x-ui.button wire:click="closeSentEmail">{{ __('Cerrar') }}</x-ui.button>
                </div>
            </div>
        </div>
        <style>.sent-body{font-size:14px;line-height:1.55;color:var(--mca-text);word-break:break-word}.sent-body img{max-width:100%}.sent-body a{color:var(--mca-blue)}.sent-body ul{list-style:disc;padding-left:1.6em;margin:.5em 0}.sent-body ol{list-style:decimal;padding-left:1.6em;margin:.5em 0}.sent-body li{margin:.2em 0}</style>
    @endif
</div>
