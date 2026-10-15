<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Illuminate\Http\Request;
use Modules\CollaborationCore\Contracts\ActorContextFactory;
use Modules\CollaborationCore\Contracts\AuthenticatedSessionContextFactory;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\DeviceRegistration;
use Modules\CollaborationCore\Enums\DevicePlatform;

final class ActorFactory implements ActorContextFactory
{
    public function __construct(private AuthenticatedSessionContextFactory $sessions, private DeviceRegistry $devices) {}

    public function fromAuthenticatedRequest(Request $request): ActorContext
    {
        $session = $this->sessions->fromAuthenticatedRequest($request);
        $device = $this->devices->register($session, new DeviceRegistration(DevicePlatform::Web, 'Web'));
        $actor = new ActorContext($session->institutionId, $session->userId, $device->id, $session->sessionId, $session->requestId, $session->authenticationLevel);
        $this->devices->assertSessionBinding($actor);

        return $actor;
    }
}
