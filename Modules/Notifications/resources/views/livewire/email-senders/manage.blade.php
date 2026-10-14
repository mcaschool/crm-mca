<div class="mca-page">
    <div class="ui-narrow">
        <x-ui.settings-tabs />

        @if (session('status'))
            <div class="ui-alert ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
        @endif

        <section class="ui-card" aria-labelledby="senders-title">
            <div class="ui-card-h">
                <x-ui.icon name="mail" class="ui-card-ic" /><h2 id="senders-title">{{ __('Remitentes de correo') }}</h2>
                <x-ui.badge tone="neutral" class="ui-badge--count">{{ $senders->count() }}</x-ui.badge>
                <span class="sp"></span>
                @unless ($showForm)
                    <x-ui.button variant="primary" size="sm" icon="plus" wire:click="newSender">{{ __('Nuevo remitente') }}</x-ui.button>
                @endunless
            </div>
            <p class="ui-card-intro">{{ __('Direcciones desde las que el equipo puede enviar correo. Cada una usa su propio SMTP (credenciales cifradas).') }}
                {{ __('Todas del dominio') }} <span class="ui-mono">{{ '@'.$domain }}</span>.</p>

            {{-- Formulario alta/edición --}}
            @if ($showForm)
                <div class="ui-subform" role="group" aria-labelledby="sender-form-title">
                    <div class="ui-card-h">
                        <h3 id="sender-form-title">{{ $editingId ? __('Editar remitente') : __('Nuevo remitente') }}</h3>
                    </div>
                    <div class="ui-card-p ui-stack">
                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Nombre visible')" for="snd-name" :error="$errors->first('name')" :help="__('Aparece como el “De” del correo.')">
                                <input id="snd-name" type="text" wire:model="name" maxlength="120" placeholder="{{ __('Ej. Finanzas MCA') }}"
                                    @if ($errors->has('name')) aria-describedby="snd-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Dirección').' ('.'@'.$domain.')'" for="snd-from" :error="$errors->first('from_address')">
                                <input id="snd-from" type="email" wire:model="from_address" maxlength="190" placeholder="{{ 'finanzas@'.$domain }}"
                                    @if ($errors->has('from_address')) aria-describedby="snd-from-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('from_address')])>
                            </x-ui.field>
                        </div>

                        <div class="ui-callout ui-callout--sm"><x-ui.icon name="lock" /> <span>{{ __('Credenciales SMTP (se guardan cifradas; la contraseña nunca se vuelve a mostrar).') }}</span></div>

                        <div class="ui-form-grid ui-form-grid--3">
                            <x-ui.field :label="__('Host SMTP')" for="snd-host" :error="$errors->first('host')">
                                <input id="snd-host" type="text" wire:model="host" maxlength="190" placeholder="smtp.hostinger.com" autocapitalize="off" spellcheck="false"
                                    @if ($errors->has('host')) aria-describedby="snd-host-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('host')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Puerto')" for="snd-port" :error="$errors->first('port')">
                                <input id="snd-port" type="number" wire:model="port" min="1" max="65535" placeholder="465" inputmode="numeric"
                                    @if ($errors->has('port')) aria-describedby="snd-port-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('port')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Cifrado')" for="snd-enc">
                                <select id="snd-enc" wire:model="encryption" class="ui-select">
                                    <option value="ssl">SSL</option>
                                    <option value="tls">TLS</option>
                                    <option value="none">{{ __('Ninguno') }}</option>
                                </select>
                            </x-ui.field>
                        </div>

                        <div class="ui-form-grid">
                            <x-ui.field :label="__('Usuario SMTP')" for="snd-user" :error="$errors->first('username')">
                                <input id="snd-user" type="text" wire:model="username" maxlength="190" autocomplete="off" placeholder="{{ 'finanzas@'.$domain }}" autocapitalize="off" spellcheck="false"
                                    @if ($errors->has('username')) aria-describedby="snd-user-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('username')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Contraseña SMTP')" for="snd-pass" :error="$errors->first('password')" :help="$editingId ? __('Deja en blanco para conservar la contraseña guardada.') : null">
                                <input id="snd-pass" type="password" wire:model="password" maxlength="500" autocomplete="new-password" placeholder="{{ $editingId ? __('•••••• (dejar en blanco conserva la actual)') : '' }}"
                                    @if ($errors->has('password')) aria-describedby="snd-pass-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('password')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Estado')" for="snd-status">
                                <select id="snd-status" wire:model="status" class="ui-select">
                                    <option value="active">{{ __('Activo') }}</option>
                                    <option value="inactive">{{ __('Inactivo') }}</option>
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
                @forelse ($senders as $sender)
                    @php $cfg = $sender->maskedConfig(); @endphp
                    <li wire:key="sender-{{ $sender->id }}">
                        <div class="ui-list-item ui-list-item--stack-sm">
                            <div class="ui-list-main">
                                <span class="ui-person-line">
                                    <span class="ui-list-title">{{ $sender->name }}</span>
                                    <x-ui.badge :tone="$sender->status === 'active' ? 'success' : 'neutral'" dot>{{ $sender->status === 'active' ? __('Activo') : __('Inactivo') }}</x-ui.badge>
                                    @if ($sender->agenda_default)
                                        <x-ui.badge tone="blue" icon="calendar">{{ __('Remitente del Calendario') }}</x-ui.badge>
                                    @endif
                                </span>
                                <dl class="ui-meta ui-meta--spaced ui-meta--stack-sm">
                                    <dt>{{ __('Dirección') }}</dt><dd>{{ $sender->from_address }}</dd>
                                    <dt>SMTP</dt><dd>{{ $cfg['host'] ?? '—' }}:{{ $cfg['port'] ?? '—' }}</dd>
                                    <dt>{{ __('Usuario') }}</dt><dd>{{ $cfg['username'] ?? '—' }}</dd>
                                    <dt>{{ __('Contraseña') }}</dt><dd><span class="ui-mono">{{ $cfg['password'] ?? '••••' }}</span></dd>
                                    @if ($sender->last_tested_at)
                                        <dt>{{ __('Última prueba') }}</dt>
                                        <dd @class(['ui-text-success' => $sender->last_test_ok, 'ui-text-danger' => ! $sender->last_test_ok])>
                                            <x-ui.icon :name="$sender->last_test_ok ? 'check' : 'x'" class="ui-meta-ic" /> {{ $sender->last_tested_at->diffForHumans() }}: {{ $sender->last_test_message }}
                                        </dd>
                                    @endif
                                </dl>

                                {{-- Probar envío --}}
                                <div class="ui-inline ui-actions-row--top">
                                    <input type="email" wire:model="testTo" maxlength="190" placeholder="{{ __('Correo de destino para la prueba') }}"
                                        aria-label="{{ __('Correo de destino para la prueba de :name', ['name' => $sender->name]) }}" @class(['ui-input', 'is-invalid' => $errors->has('testTo')])>
                                    <button type="button" wire:click="test({{ $sender->id }})" wire:loading.attr="disabled" wire:target="test({{ $sender->id }})" class="ui-btn ui-btn--secondary">
                                        <x-ui.icon name="send" /> {{ __('Probar envío') }}
                                        <span wire:loading wire:target="test({{ $sender->id }})"><span class="ui-spin"></span></span>
                                    </button>
                                </div>
                                @error('testTo') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            </div>
                            <div class="ui-list-aside">
                                @if ($sender->status === 'active' && ! $sender->agenda_default)
                                    <x-ui.button size="sm" icon="calendar" wire:click="useForAgenda({{ $sender->id }})">{{ __('Usar para el Calendario') }}</x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit({{ $sender->id }})" :title="__('Editar')" :aria-label="__('Editar :name', ['name' => $sender->name])" />
                                <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $sender->id }})" wire:confirm="{{ __('¿Eliminar el remitente “:name”?', ['name' => $sender->name]) }}" :title="__('Eliminar')" :aria-label="__('Eliminar :name', ['name' => $sender->name])" />
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="ui-empty">
                        <div class="ui-empty-ic"><x-ui.icon name="mail" /></div>
                        <b>{{ __('Aún no hay remitentes.') }}</b>
                        {{ __('Crea el primero con “Nuevo remitente”.') }}
                    </li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
