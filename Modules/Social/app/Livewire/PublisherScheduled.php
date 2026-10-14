<?php

declare(strict_types=1);

namespace Modules\Social\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Social\Livewire\Concerns\ManagesSocialPosts;
use Modules\Social\Models\SocialPost;

/**
 * Programadas: lo que aún no ha salido (programadas por fecha, en curso y borradores). Desde aquí
 * se edita, reprograma, cancela, devuelve a borrador o publica ya. Lo que ya empezó a
 * procesarse no admite cambios (se bloquea en el servidor con UPDATE condicionados al estado).
 */
#[Layout('layouts.app')]
class PublisherScheduled extends Component
{
    use ManagesSocialPosts;

    public function mount(): void
    {
        $this->authorizePublishing();
        if (session()->has('publisher_notice')) {
            $this->flashNotice((string) session('publisher_notice'), 'success');
        }
    }

    public function render(): View
    {
        $with = ['targets', 'creator', 'updater'];

        return view('social::publisher-scheduled', [
            'scheduled' => SocialPost::query()->with($with)->where('status', SocialPost::SCHEDULED)->orderBy('scheduled_at')->get(),
            'inFlight' => SocialPost::query()->with($with)->whereIn('status', SocialPost::IN_FLIGHT)->orderByDesc('updated_at')->limit(20)->get(),
            'drafts' => SocialPost::query()->with($with)->where('status', SocialPost::DRAFT)->orderByDesc('updated_at')->get(),
            'detail' => $this->detailPost(),
            'tz' => $this->viewerTz(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
