<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Exceptions;

use RuntimeException;

/**
 * Error de dominio de TeamCalls con un CÓDIGO estable ($reason) que la futura capa HTTP/API traduce
 * a un estado y a un texto para el usuario. El mensaje es técnico y nunca contiene datos sensibles.
 * Un id inexistente y uno de otra institución dan el mismo «not_found» (no se revela que existe).
 */
final class CallException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Call not found.', 404);
    }

    public static function forbidden(string $why = 'Not allowed.'): self
    {
        return new self('forbidden', $why, 403);
    }

    public static function invalidTransition(string $detail): self
    {
        return new self('invalid_transition', 'Invalid state transition: '.$detail, 409);
    }

    public static function answeredElsewhere(): self
    {
        return new self('answered_elsewhere', 'The call was already answered on another device.', 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 'The idempotency key was already used for a different call.', 409);
    }

    public static function busy(): self
    {
        return new self('busy', 'The user is already in another call.', 409);
    }

    public static function invalid(string $detail): self
    {
        return new self('invalid', $detail, 422);
    }

    public static function tooManyParticipants(int $max): self
    {
        return new self('too_many_participants', "A call allows at most {$max} participants.", 422);
    }

    public static function mediaUnavailable(): self
    {
        return new self('media_unavailable', 'The media server is not configured yet.', 503);
    }
}
