<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationRealtime\Data\SyncPage;

/** Current, short-lived signals; separate from durable history. Expiry is checked on every request. */
final class CurrentSignals
{
    public function __construct(private CollaborationAccess $access, private EventAccess $events, private EventCodec $codec, private Cursors $cursors) {}

    /** @return array{events:list<\Modules\CollaborationRealtime\Data\EventEnvelope>,cursor:string,hasMore:bool,expires_at:array<string,string>} */
    public function response(ActorContext $actor, PageRequest $request): array
    {
        $page = $this->read($actor, $request);
        $expirations = [];
        foreach (DB::table('collab_events')->where('institution_id', $actor->institutionId)->whereIn('id', array_map(fn ($event) => $event->id, $page->events))->get(['id', 'expires_at']) as $row) {
            $expirations[$row->id] = \Illuminate\Support\Carbon::parse($row->expires_at, 'UTC')->toISOString();
        }

        return ['events' => $page->events, 'cursor' => $page->cursor, 'hasMore' => $page->hasMore, 'expires_at' => $expirations];
    }

    public function read(ActorContext $actor, PageRequest $page): SyncPage
    {
        $this->access->authorize($actor, 'collaboration.use');
        $state = $page->cursor === null ? ['mode' => 'signals', 'position' => 0, 'cut' => (int) DB::table('collab_streams')->where('institution_id', $actor->institutionId)->value('position'), 'floor' => 0, 'expires' => now()->addMinutes(5)->timestamp]
            : $this->cursors->decode($actor, $page->cursor, 'signals');
        $latest = DB::table('collab_events')->selectRaw('MAX(position)')->where('institution_id', $actor->institutionId)
            ->where('ephemeral', true)->where('position', '<=', $state['cut'])->groupBy('aggregate_type', 'aggregate_id');
        $rows = DB::table('collab_events')->where('institution_id', $actor->institutionId)->whereIn('position', $latest)
            ->where('position', '>', $state['position'])->where('expires_at', '>', now())->orderBy('position')->limit($page->limit + 1)->get();
        $result = [];
        foreach ($rows->take($page->limit) as $row) {
            $delivery = $this->codec->decode($row);
            if ($this->events->allows($actor, $delivery)) {
                $result[] = $delivery->event;
            }
            $state['position'] = (int) $row->position;
        }

        return new SyncPage($result, $this->cursors->encode($actor, $state), $rows->count() > $page->limit);
    }
}
