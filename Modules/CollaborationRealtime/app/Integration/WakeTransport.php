<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Integration;

/** Only a content-free hint. Return means broker accepted, never client received. */
interface WakeTransport
{
    public function send(string $privateChannel): void;
}
