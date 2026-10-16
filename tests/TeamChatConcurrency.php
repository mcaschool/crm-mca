<?php

declare(strict_types=1);

require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Symfony\Component\Process\Process;

function chatCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
$mode = $argv[1] ?? 'parent';
if ($mode !== 'parent') {
    $f = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
    $v = $f[$argv[3]];
    $a = new ActorContext($f['tenant'], $v['user'], $v['device'], $v['session'], 'concurrent', AuthenticationLevel::Mfa);
    while (! is_file($argv[2].'.go')) {
        usleep(10000);
    }
    $commands = app(ChatCommands::class);
    $id = $commands->direct($a, $f[$argv[3] === 'a' ? 'b' : 'a']['user']);
    if ($mode === 'send') {
        $commands->send($a, $id, $argv[4], 'Concurrent '.$argv[4]);
    }
    echo $id;
    exit;
}
chatCheck(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'isolated MySQL datadir');
chatCheck(DB::table('users')->count() === 0, 'no real users');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$i = Institution::factory()->create();
$users = User::factory()->count(2)->create(['institution_id' => $i->id, 'role' => 'member']);
$a = realtimeActor($users[0]);
$b = realtimeActor($users[1]);
$file = __DIR__.'/../.testing/chat-concurrency-'.bin2hex(random_bytes(6)).'.json';
file_put_contents($file, json_encode(['tenant' => $i->id, 'a' => ['user' => $a->userId, 'device' => $a->deviceId, 'session' => $a->sessionId], 'b' => ['user' => $b->userId, 'device' => $b->deviceId, 'session' => $b->sessionId]], JSON_THROW_ON_ERROR));
$processes = [];
try {
    foreach (['direct', 'send'] as $round) {
        if (is_file($file.'.go')) {
            unlink($file.'.go');
        }
        $batch = [];
        for ($n = 0; $n < 6; $n++) {
            $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
            foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $ext) {
                array_push($cmd, '-d', 'extension='.$ext);
            }
            // Same command on three devices from the same sender is a replay.
            $p = new Process([...$cmd, __FILE__, $round, $file, $n % 2 ? 'a' : 'b', $n % 2 ? 'same-command' : 'unique-'.$n], dirname(__DIR__), timeout: 60);
            $p->start();
            $processes[] = $p;
            $batch[] = $p;
        }
        file_put_contents($file.'.go', 'start');
        foreach ($batch as $p) {
            $p->wait();
            chatCheck($p->isSuccessful(), 'concurrent '.$round.' committed '.$p->getErrorOutput().($p->isSuccessful() ? '' : $p->getOutput()));
        }
    }
    chatCheck(DB::table('collab_chat_conversations')->where('institution_id', $i->id)->count() === 1, 'six simultaneous creators yield one symmetric direct pair');
    chatCheck(DB::table('collab_chat_messages')->where('institution_id', $i->id)->count() === 4, 'six simultaneous sends include three retries of one command');
    chatCheck(DB::table('collab_chat_messages')->where('institution_id', $i->id)->orderBy('sequence')->pluck('sequence')->all() === [1, 2, 3, 4], 'database sequence is contiguous and unique');
    chatCheck(DB::table('collab_events')->where('institution_id', $i->id)->count() === 5 && DB::table('collab_outbox')->where('institution_id', $i->id)->count() === 5, 'one outbox event per committed domain change');
    // Deterministic regression: fail the first claim AFTER its SQL update,
    // verify rollback/retry before transport. Other checks above use real concurrency.
    $injected = false;
    DB::listen(function ($query) use (&$injected): void {
        if (! $injected && str_starts_with($query->sql, 'update `collab_outbox`') && ($query->bindings[0] ?? null) === 'claimed') {
            $injected = true;
            throw new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
        }
    });
    app()->instance(\Modules\CollaborationRealtime\Integration\WakeTransport::class, new class implements \Modules\CollaborationRealtime\Integration\WakeTransport
    {
        public function send(string $privateChannel): void {}
    });
    chatCheck(app(\Modules\CollaborationRealtime\Services\OutboxWorker::class)->runOne($i->id), 'claim succeeds after injected SQL deadlock');
    $claimed = DB::table('collab_outbox')->where('institution_id', $i->id)->where('status', 'published')->first();
    chatCheck($injected && $claimed !== null && (int) $claimed->attempts === 1, 'rolled back claim does not consume an attempt or lose the event');
    echo "TEAMCHAT MYSQL CONCURRENCY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    DB::table('collab_chat_conversations')->where('institution_id', $i->id)->delete();
    DB::table('users')->where('institution_id', $i->id)->delete();
    DB::table('institutions')->where('id', $i->id)->delete();
    foreach ([$file, $file.'.go'] as $p) {
        if (is_file($p)) {
            unlink($p);
        }
    }
}
