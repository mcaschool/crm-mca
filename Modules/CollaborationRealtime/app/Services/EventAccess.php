<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationRealtime\Data\DeliveryEnvelope;

final class EventAccess
{
    public function __construct(private CollaborationAccess $access, private EventPolicies $policies) {}

    public function allows(ActorContext $actor, DeliveryEnvelope $delivery): bool
    {
        $this->access->authorize($actor, 'collaboration.use');

        return $actor->institutionId === $delivery->event->institutionId
            && in_array($actor->userId, $delivery->audience->userIds, true)
            && $actor->deviceId !== $delivery->audience->exceptDeviceId
            && ($delivery->audience->expiresAt === null || $delivery->audience->expiresAt > now())
            && $this->policies->get($delivery->event->type)->allows($actor, $delivery->event);
    }
}
