<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Transport;

use Illuminate\Broadcasting\BroadcastManager;
use Modules\CollaborationRealtime\Integration\WakeTransport;

final class ReverbWakeTransport implements WakeTransport
{
    public function __construct(private BroadcastManager $broadcast) {}

    public function send(string $privateChannel): void
    {
        $this->broadcast->connection('reverb')->broadcast([$privateChannel], 'collaboration.sync.required', ['v' => 1]);
    }
}
