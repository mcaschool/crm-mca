<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\DevicePlatform;

final readonly class DeviceRegistration
{
    public function __construct(
        public DevicePlatform $platform,
        public string $label,
    ) {
        if (trim($label) === '' || strlen($label) > 200) {
            throw new \InvalidArgumentException('Invalid device label.');
        }
    }

}
