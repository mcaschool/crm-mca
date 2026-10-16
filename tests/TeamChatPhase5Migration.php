<?php

declare(strict_types=1);

require __DIR__.'/collaboration-runtime.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Institutions\Models\Institution;

if (str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) !== str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')) || DB::table('users')->count() !== 0) {
    throw new RuntimeException('Isolated empty test database required');
}
$migration = require __DIR__.'/../Modules/TeamChat/database/migrations/2026_11_05_000000_extend_teamchat_spaces.php';
$migration->down();
$institution = Institution::factory()->create();
$users = User::factory()->count(2)->create(['institution_id' => $institution->id, 'role' => 'member']);
$id = (string) Str::ulid();
$message = (string) Str::ulid();
try {
    DB::table('collab_chat_conversations')->insert(['id' => $id, 'institution_id' => $institution->id, 'first_user_id' => $users[0]->id,
        'second_user_id' => $users[1]->id, 'message_sequence' => 1, 'activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ($users as $user) {
        DB::table('collab_chat_participants')->insert(['institution_id' => $institution->id, 'conversation_id' => $id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('collab_chat_messages')->insert(['id' => $message, 'institution_id' => $institution->id, 'conversation_id' => $id, 'sender_id' => $users[0]->id,
        'sequence' => 1, 'command_id' => 'legacy', 'request_hash' => hash('sha256', 'legacy'), 'body' => 'Existing Phase 4 history', 'created_at' => now()]);
    $before = (array) DB::table('collab_chat_messages')->where('id', $message)->first();
    $migration->up();
    $after = (array) DB::table('collab_chat_messages')->where('id', $message)->first();
    unset($after['access_tick']);
    if ($before !== $after || DB::table('collab_chat_conversations')->where('id', $id)->value('type') !== 'direct') {
        throw new RuntimeException('Legacy history changed');
    }
    require __DIR__.'/RealtimeFixtures.php';
    $actor = realtimeActor($users[1]);
    $data = app(\Modules\TeamChat\Services\ChatQueries::class)->messages($actor, $id, new \Modules\CollaborationCore\Data\PageRequest);
    if (array_column($data['messages'], 'id') !== [$message]) {
        throw new RuntimeException('Legacy history inaccessible');
    }
    $duplicateRejected = false;
    try {
        DB::table('collab_chat_conversations')->insert(['id' => (string) Str::ulid(), 'institution_id' => $institution->id, 'first_user_id' => $users[0]->id,
            'second_user_id' => $users[1]->id, 'activity_at' => now()]);
    } catch (\Illuminate\Database\UniqueConstraintViolationException) {
        $duplicateRejected = true;
    }
    if (! $duplicateRejected) {
        throw new RuntimeException('Direct pair unique index lost');
    }
    echo "PASS populated Phase 4 migration: exact message preservation, direct classification, authorized history, unique pair\n";
} finally {
    DB::table('collab_chat_conversations')->where('institution_id', $institution->id)->delete();
    DB::table('users')->where('institution_id', $institution->id)->delete();
    DB::table('institutions')->where('id', $institution->id)->delete();
}
