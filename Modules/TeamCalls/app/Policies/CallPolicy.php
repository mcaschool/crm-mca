<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Policies;

use App\Models\User;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Enums\ParticipantState;
use Modules\TeamCalls\Models\Call;

/**
 * Autorización por llamada para la futura capa HTTP/API (registrada como Policy de Call). Las mismas
 * reglas las aplica CallService en cada operación; esta Policy no las sustituye, las anticipa
 * (p. ej. para decidir qué botones mostrar). Sin roles nuevos: usa los permisos existentes vía
 * UserDirectory y la pertenencia real a la llamada.
 */
class CallPolicy
{
    public function __construct(private readonly UserDirectory $users) {}

    public function view(User $user, Call $call): bool
    {
        return $this->participates($user, $call);
    }

    public function respond(User $user, Call $call): bool
    {
        return $this->participates($user, $call) && $call->status->isLive()
            && $call->participantFor($user->id)?->state === ParticipantState::Ringing;
    }

    public function invite(User $user, Call $call): bool
    {
        return $this->participates($user, $call) && $call->status->isLive()
            && ($call->participantFor($user->id)?->state->isIn() ?? false);
    }

    public function cancel(User $user, Call $call): bool
    {
        return $this->participates($user, $call) && (int) $call->initiator_id === (int) $user->id;
    }

    public function end(User $user, Call $call): bool
    {
        return $this->cancel($user, $call);
    }

    private function participates(User $user, Call $call): bool
    {
        return $this->users->canUseCalls($user)
            && (int) $user->institution_id === (int) $call->institution_id
            && $call->participantFor($user->id) !== null;
    }
}
