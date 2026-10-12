<?php

declare(strict_types=1);

namespace Modules\Crm\Livewire\Video\Concerns;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Modules\Crm\Models\VideoRoom;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Services\Video\VideoProviderException;
use Modules\Crm\Services\Video\VideoRoomService;

/**
 * Acciones de administración sobre las salas institucionales (generar, regenerar, revisar,
 * asignar al perfil y aplicar a actividades futuras sin enlace). Compartidas por la página de
 * Videoconferencias y el bloque del perfil de reuniones. Re-autoriza en cada acción.
 */
trait ManagesVideoRooms
{
    public string $videoNotice = '';

    public bool $videoNoticeError = false;

    public function generateRoom(int $userId, string $provider, bool $regenerate = false): void
    {
        $this->guardVideoAdmin();
        $advisor = app(AgendaDirectory::class)->assignableUsers()->firstWhere('id', $userId);
        abort_unless($advisor instanceof User, 404);
        $this->runVideo(function (VideoRoomService $rooms) use ($advisor, $provider, $regenerate): string {
            $room = $rooms->generate($advisor, $provider, auth()->user(), $regenerate);
            $this->dispatch('video-room-assigned');

            return $room->wasRecentlyCreated
                ? ($rooms->isProfileLink($room)
                    ? __('Sala generada y asignada al perfil de :name.', ['name' => $advisor->name])
                    : __('Sala generada. El perfil conserva su enlace manual hasta que lo reemplaces.'))
                : __('Se reutiliza la sala vigente: sigue siendo válida.');
        });
    }

    public function checkRoom(int $roomId): void
    {
        $this->guardVideoAdmin();
        $room = VideoRoom::query()->findOrFail($roomId);
        $this->runVideo(function (VideoRoomService $rooms) use ($room): string {
            $room = $rooms->check($room, auth()->user());

            return match ($room->status) {
                'active' => __('La sala existe y es válida.'),
                'missing' => __('La sala ya no existe en el proveedor. Genera una nueva.'),
                default => __('La sala tiene un problema. Revisa el detalle o regenérala.'),
            };
        });
    }

    public function assignRoom(int $roomId): void
    {
        $this->guardVideoAdmin();
        $room = VideoRoom::query()->findOrFail($roomId);
        $this->runVideo(function (VideoRoomService $rooms) use ($room): string {
            $rooms->assign($room, auth()->user());
            $this->dispatch('video-room-assigned');

            return __('El perfil usa ahora la sala institucional. Las actividades ya creadas conservan su enlace.');
        });
    }

    public function applyRoom(int $roomId): void
    {
        $this->guardVideoAdmin();
        $room = VideoRoom::query()->findOrFail($roomId);
        $this->runVideo(function (VideoRoomService $rooms) use ($room): string {
            $r = $rooms->applyToFutureActivities($room, auth()->user());

            return __('Actividades futuras sin enlace actualizadas: :n. Omitidas (ocupadas o ya con enlace): :s.', ['n' => $r['updated'], 's' => $r['skipped']]);
        });
    }

    protected function guardVideoAdmin(): void
    {
        abort_unless(auth()->user()?->canManageIntegrations() === true, 403);
    }

    /** @param  callable(VideoRoomService): string  $fn */
    protected function runVideo(callable $fn): void
    {
        try {
            $this->videoNotice = $fn(app(VideoRoomService::class));
            $this->videoNoticeError = false;
        } catch (VideoProviderException $e) {
            $this->videoNotice = $e->userMessage();
            $this->videoNoticeError = true;
        } catch (LockTimeoutException) {
            $this->videoNotice = __('Ya hay una operación en curso sobre esta sala. Inténtalo de nuevo en unos segundos.');
            $this->videoNoticeError = true;
        }
    }
}
