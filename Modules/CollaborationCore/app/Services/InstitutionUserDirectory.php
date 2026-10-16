<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\UserDescriptor;
use Modules\CollaborationCore\Exceptions\ContractException;

final class InstitutionUserDirectory implements UserDirectory
{
    public function __construct(private CollaborationAccess $authorization, private ApplicationAccess $access) {}

    public function resolve(ActorContext $context, int $userId): UserDescriptor
    {
        $this->authorization->authorize($context, 'collaboration.use');
        $user = User::query()->where('institution_id', $context->institutionId)->find($userId);
        if (! $user || ! $this->access->allows($user, 'collaboration')) {
            throw new ContractException('not_found', 404);
        }

        return new UserDescriptor((int) $user->id, (int) $user->institution_id, $user->name, true);
    }

    public function canContact(ActorContext $context, int $targetUserId): bool
    {
        $this->authorization->authorize($context, 'collaboration.use');
        try {
            $this->resolve($context, $targetUserId);

            return true;
        } catch (ContractException $e) {
            if ($e->errorCode === 'not_found') {
                return false;
            } throw $e;
        }
    }
}
