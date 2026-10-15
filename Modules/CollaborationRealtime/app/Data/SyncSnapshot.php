<?php

declare(strict_types=1);

namespace Modules\CollaborationRealtime\Data;

/** Resource IDs and versions only; fetch domain payloads through authorized APIs. */
final readonly class SyncSnapshot
{
    /** @param list<array{type:string,id:string,version:string}> $resources */
    public function __construct(public array $resources, public string $cursor, public ?string $nextPageCursor)
    {
        if (! array_is_list($resources) || $cursor === '' || strlen($cursor) > 4096 || $nextPageCursor === '') {
            throw new \InvalidArgumentException('Invalid snapshot.');
        }
        foreach ($resources as $resource) {
            foreach (['type', 'id', 'version'] as $key) {
                if (! isset($resource[$key]) || ! is_string($resource[$key]) || $resource[$key] === '') {
                    throw new \InvalidArgumentException('Invalid snapshot resource.');
                }
            }
        }
    }
}
