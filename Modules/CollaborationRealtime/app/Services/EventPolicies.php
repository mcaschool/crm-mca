<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Modules\CollaborationRealtime\Integration\EventPolicy;

final class EventPolicies
{
    /** @var array<string, EventPolicy> */
    private array $policies = [];

    public function register(string $type, EventPolicy $policy): void
    {
        if (isset($this->policies[$type])) {
            throw new \LogicException('Event policy already registered.');
        }
        $this->policies[$type] = $policy;
    }

    public function get(string $type): EventPolicy
    {
        return $this->policies[$type] ?? throw new \LogicException('Unregistered event policy.');
    }
}
