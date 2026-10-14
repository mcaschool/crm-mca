{{-- Miniatura del medio de una publicación (imagen o video) o marcador si aún no tiene archivo. --}}
@php /** @var \Modules\Social\Models\SocialPost $post */ $url = $post->mediaUrl(); @endphp
<span @class(['ui-pub-thumb', 'is-story' => $post->content_type === 'story']) aria-hidden="true">
    @if ($url && $post->media_type === 'video')
        <video src="{{ $url }}#t=0.5" preload="metadata" muted playsinline></video>
        <span class="ui-pub-thumb-badge"><x-ui.icon name="video" /></span>
    @elseif ($url)
        <img src="{{ $url }}" alt="" loading="lazy">
    @else
        <x-ui.icon name="{{ $post->media_type === 'video' ? 'video' : 'image' }}" />
    @endif
</span>
