@php
    use Modules\Crm\Support\Agenda\CalendarGrid;

    $start = $a->localStart();
    $end = $a->localEnd();
    $isScheduled = $a->isScheduled();
    $isMeeting = $a->type->isMeeting();
    $tone = CalendarGrid::statusTone($a->status);
    $mediumIcon = CalendarGrid::mediumIcon($a->medium);
    $activeGuests = $a->guests->whereNull('removed_at')->count();
    $priority = ['low' => __('Baja'), 'normal' => __('Normal'), 'high' => __('Alta')][$a->priority] ?? $a->priority;
@endphp
<div class="mca-page">
    <div class="ui-record ui-record--main-first">
        <a class="ui-back" href="{{ route('crm.agenda.index', ['date' => $start->toDateString()]) }}"><x-ui.icon name="chevron-left" /> {{ __('Calendario') }}</a>

        @if ($notice !== '')
            <div @class(['ui-alert', $noticeError ? 'ui-tone--danger' : 'ui-tone--success']) role="status"><x-ui.icon :name="$noticeError ? 'alert-triangle' : 'check'" /> {{ $notice }}</div>
        @endif

        {{-- Cabecera: qué, cuándo, estado y acciones --}}
        <div class="ui-card ui-record-h">
            <span class="ui-avatar ui-avatar--xl ui-avatar--sq ui-tone--{{ $tone }}" aria-hidden="true"><x-ui.icon :name="CalendarGrid::typeIcon($a->type)" /></span>
            <div class="ui-record-id">
                <div class="ui-person-line">
                    <x-ui.badge :tone="$tone" dot>{{ $a->status->label() }}</x-ui.badge>
                    <x-ui.badge tone="neutral">{{ $a->type->label() }}</x-ui.badge>
                    @if ($a->priority === 'high')<x-ui.badge tone="danger" icon="alert-triangle">{{ __('Prioridad alta') }}</x-ui.badge>@endif
                </div>
                <h1 @class(['ui-record-title', 'is-struck' => $a->status->value === 'cancelled'])>{{ $a->title }}</h1>
                <div class="ui-record-meta">
                    <span><x-ui.icon name="calendar" /><b>{{ \Illuminate\Support\Str::ucfirst($start->translatedFormat('l j \d\e F Y')) }}</b></span>
                    <span><x-ui.icon name="clock" /><b>{{ $start->format('H:i') }}–{{ $end->format('H:i') }}</b> ({{ $a->timezone }})</span>
                    @if ($mediumIcon)<span><x-ui.icon :name="$mediumIcon" />{{ $a->medium->label() }}</span>@endif
                    <span><x-ui.icon name="user-cog" />{{ $a->assignee?->name ?? '—' }}</span>
                </div>
            </div>
            <div class="ui-record-actions">
                @if ($canAct && $isScheduled)
                    <x-ui.button variant="primary" icon="check" wire:click="complete" wire:loading.attr="disabled">{{ __('Completar') }}</x-ui.button>
                    <x-ui.button icon="clock" wire:click="open('reschedule')" :aria-expanded="$panel === 'reschedule' ? 'true' : 'false'">{{ __('Reprogramar') }}</x-ui.button>
                    <x-ui.button icon="pencil" :href="route('crm.agenda.edit', $a)">{{ __('Editar') }}</x-ui.button>
                    <x-ui.button variant="danger" icon="x" wire:click="open('cancel')" :aria-expanded="$panel === 'cancel' ? 'true' : 'false'">{{ __('Cancelar') }}</x-ui.button>
                @elseif (! $canAct)
                    <x-ui.badge tone="neutral" icon="eye">{{ __('Solo lectura') }}</x-ui.badge>
                @endif
            </div>
        </div>

        {{-- Reprogramar / cancelar: se despliegan bajo la cabecera --}}
        @if ($canAct && $isScheduled && $panel === 'reschedule')
            <form wire:submit="reschedule" class="ui-card" novalidate>
                <div class="ui-card-h"><x-ui.icon name="clock" class="ui-card-ic" /><h2>{{ __('Reprogramar actividad') }}</h2></div>
                <div class="ui-card-p">
                    <div class="ui-form-grid ui-form-grid--3">
                        <x-ui.field :label="__('Nueva fecha')" for="rs-date" required :error="$errors->first('rsDate')">
                            <input id="rs-date" type="date" wire:model="rsDate" required @class(['ui-input', 'is-invalid' => $errors->has('rsDate')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Hora (:tz)', ['tz' => $a->timezone])" for="rs-time" required :error="$errors->first('rsTime')">
                            <input id="rs-time" type="time" wire:model="rsTime" required @class(['ui-input', 'is-invalid' => $errors->has('rsTime')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Duración (min)')" for="rs-dur" required :error="$errors->first('rsDuration')">
                            <input id="rs-dur" type="number" min="5" max="720" step="5" inputmode="numeric" wire:model="rsDuration" required @class(['ui-input', 'is-invalid' => $errors->has('rsDuration')])>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Motivo')" for="rs-reason" optional>
                            <input id="rs-reason" type="text" wire:model="rsReason" maxlength="255" class="ui-input">
                        </x-ui.field>
                        @if ($rsWarnings !== [])
                            <div class="ui-callout ui-tone--warning ui-callout--stack ui-span-full" role="alert">
                                <b><x-ui.icon name="alert-triangle" /> {{ __('Este horario no está disponible') }}</b>
                                <ul class="ui-callout-list">@foreach ($rsWarnings as $w)<li>{{ $w }}</li>@endforeach</ul>
                                <label class="ui-check"><input type="checkbox" wire:model="rsConfirm"> {{ __('Confirmo la excepción y quiero reprogramar igualmente') }}</label>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button wire:click="open('reschedule')">{{ __('Cerrar') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="clock" wire:loading.attr="disabled">{{ __('Reprogramar') }}</x-ui.button>
                </div>
            </form>
        @endif

        @if ($canAct && $isScheduled && $panel === 'cancel')
            <form wire:submit="cancelActivity" class="ui-card ui-card--danger">
                <div class="ui-card-h"><x-ui.icon name="x" class="ui-card-ic" /><h2>{{ __('Cancelar actividad') }}</h2></div>
                <div class="ui-card-p">
                    <x-ui.field :label="__('Motivo de la cancelación')" for="cx-reason" optional :help="$isMeeting && $activeGuests > 0 ? __('Los invitados recibirán la cancelación por correo.') : null">
                        <input id="cx-reason" type="text" wire:model="cancelReason" maxlength="1000" class="ui-input">
                    </x-ui.field>
                </div>
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button wire:click="open('cancel')">{{ __('Volver') }}</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="x" wire:loading.attr="disabled">{{ __('Cancelar actividad') }}</x-ui.button>
                </div>
            </form>
        @endif

        <div class="ui-record-grid">
            <div class="ui-record-main">
                {{-- Accesos directos --}}
                @if ($joinUrl || $waUrl || $phone || $contact || $a->lead)
                    <section class="ui-card" aria-labelledby="ag-access">
                        <div class="ui-card-h"><x-ui.icon name="external-link" class="ui-card-ic" /><h2 id="ag-access">{{ __('Accesos') }}</h2></div>
                        <div class="ui-card-p ui-actions-row">
                            @if ($joinUrl)
                                <a href="{{ $joinUrl }}" target="_blank" rel="noopener noreferrer" class="ui-btn ui-btn--primary"><x-ui.icon name="video" /> {{ __('Abrir :medium', ['medium' => $a->medium->label()]) }}</a>
                            @endif
                            @if ($waUrl)
                                <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer" class="ui-btn ui-btn--primary"><x-ui.icon name="message-circle" /> {{ __('Abrir WhatsApp') }}</a>
                            @endif
                            @if ($phone)
                                <x-ui.button icon="phone" :href="'tel:'.$phone">{{ __('Llamar') }}</x-ui.button>
                            @endif
                            @if ($a->lead)
                                <x-ui.button icon="user" :href="route('crm.leads.show', $a->lead)">{{ __('Abrir lead') }}</x-ui.button>
                            @endif
                            @if ($contact)
                                <x-ui.button icon="contact" :href="route('crm.contacts.show', $contact)">{{ __('Abrir contacto') }}</x-ui.button>
                            @endif
                        </div>
                    </section>
                @endif

                {{-- Resultado --}}
                <section class="ui-card" aria-labelledby="ag-outcome">
                    <div class="ui-card-h"><x-ui.icon name="sticky-note" class="ui-card-ic" /><h2 id="ag-outcome">{{ __('Resultado') }}</h2></div>
                    <div class="ui-card-p">
                        @if ($canAct)
                            <x-ui.field :label="__('¿Qué se habló o acordó?')" for="ag-outcome-t" :error="$errors->first('outcome')">
                                <textarea id="ag-outcome-t" wire:model="outcome" maxlength="5000" rows="4" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('outcome')])></textarea>
                            </x-ui.field>
                            <div class="ui-actions-row ui-actions-row--top">
                                <x-ui.button icon="check" wire:click="saveOutcome">{{ __('Guardar resultado') }}</x-ui.button>
                                @if ($isScheduled)
                                    <x-ui.button variant="ghost" wire:click="noShow">{{ __('No asistió') }}</x-ui.button>
                                @endif
                            </div>
                        @else
                            <p class="ui-note-body">{{ $a->outcome ?: '—' }}</p>
                        @endif
                    </div>
                </section>

                {{-- Detalles --}}
                <section class="ui-card" aria-labelledby="ag-details">
                    <div class="ui-card-h"><x-ui.icon name="file-text" class="ui-card-ic" /><h2 id="ag-details">{{ __('Detalles') }}</h2></div>
                    <div class="ui-card-p">
                        <dl class="ui-meta ui-meta--spaced">
                            <dt>{{ __('Asesor') }}</dt><dd>{{ $a->assignee?->name ?? '—' }}</dd>
                            <dt>{{ __('Medio') }}</dt><dd>{{ $a->medium->label() }}</dd>
                            <dt>{{ __('Duración') }}</dt><dd>{{ $a->durationMinutes() }} min</dd>
                            @if ($viewerTz !== $a->timezone)
                                <dt>{{ __('En tu zona') }}</dt><dd>{{ $a->localStart($viewerTz)->format('d/m H:i') }}–{{ $a->localEnd($viewerTz)->format('H:i') }} ({{ $viewerTz }})</dd>
                            @endif
                            <dt>{{ __('Prioridad') }}</dt><dd>{{ $priority }}</dd>
                            @if ($a->location)
                                <dt>{{ __('Ubicación') }}</dt><dd>{{ $a->location }}</dd>
                            @endif
                            @if ($a->description)
                                <dt>{{ __('Descripción') }}</dt><dd class="ui-note-body">{{ $a->description }}</dd>
                            @endif
                            @if ($a->exception_at)
                                <dt>{{ __('Excepción') }}</dt><dd>{{ __('Autorizada por :name el :date', ['name' => $a->exceptionBy?->name ?? '—', 'date' => $a->exception_at->setTimezone($viewerTz)->format('d/m/Y H:i')]) }}@if ($a->exception_reason) — {{ $a->exception_reason }}@endif</dd>
                            @endif
                            <dt>{{ __('Creada por') }}</dt><dd>{{ $a->creator?->name ?? '—' }}</dd>
                        </dl>
                    </div>
                </section>

                @if ($a->reschedules->isNotEmpty())
                    <section class="ui-card" aria-labelledby="ag-resch">
                        <div class="ui-card-h"><x-ui.icon name="refresh" class="ui-card-ic" /><h2 id="ag-resch">{{ __('Reprogramaciones') }}</h2><x-ui.badge tone="neutral" class="ui-badge--count">{{ $a->reschedules->count() }}</x-ui.badge></div>
                        <ol class="ui-timeline">
                            @foreach ($a->reschedules->sortByDesc('id') as $r)
                                <li>
                                    <span class="ui-tl-ic ui-tone--warning"><x-ui.icon name="clock" /></span>
                                    <div class="ui-tl-body">
                                        <b>{{ $r->previous_starts_at->setTimezone($a->timezone)->format('d/m H:i') }} → {{ $r->new_starts_at->setTimezone($a->timezone)->format('d/m H:i') }}</b>
                                        @if ($r->reason)<div class="ui-tl-detail">{{ $r->reason }}</div>@endif
                                        <div class="ui-tl-time">{{ $r->created_at?->setTimezone($viewerTz)->format('d/m/Y H:i') }}</div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>

            <div class="ui-record-side">
                {{-- Invitados --}}
                <section class="ui-card" aria-labelledby="ag-guests">
                    <div class="ui-card-h"><x-ui.icon name="users" class="ui-card-ic" /><h2 id="ag-guests">{{ __('Invitados') }}</h2><x-ui.badge tone="neutral" class="ui-badge--count">{{ $activeGuests }}</x-ui.badge></div>
                    @if (! $isMeeting)
                        <p class="ui-list-empty">{{ __('Este tipo de actividad no admite invitados.') }}</p>
                    @else
                        <ul class="ui-list">
                            @forelse ($a->guests->sortBy('id') as $g)
                                @php
                                    $gsLabel = ['pending' => __('Pendiente'), 'sent' => __('Invitación enviada'), 'failed' => __('Envío fallido'), 'removed' => __('Quitado')][$g->status] ?? $g->status;
                                    $gsTone = ['pending' => 'blue', 'sent' => 'success', 'failed' => 'danger', 'removed' => 'neutral'][$g->status] ?? 'neutral';
                                @endphp
                                <li wire:key="guest-{{ $g->id }}">
                                    <div class="ui-list-item">
                                        <span class="ui-list-main">
                                            <span class="ui-list-title">{{ $g->name }}</span>
                                            <span class="ui-list-meta">{{ $g->email }} · {{ strtoupper($g->language) }}{{ $g->contact_id ? ' · '.__('contacto') : '' }}</span>
                                            <span class="ui-person-line"><x-ui.badge :tone="$gsTone">{{ $gsLabel }}</x-ui.badge>
                                                @if ($g->last_emailed_at)<span class="ui-list-meta">{{ __('Último correo: :date', ['date' => $g->last_emailed_at->setTimezone($viewerTz)->format('d/m/Y H:i')]) }}</span>@endif
                                            </span>
                                        </span>
                                        @if ($canAct && $isScheduled && $g->isActive())
                                            <span class="ui-list-aside">
                                                <x-ui.button size="sm" variant="ghost" icon="mail" wire:click="resendGuest({{ $g->id }})" wire:loading.attr="disabled" :title="__('Reenviar')" :aria-label="__('Reenviar invitación a :name', ['name' => $g->name])" />
                                                <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="removeGuest({{ $g->id }})" wire:confirm="{{ __('¿Quitar a este invitado? Se le enviará la cancelación.') }}" wire:loading.attr="disabled" :title="__('Quitar')" :aria-label="__('Quitar a :name', ['name' => $g->name])" />
                                            </span>
                                        @endif
                                    </div>
                                </li>
                            @empty
                                <li class="ui-list-empty">{{ __('Sin invitados.') }}</li>
                            @endforelse
                        </ul>

                        @if ($canAct && $isScheduled)
                            <div class="ui-card-p ui-card-p--sep">
                                @error('guest') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                                @if ($contact && $contact->email && ! $a->guests->whereNull('removed_at')->contains('email', mb_strtolower($contact->email)))
                                    <x-ui.button class="ui-btn--block" icon="user-plus" wire:click="addContactGuest({{ $contact->id }})" wire:loading.attr="disabled">{{ __('Invitar a :name', ['name' => $contact->fullName()]) }}</x-ui.button>
                                @endif
                                <x-ui.field :label="__('Contacto existente')" for="g-search">
                                    <div class="ui-search">
                                        <x-ui.icon name="search" />
                                        <input id="g-search" type="search" class="ui-input" wire:model.live.debounce.300ms="guestSearch" autocomplete="off" placeholder="{{ __('Buscar por nombre o correo…') }}">
                                    </div>
                                </x-ui.field>
                                @if ($results->isNotEmpty())
                                    <ul class="ui-list ui-list--boxed ui-results">
                                        @foreach ($results as $c)
                                            <li wire:key="gres-{{ $c->id }}">
                                                <button type="button" class="ui-list-item" wire:click="addContactGuest({{ $c->id }})" wire:loading.attr="disabled">
                                                    <span class="ui-list-main"><span class="ui-list-title">{{ $c->fullName() }}</span><span class="ui-list-meta">{{ $c->email }}</span></span>
                                                    <x-ui.icon name="plus" class="ui-list-go" />
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <form wire:submit="addManualGuest" class="ui-guest-form" novalidate>
                                    <p class="ui-card-kicker ui-card-kicker--flush">{{ __('O invitado externo') }}</p>
                                    <x-ui.field :label="__('Nombre')" for="g-name" :error="$errors->first('guestName')">
                                        <input id="g-name" type="text" wire:model="guestName" maxlength="191" @class(['ui-input', 'is-invalid' => $errors->has('guestName')])>
                                    </x-ui.field>
                                    <x-ui.field :label="__('Correo')" for="g-email" :error="$errors->first('guestEmail')">
                                        <input id="g-email" type="email" wire:model="guestEmail" maxlength="191" @class(['ui-input', 'is-invalid' => $errors->has('guestEmail')])>
                                    </x-ui.field>
                                    <x-ui.field :label="__('Idioma del correo')" for="g-lang">
                                        <select id="g-lang" wire:model="guestLanguage" class="ui-select"><option value="es">Español</option><option value="en">English</option></select>
                                    </x-ui.field>
                                    <x-ui.button type="submit" variant="primary" class="ui-btn--block" icon="plus" wire:loading.attr="disabled">{{ __('Añadir y enviar invitación') }}</x-ui.button>
                                </form>
                            </div>
                        @endif
                    @endif
                </section>
            </div>
        </div>
    </div>
</div>
