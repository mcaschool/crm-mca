<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Audit\Services\AuditService;
use Modules\Crm\Support\UserTimezone;
use Modules\Social\Models\SocialPost;
use Modules\Social\Models\SocialPostTarget;
use Modules\Social\Services\PostImageService;
use Modules\Social\Services\PostVideoService;
use Modules\Social\Services\SocialPostScheduler;
use Modules\Social\Services\SocialPublishService;
use Modules\Social\Support\ScheduleInput;
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

    /** Publicación que se edita (borrador o programada); null = nueva. */
    #[Url(as: 'editar')]
    public ?int $postId = null;

    /** Cuándo: now (publicar ya) | schedule (programar). */
    public string $when = 'now';

    public string $scheduleDate = '';

    public string $scheduleTime = '';

    public string $scheduleTz = '';

    public ?string $notice = null;

    /** success | warning */
    public string $noticeTone = 'warning';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canPublishSocial() ?? false, 403);
        $this->scheduleTz = UserTimezone::current();

        if ($this->postId !== null) {
            $post = SocialPost::query()->find($this->postId);
            if ($post === null || ! $post->isEditable()) {
                // Ya publicada, en curso o de otra institución: no se edita aquí.
                $this->postId = null;
                $this->notice = __('Esa publicación ya no se puede editar (está en curso, publicada o cancelada). Consulta su resultado en el Historial.');

                return;
            }
            $this->fillFrom($post);
        }
    }

    /** Carga en el formulario una publicación existente (borrador o programada). */
    private function fillFrom(SocialPost $post): void
    {
        $this->contentType = $post->content_type;
        if ($post->content_type === 'post') {
            $this->postMedia = $post->media_type;
        } elseif ($post->content_type === 'story') {
            $this->storyMedia = $post->media_type;
        }
        $this->caption = (string) $post->caption;
        $networks = $post->destinationNetworks();
        $this->toFacebook = $networks === [] || in_array('facebook', $networks, true);
        $this->toInstagram = $networks === [] || in_array('instagram', $networks, true);
        if ($post->status === SocialPost::SCHEDULED && $post->scheduled_at !== null) {
            $tz = UserTimezone::isValid($post->timezone) ? (string) $post->timezone : UserTimezone::current();
            $at = $post->scheduled_at->copy()->setTimezone($tz);
            $this->when = 'schedule';
            $this->scheduleDate = $at->format('Y-m-d');
            $this->scheduleTime = $at->format('H:i');
            $this->scheduleTz = $tz;
        }
    }

    private function editing(): ?SocialPost
    {
        return $this->postId === null ? null : SocialPost::query()->whereKey($this->postId)->whereIn('status', SocialPost::EDITABLE)->first();
    }

    public function setWhen(string $when): void
    {
        $this->when = $when === 'schedule' ? 'schedule' : 'now';
        if ($this->when === 'schedule' && $this->scheduleDate === '') {
            $at = now($this->scheduleTz ?: UserTimezone::current())->addDay()->setTime(10, 0);
            $this->scheduleDate = $at->format('Y-m-d');
            $this->scheduleTime = $at->format('H:i');
        }
        $this->resetValidation(['scheduleDate']);
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

    /** Guarda el contenido sin publicar ni programar (archivo en el disco público, persistente). */
    public function saveDraft(PostImageService $images, PostVideoService $videos, SocialPublishService $service, AuditService $audit): void
    {
        $post = $this->persist(SocialPost::DRAFT, strict: false, images: $images, videos: $videos, service: $service);
        if ($post === null) {
            return;
        }
        $audit->log('social_post.draft_saved', $post);
        $this->postId = (int) $post->id;
        $this->reset(['image', 'video', 'result']);
        $this->notice = __('Borrador guardado. Puedes seguir editándolo, programarlo o publicarlo cuando quieras.');
        $this->noticeTone = 'success';
    }

    /** Programa la publicación: valida contenido, formato y destinos y guarda el momento en UTC. */
    public function schedule(PostImageService $images, PostVideoService $videos, SocialPublishService $service, AuditService $audit): void
    {
        $utc = ScheduleInput::toUtc($this->scheduleDate, $this->scheduleTime, $this->scheduleTz);
        if (is_string($utc)) {
            $this->addError('scheduleDate', $utc);

            return;
        }

        $wasScheduled = $this->editing()?->status === SocialPost::SCHEDULED;
        $post = $this->persist(SocialPost::SCHEDULED, strict: true, images: $images, videos: $videos, service: $service, scheduledAt: $utc);
        if ($post === null) {
            return;
        }
        $audit->log($wasScheduled ? 'social_post.rescheduled' : 'social_post.scheduled', $post, ['at' => $utc->toIso8601String(), 'timezone' => $this->scheduleTz]);

        session()->flash('publisher_notice', __('Programada para el :date (:tz). MCA CRM la publicará automáticamente.', [
            'date' => $utc->copy()->setTimezone($this->scheduleTz)->translatedFormat('j M Y · H:i'),
            'tz' => $this->scheduleTz,
        ]));
        $this->redirectRoute('social.publisher.scheduled', navigate: false);
    }

    public function publish(PostImageService $images, PostVideoService $videos, SocialPublishService $service, SocialPostScheduler $scheduler, AuditService $audit): void
    {
        // Publicar ya un borrador o una programada que se está editando: se guardan los cambios y
        // se ejecuta con el mismo reclamo atómico que la programación (nunca dos veces).
        if ($this->postId !== null) {
            $post = $this->persist(SocialPost::DRAFT, strict: true, images: $images, videos: $videos, service: $service, keepStatus: true);
            if ($post === null) {
                return;
            }
            $audit->log('social_post.published_now', $post);
            $done = $scheduler->publishNow($post);
            if ($done === null) {
                $this->notice = __('Esta publicación ya se está procesando.');
                $this->noticeTone = 'warning';

                return;
            }
            $this->result = $done->targets->map(fn ($t): array => $this->resultRow($t))->all();
            $this->postId = null;
            $this->reset(['image', 'video', 'caption', 'when', 'scheduleDate', 'scheduleTime']);
            $this->toFacebook = true;
            $this->toInstagram = true;

            return;
        }

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
     * Valida y guarda el formulario en una publicación (nueva o la que se edita) con el estado
     * dado. strict = para programar o publicar: archivo, destinos y reglas de cada red son
     * obligatorios; un borrador solo exige que haya algo que guardar. El archivo nuevo se guarda
     * en el disco público (persistente); si sustituye a otro de este mismo borrador, el anterior
     * se borra cuando ninguna otra publicación lo usa.
     */
    private function persist(string $status, bool $strict, PostImageService $images, PostVideoService $videos, SocialPublishService $service, ?Carbon $scheduledAt = null, bool $keepStatus = false): ?SocialPost
    {
        $existing = $this->editing();
        if ($this->postId !== null && $existing === null) {
            $this->notice = __('Esa publicación ya no se puede editar (está en curso, publicada o cancelada). Consulta su resultado en el Historial.');
            $this->noticeTone = 'warning';

            return null;
        }

        $wantsVideo = $this->wantsVideo();
        $mediaField = $wantsVideo ? 'video' : 'image';
        $mediaType = $wantsVideo ? 'video' : 'image';
        $newFile = $wantsVideo ? $this->video : $this->image;
        $keepsMedia = $newFile === null && $existing !== null && $existing->hasMedia() && $existing->media_type === $mediaType;

        $rules = [];
        if ($newFile !== null) {
            $rules[$mediaField] = $wantsVideo
                ? ['file', 'mimetypes:video/mp4', 'max:'.$this->videoMaxKb()]
                : ['image', 'mimes:jpg,jpeg,png', 'max:8192'];
        } elseif ($strict && ! $keepsMedia) {
            $rules[$mediaField] = ['required'];
        }
        if ($this->contentType !== 'story') {
            $rules['caption'] = ['nullable', 'string', 'max:2200'];
        }
        $this->validate($rules, [
            'video.max' => $this->videoMaxMessage(),
            'video.required' => __('Selecciona un video MP4 (máximo 250 MB).'),
        ], ['video' => 'video', 'image' => 'imagen']);

        if (! $strict && $newFile === null && ! $keepsMedia && trim($this->caption) === '') {
            $this->addError($mediaField, __('Añade un archivo o una descripción para guardar el borrador.'));

            return null;
        }

        $available = $service->availableNetworks();
        $networks = array_values(array_filter([
            $this->toFacebook && isset($available['facebook']) ? 'facebook' : null,
            $this->toInstagram && isset($available['instagram']) ? 'instagram' : null,
        ]));
        if ($strict && $networks === []) {
            $this->addError($mediaField, __('Selecciona al menos una red con canal configurado.'));

            return null;
        }

        // Mismas reglas que la publicación inmediata, comprobadas AL PROGRAMAR (no al vencer):
        // Instagram exige JPEG y las Historias de video con Instagram, 100 MB como máximo.
        if ($strict && in_array('instagram', $networks, true)) {
            $mime = $newFile !== null ? (string) $newFile->getMimeType() : (string) ($existing->media_mime ?? $this->mimeFromPath($existing?->mediaPath()));
            if (! $wantsVideo && $mime === 'image/png') {
                $this->addError('image', __('Instagram requiere imágenes en formato JPEG para este tipo de publicación. Convierte o exporta la imagen como JPG/JPEG y vuelve a seleccionarla. El archivo no ha sido modificado.'));

                return null;
            }
            $size = $newFile !== null ? (int) $newFile->getSize() : (int) ($existing->media_size ?? 0);
            if ($wantsVideo && $this->contentType === 'story' && $size > 102400 * 1024) {
                $this->addError('video', $this->videoMaxMessage());

                return null;
            }
        }

        $post = $existing ?? new SocialPost(['created_by' => (int) auth()->id()]);
        $previousPath = $existing?->mediaPath();

        if ($newFile !== null) {
            try {
                $stored = $wantsVideo ? $videos->store($newFile) : $images->store((string) $newFile->get());
            } catch (RuntimeException $e) {
                $this->addError($mediaField, $e->getMessage());

                return null;
            }
            $post->image_path = null;
            $post->image_public_url = null;
            $post->media_path = $stored['path'];
            $post->media_public_url = $stored['url'];
            $post->media_mime = $stored['mime'];
            $post->media_name = mb_substr((string) $newFile->getClientOriginalName(), 0, 255);
            $post->media_size = (int) $newFile->getSize();
        } elseif (! $keepsMedia && $existing !== null) {
            // Cambió el formato (imagen ↔ video) sin archivo nuevo: el anterior ya no sirve.
            $post->image_path = $post->image_public_url = $post->media_path = $post->media_public_url = $post->media_mime = $post->media_name = null;
            $post->media_size = null;
        }

        $post->content_type = $this->contentType;
        $post->media_type = $mediaType;
        $post->caption = $this->contentType !== 'story' && trim($this->caption) !== '' ? $this->caption : null;
        $post->networks = $networks;
        $post->updated_by = (int) auth()->id();
        if (! $keepStatus) {
            $post->status = $status;
            $post->scheduled_at = $scheduledAt;
            $post->timezone = $scheduledAt !== null ? $this->scheduleTz : null;
        } elseif ($existing === null) {
            $post->status = $status;
        }
        $post->save();

        if ($previousPath !== null && $previousPath !== $post->mediaPath()
            && ! SocialPost::query()->where(fn ($q) => $q->where('media_path', $previousPath)->orWhere('image_path', $previousPath))->exists()) {
            Storage::disk('public')->delete($previousPath);
        }

        return $post;
    }

    private function mimeFromPath(?string $path): string
    {
        return match (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'mp4' => 'video/mp4',
            default => '',
        };
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

        $editingPost = $this->editing();

        return view('social::publisher', [
            'hasFacebook' => isset($available['facebook']),
            'hasInstagram' => isset($available['instagram']),
            'editingPost' => $editingPost,
            // Archivo ya guardado que se conserva si no se elige otro (mismo tipo de medio).
            'keptMedia' => $editingPost !== null && $editingPost->hasMedia() && $editingPost->media_type === ($this->wantsVideo() ? 'video' : 'image') ? $editingPost : null,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
