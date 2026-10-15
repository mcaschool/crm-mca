<?php

declare(strict_types=1);

namespace Modules\TeamCalls\Contracts;

use Modules\TeamCalls\Support\CallSummary;

/**
 * PUERTO hacia las notificaciones push de CollaborationCore (FCM, APNs y APNs VoIP con CallKit).
 * TeamCalls solo decide QUÉ avisar y a QUIÉN; el envío real es de CollaborationCore.
 */
interface PushNotifier
{
    /** @param list<int> $userIds */
    public function incomingCall(array $userIds, CallSummary $call): void;

    /**
     * Retira el aviso de llamada entrante (contestada en otro dispositivo, cancelada o perdida).
     *
     * @param  list<int>  $userIds
     */
    public function cancelIncomingCall(array $userIds, string $callId, string $reason, ?string $exceptDeviceId = null): void;
}
