<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Cliente del API de salas de LiveKit (servicio Twirp «livekit.RoomService», JSON sobre HTTP POST) con
 * el cliente HTTP de Laravel. Tiempo máximo, tiempo de conexión y reintentos configurables; solo se
 * reintentan fallos de red, 5xx y 429 (todas las operaciones usadas son idempotentes). Cada petición
 * lleva un token de servicio corto con el permiso mínimo de esa operación.
 */
final class LiveKitRoomClient
{
    private const SERVICE = '/twirp/livekit.RoomService/';

    public function __construct(
        private readonly LiveKitConfig $config,
        private readonly LiveKitTokens $tokens,
    ) {}

    /** @return array<string, mixed> */
    public function createRoom(string $room, int $maxParticipants): array
    {
        return $this->call('CreateRoom', [
            'name' => $room,
            'empty_timeout' => $this->config->emptyTimeout,
            'departure_timeout' => $this->config->departureTimeout,
            'max_participants' => $maxParticipants,
        ], ['roomCreate' => true]);
    }

    /** @return list<array<string, mixed>> */
    public function listParticipants(string $room): array
    {
        $participants = $this->call('ListParticipants', ['room' => $room], ['roomAdmin' => true, 'room' => $room])['participants'] ?? [];

        return is_array($participants) ? array_values(array_filter($participants, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public function listRooms(): array
    {
        $rooms = $this->call('ListRooms', [], ['roomList' => true])['rooms'] ?? [];

        return is_array($rooms) ? array_values(array_filter($rooms, 'is_array')) : [];
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $room, 'identity' => $identity], ['roomAdmin' => true, 'room' => $room]);
    }

    public function deleteRoom(string $room): void
    {
        $this->call('DeleteRoom', ['room' => $room], ['roomCreate' => true]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $grant
     * @return array<string, mixed>
     */
    private function call(string $method, array $body, array $grant): array
    {
        try {
            $response = Http::withToken($this->tokens->server($grant))
                ->acceptJson()
                ->asJson()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->retry($this->config->retries + 1, $this->config->retrySleepMs, fn (Throwable $e) => $this->retryable($e), throw: false)
                ->post($this->config->apiUrl.self::SERVICE.$method, $body === [] ? new stdClass : $body);
        } catch (ConnectionException) {
            throw new LiveKitException('unreachable', "LiveKit {$method}: server unreachable.");
        }

        return $this->decode($method, $response);
    }

    /** @return array<string, mixed> */
    private function decode(string $method, Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }
        $code = is_string($response->json('code')) ? $response->json('code') : '';
        if ($response->status() === 404 || $code === 'not_found') {
            throw new LiveKitException('not_found', "LiveKit {$method}: not found.", $response->status());
        }
        $kind = $response->serverError() || $response->status() === 429 ? 'unreachable' : 'rejected';

        throw new LiveKitException($kind, "LiveKit {$method}: HTTP {$response->status()}".($code !== '' ? " ({$code})" : '').'.', $response->status());
    }

    private function retryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429);
    }
}
