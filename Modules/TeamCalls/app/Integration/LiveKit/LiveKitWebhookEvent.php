<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\LiveKit;

use Modules\TeamCalls\Support\MediaEvent;

/**
 * Traduce el cuerpo (ya verificado) de un webhook de LiveKit a un MediaEvent neutral. Acepta los
 * nombres de campo de protojson (camelCase) y los del proto (snake_case).
 */
final class LiveKitWebhookEvent
{
    /** participant_connection_aborted: se cortó antes de terminar de conectarse (equivale a salir). */
    private const KINDS = [
        'participant_joined' => 'participant_joined',
        'participant_left' => 'participant_left',
        'participant_connection_aborted' => 'participant_left',
        'room_started' => 'room_started',
        'room_finished' => 'room_finished',
    ];

    /** @param array<string, mixed> $payload */
    public static function toMediaEvent(array $payload): ?MediaEvent
    {
        $kind = self::KINDS[(string) ($payload['event'] ?? '')] ?? null;
        $room = is_array($payload['room'] ?? null) ? (string) ($payload['room']['name'] ?? '') : '';
        if ($kind === null || $room === '') {
            return null;
        }
        $participant = is_array($payload['participant'] ?? null) ? $payload['participant'] : [];
        $identity = isset($participant['identity']) ? (string) $participant['identity'] : null;
        $session = self::token($participant['sid'] ?? null, 64);

        $id = self::token($payload['id'] ?? null, 100)
            ?? self::token(implode('.', array_filter([(string) $payload['event'], $session ?? self::token($payload['room']['sid'] ?? null, 64),
                (string) ($payload['createdAt'] ?? $payload['created_at'] ?? '')])), 100);

        return $id === null ? null : new MediaEvent($id, $kind, $room, $identity, $session);
    }

    private static function token(mixed $value, int $max): ?string
    {
        return is_scalar($value) && preg_match('/^[A-Za-z0-9._:-]{1,'.$max.'}$/', (string) $value) === 1 ? (string) $value : null;
    }
}
