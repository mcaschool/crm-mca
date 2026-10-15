<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Support\Guard;

final readonly class ActorContext
{
    public function __construct(
        public int $institutionId,
        public int $userId,
        public string $deviceId,
        public string $sessionId,
        public string $requestId,
        public AuthenticationLevel $authenticationLevel,
    ) {
        Guard::id($institutionId);
        Guard::id($userId);
        Guard::token($deviceId);
        Guard::token($sessionId);
        Guard::token($requestId);
    }

}
