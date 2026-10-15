<?php

declare(strict_types=1);
require __DIR__.'/collaboration-runtime.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Institutions\Models\Institution;

if (DB::table('users')->exists()) {
    throw new RuntimeException('Migration smoke requires an empty isolated fixture database.');
}
$migration = require __DIR__.'/../Modules/CollaborationCore/database/migrations/2026_11_02_000000_create_collaboration_core.php';
$institution = Institution::factory()->create();
$migration->down();
if (Schema::hasTable('collab_devices') || Schema::hasColumn('users', 'is_identity_admin')) {
    throw new RuntimeException('Rollback incomplete.');
}
$id = DB::table('users')->insertGetId(['institution_id' => $institution->id, 'name' => 'Legacy migration fixture', 'email' => 'legacy@example.test',
    'password' => password_hash('test-only', PASSWORD_BCRYPT), 'role' => 'admin', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
$migration->up();
$row = DB::table('users')->where('id', $id)->first();
if (! $row->crm_legacy_access || ! $row->is_identity_admin || $row->role !== 'admin') {
    throw new RuntimeException('Legacy access not preserved.');
}
$neutral = DB::table('users')->insertGetId(['institution_id' => $institution->id, 'name' => 'Neutral fixture', 'email' => 'neutral@example.test',
    'password' => password_hash('test-only', PASSWORD_BCRYPT), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
$row = DB::table('users')->where('id', $neutral)->first();
if ($row->role !== 'member' || $row->crm_legacy_access || $row->is_identity_admin) {
    throw new RuntimeException('Unsafe neutral defaults.');
}
DB::table('users')->whereIn('id', [$id, $neutral])->delete();
$institution->delete();
echo "PASS: rollback removes Core persistence; reapply preserves legacy access and defaults new users to neutral.\n";
