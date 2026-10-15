<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Domain;

use Modules\TeamCalls\Enums\CallStatus;
use Modules\TeamCalls\Enums\ParticipantState;
use Modules\TeamCalls\Exceptions\CallException;

/**
 * Transiciones permitidas, explícitas, de la llamada (global) y de cada participante (individual).
 * Repetir una transición al mismo estado no es un error: la operación es idempotente y quien llama
 * decide no registrar nada nuevo (canMove() devuelve false sin lanzar).
 */
final class CallStateMachine
{
    /** @var array<string, list<CallStatus>> */
    private const CALL = [
        'initiating' => [CallStatus::Ringing, CallStatus::Cancelled, CallStatus::Failed],
        'ringing' => [CallStatus::Connecting, CallStatus::Active, CallStatus::Declined, CallStatus::Cancelled, CallStatus::Missed, CallStatus::Failed],
        'connecting' => [CallStatus::Active, CallStatus::Ended, CallStatus::Failed],
        'active' => [CallStatus::Ended, CallStatus::Failed],
    ];

    /** @var array<string, list<ParticipantState>> */
    private const PARTICIPANT = [
        'ringing' => [ParticipantState::Accepted, ParticipantState::Joined, ParticipantState::Declined, ParticipantState::Missed, ParticipantState::Cancelled],
        'accepted' => [ParticipantState::Joined, ParticipantState::Left],
        'joined' => [ParticipantState::Left],
        'left' => [ParticipantState::Joined],
        'declined' => [ParticipantState::Joined],
        'missed' => [ParticipantState::Joined],
        'cancelled' => [],
    ];

    public static function canCall(CallStatus $from, CallStatus $to): bool
    {
        return in_array($to, self::CALL[$from->value] ?? [], true);
    }

    /** Lanza si la transición no está permitida; false si ya estaba en ese estado (idempotente). */
    public static function call(CallStatus $from, CallStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }
        if (! self::canCall($from, $to)) {
            throw CallException::invalidTransition("call {$from->value} → {$to->value}");
        }

        return true;
    }

    public static function canParticipant(ParticipantState $from, ParticipantState $to): bool
    {
        return in_array($to, self::PARTICIPANT[$from->value], true);
    }

    /** Lanza si la transición no está permitida; false si ya estaba en ese estado (idempotente). */
    public static function participant(ParticipantState $from, ParticipantState $to): bool
    {
        if ($from === $to) {
            return false;
        }
        if (! self::canParticipant($from, $to)) {
            throw CallException::invalidTransition("participant {$from->value} → {$to->value}");
        }

        return true;
    }
}
