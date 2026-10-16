<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Integration;

use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Exceptions\ContractException;

final class UnavailableCallPresenceAuthorizer implements CallPresenceAuthorizer
{
    public function authorize(InstitutionContext $context, int $userId, string $callId): void
    {
        throw new ContractException('forbidden', 403);
    }
}
