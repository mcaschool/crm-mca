<?php

declare(strict_types=1);

namespace Modules\TeamChat\Console;

use Modules\Core\Console\TenantAwareCommand;
use Modules\TeamChat\Services\ChatAttachments;

final class PruneUploads extends TenantAwareCommand
{
    protected $signature = 'teamchat:prune-uploads {--institution=} {--all-institutions}';

    protected $description = 'Remove expired unattached private uploads; retain attached message history.';

    protected function handleForInstitution(int $institutionId): int
    {
        $this->info((string) app(ChatAttachments::class)->prune($institutionId));

        return self::SUCCESS;
    }
}
