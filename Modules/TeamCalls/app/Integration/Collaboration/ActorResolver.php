<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Collaboration;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Modules\CollaborationCore\Authorization\Capabilities;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Exceptions\ContractException;

/**
 * ActorContext (contrato común) → usuario de TeamCalls.
 *
 * El ActorContext lo construye CollaborationCore a partir de la sesión AUTENTICADA del servidor: aquí no
 * se acepta ningún identificador del cliente. Aun así se revalida todo, y se FALLA CERRADO mientras Core
 * no exista: sin DeviceRegistry no hay forma de comprobar que la sesión y el dispositivo siguen vigentes,
 * y sin CollaborationAccess no hay permiso definitivo (collaboration.use). Las reglas propias de TeamCalls
 * (UserDirectory, institución activa) se siguen aplicando dentro de CallService.
 */
final class ActorResolver
{
    public function __construct(private readonly Container $app) {}

    public function resolve(ActorContext $actor): User
    {
        if (! $this->app->bound(DeviceRegistry::class) || ! $this->app->bound(CollaborationAccess::class)) {
            throw new ContractException('unauthenticated', 401);
        }
        // Sesión y dispositivo vigentes (lanza session_revoked / device_revoked / unauthenticated).
        $this->app->make(DeviceRegistry::class)->assertSessionBinding($actor);

        $user = User::query()->whereKey($actor->userId)->first();
        if (! $user instanceof User) {
            throw new ContractException('unauthenticated', 401);
        }
        if ((int) $user->institution_id !== $actor->institutionId) {
            throw new ContractException('forbidden', 403);
        }
        // Quien tiene el segundo factor activo debe haberlo superado en ESTA sesión.
        if ($user->hasTwoFactorEnabled() && $actor->authenticationLevel !== AuthenticationLevel::Mfa) {
            throw new ContractException('two_factor_required', 403);
        }
        $this->app->make(CollaborationAccess::class)->authorize($actor, Capabilities::USE);

        return $user;
    }
}
