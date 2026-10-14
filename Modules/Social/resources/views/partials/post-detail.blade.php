{{--
  Detalle de una publicación (Historial, Programadas y Calendario editorial): vista previa,
  datos, resultado independiente por red y las acciones que su estado permite. Requiere
  $detail (SocialPost|null), $tz y $timezones; las acciones viven en ManagesSocialPosts.
--}}
@if ($detail)
    @php
        $fmt = fn ($d) => $d?->copy()->setTimezone($tz)->translatedFormat('j M Y · H:i');
        $canEdit = $detail->isEditable();
    @endphp
    <div class="ui-modal-bg" x-data x-on:keydown.escape.window="$wire.closePost()" wire:key="post-detail-{{ $detail->id }}">
        <div class="ui-modal ui-modal--lg" role="dialog" aria-modal="true" aria-labelledby="post-detail-t">
            <div class="ui-modal-h">
                <div>
                    <h2 class="ui-modal-title" id="post-detail-t">{{ $detail->contentLabel() }} · {{ $detail->media_type === 'video' ? __('Video') : __('Imagen') }}
                        <x-ui.badge :tone="$detail->statusTone()" dot>{{ $detail->statusLabel() }}</x-ui.badge></h2>
                    <p class="ui-modal-sub">{{ __('Creada el :date por :name', ['date' => $fmt($detail->created_at), 'name' => $detail->creator?->name ?? __('Sistema')]) }}</p>
                </div>
                <x-ui.button size="sm" variant="ghost" icon="x" wire:click="closePost" :aria-label="__('Cerrar')" />
            </div>

            <div class="ui-modal-b">
                <div class="ui-pub-detail">
                    <div class="ui-pub-detail-media">
                        @if ($detail->mediaUrl() && $detail->media_type === 'video')
                            <video src="{{ $detail->mediaUrl() }}" controls preload="metadata" playsinline></video>
                        @elseif ($detail->mediaUrl())
                            <img src="{{ $detail->mediaUrl() }}" alt="{{ __('Vista previa') }}">
                        @else
                            <div class="ui-empty"><div class="ui-empty-ic"><x-ui.icon name="image" /></div>{{ __('Sin archivo todavía.') }}</div>
                        @endif
                    </div>
                    <div class="ui-stack">
                        <dl class="ui-meta ui-meta--spaced">
                            <dt>{{ __('Destinos') }}</dt>
                            <dd class="ui-pub-targets">
                                @forelse ($detail->destinationNetworks() as $n)
                                    <span>@include('social::partials.provider-icon', ['provider' => $n, 'size' => 15]) {{ \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n] }}</span>
                                @empty
                                    <span class="t-nil">{{ __('Ninguno') }}</span>
                                @endforelse
                            </dd>
                            @if ($detail->scheduled_at)
                                <dt>{{ __('Programada') }}</dt>
                                <dd>{{ $fmt($detail->scheduled_at) }} <span class="t-3">({{ $tz }})</span>
                                    @if ($detail->timezone && $detail->timezone !== $tz)<br><span class="ui-list-meta">{{ __(':date en :tz', ['date' => $detail->scheduled_at->copy()->setTimezone($detail->timezone)->translatedFormat('j M Y · H:i'), 'tz' => $detail->timezone]) }}</span>@endif
                                </dd>
                            @endif
                            @if ($detail->published_at)
                                <dt>{{ __('Publicada') }}</dt><dd>{{ $fmt($detail->published_at) }}</dd>
                            @endif
                            @if ($detail->cancelled_at)
                                <dt>{{ __('Cancelada') }}</dt><dd>{{ $fmt($detail->cancelled_at) }} · {{ $detail->canceller?->name ?? '—' }}</dd>
                            @endif
                            @if ($detail->updater && $detail->updated_by !== $detail->created_by)
                                <dt>{{ __('Último cambio') }}</dt><dd>{{ $fmt($detail->updated_at) }} · {{ $detail->updater->name }}</dd>
                            @endif
                            @if ($detail->media_name)
                                <dt>{{ __('Archivo') }}</dt><dd class="ui-break">{{ $detail->media_name }}@if ($detail->media_size) · {{ number_format($detail->media_size / 1048576, 1, ',', '.') }} MB @endif</dd>
                            @endif
                        </dl>
                        <div>
                            <span class="ui-label">{{ __('Descripción') }}</span>
                            @if ($detail->content_type === 'story')
                                <p class="ui-help">{{ __('Las Historias se publican sin descripción.') }}</p>
                            @elseif ($detail->caption)
                                <p class="ui-pub-caption-view">{{ $detail->caption }}</p>
                            @else
                                <p class="ui-help">{{ __('Sin descripción') }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Resultado por red --}}
                @if ($detail->targets->isNotEmpty())
                    <section aria-labelledby="post-detail-res">
                        <h3 class="ui-label" id="post-detail-res">{{ __('Resultado por red') }}</h3>
                        <ul class="ui-list ui-list--boxed">
                            @foreach ($detail->targets as $t)
                                <li wire:key="dt-{{ $t->id }}">
                                    <div class="ui-list-item ui-list-item--wrap">
                                        <span class="ui-net-ic">@include('social::partials.provider-icon', ['provider' => $t->network, 'size' => 20])</span>
                                        <span class="ui-list-main">
                                            <span class="ui-person-line">
                                                <span class="ui-list-title">{{ $t->networkLabel() }}</span>
                                                @if ($t->channel)<span class="ui-list-meta">{{ $t->channel->display_name }}</span>@endif
                                                <x-ui.badge :tone="$t->statusTone()" dot>{{ $t->statusLabel() }}</x-ui.badge>
                                            </span>
                                            @if ($t->published_at)<span class="ui-list-meta">{{ __('Publicado el :date', ['date' => $fmt($t->published_at)]) }}</span>@endif
                                            @if ($t->status !== 'published' && $t->error_message)<span class="ui-pub-err">{{ $t->error_message }}</span>@endif
                                            @if ($t->network === 'instagram' && $t->status === 'published')
                                                <span class="ui-list-meta">{{ __('Instagram no permite editar la descripción por API; para borrarla usa la app de Instagram.') }}</span>
                                            @elseif ($t->network === 'facebook' && $t->status === 'published' && $detail->content_type === 'story')
                                                <span class="ui-list-meta">{{ __('Las Historias de Facebook no se pueden editar ni borrar por API.') }}</span>
                                            @endif
                                        </span>
                                        <span class="ui-list-aside ui-list-aside--wrap">
                                            @if ($t->url())
                                                <a href="{{ $t->url() }}" target="_blank" rel="noopener" class="ui-link">{{ __('Ver publicación') }} <x-ui.icon name="external-link" /></a>
                                            @endif
                                            @if ($t->status === 'failed' && ! $canEdit && $detail->status !== 'preparing')
                                                <x-ui.button size="sm" icon="refresh" wire:click="retryTarget({{ $t->id }})" wire:loading.attr="disabled" wire:target="retryTarget"
                                                    wire:confirm="{{ __('¿Reintentar la publicación solo en :network? Las demás redes no se tocan.', ['network' => $t->networkLabel()]) }}">{{ __('Reintentar') }}</x-ui.button>
                                            @elseif ($t->status === 'processing' && $t->network === 'instagram')
                                                <x-ui.button size="sm" icon="refresh" wire:click="resumeTarget({{ $t->id }})" wire:loading.attr="disabled" wire:target="resumeTarget">{{ __('Comprobar ahora') }}</x-ui.button>
                                            @endif
                                            @if ($this->canEditRemote($t))
                                                <x-ui.button size="sm" icon="pencil" wire:click="startRemoteEdit({{ $t->id }})">{{ __('Editar texto') }}</x-ui.button>
                                            @endif
                                            @if ($this->canDeleteRemote($t))
                                                <x-ui.button size="sm" variant="danger" icon="trash" wire:click="deleteRemote({{ $t->id }})"
                                                    wire:confirm="{{ __('¿Eliminar esta publicación de Facebook? Se borra de la Página y no se puede deshacer; el registro se conserva en el historial.') }}">{{ __('Eliminar de Facebook') }}</x-ui.button>
                                            @endif
                                        </span>
                                    </div>
                                    @if ($remoteEditId === $t->id)
                                        <form class="ui-subform ui-card-p ui-stack" wire:submit="saveRemoteEdit">
                                            <x-ui.field :label="__('Texto en Facebook')" for="remote-caption" :error="$errors->first('remoteCaption')">
                                                <textarea id="remote-caption" wire:model="remoteCaption" rows="4" maxlength="2200" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('remoteCaption')])></textarea>
                                            </x-ui.field>
                                            <div class="ui-form-actions ui-form-actions--end">
                                                <x-ui.button variant="ghost" wire:click="cancelRemoteEdit">{{ __('Cancelar') }}</x-ui.button>
                                                <x-ui.button type="submit" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="saveRemoteEdit">{{ __('Guardar en Facebook') }}</x-ui.button>
                                            </div>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Reprogramar --}}
                @if ($rescheduleId === $detail->id)
                    <form class="ui-callout ui-callout--stack" wire:submit="saveReschedule">
                        <b><x-ui.icon name="clock" /> {{ __('Nueva fecha de publicación') }}</b>
                        <div class="ui-form-grid ui-form-grid--3">
                            <x-ui.field :label="__('Fecha')" for="rs-date" :error="$errors->first('rescheduleDate')">
                                <input id="rs-date" type="date" wire:model="rescheduleDate" @class(['ui-input', 'is-invalid' => $errors->has('rescheduleDate')])>
                            </x-ui.field>
                            <x-ui.field :label="__('Hora')" for="rs-time">
                                <input id="rs-time" type="time" wire:model="rescheduleTime" class="ui-input">
                            </x-ui.field>
                            <x-ui.field :label="__('Zona horaria')" for="rs-tz">
                                <select id="rs-tz" wire:model="rescheduleTz" class="ui-select">
                                    @foreach ($timezones as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                                </select>
                            </x-ui.field>
                        </div>
                        <div class="ui-form-actions ui-form-actions--end">
                            <x-ui.button variant="ghost" wire:click="cancelReschedule">{{ __('Cancelar') }}</x-ui.button>
                            <x-ui.button type="submit" variant="primary" icon="check">{{ __('Guardar fecha') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>

            <div class="ui-modal-f">
                @if ($canEdit)
                    @if ($detail->status === 'draft')
                        <x-ui.button variant="danger" icon="trash" wire:click="deleteDraft({{ $detail->id }})" wire:confirm="{{ __('¿Eliminar este borrador? Se borra también su archivo.') }}">{{ __('Eliminar borrador') }}</x-ui.button>
                    @else
                        <x-ui.button variant="ghost" wire:click="toDraft({{ $detail->id }})" wire:confirm="{{ __('¿Devolver a borrador? Dejará de estar programada.') }}">{{ __('Devolver a borrador') }}</x-ui.button>
                        <x-ui.button variant="danger" icon="x" wire:click="cancelPost({{ $detail->id }})" wire:confirm="{{ __('¿Cancelar la programación? No se publicará.') }}">{{ __('Cancelar programación') }}</x-ui.button>
                    @endif
                    <span class="sp"></span>
                    <x-ui.button icon="clock" wire:click="startReschedule({{ $detail->id }})">{{ $detail->status === 'scheduled' ? __('Cambiar fecha') : __('Programar') }}</x-ui.button>
                    <x-ui.button icon="pencil" :href="route('social.publisher', ['editar' => $detail->id])">{{ __('Editar') }}</x-ui.button>
                    <x-ui.button variant="primary" icon="send" wire:click="publishNow({{ $detail->id }})" wire:loading.attr="disabled" wire:target="publishNow"
                        wire:confirm="{{ __('¿Publicar ahora en :networks?', ['networks' => collect($detail->destinationNetworks())->map(fn ($n) => \Modules\Social\Models\SocialPostTarget::NETWORK_LABELS[$n])->implode(' y ') ?: '—']) }}">
                        <span wire:loading.remove wire:target="publishNow">{{ __('Publicar ahora') }}</span>
                        <span wire:loading wire:target="publishNow"><span class="ui-spin"></span> {{ __('Publicando…') }}</span>
                    </x-ui.button>
                @elseif ($detail->status === 'cancelled')
                    <x-ui.button variant="danger" icon="trash" wire:click="deleteDraft({{ $detail->id }})" wire:confirm="{{ __('¿Eliminar esta programación cancelada? Se borra también su archivo.') }}">{{ __('Eliminar') }}</x-ui.button>
                    <span class="sp"></span>
                    <x-ui.button icon="refresh" wire:click="toDraft({{ $detail->id }})">{{ __('Recuperar como borrador') }}</x-ui.button>
                @else
                    <p class="ui-help">{{ in_array($detail->status, ['preparing', 'pending', 'processing'], true)
                        ? __('Se está publicando: no admite cambios hasta terminar.')
                        : __('Ya salió hacia las redes: solo se gestiona cada red por separado.') }}</p>
                    <span class="sp"></span>
                    <x-ui.button wire:click="closePost">{{ __('Cerrar') }}</x-ui.button>
                @endif
            </div>
        </div>
    </div>
@endif
