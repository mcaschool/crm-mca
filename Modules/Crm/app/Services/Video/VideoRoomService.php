<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Video;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Services\AuditService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Models\VideoRoom;
use Modules\Crm\Services\Agenda\ActivityService;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Integrations\Models\Integration;

/**
 * Salas permanentes por asesor creadas con la conexión INSTITUCIONAL (los asesores nunca conectan
 * cuentas propias ni ven credenciales). Solo la administración genera, revisa, asigna y regenera.
 *
 * - Idempotente: bloqueo por (institución, asesor, proveedor); se reutiliza la sala activa válida y
 *   solo se crea otra si hace falta o se pide regenerar. En Zoom, una marca determinista en la
 *   descripción permite recuperar la reunión creada si se perdió la respuesta (nunca se duplica).
 * - Asignación: la sala pasa al perfil de reuniones del asesor (el enlace que copian las actividades
 *   nuevas). Un enlace MANUAL distinto se conserva hasta que un administrador lo reemplace.
 * - Las actividades existentes nunca cambian solas: solo las futuras SIN enlace, con una operación
 *   explícita (applyToFutureActivities).
 */
class VideoRoomService
{
    public function __construct(
        private readonly VideoConnections $connections,
        private readonly GoogleMeetClient $google,
        private readonly ZoomClient $zoom,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly CurrentInstitution $context,
    ) {}

    /** Sala vigente (no reemplazada) del asesor para el proveedor. */
    public function current(int $userId, string $provider): ?VideoRoom
    {
        return VideoRoom::query()->where('user_id', $userId)->where('provider', $provider)
            ->where('status', '!=', 'replaced')->orderByDesc('id')->first();
    }

    public function generate(User $advisor, string $provider, User $actor, bool $regenerate = false): VideoRoom
    {
        $this->guardAdmin($actor);
        $this->guardProvider($provider);
        $this->guardAdvisor($advisor);
        $integration = $this->connected($provider);
        $institutionId = $this->context->idOrFail('videoconferencias');

        $room = Cache::lock('crm-video-room:'.$institutionId.':'.$advisor->id.':'.$provider, 60)->block(20, function () use ($advisor, $provider, $actor, $regenerate, $integration, $institutionId): VideoRoom {
            $current = $this->current($advisor->id, $provider);
            if (! $regenerate && $current !== null) {
                $current = $this->refresh($current, $integration);
                if ($current->status === 'active') {
                    return $current; // reutiliza la sala válida: no crea otra
                }
            }

            $generation = VideoRoom::query()->where('user_id', $advisor->id)->where('provider', $provider)->count() + 1;
            $data = $provider === VideoConnections::GOOGLE
                ? $this->createMeet($integration, $advisor)
                : $this->createZoom($integration, $advisor, $this->marker($institutionId, $advisor->id, $generation));

            return DB::transaction(function () use ($advisor, $provider, $actor, $data): VideoRoom {
                $previous = VideoRoom::query()->where('user_id', $advisor->id)->where('provider', $provider)
                    ->where('status', '!=', 'replaced')->lockForUpdate()->get();
                $room = VideoRoom::query()->create($data + [
                    'user_id' => $advisor->id,
                    'provider' => $provider,
                    'status' => 'active',
                    'created_by_user_id' => $actor->id,
                    'last_checked_at' => now(),
                ]);
                $profile = MeetingProfile::forUser($advisor->id);
                $profileLink = $provider === VideoConnections::GOOGLE ? $profile->meet_url : $profile->zoom_url;
                $profileHash = Activity::roomHash($profileLink);
                // Solo se sustituye automáticamente un perfil vacío o con la sala institucional anterior.
                if ($profileHash === null || $previous->contains(fn (VideoRoom $r) => $r->join_url_hash === $profileHash)) {
                    $this->writeProfileLink($advisor->id, $provider, $room->join_url);
                }
                foreach ($previous as $old) {
                    $old->forceFill(['status' => 'replaced'])->save();
                }

                return $room;
            });
        });

        if ($room->wasRecentlyCreated) {
            $this->audit->log($regenerate ? 'video_room.regenerated' : 'video_room.generated', $room, ['provider' => $provider, 'user_id' => $advisor->id]);
        }

        return $room;
    }

    /** Comprueba la sala en el proveedor (y reintenta la coanfitrionía en Meet si estaba pendiente). */
    public function check(VideoRoom $room, User $actor): VideoRoom
    {
        $this->guardAdmin($actor);

        return $this->refresh($room, $this->connected($room->provider));
    }

    /** Reemplazo explícito del enlace (manual) del perfil por la sala institucional. */
    public function assign(VideoRoom $room, User $actor): void
    {
        $this->guardAdmin($actor);
        if (! $room->isActive()) {
            throw ValidationException::withMessages(['room' => __('Solo se puede asignar una sala activa.')]);
        }
        $this->writeProfileLink($room->user_id, $room->provider, $room->join_url);
        $this->audit->log('video_room.assigned', $room, ['provider' => $room->provider, 'user_id' => $room->user_id]);
    }

    public function isProfileLink(VideoRoom $room): bool
    {
        $profile = MeetingProfile::forUser($room->user_id);

        return Activity::roomHash($room->provider === VideoConnections::GOOGLE ? $profile->meet_url : $profile->zoom_url) === $room->join_url_hash;
    }

    /**
     * Actividades futuras y programadas del asesor, del mismo medio y SIN enlace.
     *
     * @return \Illuminate\Support\Collection<int,Activity>
     */
    public function pendingActivities(VideoRoom $room): \Illuminate\Support\Collection
    {
        return Activity::query()->scheduled()->where('assigned_to_user_id', $room->user_id)
            ->where('medium', $room->provider === VideoConnections::GOOGLE ? ActivityMedium::GoogleMeet->value : ActivityMedium::Zoom->value)
            ->where('starts_at', '>', now())
            ->whereNull('meeting_link')
            ->orderBy('starts_at')
            ->limit(200)
            ->get();
    }

    /**
     * Operación explícita: aplica la sala a las actividades futuras sin enlace (nunca a las demás).
     *
     * @return array{updated:int, skipped:int}
     */
    public function applyToFutureActivities(VideoRoom $room, User $actor): array
    {
        $this->guardAdmin($actor);
        if (! $room->isActive()) {
            throw ValidationException::withMessages(['room' => __('Solo se puede aplicar una sala activa.')]);
        }
        $updated = 0;
        $skipped = 0;
        foreach ($this->pendingActivities($room) as $activity) {
            $this->activities->attachRoomLink($activity, $room) ? $updated++ : $skipped++;
        }
        $this->audit->log('video_room.applied', $room, ['updated' => $updated, 'skipped' => $skipped]);

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /** @return array<string,mixed> */
    private function createMeet(Integration $integration, User $advisor): array
    {
        $space = $this->call($integration, 'google.create', fn () => $this->google->createSpace($integration));
        $cohost = $this->call($integration, 'google.cohost', fn () => $this->google->assignCohost($integration, $space['name'], (string) $advisor->email));

        return [
            'remote_id' => $space['name'],
            'join_url' => $space['meetingUri'],
            'join_url_hash' => (string) Activity::roomHash($space['meetingUri']),
            'host_key' => null,
            'host_status' => $cohost['status'],
            'host_note' => $cohost['note'] !== null ? Str::limit($cohost['note'], 250) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function createZoom(Integration $integration, User $advisor, string $marker): array
    {
        // Solo se crea si el asesor es usuario CON LICENCIA de la cuenta institucional: entonces es el
        // anfitrión real de su reunión. «Unirse antes que el anfitrión» no convierte a nadie en
        // anfitrión y la sala de espera lo bloquea, así que sin licencia no se crea ninguna sala
        // (se informa y el perfil conserva su enlace manual).
        $hostId = $this->licensedAdvisorId($integration, $advisor);
        if ($hostId === null) {
            throw new VideoProviderException('advisor_not_host', 'zoom: el asesor no es usuario con licencia de la cuenta');
        }

        $meeting = $this->call($integration, 'zoom.find', fn () => $this->zoom->findByMarker($integration, $hostId, $marker))
            ?? $this->call($integration, 'zoom.create', fn () => $this->zoom->createMeeting(
                $integration,
                $hostId,
                __('Sala de :name', ['name' => $advisor->name]),
                __('Sala permanente del CRM.').' '.$marker,
            ));
        if ($meeting['host_id'] !== $hostId) {
            throw new VideoProviderException('invalid_response', 'zoom.create: anfitrión inesperado');
        }

        return [
            'remote_id' => $meeting['id'],
            'join_url' => $meeting['join_url'],
            'join_url_hash' => (string) Activity::roomHash($meeting['join_url']),
            'host_key' => 'zoom:'.$hostId,
            'host_status' => 'advisor_host',
            'host_note' => $this->zoomNote(true, $meeting['waiting_room']),
        ];
    }

    /** Id de Zoom del asesor si es usuario activo y CON LICENCIA de la cuenta institucional. */
    private function licensedAdvisorId(Integration $integration, User $advisor): ?string
    {
        $own = $this->call($integration, 'zoom.user', fn () => $this->zoom->user($integration, (string) $advisor->email));

        return $own !== null && $own['type'] === 2 ? $own['id'] : null;
    }

    private function refresh(VideoRoom $room, Integration $integration): VideoRoom
    {
        try {
            if ($room->provider === VideoConnections::GOOGLE) {
                $space = $this->call($integration, 'google.get', fn () => $this->google->getSpace($integration, $room->remote_id));
                $ok = Activity::roomHash($space['meetingUri']) === $room->join_url_hash;
                $updates = ['status' => $ok ? 'active' : 'error', 'last_error' => $ok ? null : 'El enlace del espacio no coincide.'];
                if ($ok && $room->host_status !== 'assigned') {
                    $user = User::query()->find($room->user_id);
                    $cohost = $user !== null
                        ? $this->call($integration, 'google.cohost', fn () => $this->google->assignCohost($integration, $room->remote_id, (string) $user->email))
                        : ['status' => 'failed', 'note' => null];
                    $updates += ['host_status' => $cohost['status'], 'host_note' => $cohost['note'] !== null ? Str::limit($cohost['note'], 250) : null];
                }
            } else {
                $meeting = $this->call($integration, 'zoom.get', fn () => $this->zoom->getMeeting($integration, $room->remote_id));
                $user = User::query()->find($room->user_id);
                // Operativa solo si el asesor sigue siendo, con licencia, el anfitrión de ESA reunión.
                $isHost = $user !== null && $this->licensedAdvisorId($integration, $user) === $meeting['host_id'];
                $linkOk = Activity::roomHash($meeting['join_url']) === $room->join_url_hash;
                $updates = [
                    'status' => $linkOk && $isHost ? 'active' : 'error',
                    'last_error' => ! $linkOk ? 'El enlace de la reunión no coincide.' : ($isHost ? null : 'El asesor no es el anfitrión de la reunión.'),
                    'host_status' => $isHost ? 'advisor_host' : 'not_host',
                    'host_note' => $this->zoomNote($isHost, $meeting['waiting_room']),
                ];
            }
        } catch (VideoProviderException $e) {
            if ($e->kind !== 'not_found') {
                $room->forceFill(['last_error' => Str::limit($e->userMessage(), 250), 'last_checked_at' => now()])->save();
                throw $e;
            }
            $updates = ['status' => 'missing', 'last_error' => $e->userMessage()];
        }
        $room->forceFill($updates + ['last_checked_at' => now()])->save();

        return $room;
    }

    private function zoomNote(bool $advisorIsHost, bool $waitingRoom): string
    {
        $note = match (true) {
            ! $advisorIsHost => __('El asesor no es el anfitrión: no puede iniciarla ni dirigirla («unirse antes» no lo convierte en anfitrión y la sala de espera lo impide), así que no se considera operativa. Usa un enlace manual o regenérala cuando tenga licencia.'),
            $waitingRoom => __('El asesor es el anfitrión con su licencia de la cuenta institucional: inicia la reunión y admite a los invitados desde la sala de espera.'),
            default => __('El asesor es el anfitrión con su licencia, pero la cuenta de Zoom no aplicó la sala de espera solicitada. Revisa la configuración de la cuenta.'),
        };

        return Str::limit($note, 250);
    }

    /**
     * Ejecuta una llamada al proveedor registrando el fallo sin secretos (y marcando la conexión
     * como caducada si la autorización ya no es válida).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function call(Integration $integration, string $operation, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (VideoProviderException $e) {
            if ($e->kind !== 'not_found') {
                $this->connections->recordFailure($integration, $e, $operation);
            }
            throw $e;
        }
    }

    private function writeProfileLink(int $userId, string $provider, string $url): void
    {
        $profile = MeetingProfile::query()->firstOrNew(['user_id' => $userId]);
        if (! $profile->exists) {
            $profile->timezone = (string) config('crm.agenda.default_timezone', 'UTC');
        }
        $profile->{$provider === VideoConnections::GOOGLE ? 'meet_url' : 'zoom_url'} = $url;
        $profile->save();
    }

    private function marker(int $institutionId, int $userId, int $generation): string
    {
        return 'crm-room-'.substr(hash_hmac('sha256', $institutionId.'|'.$userId.'|zoom|'.$generation, (string) config('app.key')), 0, 20);
    }

    private function connected(string $provider): Integration
    {
        $integration = $this->connections->get($provider);
        if (! $this->connections->isConnected($integration)) {
            throw new VideoProviderException($integration?->status === 'expired' ? 'auth' : 'config', $provider.': sin conexión activa');
        }

        /** @var Integration $integration */
        return $integration;
    }

    private function guardAdmin(User $actor): void
    {
        if (! $actor->canManageIntegrations()) {
            throw new AuthorizationException(__('Solo la administración gestiona las salas institucionales.'));
        }
    }

    private function guardProvider(string $provider): void
    {
        if (! in_array($provider, VideoConnections::TYPES, true)) {
            throw ValidationException::withMessages(['provider' => __('Proveedor no válido.')]);
        }
    }

    private function guardAdvisor(User $advisor): void
    {
        if (! app(AgendaDirectory::class)->assignableUsers()->contains('id', $advisor->id)) {
            throw ValidationException::withMessages(['user' => __('Asesor no válido.')]);
        }
    }
}
