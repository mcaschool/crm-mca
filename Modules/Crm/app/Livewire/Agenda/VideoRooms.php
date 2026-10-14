<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Agenda;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Crm\Livewire\Video\Concerns\ManagesVideoRooms;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Policies\ActivityPolicy;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Video\VideoConnections;
use Modules\Crm\Services\Video\VideoRoomService;

/**
 * «Salas institucionales» dentro de Calendario › Perfil de reuniones. El usuario ve su enlace
 * permanente, el estado de la sala y si puede dirigirla, y la abre; la administración además la
 * genera, revisa, regenera, asigna al perfil y aplica a actividades futuras sin enlace.
 * Nunca muestra credenciales ni enlaces de anfitrión.
 */
class VideoRooms extends Component
{
    use ManagesVideoRooms;

    #[Locked]
    public int $ownerId;

    public function mount(int $ownerId): void
    {
        $this->ownerId = $ownerId;
        $this->guard();
    }

    #[On('meeting-profile-saved')]
    public function refreshAfterProfile(): void
    {
        // El perfil se guardó: se vuelve a pintar el estado (enlace manual vs. sala).
    }

    public function generateFor(string $provider, bool $regenerate = false): void
    {
        $this->guard();
        $this->generateRoom($this->ownerId, $provider, $regenerate);
    }

    public function render(VideoConnections $connections, VideoRoomService $rooms): View
    {
        $this->guard();
        $profile = MeetingProfile::forUser($this->ownerId);
        $blocks = [];
        foreach ([VideoConnections::GOOGLE => $profile->meet_url, VideoConnections::ZOOM => $profile->zoom_url] as $provider => $link) {
            $room = $rooms->current($this->ownerId, $provider);
            $inProfile = $room !== null && Activity::roomHash($link) === $room->join_url_hash;
            $blocks[$provider] = [
                'link' => $link ?: null,
                'room' => $room,
                'inProfile' => $inProfile,
                'manual' => $link && ! $inProfile,
                'connected' => $connections->isConnected($connections->get($provider)),
                'pending' => $room !== null && $room->isActive() && $inProfile ? $rooms->pendingActivities($room)->count() : 0,
            ];
        }

        return view('crm::livewire.agenda.video-rooms', [
            'blocks' => $blocks,
            'isAdmin' => auth()->user()?->canManageIntegrations() === true,
        ]);
    }

    private function guard(): void
    {
        $actor = auth()->user();
        abort_unless($actor !== null && $actor->can('create', Activity::class), 403);
        if ($this->ownerId !== $actor->id) {
            abort_unless((new ActivityPolicy)->manageOthers($actor), 403);
            abort_unless(app(AgendaDirectory::class)->roomUsers()->contains('id', $this->ownerId), 404);
        }
    }
}
