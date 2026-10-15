<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;

/** Revalidate active account/membership. collaboration.use never requires or grants CRM access. */
interface CollaborationAccess
{
    public function authorize(ActorContext $context, string $capability): void;
}
