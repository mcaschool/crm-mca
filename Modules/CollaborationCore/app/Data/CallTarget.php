<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Support\Guard;

final readonly class CallTarget
{
    /** @param list<int> $userIds */
    private function __construct(public ?ConversationRef $conversation, public array $userIds) {}

    /** @param list<int> $userIds */
    public static function users(array $userIds): self
    {
        Guard::users($userIds);
        sort($userIds, SORT_NUMERIC);

        return new self(null, $userIds);
    }

    /** Explicit invitees required for groups/channels; direct calls resolve the other member on the server.
     * @param  list<int>  $selection
     */
    public static function conversation(ConversationRef $ref, array $selection = []): self
    {
        Guard::users($selection, allowEmpty: $ref->type === ConversationType::Direct);
        if ($ref->type === ConversationType::Direct && $selection !== []) {
            throw new \InvalidArgumentException('Direct recipients are resolved by the server.');
        }
        sort($selection, SORT_NUMERIC);

        return new self($ref, $selection);
    }
}
