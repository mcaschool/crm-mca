<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;

final class DeviceChannels
{
    public function __construct(private CollaborationAccess $access) {}

    public function name(ActorContext $actor): string
    {
        $this->access->authorize($actor, 'collaboration.use');

        return 'private-collaboration.'.hash_hmac('sha256', $actor->institutionId.':'.$actor->userId.':'.$actor->deviceId.':'.$actor->sessionId, (string) config('app.key'));
    }
}
