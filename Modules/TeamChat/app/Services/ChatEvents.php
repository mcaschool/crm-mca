<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Data\EventEnvelope;

final class ChatEvents
{
    public function __construct(private TransactionalEventRecorder $recorder) {}

    /** @param array<string,mixed> $data */
    public function record(ActorContext $actor, object $conversation, string $type, array $data): string
    {
        $version = (int) $conversation->event_version + 1;
        $id = (string) Str::ulid();
        DB::table('collab_chat_event_access')->insert(['event_id' => $id, 'institution_id' => $actor->institutionId, 'conversation_id' => $conversation->id, 'access_tick' => app(ChatClock::class)->next($actor->institutionId)]);
        $this->recorder->append(new InstitutionContext($actor->institutionId), new EventEnvelope(
            $id, $type, 1, $actor->institutionId, 'teamchat_conversation', $conversation->id, $version, $actor->userId,
            now()->toDateTimeImmutable(), $actor->requestId, null, ['conversation_id' => $conversation->id] + $data),
            new DeliveryAudience($actor->institutionId, app(ChatAccess::class)->members($conversation)));
        DB::table('collab_chat_conversations')->where('institution_id', $actor->institutionId)->where('id', $conversation->id)->update(['event_version' => $version]);
        $conversation->event_version = $version;

        return $id;
    }
}
