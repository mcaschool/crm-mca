<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\PostImageService;
use Modules\Social\Services\PostVideoService;
use Modules\Social\Services\SocialPublishService;
use RuntimeException;

/**
 * Publicador multiformato (Bloque 5 + extensión): POST (imagen + descripción), REEL (video +
 * descripción) e HISTORIA (imagen o video, sin descripción) hacia la Página de Facebook y/o
 * Instagram. La publicación por red es independiente (una puede fallar y la otra no); el
 * resultado se muestra POR RED. El POST conserva exactamente su flujo histórico (image_*).
 * Acceso: canPublishSocial (Admin/Marketing) + guard del panel; scoping por institución.
 */
#[Layout('layouts.app')]
class Publisher extends Component
{
    use WithFileUploads;

    /** Tipo de publicación: post | reel | story. */
    public string $contentType = 'post';

    /** Medio del Post: image | video (solo aplica cuando contentType = post). */
    public string $postMedia = 'image';

    /** Medio de la Historia: image | video (solo aplica cuando contentType = story). */
    public string $storyMedia = 'image';

    public mixed $image = null;

    public mixed $video = null;

    public string $caption = '';

    public bool $toFacebook = true;

    public bool $toInstagram = true;

    /**
     * Resultado por red tras publicar: [['network','status','external_post_id','error','url'], ...].
     *
     * @var array<int, array<string, mixed>>|null
     */
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canPublishSocial() ?? false, 403);
    }

    /** Cambia el tipo de publicación y limpia archivo elegido, resultado y errores previos. */
    public function setContentType(string $type): void
    {
        if (! in_array($type, SocialPost::CONTENT_TYPES, true)) {
            return;
        }

        $this->contentType = $type;
        $this->reset(['image', 'video', 'result']);
        $this->resetValidation();
    }

    /** Cambia el medio del Post (imagen | video) y limpia el archivo elegido. */
    public function setPostMedia(string $media): void
    {
        if (! in_array($media, SocialPost::MEDIA_TYPES, true)) {
            return;
        }

        $this->postMedia = $media;
        $this->reset(['image', 'video', 'result']);
        $this->resetValidation();
    }

    /** Cambia el medio de la Historia (imagen | video) y limpia el archivo elegido. */
    public function setStoryMedia(string $media): void
    {
        if (! in_array($media, SocialPost::MEDIA_TYPES, true)) {
            return;
        }

        $this->storyMedia = $media;
        $this->reset(['image', 'video', 'result']);
        $this->resetValidation();
    }

    public function updatedImage(): void
    {
        $this->result = null;
        $this->validateOnly('image', ['image' => ['image', 'mimes:jpg,jpeg,png', 'max:8192']]);
    }

    public function updatedVideo(): void
    {
        $this->result = null;
        $this->validateOnly('video', ['video' => ['file', 'mimetypes:video/mp4', 'max:'.$this->videoMaxKb()]], ['video.max' => $this->videoMaxMessage()]);
    }

    /** El límite de Historia de video depende de las redes: re-validar el archivo ya elegido. */
    public function updatedToInstagram(): void
    {
        if ($this->video !== null && $this->wantsVideo()) {
            $this->resetValidation('video');
            $this->validateOnly('video', ['video' => ['file', 'mimetypes:video/mp4', 'max:'.$this->videoMaxKb()]], ['video.max' => $this->videoMaxMessage()]);
        }
    }

    /** ¿El formato seleccionado sube VIDEO? (Reel siempre; Post e Historia según su toggle). */
    private function wantsVideo(): bool
    {
        return $this->contentType === 'reel'
            || ($this->contentType === 'post' && $this->postMedia === 'video')
            || ($this->contentType === 'story' && $this->storyMedia === 'video');
    }

    /** Etiqueta del máximo vigente, para mostrarla junto al archivo elegido en la vista. */
    public function videoMaxLabel(): string
    {
        return $this->contentType === 'story' && $this->toInstagram
            ? __('Máximo 100 MB para Instagram')
            : __('Máximo 250 MB');
    }

    /**
     * Límite de subida en KB según formato y redes seleccionadas:
     *  - Reel (FB, IG o dual): 250 MB (límite general del CRM).
     *  - Historia de video CON Instagram (solo IG o dual): 100 MB — límite de la API de
     *    Instagram Stories; se valida ANTES de enviar nada a Meta.
     *  - Historia de video solo Facebook: 250 MB (límite general).
     */
    private function videoMaxKb(): int
    {
        if ($this->contentType === 'story' && $this->toInstagram) {
            return 102400; // 100 MB
        }

        return 256000; // 250 MB
    }

    private function videoMaxMessage(): string
    {
        return $this->contentType === 'story' && $this->toInstagram
            ? __('Las Historias de Instagram admiten videos de hasta 100 MB. Reduce el tamaño del archivo o publica únicamente en Facebook.')
            : __('El video supera el tamaño máximo permitido de 250 MB.');
    }

    public function publish(PostImageService $images, PostVideoService $videos, SocialPublishService $service): void
    {
        $wantsVideo = $this->wantsVideo();
        $mediaField = $wantsVideo ? 'video' : 'image';

        // REQUISITOS validables en servidor: tipo real (MIME) y tamaño. Duración/FPS/resolución
        // los valida Meta al publicar (sin ffprobe fiable en hosting compartido) y sus errores
        // se traducen a mensajes claros. Las recomendaciones (9:16, 1080×1920) van en la UI.
        $rules = $wantsVideo
            ? ['video' => ['required', 'file', 'mimetypes:video/mp4', 'max:'.$this->videoMaxKb()]]
            : ['image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:8192']];
        if ($this->contentType !== 'story') {
            $rules['caption'] = ['nullable', 'string', 'max:2200'];
        }
        // 'video.required' también cubre el caso de un archivo rechazado por la puerta de
        // subida temporal de Livewire (>250 MB): la propiedad queda vacía y este mensaje
        // sustituye a la clave técnica de validación.
        $this->validate($rules, [
            'video.max' => $this->videoMaxMessage(),
            'video.required' => __('Selecciona un video MP4 (máximo 250 MB).'),
        ], ['video' => 'video', 'image' => 'imagen']);

        $available = $service->availableNetworks();
        $networks = [];
        if ($this->toFacebook && isset($available['facebook'])) {
            $networks[] = 'facebook';
        }
        if ($this->toInstagram && isset($available['instagram'])) {
            $networks[] = 'instagram';
        }

        if ($networks === []) {
            $this->addError($mediaField, __('Selecciona al menos una red con canal configurado.'));

            return;
        }

        // POLÍTICA DE PRESERVACIÓN: el CRM nunca convierte formatos. Instagram exige JPEG
        // en imágenes; si hay un PNG con Instagram entre los destinos, se BLOQUEA aquí —
        // antes de publicar en CUALQUIER red — para no dejar publicaciones parciales
        // (Facebook publicado + Instagram rechazado). El archivo no se modifica jamás.
        if (! $wantsVideo
            && in_array('instagram', $networks, true)
            && $this->image->getMimeType() === 'image/png') {
            $this->addError('image', __('Instagram requiere imágenes en formato JPEG para este tipo de publicación. Convierte o exporta la imagen como JPG/JPEG y vuelve a seleccionarla. El archivo no ha sido modificado.'));

            return;
        }

        $post = new SocialPost;
        $post->created_by = (int) auth()->id();
        $post->content_type = $this->contentType;
        $post->media_type = $wantsVideo ? 'video' : 'image';
        // Las Historias no llevan descripción (las APIs de destino no la usan como un post).
        $post->caption = $this->contentType !== 'story' && $this->caption !== '' ? $this->caption : null;
        $post->status = 'pending';

        if ($wantsVideo) {
            try {
                $stored = $videos->store($this->video);
            } catch (RuntimeException $e) {
                $this->addError('video', $e->getMessage());

                return;
            }
            $post->media_path = $stored['path'];
            $post->media_public_url = $stored['url'];
            $post->media_mime = $stored['mime'];
        } elseif ($this->contentType === 'story') {
            // La imagen se guarda TAL CUAL (JPEG o PNG, byte a byte; sin recompresión).
            $stored = $images->store((string) $this->image->get());
            $post->media_path = $stored['path'];
            $post->media_public_url = $stored['url'];
            $post->media_mime = $stored['mime'];
        } else {
            // POST de imagen: byte a byte en los campos image_* históricos.
            $stored = $images->store((string) $this->image->get());
            $post->image_path = $stored['path'];
            $post->image_public_url = $stored['url'];
        }

        $post->save();

        $post = $service->publish($post, $networks);

        $this->result = $post->targets->map(fn ($t): array => $this->resultRow($t))->all();

        // Limpiar el formulario para una nueva publicación (el resultado queda visible).
        $this->reset(['image', 'video', 'caption']);
        $this->toFacebook = true;
        $this->toInstagram = true;
    }

    /**
     * "Continuar": reanuda un target de Instagram que quedó en 'processing' (video que Meta
     * seguía procesando). Guardas: permiso canPublishSocial, target de la institución ACTIVA
     * (InstitutionScope: un id de otro tenant no se encuentra), red instagram, estado
     * processing y container_id presente. La lógica vive en SocialPublishService::resumeTarget
     * (futuro cuerpo de un Job); aquí solo se autoriza, se invoca y se refresca el resultado.
     */
    public function resumeTarget(int $targetId, SocialPublishService $service): void
    {
        abort_unless(auth()->user()?->canPublishSocial() ?? false, 403);

        $target = SocialPostTarget::query()->find($targetId);
        if ($target === null
            || $target->network !== 'instagram'
            || $target->status !== 'processing'
            || (string) $target->container_id === '') {
            return;
        }

        $target = $service->resumeTarget($target);

        if (is_array($this->result)) {
            $this->result = collect($this->result)
                ->map(fn (array $row): array => ($row['id'] ?? null) === $target->id ? $this->resultRow($target) : $row)
                ->all();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultRow(SocialPostTarget $t): array
    {
        return [
            'id' => $t->id,
            'network' => $t->network,
            'status' => $t->status,
            'external_post_id' => $t->external_post_id,
            'error' => $t->error_message,
            'url' => $this->postUrl($t->network, $t->external_post_id),
        ];
    }

    private function postUrl(string $network, ?string $externalId): ?string
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return $network === 'facebook'
            ? 'https://www.facebook.com/'.$externalId
            : 'https://www.instagram.com/';
    }

    public function render(): View
    {
        $available = app(SocialPublishService::class)->availableNetworks();

        return view('social::publisher', [
            'hasFacebook' => isset($available['facebook']),
            'hasInstagram' => isset($available['instagram']),
        ]);
    }
}
