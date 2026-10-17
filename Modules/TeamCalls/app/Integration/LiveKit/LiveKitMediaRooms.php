<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\TeamCalls\Contracts\MediaOutage;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;
use Modules\TeamCalls\Support\MediaParticipant;

/**
 * MediaRoomService sobre LiveKit AUTOHOSPEDADO. Las salas las crea solo el backend (en el servidor:
 * room.auto_create = false), con nombre opaco ligado a una llamada; los clientes reciben una credencial
 * para esa sala y nada más. La conexión real la confirman los webhooks firmados (confirmsConnections).
 *
 * Errores de comunicación → CallException «media_unavailable» (503). «No existe» en operaciones
 * idempotentes (retirar, cerrar, listar) no es un error.
 */
final class LiveKitMediaRooms implements MediaOutage, MediaRoomService
{
    public function __construct(
        private readonly LiveKitConfig $config,
        private readonly LiveKitRoomClient $client,
        private readonly LiveKitTokens $tokens,
        private readonly ?MediaHealth $health = null,
    ) {}

    /** Configurado y no marcado como caído por el disyuntor (sin llamada de red: se consulta a menudo). */
    public function available(): bool
    {
        return ! $this->outage();
    }

    public function outage(): bool
    {
        return $this->health?->isDown() ?? false;
    }

    public function confirmsConnections(): bool
    {
        return true;
    }

    public function prepareRoom(string $room, int $maxParticipants): void
    {
        $this->guard('prepare_room', fn () => $this->client->createRoom($room, $maxParticipants));
    }

    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials
    {
        $token = $this->tokens->participant($grant);

        return new JoinCredentials($this->config->clientUrl, $token['token'], $grant->identity, $token['expires_at']);
    }

    public function admitParticipant(MediaGrant $grant): void
    {
        // Si ya no está en la sala («participant does not exist») no hay nada que conceder.
        $this->guard('admit_participant', fn () => $this->client->updatePermission($grant->room, $grant->identity, $this->tokens->permission($grant)), notFound: null);
    }

    public function participants(string $room): array
    {
        $rows = $this->guard('list_participants', fn () => $this->client->listParticipants($room), notFound: []);
        $out = [];
        foreach ($rows as $row) {
            $state = strtoupper((string) ($row['state'] ?? ''));
            $identity = (string) ($row['identity'] ?? '');
            if ($identity === '' || in_array($state, ['DISCONNECTED', '3'], true)) {
                continue;
            }
            // El API real responde con los nombres del proto (snake_case); se aceptan ambos formatos.
            $joined = $row['joined_at'] ?? $row['joinedAt'] ?? null;
            $permission = is_array($row['permission'] ?? null) ? $row['permission'] : [];
            $out[] = new MediaParticipant($identity, (string) ($row['sid'] ?? ''), $state,
                is_numeric($joined) && (int) $joined > 0 ? Carbon::createFromTimestamp((int) $joined) : null,
                (bool) ($permission['can_subscribe'] ?? $permission['canSubscribe'] ?? false));
        }

        return $out;
    }

    public function rooms(): array
    {
        $rows = $this->guard('list_rooms', fn () => $this->client->listRooms(), notFound: []);

        return array_values(array_filter(array_map(fn (array $r) => (string) ($r['name'] ?? ''), $rows)));
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->guard('remove_participant', fn () => $this->client->removeParticipant($room, $identity), notFound: null);
    }

    public function closeRoom(string $room): void
    {
        $this->guard('close_room', fn () => $this->client->deleteRoom($room), notFound: null);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  mixed  $notFound  valor a devolver si LiveKit responde «no existe» (false = es un error)
     * @return T
     */
    private function guard(string $operation, callable $fn, mixed $notFound = false): mixed
    {
        try {
            return $fn();
        } catch (LiveKitException $e) {
            if ($e->kind === 'not_found' && $notFound !== false) {
                return $notFound;
            }
            Log::warning('teamcalls.livekit_failed', ['operation' => $operation, 'kind' => $e->kind, 'status' => $e->httpStatus]);

            throw CallException::mediaUnavailable();
        }
    }
}
