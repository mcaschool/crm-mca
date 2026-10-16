<?php

declare(strict_types=1);
require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationCore\Services\Revocation;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\CollaborationRealtime\Services\EventCodec;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\CollaborationRealtime\Services\RevocationDelivery;
use Modules\Institutions\Models\Institution;
use Symfony\Component\Process\Process;

function check4b(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
$mode = $argv[1] ?? 'parent';
if ($mode === 'revoke') {
    $f = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
    file_put_contents($argv[2].'.ready.'.$argv[3], 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($argv[2].'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
    app(ApplicationAccess::class)->set(User::findOrFail($f['admin']), User::findOrFail($f['user']), 'collaboration', false);
    exit;
}
check4b(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'dedicated test datadir');
check4b(DB::table('users')->count() === 0, 'no application users');
$i = Institution::factory()->create();
$admin = User::factory()->create(['institution_id' => $i->id]);
$u = User::factory()->create(['institution_id' => $i->id]);
$a = realtimeActor($u);
$file = __DIR__.'/../.testing/4b-'.bin2hex(random_bytes(6)).'.json';
file_put_contents($file, json_encode(['admin' => $admin->id, 'user' => $u->id], JSON_THROW_ON_ERROR));
$children = [];
try {
    DB::beginTransaction();
    app(Revocation::class)->session($u->id, $a->sessionId);
    DB::rollBack();
    check4b(DB::table('collab_events')->where('institution_id', $i->id)->count() === 0, 'outer rollback leaves no revocation');
    for ($n = 0; $n < 6; $n++) {
        $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $ext) {
            array_push($cmd, '-d', 'extension='.$ext);
        }
        $p = new Process([...$cmd, __FILE__, 'revoke', $file, (string) $n], dirname(__DIR__), timeout: 60);
        $p->start();
        $children[] = $p;
    }
    $deadline = microtime(true) + 45;
    while (count(glob($file.'.ready.*')) !== 6) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Children not ready');
        }
        usleep(10000);
    }
    file_put_contents($file.'.go', 'go');
    foreach ($children as $p) {
        $p->wait();
        check4b($p->isSuccessful(), 'concurrent revoker completed: '.$p->getErrorOutput());
    }
    check4b(DB::table('collab_events')->where('institution_id', $i->id)->count() === 2, 'six concurrent revocations produce one access and one device event');
    $worker = app(OutboxWorker::class);
    $worker->runOne($i->id);
    $failed = DB::table('collab_outbox')->where('institution_id', $i->id)->where('attempts', 1)->first();
    check4b($failed->status === 'pending' && $failed->failure_code === 'transport_unavailable', 'missing consumer persists retry after commit');
    $consumer = new class implements RevocationConsumer
    {
        public int $calls = 0;

        public bool $fail = true;

        public function consume(EventEnvelope $event): void
        {
            $this->calls++;
            if ($this->fail) {
                throw new RuntimeException('Simulated service outage');
            }
        }
    };
    app()->instance(RevocationConsumer::class, $consumer);
    DB::table('collab_outbox')->where('institution_id', $i->id)->update(['available_at' => now()->subSecond()]);
    $worker->runOne($i->id);
    check4b(DB::table('collab_revocation_receipts')->where('institution_id', $i->id)->count() === 0, 'failed consumer leaves no receipt');
    $consumer->fail = false;
    DB::table('collab_outbox')->where('institution_id', $i->id)->update(['available_at' => now()->subSecond()]);
    while ($worker->runOne($i->id)) {
    }
    check4b(DB::table('collab_outbox')->where('institution_id', $i->id)->where('status', 'published')->count() === 2, 'pending recovery delivers both durable events');
    $calls = $consumer->calls;
    DB::table('collab_outbox')->where('institution_id', $i->id)->update(['status' => 'claimed', 'claim' => 'dead-worker', 'lease_until' => now()->subMinute()]);
    while ($worker->runOne($i->id)) {
    }
    check4b($consumer->calls === $calls, 'crash after consumer commit is deduplicated by durable receipt');
    check4b(DB::table('collab_revocation_receipts')->where('institution_id', $i->id)->count() === 2, 'one durable receipt per event');
    $row = DB::table('collab_events')->where('institution_id', $i->id)->first();
    app(RevocationDelivery::class)->deliver(app(EventCodec::class)->decode($row)->event);
    check4b($consumer->calls === $calls, 'explicit replay is idempotent');
} finally {
    foreach ($children as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    DB::table('audit_logs')->where('institution_id', $i->id)->delete();
    DB::table('users')->where('institution_id', $i->id)->delete();
    DB::table('institutions')->where('id', $i->id)->delete();
    foreach (glob($file.'*') as $path) {
        unlink($path);
    }
}
