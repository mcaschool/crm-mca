<?php

declare(strict_types=1);

require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Institutions\Models\Institution;

if (str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) !== str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')) || DB::table('users')->count() !== 0) {
    throw new RuntimeException('Requires the empty dedicated test database');
}
$i = Institution::factory()->create();
$u = User::factory()->create(['institution_id' => $i->id, 'role' => 'member']);
$a = realtimeActor($u);
$migration = require __DIR__.'/../Modules/CollaborationRealtime/database/migrations/2026_11_03_000000_create_collaboration_realtime.php';
try {
    $migration->down();
    if (Schema::hasTable('collab_events') || ! DB::table('collab_devices')->where('id', $a->deviceId)->exists()) {
        throw new RuntimeException('Rollback changed Core');
    }
    $migration->up();
    if (! Schema::hasTable('collab_events') || ! DB::table('collab_devices')->where('id', $a->deviceId)->exists()) {
        throw new RuntimeException('Migration did not preserve Core');
    }
    echo "PASS Realtime down/up preserves Core user and device\n";
} finally {
    DB::table('users')->where('id', $u->id)->delete();
    DB::table('institutions')->where('id', $i->id)->delete();
}
