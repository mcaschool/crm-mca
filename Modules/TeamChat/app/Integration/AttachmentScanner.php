<?php

declare(strict_types=1);

namespace Modules\TeamChat\Integration;

/** Private local input. Return clean only after a real completed scan; throw on malware or service failure. */
interface AttachmentScanner
{
    public function scan(string $path): string;
}
