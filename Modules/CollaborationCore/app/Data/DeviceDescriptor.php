<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Data;

use Modules\CollaborationCore\Enums\DevicePlatform;
use Modules\CollaborationCore\Enums\DeviceStatus;
use Modules\CollaborationCore\Support\Guard;

final readonly class DeviceDescriptor
{
    public function __construct(
        public string $id,
        public int $userId,
        public DevicePlatform $platform,
        public DeviceStatus $status,
    ) {
        Guard::token($id);
        Guard::id($userId);
    }

}
