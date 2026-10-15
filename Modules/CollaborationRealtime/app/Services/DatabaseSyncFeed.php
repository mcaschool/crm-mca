<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CollaborationAccess;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Data\SyncPage;
use Modules\CollaborationRealtime\Data\SyncSnapshot;

final class DatabaseSyncFeed implements SyncFeed
{
    public function __construct(private CollaborationAccess $access, private EventAccess $events, private EventCodec $codec, private Cursors $cursors) {}

    public function read(ActorContext $context, PageRequest $page): SyncPage
    {
        $this->access->authorize($context, 'collaboration.use');
        $stream = DB::table('collab_streams')->where('institution_id', $context->institutionId)->first();
        $floor = (int) ($stream->floor ?? 0);
        $cut = (int) ($stream->position ?? 0);
        $state = $page->cursor === null ? ['mode' => 'feed', 'position' => $floor, 'cut' => $cut, 'floor' => $floor] : $this->cursors->decode($context, $page->cursor, 'feed');
        if ($state['position'] < $floor || $state['position'] > $cut) {
            throw new ContractException('sync_cursor_expired', 410);
        }
        $rows = DB::table('collab_events')->where('institution_id', $context->institutionId)->where('position', '>', $state['position'])->where('position', '<=', $cut)->orderBy('position')->limit($page->limit)->get();
        $result = [];
        foreach ($rows as $row) {
            // Do not decode expired transient tombstones after technical cleanup.
            if (! $row->ephemeral && ($row->expires_at === null || \Illuminate\Support\Carbon::parse($row->expires_at)->isFuture())) {
                $delivery = $this->codec->decode($row);
                if ($this->events->allows($context, $delivery)) {
                    $result[] = $delivery->event;
                }
            }
            $state['position'] = (int) $row->position;
        }
        if ($rows->isEmpty()) {
            $state['position'] = $cut;
        }
        $more = $state['position'] < $cut;
        $state['cut'] = $cut;
        // New feed pages renew the lease; bootstrap pages keep their fixed original expiry.
        unset($state['expires']);

        return new SyncPage($result, $this->cursors->encode($context, $state), $more);
    }

    public function bootstrap(ActorContext $context, PageRequest $page): SyncSnapshot
    {
        $this->access->authorize($context, 'collaboration.use');
        $stream = DB::table('collab_streams')->where('institution_id', $context->institutionId)->first();
        $floor = (int) ($stream->floor ?? 0);
        $state = $page->cursor === null
            ? ['mode' => 'snapshot', 'position' => 0, 'cut' => (int) ($stream->position ?? 0), 'floor' => $floor, 'expires' => now()->addDay()->timestamp]
            : $this->cursors->decode($context, $page->cursor, 'snapshot');
        if ($state['floor'] < $floor) {
            throw new ContractException('sync_cursor_expired', 410);
        }
        $latest = DB::table('collab_events')->selectRaw('MAX(position)')->where('institution_id', $context->institutionId)
            ->where('position', '<=', $state['cut'])->where('ephemeral', false)->groupBy('aggregate_type', 'aggregate_id');
        $rows = DB::table('collab_events')->where('institution_id', $context->institutionId)->whereIn('position', $latest)
            ->where('position', '>', $state['position'])->orderBy('position')->limit($page->limit + 1)->get();
        $hasMore = $rows->count() > $page->limit;
        $resources = [];
        foreach ($rows->take($page->limit) as $row) {
            $delivery = $this->codec->decode($row);
            if ($this->events->allows($context, $delivery)) {
                $resources[] = ['type' => $delivery->event->aggregateType, 'id' => $delivery->event->aggregateId, 'version' => (string) $delivery->event->aggregateVersion];
            }
            $state['position'] = (int) $row->position;
        }
        $feed = ['mode' => 'feed', 'position' => $state['cut'], 'cut' => $state['cut'], 'floor' => $floor, 'expires' => $state['expires']];

        return new SyncSnapshot($resources, $this->cursors->encode($context, $feed), $hasMore ? $this->cursors->encode($context, $state) : null);
    }
}
