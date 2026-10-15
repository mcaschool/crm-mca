<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\ApplicationAccess;

final class ChatAccess
{
    public function __construct(private CollaborationAccess $access, private ApplicationAccess $applications) {}

    public function conversation(ActorContext $actor, string $id, bool $sending = false): object
    {
        $this->access->authorize($actor, 'collaboration.use');
        $conversation = DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $id)->first();
        if (! $conversation || ! DB::table('collab_chat_participants')->where('institution_id', $actor->institutionId)
            ->where('conversation_id', $id)->where('user_id', $actor->userId)->where('active', true)->exists()) {
            throw new ContractException('not_found', 404);
        }
        if ($sending && ! $this->memberActive($actor->institutionId, $id, $this->peerId($conversation, $actor->userId))) {
            throw new ContractException('forbidden', 403);
        }

        return $conversation;
    }

    public function memberActive(int $institutionId, string $conversationId, int $userId): bool
    {
        $user = User::query()->where('institution_id', $institutionId)->find($userId);

        return $user !== null && $this->applications->allows($user, 'collaboration')
            && DB::table('collab_chat_participants')->where('institution_id', $institutionId)->where('conversation_id', $conversationId)
                ->where('user_id', $userId)->where('active', true)->exists();
    }

    public function peerId(object $conversation, int $userId): int
    {
        return (int) $conversation->first_user_id === $userId ? (int) $conversation->second_user_id : (int) $conversation->first_user_id;
    }
}
