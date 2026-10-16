<?php

declare(strict_types=1);

namespace Modules\TeamChat\Integration;

final class UnavailableAttachmentScanner implements AttachmentScanner
{
    public function scan(string $path): string
    {
        return 'not_scanned';
    }
}
