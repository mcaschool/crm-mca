<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Services\NotificationPreferences;

final class NotificationInbox
{
    public function __construct(private CollaborationAccess $access, private NotificationPreferences $preferences, private EventAccess $events, private EventCodec $codec) {}

    /** Current notification intents, not delivery acknowledgements.
     * @return list<array{id:string,type:string,resource_id:string,source_event_id:string,expires_at:string}>
     */
    public function current(ActorContext $actor): array
    {
        $this->access->authorize($actor, 'collaboration.use');
        if (! $this->preferences->enabled($actor)) {
            return [];
        }
        $result = [];
        $rows = DB::table('collab_notification_intents')->where('institution_id', $actor->institutionId)->where('status', 'pending')
            ->where('expires_at', '>', now())->whereJsonContains('recipients', $actor->userId)->whereJsonContains('device_ids', $actor->deviceId)
            ->orderByDesc('created_at')->orderBy('id')->limit(100)->get();
        foreach ($rows as $row) {
            $event = DB::table('collab_events')->where('institution_id', $actor->institutionId)->where('id', $row->source_event_id)->first();
            if ($event && $this->events->allows($actor, $this->codec->decode($event))) {
                $result[] = ['id' => $row->id, 'type' => $row->type, 'resource_id' => $row->resource_id, 'source_event_id' => $row->source_event_id, 'expires_at' => \Illuminate\Support\Carbon::parse($row->expires_at, 'UTC')->toISOString()];
            }
        }

        return $result;
    }
}
