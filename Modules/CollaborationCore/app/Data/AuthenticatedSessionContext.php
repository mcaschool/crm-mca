<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Support\Guard;

/** Server-created login context for initial device enrollment, before an ActorContext exists. */
final readonly class AuthenticatedSessionContext
{
    public function __construct(
        public int $institutionId,
        public int $userId,
        public string $sessionId,
        public string $requestId,
        public AuthenticationLevel $authenticationLevel,
    ) {
        Guard::id($institutionId);
        Guard::id($userId);
        Guard::token($sessionId);
        Guard::token($requestId);
    }
}
