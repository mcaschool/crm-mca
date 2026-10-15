<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Services\DatabaseDeviceRegistry;
use Modules\CollaborationCore\Services\NotificationPreferences;

/** Reconciles Core intents against the single outbox transport path. Never sends directly. */
final class NotificationDelivery
{
    public function __construct(private DatabaseDeviceRegistry $devices, private NotificationPreferences $preferences, private EventAccess $access, private EventCodec $codec) {}

    public function reconcile(int $institutionId, int $limit = 100): int
    {
        $count = 0;
        $intents = DB::table('collab_notification_intents as i')->leftJoin('collab_notification_delivery as d', 'd.intent_id', '=', 'i.id')
            ->where('i.institution_id', $institutionId)->whereIn('i.status', ['pending', 'expired', 'suppressed'])
            ->select('i.*')->orderByRaw('d.updated_at IS NOT NULL')->orderBy('d.updated_at')->orderBy('i.id')->limit(max(1, min(1000, $limit)))->get();
        foreach ($intents as $intent) {
            $count++;
            $status = $intent->status;
            $accepted = 0;
            if (\Illuminate\Support\Carbon::parse($intent->expires_at)->isPast()) {
                $status = 'expired';
            } elseif ($status === 'pending') {
                $event = DB::table('collab_events')->where('institution_id', $institutionId)->where('id', $intent->source_event_id)->first();
                $outbox = DB::table('collab_outbox')->where('institution_id', $institutionId)->where('event_id', $intent->source_event_id)->first();
                $eligible = [];
                $delivery = $event ? $this->codec->decode($event) : null;
                foreach (DB::table('collab_devices')->where('institution_id', $institutionId)->whereIn('id', json_decode($intent->device_ids, true, 8, JSON_THROW_ON_ERROR))->get() as $device) {
                    if (! in_array((int) $device->user_id, json_decode($intent->recipients, true, 8, JSON_THROW_ON_ERROR), true)
                        || ! $this->devices->usable($device) || ! $this->preferences->forUser($institutionId, (int) $device->user_id)) {
                        continue;
                    }
                    $actor = new ActorContext($institutionId, (int) $device->user_id, $device->id, $device->session_hash, $intent->id, AuthenticationLevel::Mfa);
                    if ($delivery && $this->access->allows($actor, $delivery)) {
                        $eligible[] = $device->id;
                    }
                }
                $accepted = DB::table('collab_transport_receipts')->where('institution_id', $institutionId)->where('event_id', $intent->source_event_id)->whereIn('device_id', $eligible)->count();
                $status = match (true) {
                    ! $event => 'awaiting_source',
                    ! $eligible => 'suppressed',
                    $accepted > 0 => 'transport_accepted',
                    $outbox?->status === 'failed' => 'failed',
                    (int) ($outbox->attempts ?? 0) > 0 => 'attempted',
                    default => 'pending',
                };
            }
            DB::table('collab_notification_delivery')->updateOrInsert(['intent_id' => $intent->id],
                ['institution_id' => $institutionId, 'status' => $status, 'accepted_devices' => $accepted, 'updated_at' => now()]);
            // Core's pending status remains: broker acceptance is not application receipt.
        }

        return $count;
    }
}
