<?php

declare(strict_types=1);
require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\MessageInteractions;
use Symfony\Component\Process\Process;

config(['teamchat.interactions.pin_limit' => 2]);
function spaceCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }echo "PASS $label\n";
}
if (($argv[1] ?? '') === 'child') {
    $f = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $index = (int) $argv[3];
    $job = $f['jobs'][$index];
    $v = $f['actors'][$job['actor'] ?? 0];
    $actor = new ActorContext($f['tenant'], $v['user'], $v['device'], $v['session'], 'interaction-race', AuthenticationLevel::Mfa);
    file_put_contents($argv[2].'.ready.'.$index, 'ready');
    while (! is_file($argv[2].'.go')) {
        usleep(1000);
    }
    $start = hrtime(true);
    try {
        if ($job['operation'] === 'revoke') {
            app(ChatCommands::class)->membership($actor, $job['conversation'], false);
        } else {
            app(MessageInteractions::class)->change($actor, $job['conversation'], $job['message'], $job['command'], $job['operation'], $job['data'] ?? []);
        }
        echo json_encode(['result' => 'ok', 'ms' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR);
    } catch (ContractException $e) {
        echo json_encode(['denied' => $e->getMessage(), 'ms' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR);
    }
    exit;
}
spaceCheck(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'isolated MySQL');
spaceCheck(DB::table('users')->count() === 0, 'no application users');
Artisan::call('migrate', ['--force' => true]);
$institution = Institution::factory()->create();
$actors = User::factory()->count(2)->create(['institution_id' => $institution->id, 'role' => 'member'])->map(fn ($u) => realtimeActor($u))->all();
$file = __DIR__.'/../.testing/interactions-race-'.bin2hex(random_bytes(6)).'.json';
$processes = [];
$measurements = [];
$run = function (string $label, array $jobs) use ($actors, $institution, $file, &$processes, &$measurements): array {
    foreach (glob($file.'.*') as $old) {
        unlink($old);
    }
    file_put_contents($file, json_encode(['tenant' => $institution->id, 'actors' => array_map(fn ($a) => ['user' => $a->userId, 'device' => $a->deviceId, 'session' => $a->sessionId], $actors), 'jobs' => $jobs], JSON_THROW_ON_ERROR));
    $batch = [];
    foreach ($jobs as $n => $job) {
        $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $ext) {
            array_push($cmd, '-d', 'extension='.$ext);
        }
        $p = new Process([...$cmd, __FILE__, 'child', $file, (string) $n], dirname(__DIR__), timeout: 60);
        $p->start();
        $processes[] = $p;
        $batch[] = $p;
    }
    $deadline = microtime(true) + 40;
    while (count(glob($file.'.ready.*')) < count($jobs)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Child startup timeout');
        }
        usleep(10000);
    }
    $start = hrtime(true);
    file_put_contents($file.'.go', 'go');
    $results = [];
    foreach ($batch as $p) {
        $p->wait();
        spaceCheck($p->isSuccessful(), $label.' process '.$p->getErrorOutput().($p->isSuccessful() ? '' : $p->getOutput()));
        $results[] = json_decode($p->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }
    $ms = array_column($results, 'ms');
    sort($ms);
    $measurements[$label] = ['processes' => count($jobs), 'wall_ms' => round((hrtime(true) - $start) / 1e6, 2),
        'median_ms' => round($ms[(int) floor(count($ms) / 2)], 2), 'max_ms' => round(max($ms), 2)];
    echo json_encode([$label => $measurements[$label]], JSON_THROW_ON_ERROR)."\n";

    return $results;
};
try {
    $chat = app(ChatCommands::class);
    $conversation = $chat->direct($actors[0], $actors[1]->userId);
    $id = $chat->send($actors[0], $conversation, 'original', 'Original');
    $jobs = [];
    for ($i = 0; $i < 6; $i++) {
        $jobs[] = ['conversation' => $conversation, 'message' => $id, 'command' => 'edit-'.$i, 'operation' => 'edit', 'data' => ['version' => 1, 'body' => 'Edit '.$i]];
    }
    $r = $run('competing_edits', $jobs);
    spaceCheck(count(array_column($r, 'result')) === 1 && count(array_column($r, 'denied')) === 5, 'one edit wins and five receive a conflict');
    spaceCheck((int) DB::table('collab_chat_messages')->where('id', $id)->value('version') === 2 && DB::table('collab_chat_message_audit')->count() === 1, 'one audited text version');
    $jobs = [];
    for ($i = 0; $i < 6; $i++) {
        $jobs[] = ['conversation' => $conversation, 'message' => $id, 'actor' => 1, 'command' => 'reaction-'.$i, 'operation' => 'react', 'data' => ['emoji' => '👍']];
    }
    $r = $run('duplicate_reactions', $jobs);
    spaceCheck(count(array_column($r, 'result')) === 6 && DB::table('collab_chat_reactions')->count() === 1, 'six operations produce one reaction');
    $r = $run('delete_versus_react', [
        ['conversation' => $conversation, 'message' => $id, 'command' => 'delete', 'operation' => 'delete', 'data' => ['version' => 2]],
        ['conversation' => $conversation, 'message' => $id, 'actor' => 1, 'command' => 'another-reaction', 'operation' => 'react', 'data' => ['emoji' => '❤️']],
    ]);
    spaceCheck(DB::table('collab_chat_messages')->where('id', $id)->value('deleted_at') !== null && DB::table('collab_chat_reactions')->count() === 0, 'deletion leaves no dangling reactions');
    $jobs = [];
    for ($i = 0; $i < 6; $i++) {
        $message = $chat->send($actors[0], $conversation, 'message-'.$i, 'Pinned candidate '.$i);
        $jobs[] = ['conversation' => $conversation, 'message' => $message, 'command' => 'pin-'.$i, 'operation' => 'pin'];
    }
    $r = $run('pin_limit', $jobs);
    spaceCheck(count(array_column($r, 'result')) === 2 && DB::table('collab_chat_pins')->count() === 2, 'concurrent pin limit is never exceeded');
    $last = $chat->send($actors[0], $conversation, 'revocation-message', 'Revocation race');
    $run('reaction_versus_revocation', [
        ['conversation' => $conversation, 'message' => $last, 'actor' => 1, 'command' => 'last-reaction', 'operation' => 'react', 'data' => ['emoji' => '👍']],
        ['conversation' => $conversation, 'message' => $last, 'actor' => 1, 'command' => 'leave', 'operation' => 'revoke'],
    ]);
    $row = DB::table('collab_chat_messages')->where('id', $last)->first();
    spaceCheck(app(MessageInteractions::class)->metadata($actors[0], $row)['reactions'] === [], 'revoked participant cannot leave a visible reaction');
    try {
        app(MessageInteractions::class)->change($actors[1], $conversation, $last, 'after-leave', 'react', ['emoji' => '👍']);
        throw new RuntimeException('Revoked participant accepted');
    } catch (ContractException) {
        spaceCheck(true, 'new reactions denied immediately after revocation');
    }
    spaceCheck(DB::table('collab_events')->count() === DB::table('collab_outbox')->count(), 'every event has atomic outbox record');
    file_put_contents(__DIR__.'/../.testing/phase7-concurrency.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "INTERACTIONS MYSQL CONCURRENCY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    DB::table('collab_chat_conversations')->where('institution_id', $institution->id)->delete();
    DB::table('users')->where('institution_id', $institution->id)->delete();
    DB::table('institutions')->where('id', $institution->id)->delete();
    foreach (array_merge([$file], glob($file.'.*')) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
