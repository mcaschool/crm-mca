<div>
    <x-ui.styles />
    <div class="mca-panel" style="padding:22px 26px 34px;max-width:900px">

        <x-ui.settings-tabs />

        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <h2 class="mca-h1" style="font-size:16px;margin:0">Remitentes de correo</h2>
                <p class="mca-sub" style="margin:4px 0 0">Direcciones desde las que el equipo puede enviar correo. Cada una usa su propio SMTP (credenciales cifradas). Todas del dominio <code>{{ '@'.$domain }}</code>.</p>
            </div>
            @unless ($showForm)
                <button type="button" wire:click="newSender" class="btn btn-primary"><x-ui.icon name="plus" /> Nuevo remitente</button>
            @endunless
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade" style="margin-top:14px"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif

        {{-- Formulario alta/edición --}}
        @if ($showForm)
            <div class="card card-p fade" style="margin-top:16px">
                <h3 style="margin:0 0 14px;font-size:15px;font-weight:700">{{ $editingId ? 'Editar remitente' : 'Nuevo remitente' }}</h3>

                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <div class="field" style="flex:1;min-width:220px">
                        <label>Nombre visible</label>
                        <input type="text" wire:model="name" maxlength="120" placeholder="Ej. Finanzas MCA">
                        @error('name') <span class="mca-err">{{ $message }}</span> @enderror
                        <div class="mca-help">Aparece como el “De” del correo.</div>
                    </div>
                    <div class="field" style="flex:1;min-width:220px">
                        <label>Dirección ({{ '@'.$domain }})</label>
                        <input type="email" wire:model="from_address" maxlength="190" placeholder="{{ 'finanzas@'.$domain }}">
                        @error('from_address') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div style="border-top:1px solid var(--line);margin:8px 0 14px"></div>
                <div class="mca-help" style="margin-bottom:10px"><x-ui.icon name="lock" class="ic" style="width:13px;height:13px" /> Credenciales SMTP (se guardan cifradas; la contraseña nunca se vuelve a mostrar).</div>

                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <div class="field" style="flex:2;min-width:220px">
                        <label>Host SMTP</label>
                        <input type="text" wire:model="host" maxlength="190" placeholder="smtp.hostinger.com">
                        @error('host') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="flex:1;min-width:120px">
                        <label>Puerto</label>
                        <input type="number" wire:model="port" min="1" max="65535" placeholder="465">
                        @error('port') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="flex:1;min-width:140px">
                        <label>Cifrado</label>
                        <select wire:model="encryption">
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                            <option value="none">Ninguno</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <div class="field" style="flex:1;min-width:220px">
                        <label>Usuario SMTP</label>
                        <input type="text" wire:model="username" maxlength="190" autocomplete="off" placeholder="{{ 'finanzas@'.$domain }}">
                        @error('username') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="flex:1;min-width:220px">
                        <label>Contraseña SMTP</label>
                        <input type="password" wire:model="password" maxlength="500" autocomplete="new-password" placeholder="{{ $editingId ? '•••••• (dejar en blanco conserva la actual)' : '' }}">
                        @error('password') <span class="mca-err">{{ $message }}</span> @enderror
                        @if ($editingId)<div class="mca-help">Deja en blanco para conservar la contraseña guardada.</div>@endif
                    </div>
                </div>

                <div class="field" style="max-width:220px">
                    <label>Estado</label>
                    <select wire:model="status">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <div style="display:flex;gap:10px;margin-top:8px">
                    <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary"><x-ui.icon name="check" class="ic" /> Guardar</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn btn-ghost">Cancelar</button>
                </div>
            </div>
        @endif

        {{-- Listado --}}
        <div style="margin-top:18px;display:flex;flex-direction:column;gap:12px">
            @forelse ($senders as $sender)
                <div class="card card-p fade" wire:key="sender-{{ $sender->id }}">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div>
                            <div style="font-weight:700;font-size:15px;color:var(--ink)">{{ $sender->name }}
                                @if ($sender->status !== 'active')<span class="mca-help" style="font-weight:600">· inactivo</span>@endif
                            </div>
                            <div class="mca-help" style="margin-top:2px"><x-ui.icon name="mail" class="ic" style="width:13px;height:13px" /> {{ $sender->from_address }}</div>
                            <div class="mca-help" style="margin-top:4px">
                                SMTP: {{ $sender->maskedConfig()['host'] ?? '—' }}:{{ $sender->maskedConfig()['port'] ?? '—' }}
                                · usuario {{ $sender->maskedConfig()['username'] ?? '—' }}
                                · contraseña <code>{{ $sender->maskedConfig()['password'] ?? '••••' }}</code>
                            </div>
                            @if ($sender->last_tested_at)
                                <div class="mca-help" style="margin-top:4px;color:{{ $sender->last_test_ok ? 'var(--mca-ok, #1B7A4B)' : 'var(--mca-warn, #B4232A)' }}">
                                    <x-ui.icon name="{{ $sender->last_test_ok ? 'check' : 'x' }}" class="ic" style="width:13px;height:13px" />
                                    Última prueba {{ $sender->last_tested_at->diffForHumans() }}: {{ $sender->last_test_message }}
                                </div>
                            @endif
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="button" wire:click="edit({{ $sender->id }})" class="btn btn-ghost btn-sm"><x-ui.icon name="pencil" class="ic" style="width:14px;height:14px" /> Editar</button>
                            <button type="button" wire:click="delete({{ $sender->id }})" wire:confirm="¿Eliminar el remitente “{{ $sender->name }}”?" class="btn btn-ghost btn-sm"><x-ui.icon name="trash" class="ic" style="width:14px;height:14px" /> Eliminar</button>
                        </div>
                    </div>

                    {{-- Probar envío --}}
                    <div style="border-top:1px solid var(--line);margin-top:12px;padding-top:12px;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                        <div class="field" style="margin:0;flex:1;min-width:220px">
                            <input type="email" wire:model="testTo" maxlength="190" placeholder="Correo de destino para la prueba">
                            @error('testTo') <span class="mca-err">{{ $message }}</span> @enderror
                        </div>
                        <button type="button" wire:click="test({{ $sender->id }})" wire:loading.attr="disabled" wire:target="test({{ $sender->id }})" class="btn btn-ghost">
                            <x-ui.icon name="mail" class="ic" style="width:15px;height:15px" /> Probar envío
                            <span wire:loading wire:target="test({{ $sender->id }})"><span class="mca-spin"></span></span>
                        </button>
                    </div>
                </div>
            @empty
                <div class="card card-p" style="text-align:center;color:var(--muted)">Aún no hay remitentes. Crea el primero con “Nuevo remitente”.</div>
            @endforelse
        </div>
    </div>
</div>
