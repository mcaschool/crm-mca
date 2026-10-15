<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Tests\Support;

use App\Models\User;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Data\DeviceDescriptor;
use Modules\CollaborationCore\Data\DeviceRegistration;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Enums\DevicePlatform;
use Modules\CollaborationCore\Enums\DeviceStatus;
use Modules\CollaborationCore\Exceptions\ContractException;

/**
 * Doble de prueba de CollaborationCore sobre sus interfaces OFICIALES (DeviceRegistry y
 * CollaborationAccess). No es una implementación de Core: solo registra dispositivos revocados,
 * personas sin collaboration.use y lo que se le consultó. Nunca se enlaza fuera de las pruebas.
 */
final class FakeCollaborationCore implements CollaborationAccess, DeviceRegistry
{
    /** @var array<string, true> dispositivos revocados */
    public array $revokedDevices = [];

    /** @var array<int, true> personas sin collaboration.use */
    public array $denied = [];

    /** @var list<array{user:int, capability:string}> */
    public array $authorized = [];

    public static function bind(): self
    {
        $core = new self;
        app()->instance(DeviceRegistry::class, $core);
        app()->instance(CollaborationAccess::class, $core);

        return $core;
    }

    public static function actor(User $user, string $device, AuthenticationLevel $level = AuthenticationLevel::Mfa, ?int $institutionId = null): ActorContext
    {
        return new ActorContext($institutionId ?? (int) $user->institution_id, (int) $user->id, $device, 'sess-'.$device, 'req-'.$device, $level);
    }

    public function authorize(ActorContext $context, string $capability): void
    {
        $this->authorized[] = ['user' => $context->userId, 'capability' => $capability];
        if (isset($this->denied[$context->userId])) {
            throw new ContractException('forbidden', 403);
        }
    }

    public function register(AuthenticatedSessionContext $context, DeviceRegistration $registration): DeviceDescriptor
    {
        throw new \LogicException('Not used by TeamCalls.');
    }

    public function resolveCurrent(ActorContext $context): DeviceDescriptor
    {
        return new DeviceDescriptor($context->deviceId, $context->userId, DevicePlatform::Web,
            isset($this->revokedDevices[$context->deviceId]) ? DeviceStatus::Revoked : DeviceStatus::Active);
    }

    public function assertSessionBinding(ActorContext $context): void
    {
        if (isset($this->revokedDevices[$context->deviceId])) {
            throw new ContractException('device_revoked', 403);
        }
    }

    public function revoke(ActorContext $context, string $deviceId): void
    {
        $this->revokedDevices[$deviceId] = true;
    }

    public function listForCurrentUser(ActorContext $context): array
    {
        return [$this->resolveCurrent($context)];
    }
}
