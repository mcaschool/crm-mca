<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Contracts;

use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\ConversationDescriptor;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\MembershipSnapshot;
use Modules\CollaborationCore\Data\UserDescriptor;
use Modules\CollaborationCore\Enums\ConversationAction;

interface ConversationDirectory
{
    public function resolve(ActorContext $context, ConversationRef $ref): ConversationDescriptor;

    public function authorize(ActorContext $context, ConversationRef $ref, ConversationAction $action): void;

    /**
     * Server validates every selected recipient, all or nothing.
     *
     * @param  list<int>  $selection
     * @return list<UserDescriptor>
     */
    public function eligibleInvitees(ActorContext $context, ConversationRef $ref, array $selection): array;

    public function membership(ActorContext $context, ConversationRef $ref, int $userId): MembershipSnapshot;

    public function accessVersion(ActorContext $context, ConversationRef $ref): string;
}
