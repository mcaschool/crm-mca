<?php

declare(strict_types=1);

namespace Modules\FollowUp\Data;

use Carbon\CarbonImmutable;

final readonly class PolicyDecision
{
    /** @param array<string,bool|int|string|null> $facts */
    public function __construct(
        public string $status,
        public string $reasonCode,
        public string $explanation,
        public string $policy,
        public string $version,
        public CarbonImmutable $evaluatedAt,
        public ?CarbonImmutable $nextReevaluationAt,
        public string $fingerprint,
        public array $facts = [],
    ) {}

    public function allows(): bool
    {
        return $this->status === 'allowed';
    }
}
