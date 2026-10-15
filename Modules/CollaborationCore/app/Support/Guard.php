<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Support;

final class Guard
{
    public static function id(int $value): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Expected a positive identifier.');
        }
    }

    public static function token(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid opaque identifier.');
        }
    }

    /** @param list<int> $ids */
    public static function users(array $ids, bool $allowEmpty = false): void
    {
        if (! array_is_list($ids) || (! $allowEmpty && $ids === []) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new \InvalidArgumentException('Expected distinct user identifiers.');
        }
        foreach ($ids as $id) {
            if (! is_int($id)) {
                throw new \InvalidArgumentException('Expected integer user identifiers.');
            }
            self::id($id);
        }
    }
}
