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
 * Salas permanentes por usuario creadas con la conexión INSTITUCIONAL (los usuarios nunca conectan
 * cuentas propias ni ven credenciales). Solo la administración genera, revisa, asigna y regenera.
 *
 * - Una sala por usuario y proveedor, con su propio id y enlace. En Zoom, si el usuario tiene
 *   licencia en la cuenta es el anfitrión real de SU reunión; si no, la reunión pertenece al
 *   anfitrión técnico institucional (no hace falta ninguna licencia individual) y al usuario no se
 *   le atribuye ningún privilegio de anfitrión.
 * - Idempotente: bloqueo por (institución, usuario, proveedor); se reutiliza la sala activa válida y
 *   solo se crea otra si hace falta o se pide regenerar. En Zoom, una marca determinista en la
 *   descripción permite recuperar la reunión creada si se perdió la respuesta (nunca se duplica).
 * - Asignación: la sala pasa al perfil de reuniones del usuario (el enlace que copian las
 *   actividades nuevas). Un enlace MANUAL distinto se conserva hasta que un administrador lo reemplace.
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

    /** Sala vigente (no reemplazada) del usuario para el proveedor. */
    public function current(int $userId, string $provider): ?VideoRoom
    {
        return VideoRoom::query()->where('user_id', $userId)->where('provider', $provider)
            ->where('status', '!=', 'replaced')->orderByDesc('id')->first();
    }

    public function generate(User $owner, string $provider, User $actor, bool $regenerate = false): VideoRoom
    {
        $this->guardAdmin($actor);
        $this->guardProvider($provider);
        $this->guardOwner($owner);
        $integration = $this->connected($provider);
        $institutionId = $this->context->idOrFail('videoconferencias');

        $room = Cache::lock('crm-video-room:'.$institutionId.':'.$owner->id.':'.$provider, 60)->block(20, function () use ($owner, $provider, $actor, $regenerate, $integration, $institutionId): VideoRoom {
            $current = $this->current($owner->id, $provider);
            if (! $regenerate && $current !== null) {
                $current = $this->refresh($current, $integration);
                if ($current->status === 'active') {
                    return $current; // reutiliza la sala válida: no crea otra
                }
            }

            $generation = VideoRoom::query()->where('user_id', $owner->id)->where('provider', $provider)->count() + 1;
            $data = $provider === VideoConnections::GOOGLE
                ? $this->createMeet($integration, $owner)
                : $this->createZoom($integration, $owner, $this->marker($institutionId, $owner->id, $generation));

            return DB::transaction(function () use ($owner, $provider, $actor, $data): VideoRoom {
                $previous = VideoRoom::query()->where('user_id', $owner->id)->where('provider', $provider)
                    ->where('status', '!=', 'replaced')->lockForUpdate()->get();
                $room = VideoRoom::query()->create($data + [
                    'user_id' => $owner->id,
                    'provider' => $provider,
                    'status' => 'active',
                    'created_by_user_id' => $actor->id,
                    'last_checked_at' => now(),
                ]);
                $profile = MeetingProfile::forUser($owner->id);
                $profileLink = $provider === VideoConnections::GOOGLE ? $profile->meet_url : $profile->zoom_url;
                $profileHash = Activity::roomHash($profileLink);
                // Solo se sustituye automáticamente un perfil vacío o con la sala institucional anterior.
                if ($profileHash === null || $previous->contains(fn (VideoRoom $r) => $r->join_url_hash === $profileHash)) {
                    $this->writeProfileLink($owner->id, $provider, $room->join_url);
                }
                foreach ($previous as $old) {
                    $old->forceFill(['status' => 'replaced'])->save();
                }

                return $room;
            });
        });

        if ($room->wasRecentlyCreated) {
            $this->audit->log($regenerate ? 'video_room.regenerated' : 'video_room.generated', $room, ['provider' => $provider, 'user_id' => $owner->id]);
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
     * Actividades futuras y programadas del usuario, del mismo medio y SIN enlace.
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

    /** ¿La administración decidió el acceso sin anfitrión para las reuniones del anfitrión institucional? */
    public function hostless(?Integration $integration): bool
    {
        return $integration !== null && $integration->secret('hostless') === true;
    }

    /**
     * Decisión EXPLÍCITA de la administración sobre la modalidad de acceso de las reuniones del
     * anfitrión institucional (las de usuarios con licencia no cambian: ellos las dirigen). Se aplica
     * también a las salas existentes, sin cambiar su id ni su enlace, y se informa de lo que Zoom
     * aplicó realmente en cada una.
     *
     * @return array{updated:int, failed:array<string,string>}
     */
    public function setZoomAccess(bool $hostless, User $actor): array
    {
        $this->guardAdmin($actor);
        $integration = $this->connected(VideoConnections::ZOOM);
        $this->connections->put($integration, [
            'hostless' => $hostless,
            'hostless_decided_by' => $actor->id,
            'hostless_decided_at' => now()->toIso8601String(),
        ]);

        $updated = 0;
        $failed = [];
        $rooms = VideoRoom::query()->with('user:id,name')->where('provider', VideoConnections::ZOOM)
            ->where('status', 'active')->where('host_status', 'institutional_host')->orderBy('id')->limit(500)->get();
        foreach ($rooms as $room) {
            try {
                $meeting = $this->call($integration, 'zoom.update', fn () => $this->zoom->updateAccess($integration, $room->remote_id, $hostless));
                $room->forceFill([
                    'host_note' => $this->zoomNote('institutional_host', $meeting, $hostless),
                    'last_error' => null,
                    'last_checked_at' => now(),
                ])->save();
                $updated++;
            } catch (VideoProviderException $e) {
                $failed[$room->user->name ?? '#'.$room->user_id] = $e->userMessage();
                $room->forceFill(['last_error' => Str::limit($e->userMessage(), 250), 'last_checked_at' => now()])->save();
            }
        }
        $this->audit->log('video_room.access_mode_changed', $integration, ['hostless' => $hostless, 'updated' => $updated, 'failed' => count($failed)]);

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** @return array<string,mixed> */
    private function createMeet(Integration $integration, User $owner): array
    {
        $space = $this->call($integration, 'google.create', fn () => $this->google->createSpace($integration));
        $cohost = $this->call($integration, 'google.cohost', fn () => $this->google->assignCohost($integration, $space['name'], (string) $owner->email));

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
    private function createZoom(Integration $integration, User $owner, string $marker): array
    {
        // Con licencia en la cuenta, el usuario es el anfitrión real de su reunión. Sin licencia, la
        // reunión (con su propio id y enlace) pertenece al anfitrión técnico institucional: no se
        // exige licencia individual y al usuario no se le atribuye ningún privilegio de anfitrión.
        $own = $this->licensedUserId($integration, $owner);
        $hostId = $own ?? $this->institutionalHostId($integration);
        $hostless = $own === null && $this->hostless($integration);

        $meeting = $this->call($integration, 'zoom.find', fn () => $this->zoom->findByMarker($integration, $hostId, $marker))
            ?? $this->call($integration, 'zoom.create', fn () => $this->zoom->createMeeting(
                $integration,
                $hostId,
                __('Sala de :name', ['name' => $owner->name]),
                __('Sala permanente del CRM.').' '.$marker,
                $hostless,
            ));
        if ($meeting['host_id'] !== $hostId) {
            throw new VideoProviderException('invalid_response', 'zoom.create: anfitrión inesperado');
        }
        $hostStatus = $own !== null ? 'advisor_host' : 'institutional_host';

        return [
            'remote_id' => $meeting['id'],
            'join_url' => $meeting['join_url'],
            'join_url_hash' => (string) Activity::roomHash($meeting['join_url']),
            'host_key' => 'zoom:'.$hostId,
            'host_status' => $hostStatus,
            'host_note' => $this->zoomNote($hostStatus, $meeting, $hostless),
        ];
    }

    /** Id de Zoom del usuario si es usuario activo y CON LICENCIA de la cuenta institucional. */
    private function licensedUserId(Integration $integration, User $user): ?string
    {
        $own = $this->call($integration, 'zoom.user', fn () => $this->zoom->user($integration, (string) $user->email));

        return $own !== null && $own['type'] === 2 ? $own['id'] : null;
    }

    /** Anfitrión técnico de la conexión (identificado en la prueba; se resuelve si aún no consta). */
    private function institutionalHostId(Integration $integration): string
    {
        $id = (string) $integration->secret('host_user_id', '');
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1) {
            return $id;
        }
        $host = $this->call($integration, 'zoom.user', fn () => $this->zoom->user($integration, (string) ($integration->secret('host_email') ?: 'me')));
        if ($host === null) {
            throw new VideoProviderException('config', 'zoom: anfitrión institucional no identificado');
        }
        $this->connections->put($integration, ['host_user_id' => $host['id'], 'account_email' => $host['email'], 'host_type' => $host['type']]);

        return $host['id'];
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
                $linkOk = Activity::roomHash($meeting['join_url']) === $room->join_url_hash;
                // De quién es la reunión: del anfitrión institucional (sala válida sin licencia) o del
                // propio usuario con licencia. Cualquier otro anfitrión no se da por operativo.
                if ($meeting['host_id'] === $this->institutionalHostId($integration)) {
                    $hostStatus = 'institutional_host';
                } else {
                    $user = User::query()->find($room->user_id);
                    $hostStatus = $user !== null && $this->licensedUserId($integration, $user) === $meeting['host_id'] ? 'advisor_host' : 'not_host';
                }
                $updates = [
                    'status' => $linkOk && $hostStatus !== 'not_host' ? 'active' : 'error',
                    'last_error' => ! $linkOk ? 'El enlace de la reunión no coincide.' : ($hostStatus === 'not_host' ? 'La reunión no es del usuario ni del anfitrión institucional.' : null),
                    'host_status' => $hostStatus,
                    'host_key' => $hostStatus === 'not_host' ? $room->host_key : 'zoom:'.$meeting['host_id'],
                    'host_note' => $this->zoomNote($hostStatus, $meeting, $this->hostless($integration)),
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

    /** @param  array{join_before_host:bool, waiting_room:bool}  $meeting  lo que Zoom aplicó realmente */
    private function zoomNote(string $hostStatus, array $meeting, bool $wantHostless): string
    {
        $applied = $meeting['join_before_host'] && ! $meeting['waiting_room'];
        $note = match (true) {
            $hostStatus === 'not_host' => __('La reunión no pertenece a este usuario ni al anfitrión institucional, así que no se considera operativa. Regenérala.'),
            $hostStatus === 'advisor_host' && $meeting['waiting_room'] => __('Es el anfitrión con su licencia de la cuenta institucional: inicia la reunión y admite a los invitados desde la sala de espera.'),
            $hostStatus === 'advisor_host' => __('Es el anfitrión con su licencia, pero la cuenta de Zoom no aplicó la sala de espera solicitada. Revisa la configuración de la cuenta.'),
            $applied => __('Anfitrión técnico: la cuenta institucional. Acceso sin anfitrión: se entra con el enlace (incluye la contraseña) sin esperar; nadie tiene controles de anfitrión salvo quien entre con la cuenta institucional.'),
            $wantHostless => __('Se pidió el acceso sin anfitrión, pero la cuenta de Zoom no lo aplicó (puede estar bloqueado en su configuración). Los invitados esperan hasta que alguien la inicie con la cuenta institucional.'),
            default => __('Anfitrión técnico: la cuenta institucional. Este usuario no es anfitrión en Zoom: la reunión empieza cuando alguien la inicia con la cuenta institucional; hasta entonces los invitados esperan.'),
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

    private function guardOwner(User $owner): void
    {
        if (! app(AgendaDirectory::class)->roomUsers()->contains('id', $owner->id)) {
            throw ValidationException::withMessages(['user' => __('Este usuario no puede tener sala: debe estar activo y con acceso al Calendario.')]);
        }
    }
}
