<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Integration\Provisional;

use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Support\CallSummary;

/** PROVISIONAL hasta el PushNotifier de CollaborationCore (FCM, APNs, VoIP): no envía nada. */
final class NoopPushNotifier implements PushNotifier
{
    public function incomingCall(array $userIds, CallSummary $call): void {}

    public function cancelIncomingCall(array $userIds, string $callId, string $reason, ?string $exceptDeviceId = null): void {}
}
