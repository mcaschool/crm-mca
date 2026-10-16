<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Http;

use Illuminate\Http\Request;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\TeamCalls\Exceptions\CallException;

/**
 * Dispositivo de la petición: el que CollaborationCore registró y validó para ESTA sesión
 * (DeviceRegistry, vía RequireCollaborationActor). Sustituye al identificador provisional por sesión
 * (WebDevice). El cliente nunca aporta el identificador.
 */
final class ActorDevice
{
    public function actor(Request $request): ActorContext
    {
        $actor = $request->attributes->get('collaboration.actor');
        if (! $actor instanceof ActorContext) {
            throw new CallException('unauthenticated', 'Unauthenticated.', 401);
        }

        return $actor;
    }

    public function id(Request $request): string
    {
        return $this->actor($request)->deviceId;
    }
}
