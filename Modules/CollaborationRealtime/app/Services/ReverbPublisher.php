<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Services;

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Services\DatabaseDeviceRegistry;
use Modules\CollaborationRealtime\Contracts\RealtimePublisher;
use Modules\CollaborationRealtime\Data\DeliveryEnvelope;
use Modules\CollaborationRealtime\Integration\WakeTransport;

final class ReverbPublisher implements RealtimePublisher
{
    public function __construct(private DatabaseDeviceRegistry $devices, private EventAccess $access, private DeviceChannels $channels, private WakeTransport $transport) {}

    public function publish(DeliveryEnvelope $delivery): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Network publication must occur outside a database transaction.');
        }
        $rows = DB::table('collab_devices')->where('institution_id', $delivery->event->institutionId)->whereIn('user_id', $delivery->audience->userIds)->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('id')->get();
        foreach ($rows as $row) {
            if (! $this->devices->usable($row)) {
                continue;
            }
            $actor = new ActorContext((int) $row->institution_id, (int) $row->user_id, $row->id, $row->session_hash, $delivery->event->id, AuthenticationLevel::Mfa);
            if (! $this->access->allows($actor, $delivery) || DB::table('collab_transport_receipts')->where('event_id', $delivery->event->id)->where('device_id', $row->id)->exists()) {
                continue;
            }
            $this->transport->send($this->channels->name($actor));
            // A crash between broker acceptance and insert can duplicate the harmless hint.
            DB::table('collab_transport_receipts')->insertOrIgnore(['institution_id' => $actor->institutionId, 'event_id' => $delivery->event->id, 'device_id' => $row->id, 'accepted_at' => now()]);
        }
    }
}
