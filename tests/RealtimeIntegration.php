<?php

declare(strict_types=1);

require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Integration\WakeTransport;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Institutions\Models\Institution;
use Symfony\Component\Process\Process;

function checkRealtime(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}
function realtimeProcess(array $arguments): Process
{
    $command = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
    foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $extension) {
        array_push($command, '-d', 'extension='.$extension);
    }

    return new Process([...$command, __FILE__, ...$arguments], dirname(__DIR__), timeout: 45);
}
realtimePolicies();
$mode = $argv[1] ?? 'parent';
if ($mode !== 'parent') {
    $fixture = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
    $a = new ActorContext($fixture['tenant'], $fixture['user'], $fixture['device'], $fixture['session'], 'test', AuthenticationLevel::Mfa);
    if ($mode === 'writer') {
        DB::transaction(function () use ($a, $argv): void {
            $id = $argv[3];
            app(TransactionalEventRecorder::class)->append(new InstitutionContext($a->institutionId), realtimeEvent($a, $id, 1, $id), new DeliveryAudience($a->institutionId, [$a->userId]));
            if ($id === 'first') {
                file_put_contents($argv[2].'.ready', 'ready');
                $deadline = microtime(true) + 20;
                while (! is_file($argv[2].'.release')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Barrier timeout');
                    }
                    usleep(10000);
                }
            }
        });
        exit(0);
    }
    app()->instance(WakeTransport::class, new class($argv[2], $mode) implements WakeTransport
    {
        public function __construct(private string $file, private string $mode) {}

        public function send(string $privateChannel): void
        {
            if ($this->mode === 'crash') {
                exit(17);
            }
            file_put_contents($this->file.'.sent', $privateChannel."\n", FILE_APPEND | LOCK_EX);
        }
    });
    for ($n = 0; $n < 30 && app(OutboxWorker::class)->runOne($a->institutionId); $n++) {
    }
    exit(0);
}

checkRealtime(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'dedicated test datadir');
checkRealtime(DB::table('users')->count() === 0, 'database has no real users');
Artisan::call('migrate', ['--force' => true]);
$i = Institution::factory()->create();
$u = User::factory()->create(['institution_id' => $i->id, 'role' => 'member']);
$a = realtimeActor($u);
$fixturePath = __DIR__.'/../.testing/realtime-'.bin2hex(random_bytes(6)).'.json';
file_put_contents($fixturePath, json_encode(['tenant' => $a->institutionId, 'user' => $a->userId, 'device' => $a->deviceId, 'session' => $a->sessionId], JSON_THROW_ON_ERROR));
$processes = [];
try {
    $recorder = app(TransactionalEventRecorder::class);
    try {
        $recorder->append(new InstitutionContext($i->id), realtimeEvent($a), new DeliveryAudience($i->id, [$u->id]));
        throw new RuntimeException('Recorder accepted no transaction');
    } catch (LogicException) {
        echo "PASS rejects missing transaction\n";
    }
    DB::beginTransaction();
    $recorder->append(new InstitutionContext($i->id), realtimeEvent($a), new DeliveryAudience($i->id, [$u->id]));
    DB::rollBack();
    checkRealtime(DB::table('collab_events')->where('institution_id', $i->id)->count() === 0, 'real outer rollback removes event');
    $initial = app(SyncFeed::class)->read($a, new PageRequest);
    $first = realtimeProcess(['writer', $fixturePath, 'first']);
    $processes[] = $first;
    $first->start();
    $deadline = microtime(true) + 15;
    while (! is_file($fixturePath.'.ready')) {
        if (! $first->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException($first->getErrorOutput().$first->getOutput());
        }
        usleep(10000);
    }
    $second = realtimeProcess(['writer', $fixturePath, 'second']);
    $processes[] = $second;
    $second->start();
    usleep(200000);
    $during = app(SyncFeed::class)->read($a, new PageRequest($initial->cursor));
    checkRealtime($during->events === [], 'uncommitted events cannot advance cursor');
    file_put_contents($fixturePath.'.release', 'release');
    foreach ([$first, $second] as $p) {
        $p->wait();
        checkRealtime($p->isSuccessful(), 'concurrent writer commits: '.$p->getErrorOutput());
    }
    $after = app(SyncFeed::class)->read($a, new PageRequest($during->cursor));
    checkRealtime(array_map(fn ($e) => $e->id, $after->events) === ['first', 'second'], 'commit-ordered cursor has no concurrent loss');
    app()->instance(WakeTransport::class, new class implements WakeTransport
    {
        public function send(string $privateChannel): void
        {
            throw new RuntimeException('offline');
        }
    });
    app(OutboxWorker::class)->runOne($i->id);
    checkRealtime(DB::table('collab_outbox')->where('institution_id', $i->id)->where('failure_code', 'transport_unavailable')->count() === 1, 'transport failure persists retry');
    checkRealtime(DB::table('collab_transport_receipts')->count() === 0, 'failure never claims delivery');
    DB::table('collab_outbox')->where('institution_id', $i->id)->update(['available_at' => now()]);
    for ($n = 0; $n < 4; $n++) {
        $p = realtimeProcess(['worker', $fixturePath]);
        $processes[] = $p;
        $p->start();
    }
    foreach (array_slice($processes, 2) as $p) {
        $p->wait();
        checkRealtime($p->isSuccessful(), 'parallel worker completes '.$p->getErrorOutput());
    }
    checkRealtime(DB::table('collab_transport_receipts')->where('institution_id', $i->id)->count() === 2, 'four workers create one receipt per event-device');
    checkRealtime(count(file($fixturePath.'.sent')) === 2, 'parallel claims avoid duplicate sends');
    DB::transaction(fn () => $recorder->append(new InstitutionContext($i->id), realtimeEvent($a, 'crash', 1, 'crash'), new DeliveryAudience($i->id, [$u->id])));
    $crash = realtimeProcess(['crash', $fixturePath]);
    $processes[] = $crash;
    $crash->run();
    checkRealtime($crash->getExitCode() === 17 && DB::table('collab_outbox')->where('event_id', 'crash')->value('status') === 'claimed', 'worker process dies with durable claim');
    DB::table('collab_outbox')->where('event_id', 'crash')->update(['lease_until' => now()->subSecond()]);
    $recover = realtimeProcess(['worker', $fixturePath]);
    $processes[] = $recover;
    $recover->run();
    checkRealtime($recover->isSuccessful() && DB::table('collab_outbox')->where('event_id', 'crash')->value('status') === 'published', 'expired worker lease recovered');
    checkRealtime((int) DB::table('collab_outbox')->where('event_id', 'crash')->value('attempts') === 2, 'recovered claim counts attempt');
    // Existing notification intent shares the original event's receipt, without another send.
    $dispatcher = app(\Modules\CollaborationCore\Contracts\NotificationDispatcher::class);
    $intent = new \Modules\CollaborationCore\Data\NotificationIntent('intent-1', 'test.changed', 'crash', 'crash', 'dedupe-1', now()->addMinute()->toDateTimeImmutable());
    $audience = new \Modules\CollaborationCore\Data\NotificationAudience($i->id, [$u->id]);
    $dispatcher->enqueue(new InstitutionContext($i->id), $intent, $audience);
    $dispatcher->enqueue(new InstitutionContext($i->id), $intent, $audience);
    app(\Modules\CollaborationRealtime\Services\NotificationDelivery::class)->reconcile($i->id);
    checkRealtime(DB::table('collab_notification_delivery')->where('intent_id', 'intent-1')->value('status') === 'transport_accepted', 'notification reconciles broker acceptance only');
    checkRealtime(DB::table('collab_notification_intents')->where('id', 'intent-1')->value('status') === 'pending', 'notification is never falsely marked received');
    app(\Modules\CollaborationCore\Services\NotificationPreferences::class)->set($a, false);
    app(\Modules\CollaborationRealtime\Services\NotificationDelivery::class)->reconcile($i->id);
    checkRealtime(DB::table('collab_notification_delivery')->where('intent_id', 'intent-1')->value('status') === 'suppressed', 'notification preferences revalidated');
    DB::table('collab_notification_intents')->where('id', 'intent-1')->update(['expires_at' => now()->subSecond()]);
    app(\Modules\CollaborationRealtime\Services\NotificationDelivery::class)->reconcile($i->id);
    checkRealtime(DB::table('collab_notification_delivery')->where('intent_id', 'intent-1')->value('status') === 'expired', 'notification expiration revalidated');
    checkRealtime(count(file($fixturePath.'.sent')) === 3, 'notification path never sends twice');
    // Same producer ID racing on independent transactions must remain one event.
    $duplicates = [realtimeProcess(['writer', $fixturePath, 'duplicate']), realtimeProcess(['writer', $fixturePath, 'duplicate'])];
    foreach ($duplicates as $p) {
        $processes[] = $p;
        $p->start();
    }
    foreach ($duplicates as $p) {
        $p->wait();
        checkRealtime($p->isSuccessful(), 'concurrent duplicate producer succeeds');
    }
    checkRealtime(DB::table('collab_events')->where('id', 'duplicate')->count() === 1, 'concurrent duplicate producers retain one event');
    DB::table('collab_outbox')->where('event_id', 'duplicate')->update(['attempts' => 7, 'available_at' => now()]);
    app(OutboxWorker::class)->runOne($i->id);
    checkRealtime(DB::table('collab_outbox')->where('event_id', 'duplicate')->value('status') === 'failed'
        && (int) DB::table('collab_outbox')->where('event_id', 'duplicate')->value('attempts') === 8, 'eighth failed attempt becomes terminal');
    Artisan::call('collaboration:realtime', ['action' => 'retry', '--institution' => $i->id, '--event' => 'duplicate']);
    $retry = realtimeProcess(['worker', $fixturePath]);
    $processes[] = $retry;
    $retry->run();
    checkRealtime($retry->isSuccessful() && DB::table('collab_outbox')->where('event_id', 'duplicate')->value('status') === 'published', 'operator retry recovers terminal failure');
    $sentBefore = count(file($fixturePath.'.sent'));
    DB::table('collab_outbox')->where('event_id', 'duplicate')->update(['status' => 'pending', 'available_at' => now()]);
    $repeat = realtimeProcess(['worker', $fixturePath]);
    $processes[] = $repeat;
    $repeat->run();
    checkRealtime($repeat->isSuccessful() && count(file($fixturePath.'.sent')) === $sentBefore, 'stored transport receipt suppresses replay side effects');
    DB::transaction(fn () => $recorder->append(new InstitutionContext($i->id), realtimeEvent($a, 'permanent', 1, 'permanent'), new DeliveryAudience($i->id, [$u->id])));
    app()->instance(WakeTransport::class, new class implements WakeTransport
    {
        public function send(string $channel): void
        {
            throw new LogicException('Invalid adapter configuration');
        }
    });
    app(OutboxWorker::class)->runOne($i->id);
    checkRealtime(DB::table('collab_outbox')->where('event_id', 'permanent')->value('status') === 'failed'
        && DB::table('collab_outbox')->where('event_id', 'permanent')->value('failure_code') === 'invalid_producer', 'permanent adapter error is not retried indefinitely');
    config(['queue.default' => 'database']);
    DB::beginTransaction();
    \Modules\CollaborationRealtime\Jobs\DrainOutbox::dispatch($i->id);
    checkRealtime(DB::table('jobs')->count() === 0, 'queue wake is deferred until business commit');
    DB::rollBack();
    checkRealtime(DB::table('jobs')->count() === 0, 'rolled back business transaction creates no queue job');
    DB::transaction(fn () => \Modules\CollaborationRealtime\Jobs\DrainOutbox::dispatch($i->id));
    checkRealtime(DB::table('jobs')->where('queue', 'collaboration')->count() === 1, 'committed wake persists on Laravel database queue');
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'collaboration', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    checkRealtime(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'Laravel database worker consumes shared outbox job');
    echo "REAL MYSQL CONCURRENCY AND RECOVERY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    DB::table('users')->where('id', $u->id)->delete();
    DB::table('institutions')->where('id', $i->id)->delete();
    foreach ([$fixturePath, $fixturePath.'.ready', $fixturePath.'.release', $fixturePath.'.sent'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
