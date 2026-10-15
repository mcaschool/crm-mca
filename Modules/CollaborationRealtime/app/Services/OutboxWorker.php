<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CollaborationRealtime\Contracts\RealtimePublisher;

final class OutboxWorker
{
    public function __construct(private RealtimePublisher $publisher, private EventCodec $codec) {}

    public function runOne(int $institutionId): bool
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Worker requires a committed connection.');
        }
        $claim = (string) Str::ulid();
        $row = DB::transaction(function () use ($institutionId, $claim): ?object {
            $row = DB::table('collab_outbox')->where('institution_id', $institutionId)
                ->where(function ($q): void {
                    $q->where(function ($q): void {
                        $q->where('status', 'pending')->where('available_at', '<=', now());
                    })
                        ->orWhere(function ($q): void {
                            $q->where('status', 'claimed')->where('lease_until', '<=', now());
                        });
                })->orderBy('available_at')->orderBy('event_id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (! $row) {
                return null;
            }
            DB::table('collab_outbox')->where('event_id', $row->event_id)->update(['status' => 'claimed', 'claim' => $claim,
                'lease_until' => now()->addMinutes(2), 'attempts' => min(8, (int) $row->attempts + 1)]);

            return $row;
        });
        if (! $row) {
            return false;
        }
        $query = DB::table('collab_outbox')->where('event_id', $row->event_id)->where('claim', $claim)->where('status', 'claimed');
        try {
            if ((int) $row->attempts >= 8) {
                $query->update(['status' => 'failed', 'failure_code' => 'retry_exhausted', 'claim' => null, 'lease_until' => null]);

                return true;
            }
            $event = DB::table('collab_events')->where('id', $row->event_id)->where('institution_id', $institutionId)->firstOrFail();
            if ($event->expires_at !== null && \Illuminate\Support\Carbon::parse($event->expires_at)->isPast()) {
                $query->update(['status' => 'expired', 'claim' => null, 'lease_until' => null]);

                return true;
            }
            $this->publisher->publish($this->codec->decode($event));
            $accepted = DB::table('collab_transport_receipts')->where('event_id', $event->id)->exists();
            $query->update(['status' => $accepted ? 'published' : 'suppressed', 'accepted_at' => $accepted ? now() : null, 'claim' => null, 'lease_until' => null, 'failure_code' => null]);
        } catch (\Throwable $error) {
            $permanent = $error instanceof \LogicException;
            $attempt = (int) $row->attempts + 1;
            $query->update(['status' => $permanent || $attempt >= 8 ? 'failed' : 'pending', 'failure_code' => $permanent ? 'invalid_producer' : 'transport_unavailable',
                'available_at' => now()->addSeconds(min(300, 2 ** $attempt) + random_int(0, 3)), 'claim' => null, 'lease_until' => null]);
        }

        return true;
    }
}
