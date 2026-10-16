<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;

final class AccessService implements CollaborationAccess
{
    public function __construct(private DeviceRegistry $devices) {}

    public function authorize(ActorContext $context, string $capability): void
    {
        $this->devices->assertSessionBinding($context);
        if ($capability !== 'collaboration.use') {
            throw new ContractException('forbidden', 403);
        }
    }
}
