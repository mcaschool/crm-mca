<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

final readonly class JoinCredentials
{
    public function __construct(
        public string $serverUrl,
        public string $token,
        public string $identity,
        public \DateTimeImmutable $expiresAt,
    ) {
        if (! in_array(parse_url($serverUrl, PHP_URL_SCHEME), ['https', 'wss'], true) || ! parse_url($serverUrl, PHP_URL_HOST) || $token === '' || $identity === '') {
            throw new \InvalidArgumentException('Invalid media credentials.');
        }
    }

}
