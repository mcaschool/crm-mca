<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;

final class ContextLifecycleJob extends TenantAwareJob
{
    /** @var list<array{int|null, int, int}> */
    public static array $observations = [];

    public function __construct(int $institutionId, public bool $retry = false, public bool $fail = false)
    {
        parent::__construct($institutionId);
    }

    protected function handleForInstitution(): void
    {
        self::$observations[] = [app(CurrentInstitution::class)->id(), Contact::query()->count(), $this->attempts()];
        if ($this->fail) {
            throw new \RuntimeException('job failure');
        }
        if ($this->retry && $this->attempts() === 1) {
            $this->release(0);
        }
    }
}
