<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Social\Models\SocialPost;
use Modules\Social\Services\PostImageService;
use Modules\Social\Services\SocialPublishService;

/**
 * Publicador (Bloque 5): una imagen + descripción se publica a la vez en la Página de Facebook
 * y en Instagram. La publicación por red es independiente (una puede fallar y la otra no); el
 * resultado se muestra POR RED. Acceso: canPublishSocial (Admin/Marketing) + guard del panel;
 * scoping por institución reutilizado (solo canales/redes de la institución activa).
 */
#[Layout('layouts.app')]
class Publisher extends Component
{
    use WithFileUploads;

    public mixed $image = null;

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

    public function updatedImage(): void
    {
        $this->result = null;
        $this->validateOnly('image', ['image' => ['image', 'mimes:jpg,jpeg,png', 'max:8192']]);
    }

    public function publish(PostImageService $images, SocialPublishService $service): void
    {
        $this->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:2200'],
        ]);

        $available = $service->availableNetworks();
        $networks = [];
        if ($this->toFacebook && isset($available['facebook'])) {
            $networks[] = 'facebook';
        }
        if ($this->toInstagram && isset($available['instagram'])) {
            $networks[] = 'instagram';
        }

        if ($networks === []) {
            $this->addError('image', __('Selecciona al menos una red con canal configurado.'));

            return;
        }

        $stored = $images->storeJpeg((string) $this->image->get());

        $post = new SocialPost;
        $post->created_by = (int) auth()->id();
        $post->caption = $this->caption !== '' ? $this->caption : null;
        $post->image_path = $stored['path'];
        $post->image_public_url = $stored['url'];
        $post->status = 'pending';
        $post->save();

        $post = $service->publish($post, $networks);

        $this->result = $post->targets->map(fn ($t): array => [
            'network' => $t->network,
            'status' => $t->status,
            'external_post_id' => $t->external_post_id,
            'error' => $t->error_message,
            'url' => $this->postUrl($t->network, $t->external_post_id),
        ])->all();

        // Limpiar el formulario para una nueva publicación (el resultado queda visible).
        $this->reset(['image', 'caption']);
        $this->toFacebook = true;
        $this->toInstagram = true;
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
