<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Data\DeviceDescriptor;
use Modules\CollaborationCore\Data\DeviceRegistration;

/** Enrollment requires a validated server-side login session, not an already registered device. Return a server-assigned identifier bound to that session. Presence is not authentication. */
interface DeviceRegistry
{
    public function register(AuthenticatedSessionContext $context, DeviceRegistration $registration): DeviceDescriptor;

    public function resolveCurrent(ActorContext $context): DeviceDescriptor;

    public function assertSessionBinding(ActorContext $context): void;

    public function revoke(ActorContext $context, string $deviceId): void;

    /** @return list<DeviceDescriptor> */
    public function listForCurrentUser(ActorContext $context): array;
}
