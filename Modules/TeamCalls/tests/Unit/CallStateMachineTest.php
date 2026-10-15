<?php

declare(strict_types=1);

use Modules\TeamCalls\Domain\CallStateMachine;
use Modules\TeamCalls\Enums\CallStatus;
use Modules\TeamCalls\Enums\ParticipantState;
use Modules\TeamCalls\Exceptions\CallException;

it('permite el ciclo normal de la llamada y de cada participante', function () {
    expect(CallStateMachine::call(CallStatus::Initiating, CallStatus::Ringing))->toBeTrue()
        ->and(CallStateMachine::call(CallStatus::Ringing, CallStatus::Connecting))->toBeTrue()
        ->and(CallStateMachine::call(CallStatus::Connecting, CallStatus::Active))->toBeTrue()
        ->and(CallStateMachine::call(CallStatus::Active, CallStatus::Ended))->toBeTrue()
        ->and(CallStateMachine::participant(ParticipantState::Ringing, ParticipantState::Accepted))->toBeTrue()
        ->and(CallStateMachine::participant(ParticipantState::Accepted, ParticipantState::Joined))->toBeTrue()
        ->and(CallStateMachine::participant(ParticipantState::Joined, ParticipantState::Left))->toBeTrue()
        ->and(CallStateMachine::participant(ParticipantState::Left, ParticipantState::Joined))->toBeTrue()
        ->and(CallStateMachine::participant(ParticipantState::Missed, ParticipantState::Joined))->toBeTrue();
});

it('repetir el mismo estado es idempotente (no es un error ni un cambio)', function () {
    expect(CallStateMachine::call(CallStatus::Active, CallStatus::Active))->toBeFalse()
        ->and(CallStateMachine::participant(ParticipantState::Accepted, ParticipantState::Accepted))->toBeFalse();
});

it('rechaza las transiciones inválidas', function (string $kind, string $from, string $to) {
    expect(fn () => $kind === 'call'
        ? CallStateMachine::call(CallStatus::from($from), CallStatus::from($to))
        : CallStateMachine::participant(ParticipantState::from($from), ParticipantState::from($to)))
        ->toThrow(CallException::class);
})->with([
    'terminada no revive' => ['call', 'ended', 'active'],
    'cancelada no suena' => ['call', 'cancelled', 'ringing'],
    'activa no se rechaza' => ['call', 'active', 'declined'],
    'activa no se pierde' => ['call', 'active', 'missed'],
    'sonando no termina sin conectar' => ['call', 'ringing', 'ended'],
    'conectando no se cancela' => ['call', 'connecting', 'cancelled'],
    'invitación retirada no se acepta' => ['participant', 'cancelled', 'accepted'],
    'conectado no rechaza' => ['participant', 'joined', 'declined'],
    'rechazada no se acepta' => ['participant', 'declined', 'accepted'],
]);

it('distingue estados vivos y terminales', function () {
    expect(collect(CallStatus::cases())->filter->isLive()->map->value->values()->all())
        ->toBe(['initiating', 'ringing', 'connecting', 'active'])
        ->and(ParticipantState::Accepted->isIn())->toBeTrue()
        ->and(ParticipantState::Ringing->isIn())->toBeFalse();
});
