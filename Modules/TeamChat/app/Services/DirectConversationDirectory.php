<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Modules\CollaborationCore\Contracts\ConversationDirectory;
use Modules\CollaborationCore\Contracts\UserDirectory;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\ConversationDescriptor;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\MembershipSnapshot;
use Modules\CollaborationCore\Enums\ConversationAction;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Exceptions\ContractException;

final class DirectConversationDirectory implements ConversationDirectory
{
    public function __construct(private ChatAccess $access, private UserDirectory $users) {}

    public function resolve(ActorContext $context, ConversationRef $ref): ConversationDescriptor
    {
        $this->authorize($context, $ref, ConversationAction::View);
        $row = $this->access->conversation($context, $ref->id);

        return new ConversationDescriptor($ref, $context->institutionId,
            $this->access->memberActive($context->institutionId, $ref->id, $this->access->peerId($row, $context->userId)), $this->version($row, $context));
    }

    public function authorize(ActorContext $context, ConversationRef $ref, ConversationAction $action): void
    {
        if ($ref->type !== ConversationType::Direct) {
            throw new ContractException('not_found', 404);
        }
        $this->access->conversation($context, $ref->id, $action !== ConversationAction::View);
    }

    public function eligibleInvitees(ActorContext $context, ConversationRef $ref, array $selection): array
    {
        $this->authorize($context, $ref, ConversationAction::InviteToCall);
        $row = $this->access->conversation($context, $ref->id);
        $peer = $this->access->peerId($row, $context->userId);
        if ($selection !== [$peer]) {
            throw new ContractException('invalid', 422);
        }

        return [$this->users->resolve($context, $peer)];
    }

    public function membership(ActorContext $context, ConversationRef $ref, int $userId): MembershipSnapshot
    {
        $this->authorize($context, $ref, ConversationAction::View);
        $row = $this->access->conversation($context, $ref->id);
        if (! in_array($userId, [(int) $row->first_user_id, (int) $row->second_user_id], true)) {
            throw new ContractException('not_found', 404);
        }

        return new MembershipSnapshot($context->institutionId, $ref, $userId,
            $this->access->memberActive($context->institutionId, $ref->id, $userId), $this->version($row, $context));
    }

    public function accessVersion(ActorContext $context, ConversationRef $ref): string
    {
        return $this->resolve($context, $ref)->accessVersion;
    }

    private function version(object $row, ActorContext $actor): string
    {
        // A snapshot identifier is never an authorization cache.
        return hash('sha256', $row->access_version.':'.(int) $this->access->memberActive($actor->institutionId, $row->id, (int) $row->first_user_id).':'.(int) $this->access->memberActive($actor->institutionId, $row->id, (int) $row->second_user_id));
    }
}
