<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Storage;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\PresenceSnapshot;
use Modules\CollaborationCore\Enums\ManualPresence;

final class DatabasePresenceStore implements PresenceStore
{
    public function __construct(private \Modules\CollaborationCore\Services\DatabaseDeviceRegistry $devices) {}

    public function heartbeat(ActorContext $actor): void
    {
        $key = ['institution_id' => $actor->institutionId, 'user_id' => $actor->userId];
        DB::table('collab_presence')->updateOrInsert($key, ['last_seen_at' => now(), 'last_activity_at' => now(), 'last_online_at' => now(), 'updated_at' => now()]);
        DB::table('collab_presence_devices')->updateOrInsert(['device_id' => $actor->deviceId], $key + ['expires_at' => now()->addSeconds(90), 'last_activity_at' => now()]);
    }

    public function manual(ActorContext $actor, ManualPresence $status): void
    {
        DB::table('collab_presence')->updateOrInsert(['institution_id' => $actor->institutionId, 'user_id' => $actor->userId], ['manual_status' => $status->value, 'updated_at' => now()]);
    }

    public function snapshot(int $institutionId, int $userId): PresenceSnapshot
    {
        $key = ['institution_id' => $institutionId, 'user_id' => $userId];
        $row = DB::table('collab_presence')->where($key)->first();
        $manual = ManualPresence::from($row->manual_status ?? 'available');
        $online = DB::table('collab_presence_devices as p')->join('collab_devices as d', 'd.id', '=', 'p.device_id')
            ->where('p.institution_id', $institutionId)->where('p.user_id', $userId)
            ->where('d.institution_id', $institutionId)->where('d.user_id', $userId)
            ->whereNull('d.revoked_at')->where('d.expires_at', '>', now())->where('p.expires_at', '>', now())
            ->select('d.*')->get()->contains(fn ($device) => $this->devices->usable($device));
        $call = DB::table('collab_presence_calls')->where($key)->where('expires_at', '>', now())->orderByDesc('expires_at')->orderBy('call_id')->value('call_id');

        return new PresenceSnapshot($institutionId, $userId, $manual, $online && $manual !== ManualPresence::Offline,
            $row?->last_seen_at ? new \DateTimeImmutable($row->last_seen_at, new \DateTimeZone('UTC')) : null, $call);
    }

    public function call(int $institutionId, int $userId, string $callId, ?int $ttlSeconds): void
    {
        $key = ['institution_id' => $institutionId, 'user_id' => $userId, 'call_id' => $callId];
        if ($ttlSeconds === null) {
            DB::table('collab_presence_calls')->where($key)->delete();

            return;
        }
        DB::table('collab_presence_calls')->updateOrInsert($key, ['expires_at' => now()->addSeconds($ttlSeconds)]);
    }
}
