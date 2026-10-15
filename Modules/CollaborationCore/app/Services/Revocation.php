<?php

declare(strict_types=1);

namespace Modules\CollaborationCore\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Revocation
{
    public function user(User $user): void
    {
        DB::table('collab_devices')->where('user_id', $user->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('collab_presence_devices')->where('user_id', $user->id)->delete();
        DB::table('collab_presence_calls')->where('user_id', $user->id)->delete();
    }

    public function session(int $userId, string $sessionHash): void
    {
        $ids = DB::table('collab_devices')->where('user_id', $userId)->where('session_hash', $sessionHash)->pluck('id');
        DB::table('collab_devices')->whereIn('id', $ids)->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('collab_presence_devices')->whereIn('device_id', $ids)->delete();
        if (config('session.driver') === 'database') {
            // Only the user's sessions are inspected; never expose the session identifier.
            $connection = DB::connection(config('session.connection'));
            foreach ($connection->table(config('session.table', 'sessions'))->where('user_id', $userId)->pluck('id') as $id) {
                if (hash_equals($sessionHash, hash('sha256', $id))) {
                    $connection->table(config('session.table', 'sessions'))->where('id', $id)->delete();
                }
            }
        }
    }
}
