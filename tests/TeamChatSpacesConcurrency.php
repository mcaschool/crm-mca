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
use Modules\TeamChat\Services\SpaceCommands;
use Modules\TeamChat\Services\SpaceQueries;
use Symfony\Component\Process\Process;

function spaceCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
if (($argv[1] ?? '') === 'child') {
    $f = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $index = (int) $argv[3];
    $job = $f['jobs'][$index];
    $v = $f['actors'][$job['actor'] ?? 0];
    $actor = new ActorContext($f['tenant'], $v['user'], $v['device'], $v['session'], 'space-race', AuthenticationLevel::Mfa);
    file_put_contents($argv[2].'.ready.'.$index, 'ready');
    while (! is_file($argv[2].'.go')) {
        usleep(1000);
    }
    $start = hrtime(true);
    try {
        $service = app(SpaceCommands::class);
        $result = match ($job['type']) {
            'create' => $service->create($actor, 'team', 'same-team', 'Race Team', '', [$f['actors'][1]['user'], $f['actors'][2]['user']]),
            'join' => $service->member($actor, 'team', $job['id'], 'join-'.$index, $f['actors'][3]['user'], 'add'),
            'send' => app(ChatCommands::class)->send($actor, $job['id'], $job['command'], 'Concurrent '.$job['command']),
            'remove' => $service->member($actor, 'team', $job['id'], 'remove-race', $f['actors'][3]['user'], 'remove'),
            'transfer' => $service->member($actor, 'team', $job['id'], 'transfer-'.$index, $f['actors'][$job['target']]['user'], 'transfer'),
        };
        echo json_encode(['result' => $result, 'ms' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR);
    } catch (ContractException $e) {
        echo json_encode(['denied' => $e->getMessage(), 'ms' => (hrtime(true) - $start) / 1e6], JSON_THROW_ON_ERROR);
    }
    exit;
}
spaceCheck(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'isolated MySQL');
spaceCheck(DB::table('users')->count() === 0, 'no application users');
Artisan::call('migrate', ['--force' => true]);
$institution = Institution::factory()->create();
$actors = User::factory()->count(4)->create(['institution_id' => $institution->id, 'role' => 'member'])->map(fn ($u) => realtimeActor($u))->all();
$file = __DIR__.'/../.testing/spaces-race-'.bin2hex(random_bytes(6)).'.json';
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
    $r = $run('same_team_creation', array_fill(0, 6, ['type' => 'create']));
    $team = $r[0]['result'];
    spaceCheck(count(array_unique(array_column($r, 'result'))) === 1, 'idempotent team creation');
    $general = app(SpaceQueries::class)->team($actors[0], $team)['general_id'];
    spaceCheck(DB::table('collab_chat_conversations')->where('team_id', $team)->where('default_key', 'general')->count() === 1, 'one General');
    $run('duplicate_join', array_fill(0, 6, ['type' => 'join', 'id' => $team]));
    spaceCheck(DB::table('collab_chat_team_members')->where('team_id', $team)->where('user_id', $actors[3]->userId)->count() === 1, 'one membership');
    spaceCheck((int) DB::table('collab_chat_team_members')->where('team_id', $team)->where('user_id', $actors[3]->userId)->value('generation') === 1, 'duplicate joins preserve history epoch');
    $group = app(SpaceCommands::class)->create($actors[0], 'group', 'load-group', 'Load', '', [$actors[1]->userId, $actors[2]->userId]);
    $channel = app(SpaceCommands::class)->create($actors[0], 'channel', 'load-channel', 'Projects', '', [], $team, 'standard');
    $jobs = [];
    foreach ([$general, $group, $channel] as $n => $id) {
        for ($i = 0; $i < 4; $i++) {
            $jobs[] = ['type' => 'send', 'id' => $id, 'command' => 'load-'.$n.'-'.($i < 2 ? 'retry' : $i)];
        }
    }
    $results = $run('three_spaces_twelve_sends', $jobs);
    spaceCheck(count(array_filter($results, fn ($r) => isset($r['denied']))) === 0, 'all concurrent sends authorized');
    foreach ([$general, $group, $channel] as $id) {
        spaceCheck(DB::table('collab_chat_messages')->where('conversation_id', $id)->orderBy('sequence')->pluck('sequence')->all() === [1, 2, 3], 'contiguous idempotent sequence '.$id);
    }
    $race = $run('expel_versus_send', [['type' => 'remove', 'id' => $team], ['type' => 'send', 'actor' => 3, 'id' => $general, 'command' => 'revocation-race']]);
    $revokedTick = (int) DB::table('collab_chat_membership_history')->where('resource_id', $team)->where('user_id', $actors[3]->userId)->where('active', false)->value('tick');
    $message = DB::table('collab_chat_messages')->where('conversation_id', $general)->where('command_id', 'revocation-race')->first();
    spaceCheck($message === null || (int) $message->access_tick < $revokedTick, 'send cannot commit after revocation');
    $ownership = $run('competing_ownership', [['type' => 'transfer', 'id' => $team, 'target' => 1], ['type' => 'transfer', 'id' => $team, 'target' => 2]]);
    spaceCheck(count(array_filter($ownership, fn ($r) => isset($r['denied']))) === 1, 'one authorized ownership winner');
    spaceCheck(DB::table('collab_chat_team_members')->where('team_id', $team)->where('role', 'owner')->where('active', true)->count() === 1, 'exactly one active owner');
    spaceCheck(DB::table('collab_events')->where('institution_id', $institution->id)->count() === DB::table('collab_outbox')->where('institution_id', $institution->id)->count(), 'atomic event and outbox count');
    file_put_contents(__DIR__.'/../.testing/phase5-concurrency.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "SPACES MYSQL CONCURRENCY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    DB::table('collab_chat_conversations')->where('institution_id', $institution->id)->delete();
    DB::table('collab_chat_teams')->where('institution_id', $institution->id)->delete();
    DB::table('collab_chat_membership_history')->where('institution_id', $institution->id)->delete();
    DB::table('collab_chat_space_commands')->where('institution_id', $institution->id)->delete();
    DB::table('users')->where('institution_id', $institution->id)->delete();
    DB::table('institutions')->where('id', $institution->id)->delete();
    foreach (array_merge([$file], glob($file.'.*')) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
