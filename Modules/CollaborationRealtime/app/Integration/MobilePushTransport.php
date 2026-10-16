<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Integration;

/** Future FCM/APNs boundary. No production binding or registration credentials in this phase. */
interface MobilePushTransport
{
    /** Return provider receipt, not a claim of device receipt. */
    public function wake(string $registeredDeviceId, string $deduplicationId): string;
}
