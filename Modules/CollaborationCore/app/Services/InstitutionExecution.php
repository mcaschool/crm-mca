<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

final class InstitutionExecution
{
    /**
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    public function run(InstitutionContext $context, callable $operation): mixed
    {
        if (! Institution::query()->whereKey($context->institutionId)->where('status', 'active')->where('collaboration_enabled', true)->exists()) {
            throw new ContractException('forbidden', 403);
        }

        return app(CurrentInstitution::class)->runFor($context->institutionId, $operation);
    }
}
