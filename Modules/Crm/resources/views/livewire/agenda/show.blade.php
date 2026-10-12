<div class="crm-page" style="padding:22px 26px 34px">
    <x-ui.crm-styles />
    @include('crm::livewire.agenda.partials.styles')

    @php
        $start = $a->localStart();
        $end = $a->localEnd();
        $isScheduled = $a->isScheduled();
        $isMeeting = $a->type->isMeeting();
    @endphp

    <div style="max-width:1120px">
        <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">
            <a href="{{ route('crm.agenda.index', ['date' => $start->toDateString()]) }}" class="ghost"><x-ui.icon name="chevron-left" class="i15" /> {{ __('Calendario') }}</a>
        </div>

        @if ($notice !== '')
            <div class="toast {{ $noticeError ? 'err' : 'ok' }}" role="status"><x-ui.icon name="{{ $noticeError ? 'alert-triangle' : 'check' }}" class="i15" /> {{ $notice }}</div>
        @endif

        <div class="panel">
            <div class="d-head">
                <span class="ava"><x-ui.icon name="calendar" class="i18" /></span>
                <div style="min-width:0">
                    <div class="nm">{{ $a->title }}</div>
                    <div class="sub">
                        <span>{{ $a->type->label() }}</span> ·
                        <span>{{ $start->translatedFormat('l j \d\e F Y') }}, {{ $start->format('H:i') }}–{{ $end->format('H:i') }} ({{ $a->timezone }})</span>
                        <span class="ag-badge {{ $a->status->value }}">{{ $a->status->label() }}</span>
                    </div>
                </div>
                <div class="d-actions">
                    @if ($canAct && $isScheduled)
                        <button type="button" class="ghost solid" wire:click="complete" wire:loading.attr="disabled"><x-ui.icon name="check" class="i15" /> {{ __('Completar') }}</button>
                        <button type="button" class="ghost" wire:click="open('reschedule')"><x-ui.icon name="clock" class="i15" /> {{ __('Reprogramar') }}</button>
                        <a href="{{ route('crm.agenda.edit', $a) }}" class="ghost"><x-ui.icon name="pencil" class="i15" /> {{ __('Editar') }}</a>
                        <button type="button" class="ghost" wire:click="open('cancel')"><x-ui.icon name="x" class="i15" /> {{ __('Cancelar') }}</button>
                    @elseif (! $canAct)
                        <span class="ro-tag"><x-ui.icon name="eye" class="i12" /> {{ __('Solo lectura') }}</span>
                    @endif
                </div>
            </div>

            @if ($canAct && $isScheduled && $panel === 'reschedule')
                <div style="padding:16px 20px;border-bottom:1px solid var(--line);background:#FBFCFE">
                    <form wire:submit="reschedule" class="ag-form" style="padding:0">
                        <div class="ag-f">
                            <label for="rs-date">{{ __('Nueva fecha') }}</label>
                            <input id="rs-date" type="date" wire:model="rsDate" required>
                            @error('rsDate') <div class="err">{{ $message }}</div> @enderror
                        </div>
                        <div class="ag-f" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <div>
                                <label for="rs-time">{{ __('Hora (:tz)', ['tz' => $a->timezone]) }}</label>
                                <input id="rs-time" type="time" wire:model="rsTime" required>
                                @error('rsTime') <div class="err">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="rs-dur">{{ __('Duración (min)') }}</label>
                                <input id="rs-dur" type="number" min="5" max="720" step="5" wire:model="rsDuration" required>
                            </div>
                        </div>
                        <div class="ag-f full">
                            <label for="rs-reason">{{ __('Motivo (opcional)') }}</label>
                            <input id="rs-reason" type="text" wire:model="rsReason" maxlength="255">
                        </div>
                        @if ($rsWarnings !== [])
                            <div class="ag-warn full" role="alert">
                                <b>{{ __('Este horario no está disponible') }}</b>
                                <ul>@foreach ($rsWarnings as $w)<li>{{ $w }}</li>@endforeach</ul>
                                <label><input type="checkbox" wire:model="rsConfirm"> {{ __('Confirmo la excepción y quiero reprogramar igualmente') }}</label>
                            </div>
                        @endif
                        <div class="full" style="display:flex;gap:8px;justify-content:flex-end">
                            <button type="button" class="ghost" wire:click="open('reschedule')">{{ __('Cerrar') }}</button>
                            <button type="submit" class="ghost solid" wire:loading.attr="disabled">{{ __('Reprogramar') }}</button>
                        </div>
                    </form>
                </div>
            @endif

            @if ($canAct && $isScheduled && $panel === 'cancel')
                <div style="padding:16px 20px;border-bottom:1px solid var(--line);background:#FDF6F6">
                    <form wire:submit="cancelActivity" class="ag-f">
                        <label for="cx-reason">{{ __('Motivo de la cancelación (opcional)') }}</label>
                        <input id="cx-reason" type="text" wire:model="cancelReason" maxlength="1000">
                        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
                            <button type="button" class="ghost" wire:click="open('cancel')">{{ __('Volver') }}</button>
                            <button type="submit" class="ghost" style="color:#B23B3B;border-color:#F5C6C6" wire:loading.attr="disabled">{{ __('Cancelar actividad') }}</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="ag-detail">
                <div class="ag-main">
                    <div class="block">
                        <h3><x-ui.icon name="file-text" class="i14" /> {{ __('Detalles') }}</h3>
                        <div class="ag-kv">
                            <div class="k">{{ __('Asesor') }}</div><div class="v">{{ $a->assignee?->name ?? '—' }}</div>
                            <div class="k">{{ __('Medio') }}</div><div class="v">{{ $a->medium->label() }}</div>
                            <div class="k">{{ __('Duración') }}</div><div class="v">{{ $a->durationMinutes() }} min</div>
                            @if ($viewerTz !== $a->timezone)
                                <div class="k">{{ __('En tu zona') }}</div><div class="v">{{ $a->localStart($viewerTz)->format('d/m H:i') }}–{{ $a->localEnd($viewerTz)->format('H:i') }} ({{ $viewerTz }})</div>
                            @endif
                            <div class="k">{{ __('Prioridad') }}</div><div class="v">{{ ['low' => __('Baja'), 'normal' => __('Normal'), 'high' => __('Alta')][$a->priority] ?? $a->priority }}</div>
                            @if ($a->location)
                                <div class="k">{{ __('Ubicación') }}</div><div class="v">{{ $a->location }}</div>
                            @endif
                            @if ($a->description)
                                <div class="k">{{ __('Descripción') }}</div><div class="v" style="white-space:pre-line">{{ $a->description }}</div>
                            @endif
                            @if ($a->exception_at)
                                <div class="k">{{ __('Excepción') }}</div><div class="v">{{ __('Autorizada por :name el :date', ['name' => $a->exceptionBy?->name ?? '—', 'date' => $a->exception_at->setTimezone($viewerTz)->format('d/m/Y H:i')]) }}@if ($a->exception_reason) — {{ $a->exception_reason }}@endif</div>
                            @endif
                            <div class="k">{{ __('Creada por') }}</div><div class="v">{{ $a->creator?->name ?? '—' }}</div>
                        </div>
                    </div>

                    @if ($joinUrl || $waUrl || $phone || $contact || $a->lead)
                        <div class="block">
                            <h3><x-ui.icon name="external-link" class="i14" /> {{ __('Accesos') }}</h3>
                            <div class="ag-links">
                                @if ($a->lead)
                                    <a href="{{ route('crm.leads.show', $a->lead) }}" class="ghost"><x-ui.icon name="user" class="i15" /> {{ __('Abrir lead') }}</a>
                                @endif
                                @if ($contact)
                                    <a href="{{ route('crm.contacts.show', $contact) }}" class="ghost"><x-ui.icon name="contact" class="i15" /> {{ __('Abrir contacto') }}</a>
                                @endif
                                @if ($joinUrl)
                                    <a href="{{ $joinUrl }}" target="_blank" rel="noopener noreferrer" class="ghost solid"><x-ui.icon name="video" class="i15" /> {{ __('Abrir :medium', ['medium' => $a->medium->label()]) }}</a>
                                @endif
                                @if ($waUrl)
                                    <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer" class="ghost solid"><x-ui.icon name="message-circle" class="i15" /> {{ __('Abrir WhatsApp') }}</a>
                                @endif
                                @if ($phone)
                                    <a href="tel:{{ $phone }}" class="ghost"><x-ui.icon name="phone" class="i15" /> {{ __('Llamar') }}</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="block">
                        <h3><x-ui.icon name="sticky-note" class="i14" /> {{ __('Resultado') }}</h3>
                        @if ($canAct)
                            <div class="ag-f">
                                <textarea wire:model="outcome" maxlength="5000" placeholder="{{ __('¿Qué se habló o acordó?') }}"></textarea>
                                @error('outcome') <div class="err">{{ $message }}</div> @enderror
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                                <button type="button" class="ghost" wire:click="saveOutcome"><x-ui.icon name="check" class="i15" /> {{ __('Guardar resultado') }}</button>
                                @if ($isScheduled)
                                    <button type="button" class="ghost" wire:click="noShow">{{ __('No asistió') }}</button>
                                @endif
                            </div>
                        @else
                            <p style="font-size:13.5px;margin:0;white-space:pre-line">{{ $a->outcome ?: '—' }}</p>
                        @endif
                    </div>

                    @if ($a->reschedules->isNotEmpty())
                        <div class="block">
                            <h3><x-ui.icon name="refresh" class="i14" /> {{ __('Reprogramaciones') }}</h3>
                            @foreach ($a->reschedules->sortByDesc('id') as $r)
                                <div class="ev">
                                    <x-ui.icon name="clock" class="i14" />
                                    <div>
                                        {{ $r->previous_starts_at->setTimezone($a->timezone)->format('d/m H:i') }} → {{ $r->new_starts_at->setTimezone($a->timezone)->format('d/m H:i') }}
                                        @if ($r->reason) · {{ $r->reason }} @endif
                                        <div class="t">{{ $r->created_at?->setTimezone($viewerTz)->format('d/m/Y H:i') }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="ag-side">
                    <div class="block">
                        <h3><x-ui.icon name="users" class="i14" /> {{ __('Invitados') }} <span class="count">{{ $a->guests->whereNull('removed_at')->count() }}</span></h3>
                        @if (! $isMeeting)
                            <p style="font-size:12.5px;color:var(--muted);margin:0">{{ __('Este tipo de actividad no admite invitados.') }}</p>
                        @else
                            @forelse ($a->guests->sortBy('id') as $g)
                                <div class="ag-guest" wire:key="guest-{{ $g->id }}">
                                    <div class="g">
                                        <b>{{ $g->name }}</b>
                                        <small>{{ $g->email }} · {{ strtoupper($g->language) }}{{ $g->contact_id ? ' · '.__('contacto') : '' }}</small>
                                        @php
                                            $gsLabel = ['pending' => __('Pendiente'), 'sent' => __('Invitación enviada'), 'failed' => __('Envío fallido'), 'removed' => __('Quitado')][$g->status] ?? $g->status;
                                        @endphp
                                        <span class="ag-gs {{ $g->status }}">{{ $gsLabel }}</span>
                                        @if ($g->last_emailed_at)
                                            <small>{{ __('Último correo: :date', ['date' => $g->last_emailed_at->setTimezone($viewerTz)->format('d/m/Y H:i')]) }}</small>
                                        @endif
                                    </div>
                                    @if ($canAct && $isScheduled && $g->isActive())
                                        <div class="ga">
                                            <button type="button" wire:click="resendGuest({{ $g->id }})" wire:loading.attr="disabled" title="{{ __('Reenviar') }}" aria-label="{{ __('Reenviar') }}"><x-ui.icon name="mail" class="i14" /></button>
                                            <button type="button" wire:click="removeGuest({{ $g->id }})" wire:confirm="{{ __('¿Quitar a este invitado? Se le enviará la cancelación.') }}" wire:loading.attr="disabled" title="{{ __('Quitar') }}" aria-label="{{ __('Quitar') }}"><x-ui.icon name="trash" class="i14" /></button>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p style="font-size:12.5px;color:var(--muted);margin:0">{{ __('Sin invitados.') }}</p>
                            @endforelse

                            @if ($canAct && $isScheduled)
                                <div class="ag-box">
                                    @error('guest') <div class="err" style="color:#B23B3B;font-size:12px;margin-bottom:6px">{{ $message }}</div> @enderror
                                    @if ($contact && $contact->email && ! $a->guests->whereNull('removed_at')->contains('email', mb_strtolower($contact->email)))
                                        <button type="button" class="ghost" style="width:100%;justify-content:center;margin-bottom:10px" wire:click="addContactGuest({{ $contact->id }})" wire:loading.attr="disabled">
                                            <x-ui.icon name="user-plus" class="i15" /> {{ __('Invitar a :name', ['name' => $contact->fullName()]) }}
                                        </button>
                                    @endif
                                    <div class="ag-f">
                                        <label for="g-search">{{ __('Contacto existente') }}</label>
                                        <input id="g-search" type="text" wire:model.live.debounce.300ms="guestSearch" placeholder="{{ __('Buscar por nombre o correo…') }}">
                                    </div>
                                    @if ($results->isNotEmpty())
                                        <div class="ag-results">
                                            @foreach ($results as $c)
                                                <button type="button" wire:click="addContactGuest({{ $c->id }})" wire:loading.attr="disabled" wire:key="gres-{{ $c->id }}">{{ $c->fullName() }} <small>{{ $c->email }}</small></button>
                                            @endforeach
                                        </div>
                                    @endif
                                    <form wire:submit="addManualGuest" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                                        <div class="ag-f"><label for="g-name">{{ __('O invitado externo: nombre') }}</label><input id="g-name" type="text" wire:model="guestName" maxlength="191">@error('guestName') <div class="err">{{ $message }}</div> @enderror</div>
                                        <div class="ag-f"><label for="g-email">{{ __('Correo') }}</label><input id="g-email" type="email" wire:model="guestEmail" maxlength="191">@error('guestEmail') <div class="err">{{ $message }}</div> @enderror</div>
                                        <div class="ag-f"><label for="g-lang">{{ __('Idioma del correo') }}</label>
                                            <select id="g-lang" wire:model="guestLanguage"><option value="es">Español</option><option value="en">English</option></select>
                                        </div>
                                        <button type="submit" class="ghost solid" style="justify-content:center" wire:loading.attr="disabled"><x-ui.icon name="plus" class="i15" /> {{ __('Añadir y enviar invitación') }}</button>
                                    </form>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
