<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use Illuminate\Support\Carbon;
use Modules\TeamCalls\Contracts\CallEventPublisher;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\Presence;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Support\CallEnvelope;
use Modules\TeamCalls\Support\CallSummary;
use Modules\TeamCalls\Support\JoinCredentials;
use Modules\TeamCalls\Support\MediaGrant;
use Modules\TeamCalls\Support\MediaParticipant;

/**
 * Dobles de prueba que REGISTRAN lo que TeamCalls pide a CollaborationCore y al servidor de medios:
 * publicaciones en tiempo real, push, presencia y salas. Ningún envío real.
 */
final class RecordingCollaboration implements CallEventPublisher, MediaRoomService, Presence, PushNotifier
{
    /** @var list<CallEnvelope> */
    public array $published = [];

    /** @var list<array{users:list<int>, call:string}> */
    public array $incoming = [];

    /** @var list<array{users:list<int>, call:string, reason:string, except:?string}> */
    public array $cancelled = [];

    /** @var array<string, true> userId|callId */
    public array $inCall = [];

    /** @var list<MediaGrant> */
    public array $grants = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var list<string> */
    public array $closed = [];

    public function publish(CallEnvelope $envelope): void
    {
        $this->published[] = $envelope;
    }

    public function incomingCall(array $userIds, CallSummary $call): void
    {
        $this->incoming[] = ['users' => $userIds, 'call' => $call->id];
    }

    public function cancelIncomingCall(array $userIds, string $callId, string $reason, ?string $exceptDeviceId = null): void
    {
        $this->cancelled[] = ['users' => $userIds, 'call' => $callId, 'reason' => $reason, 'except' => $exceptDeviceId];
    }

    public function setInCall(int $userId, string $callId, int $ttlSeconds): void
    {
        $this->inCall[$userId.'|'.$callId] = true;
    }

    public function clearInCall(int $userId, string $callId): void
    {
        unset($this->inCall[$userId.'|'.$callId]);
    }

    /** Servidor de medios de prueba (sin red). false simula «sin infraestructura multimedia». */
    public bool $mediaAvailable = true;

    /** false (fases 1 y 2): unirse cuenta como conectado. true: solo los eventos de medios conectan. */
    public bool $confirms = false;

    /** @var array<string, int> sala => máximo de participantes */
    public array $prepared = [];

    /** @var array<string, list<MediaParticipant>> participantes reales por sala (los fija la prueba) */
    public array $live = [];

    public function available(): bool
    {
        return $this->mediaAvailable;
    }

    public function confirmsConnections(): bool
    {
        return $this->confirms;
    }

    public function prepareRoom(string $room, int $maxParticipants): void
    {
        $this->prepared[$room] = $maxParticipants;
    }

    public function participants(string $room): array
    {
        return $this->live[$room] ?? [];
    }

    public function rooms(): array
    {
        return array_keys($this->prepared);
    }

    public function issueJoinCredentials(MediaGrant $grant): JoinCredentials
    {
        $this->grants[] = $grant;

        return new JoinCredentials('wss://media.test', 'fake.'.$grant->identity, $grant->identity, Carbon::now()->addSeconds($grant->ttlSeconds));
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->removed[] = $room.'|'.$identity;
    }

    public function closeRoom(string $room): void
    {
        unset($this->prepared[$room], $this->live[$room]);
        $this->closed[] = $room;
    }

    /** @return list<string> tipos publicados (en orden) */
    public function types(): array
    {
        return array_map(fn (CallEnvelope $e) => $e->type, $this->published);
    }

    /** @return list<CallEnvelope> */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->published, fn (CallEnvelope $e) => $e->type === $type));
    }
}
