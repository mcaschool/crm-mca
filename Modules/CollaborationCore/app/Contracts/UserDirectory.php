<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\UserDescriptor;

interface UserDirectory
{
    public function resolve(ActorContext $context, int $userId): UserDescriptor;

    public function canContact(ActorContext $context, int $targetUserId): bool;
}
