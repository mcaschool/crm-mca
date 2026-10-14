@php
    $isVideo = $contentType === 'reel'
        || ($contentType === 'post' && $postMedia === 'video')
        || ($contentType === 'story' && $storyMedia === 'video');
    $formats = [
        'post' => [__('Post'), __('Imagen o video con descripción, en el feed.')],
        'reel' => [__('Reel'), __('Video vertical con descripción.')],
        'story' => [__('Historia'), __('Imagen o video a pantalla completa, sin descripción.')],
    ];
    $file = $isVideo ? $video : $image;
    $targets = collect([
        $toFacebook && $hasFacebook ? 'facebook' : null,
        $toInstagram && $hasInstagram ? 'instagram' : null,
    ])->filter();
@endphp
<div class="mca-page" wire:key="social-publisher">
    <x-ui.page-header :title="__('Publicador')" :subtitle="__('Publica un Post, un Reel o una Historia en la Página de Facebook y en Instagram.')" />

    <div class="ui-pub" x-data="{ chars: ($wire.caption || '').length }" x-init="$wire.$watch('caption', v => chars = (v || '').length)">
        {{-- ===================== Contenido ===================== --}}
        <form id="pub-form" class="ui-card ui-pub-editor" wire:submit.prevent="publish" novalidate>
            <div class="ui-card-h"><x-ui.icon name="layers" class="ui-card-ic" /><h2>{{ __('Formato') }}</h2></div>
            <div class="ui-card-p ui-pub-stack">
                <div class="ui-field">
                    <span class="ui-label" id="pub-type-l">{{ __('Tipo de publicación') }}</span>
                    <div class="ui-seg ui-seg--fill" role="group" aria-labelledby="pub-type-l">
                        @foreach ($formats as $key => [$label])
                            <button type="button" wire:click="setContentType('{{ $key }}')" aria-pressed="{{ $contentType === $key ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <p class="ui-help">{{ $formats[$contentType][1] }}</p>
                </div>

                {{-- Post e Historia eligen entre imagen o video (el Reel siempre es video) --}}
                @if ($contentType === 'post' || $contentType === 'story')
                    @php $mediaNow = $contentType === 'post' ? $postMedia : $storyMedia; $mediaSetter = $contentType === 'post' ? 'setPostMedia' : 'setStoryMedia'; @endphp
                    <div class="ui-field">
                        <span class="ui-label" id="pub-media-l">{{ __('Contenido') }}</span>
                        <div class="ui-seg ui-seg--fill ui-seg--icons" role="group" aria-labelledby="pub-media-l">
                            <button type="button" wire:click="{{ $mediaSetter }}('image')" aria-pressed="{{ $mediaNow === 'image' ? 'true' : 'false' }}"><x-ui.icon name="image" /> {{ __('Imagen') }}</button>
                            <button type="button" wire:click="{{ $mediaSetter }}('video')" aria-pressed="{{ $mediaNow === 'video' ? 'true' : 'false' }}"><x-ui.icon name="video" /> {{ __('Video') }}</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- ===================== Multimedia ===================== --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="{{ $isVideo ? 'video' : 'image' }}" class="ui-card-ic" /><h2>{{ $isVideo ? __('Video') : __('Imagen') }}</h2><span class="sp"></span><span class="ui-card-sub"><span class="ui-req" aria-hidden="true">*</span> {{ __('obligatorio') }}</span></div>
            <div class="ui-card-p ui-pub-stack">
                @if ($isVideo)
                    {{-- Subida de VIDEO (Reel / Historia de video). Requisito: MP4; el resto son
                         recomendaciones (Meta valida duración/FPS/resolución al publicar). --}}
                    <label @class(['ui-drop', 'has-file' => (bool) $video, 'is-invalid' => $errors->has('video')])>
                        @if ($video)
                            {{-- Identificación del archivo elegido: nombre ORIGINAL + tamaño real
                                 + máximo aplicable en este momento (cambia con las redes). --}}
                            <span class="ui-drop-file">
                                <span class="ui-drop-ic ui-tone--blue"><x-ui.icon name="video" /></span>
                                <span class="ui-drop-meta">
                                    <span class="ui-drop-title">{{ $video->getClientOriginalName() }}</span>
                                    <span class="ui-drop-hint">{{ number_format($video->getSize() / 1048576, 1, '.', '') }} MB · {{ $this->videoMaxLabel() }}</span>
                                </span>
                                <span class="ui-btn ui-btn--secondary ui-btn--sm" aria-hidden="true"><x-ui.icon name="refresh" /> {{ __('Haz clic para cambiar el video') }}</span>
                            </span>
                        @else
                            <span class="ui-drop-ic"><x-ui.icon name="upload" /></span>
                            <span class="ui-drop-title">{{ __('Subir video') }}</span>
                            <span class="ui-drop-hint">
                                @if ($contentType === 'reel')
                                    {{ __('MP4 · Máximo 250 MB · 3–90 s · Vertical 9:16 recomendado') }}
                                @elseif ($contentType === 'post')
                                    {{ __('MP4 · Máximo 250 MB · Vertical u horizontal') }}
                                @elseif ($toInstagram)
                                    {{ __('MP4 · Máximo 100 MB para Instagram · 3–60 s · Vertical 9:16 recomendado') }}
                                @else
                                    {{ __('MP4 · Máximo 250 MB · 3–60 s · Vertical 9:16 recomendado') }}
                                @endif
                            </span>
                        @endif
                        <input type="file" wire:model="video" accept="video/mp4" class="ui-sr" @error('video') aria-invalid="true" aria-describedby="pub-file-error" @enderror>
                    </label>
                    <div wire:loading wire:target="video" class="ui-help"><span class="ui-spin"></span> {{ __('Subiendo video…') }}</div>
                    @error('video') <p class="ui-error" id="pub-file-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                @else
                    <label @class(['ui-drop', 'has-file' => (bool) $image, 'is-invalid' => $errors->has('image'), 'is-story' => $contentType === 'story'])>
                        @if ($image)
                            <span class="ui-drop-preview"><img src="{{ $image->temporaryUrl() }}" alt="{{ __('Vista previa') }}"></span>
                            <span class="ui-drop-file">
                                <span class="ui-drop-meta">
                                    <span class="ui-drop-title">{{ $image->getClientOriginalName() }}</span>
                                    <span class="ui-drop-hint">{{ $image->getSize() >= 1048576 ? number_format($image->getSize() / 1048576, 1, '.', '').' MB' : max(1, (int) round($image->getSize() / 1024)).' KB' }}</span>
                                </span>
                                <span class="ui-btn ui-btn--secondary ui-btn--sm" aria-hidden="true"><x-ui.icon name="refresh" /> {{ __('Haz clic para cambiar la imagen') }}</span>
                            </span>
                        @else
                            <span class="ui-drop-ic"><x-ui.icon name="upload" /></span>
                            <span class="ui-drop-title">{{ __('Subir imagen') }}</span>
                            <span class="ui-drop-hint">
                                {{-- Instagram exige JPEG; sin IG, el PNG también pasa TAL CUAL. --}}
                                @if ($contentType === 'story')
                                    @if ($toInstagram)
                                        {{ __('JPEG · Vertical 9:16 recomendado · MCA no recomprime ni redimensiona el archivo antes de enviarlo a la plataforma.') }}
                                    @else
                                        {{ __('JPG y PNG · Vertical 9:16 recomendado · MCA no recomprime ni redimensiona el archivo antes de enviarlo a la plataforma.') }}
                                    @endif
                                @else
                                    @if ($toInstagram)
                                        {{ __('JPEG · MCA no recomprime ni redimensiona el archivo antes de enviarlo a la plataforma.') }}
                                    @else
                                        {{ __('JPG y PNG · MCA no recomprime ni redimensiona el archivo antes de enviarlo a la plataforma.') }}
                                    @endif
                                @endif
                            </span>
                        @endif
                        <input type="file" wire:model="image" accept="image/jpeg,image/png" class="ui-sr" @error('image') aria-invalid="true" aria-describedby="pub-file-error" @enderror>
                    </label>
                    <div wire:loading wire:target="image" class="ui-help"><span class="ui-spin"></span> {{ __('Subiendo imagen…') }}</div>
                    @error('image') <p class="ui-error" id="pub-file-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                @endif
            </div>

            {{-- ===================== Descripción ===================== --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="file-text" class="ui-card-ic" /><h2>{{ __('Descripción') }}</h2></div>
            <div class="ui-card-p">
                {{-- Las Historias no llevan descripción (las APIs de destino no la usan como un post). --}}
                @if ($contentType !== 'story')
                    <div class="ui-field">
                        <label class="ui-sr" for="pub-caption">{{ __('Descripción') }}</label>
                        <textarea id="pub-caption" wire:model="caption" rows="6" maxlength="2200" x-on:input="chars = $event.target.value.length"
                                  @class(['ui-input ui-textarea ui-pub-caption', 'is-invalid' => $errors->has('caption')])
                                  placeholder="{{ __('Escribe la descripción de la publicación…') }}" aria-describedby="pub-caption-count"></textarea>
                        <div class="ui-pub-count">
                            @error('caption') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                            <span class="sp"></span>
                            <span id="pub-caption-count" :class="{ 'is-near': chars > 2000 }" aria-live="polite"><span x-text="chars">{{ mb_strlen($caption) }}</span> / 2200</span>
                        </div>
                    </div>
                @else
                    <p class="ui-help ui-pub-nodesc"><x-ui.icon name="eye" /> {{ __('Las Historias se publican sin descripción.') }}</p>
                @endif
            </div>

            {{-- ===================== Destino ===================== --}}
            <div class="ui-card-h ui-card-h--sep"><x-ui.icon name="globe" class="ui-card-ic" /><h2>{{ __('Publicar en') }}</h2></div>
            <div class="ui-card-p">
                <div class="ui-nets" role="group" aria-label="{{ __('Publicar en') }}">
                    <label @class(['ui-net', 'is-off' => ! $hasFacebook])>
                        <input type="checkbox" wire:model.live="toFacebook" @checked($toFacebook && $hasFacebook) @disabled(! $hasFacebook)>
                        <span class="ui-net-ic">@include('social::partials.provider-icon', ['provider' => 'facebook', 'size' => 22])</span>
                        <span class="ui-net-text"><b>{{ __('Facebook (Página)') }}</b>
                            <small>@if ($hasFacebook){{ __('Página de Facebook conectada') }}@else{{ __('sin canal') }}@endif</small></span>
                    </label>
                    <label @class(['ui-net', 'is-off' => ! $hasInstagram])>
                        <input type="checkbox" wire:model.live="toInstagram" @checked($toInstagram && $hasInstagram) @disabled(! $hasInstagram)>
                        <span class="ui-net-ic">@include('social::partials.provider-icon', ['provider' => 'instagram', 'size' => 22])</span>
                        <span class="ui-net-text"><b>{{ __('Instagram') }}</b>
                            <small>@if ($hasInstagram){{ __('Cuenta de Instagram conectada') }}@else{{ __('sin canal') }}@endif</small></span>
                    </label>
                </div>
            </div>
        </form>

        {{-- ===================== Publicación y resultado ===================== --}}
        <aside class="ui-pub-side">
            <section class="ui-card" aria-labelledby="pub-sum-t">
                <div class="ui-card-h"><x-ui.icon name="send" class="ui-card-ic" /><h2 id="pub-sum-t">{{ __('Publicación') }}</h2></div>
                <div class="ui-card-p ui-pub-stack">
                    <dl class="ui-meta ui-pub-sum">
                        <dt>{{ __('Formato') }}</dt><dd>{{ $formats[$contentType][0] }} · {{ $isVideo ? __('Video') : __('Imagen') }}</dd>
                        <dt>{{ __('Archivo') }}</dt>
                        <dd>@if ($file)<span class="ui-break">{{ $file->getClientOriginalName() }}</span>@else<span class="t-nil">{{ __('Sin archivo') }}</span>@endif</dd>
                        <dt>{{ __('Descripción') }}</dt>
                        <dd>@if ($contentType === 'story')<span class="t-nil">{{ __('No aplica') }}</span>@else<span x-text="chars > 0 ? chars + ' ' + (chars === 1 ? @js(__('carácter')) : @js(__('caracteres'))) : @js(__('Sin descripción'))">{{ $caption !== '' ? trans_choice(':n carácter|:n caracteres', mb_strlen($caption), ['n' => mb_strlen($caption)]) : __('Sin descripción') }}</span>@endif</dd>
                        <dt>{{ __('Destinos') }}</dt>
                        <dd class="ui-pub-targets">
                            @forelse ($targets as $t)
                                <span>@include('social::partials.provider-icon', ['provider' => $t, 'size' => 15]) {{ $t === 'facebook' ? 'Facebook' : 'Instagram' }}</span>
                            @empty
                                <span class="t-nil">{{ __('Ninguno') }}</span>
                            @endforelse
                        </dd>
                    </dl>
                    <button type="submit" form="pub-form" class="ui-btn ui-btn--primary ui-pub-go" wire:target="publish" wire:loading.attr="disabled">
                        <span class="ui-btn-label" wire:loading.remove wire:target="publish"><x-ui.icon name="send" />
                            @if ($contentType === 'reel') {{ __('Publicar Reel') }}
                            @elseif ($contentType === 'story') {{ __('Publicar Historia') }}
                            @else {{ __('Publicar') }} @endif
                        </span>
                        <span wire:loading wire:target="publish"><span class="ui-spin"></span> {{ __('Publicando…') }}</span>
                    </button>
                    <p class="ui-help">{{ __('Se publica de inmediato en cada red elegida; el resultado de cada una aparece abajo.') }}</p>
                </div>
            </section>

            <section class="ui-card" aria-labelledby="pub-res-t" aria-live="polite">
                <div class="ui-card-h"><x-ui.icon name="activity" class="ui-card-ic" /><h2 id="pub-res-t">{{ __('Resultado') }}</h2></div>
                @if ($result)
                    <ul class="ui-list">
                        @foreach ($result as $r)
                            @php
                                $net = $r['network'] === 'facebook' ? 'facebook' : 'instagram';
                                [$tone, $icon, $label] = match ($r['status']) {
                                    'published' => ['success', 'check', __('Publicado')],
                                    'processing' => ['warning', 'clock', __('Procesando')],
                                    default => ['danger', 'x', __('Falló')],
                                };
                            @endphp
                            <li wire:key="res-{{ $r['id'] ?? $loop->index }}">
                                <div class="ui-list-item ui-pub-res">
                                    <span class="ui-net-ic">@include('social::partials.provider-icon', ['provider' => $net, 'size' => 20])</span>
                                    <span class="ui-list-main">
                                        <span class="ui-person-line">
                                            <span class="ui-list-title">{{ $net === 'facebook' ? 'Facebook' : 'Instagram' }}</span>
                                            <x-ui.badge :tone="$tone" :icon="$icon">{{ $label }}</x-ui.badge>
                                        </span>
                                        @if ($r['status'] !== 'published' && $r['error'])
                                            <span @class(['ui-pub-err', 'is-wait' => $r['status'] === 'processing'])>{{ $r['error'] }}</span>
                                        @endif
                                    </span>
                                    <span class="ui-list-aside">
                                        @if ($r['status'] === 'published' && $r['url'])
                                            <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="ui-link">{{ __('Ver post') }} <x-ui.icon name="external-link" /></a>
                                        @elseif ($r['status'] === 'processing')
                                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" wire:click="resumeTarget({{ $r['id'] }})"
                                                    wire:loading.attr="disabled" wire:target="resumeTarget">
                                                <span wire:loading.remove wire:target="resumeTarget">{{ __('Continuar') }}</span>
                                                <span wire:loading wire:target="resumeTarget"><span class="ui-spin"></span> {{ __('Comprobando…') }}</span>
                                            </button>
                                        @endif
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="ui-empty ui-pub-empty">
                        <div class="ui-empty-ic"><x-ui.icon name="send" /></div>
                        <b>{{ __('Aún no has publicado nada') }}</b>
                        {{ __('Sube una imagen, escribe la descripción y pulsa Publicar. El resultado de cada red aparecerá aquí.') }}
                    </div>
                @endif
            </section>
        </aside>
    </div>
</div>
