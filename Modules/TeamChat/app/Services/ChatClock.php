<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Illuminate\Support\Facades\DB;

/** Called only inside the institution write lock. Ordering never depends on wall clocks. */
final class ChatClock
{
    public function next(int $institutionId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Chat clock requires a transaction.');
        }
        DB::table('collab_chat_clocks')->insertOrIgnore(['institution_id' => $institutionId, 'tick' => 0]);
        DB::table('collab_chat_clocks')->where('institution_id', $institutionId)->increment('tick');

        return (int) DB::table('collab_chat_clocks')->where('institution_id', $institutionId)->value('tick');
    }
}
