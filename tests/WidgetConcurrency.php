<?php

declare(strict_types=1);
require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\WidgetApplications;
use Modules\CollaborationCore\Services\WidgetSessions;
use Modules\CollaborationRealtime\Services\NotificationInbox;
use Modules\CollaborationRealtime\Services\WidgetCoordinator;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatCommands;
use Symfony\Component\Process\Process;

config(['collaboration_widget.allow_local_http' => true, 'collaboration_widget.cache_store' => 'database']);
function widgetCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException($label);
    }echo "PASS $label\n";
}
if (($argv[1] ?? '') === 'child') {
    $f = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $n = (int) $argv[3];
    file_put_contents($argv[2].'.ready.'.$n, 'ready');
    while (! is_file($argv[2].'.go')) {
        usleep(1000);
    }
    try {
        if ($f['mode'] === 'exchange') {
            app(WidgetSessions::class)->exchange('race', 'http://localhost:33320', $f['code'], $f['verifier']);
            $result = ['success' => true];
        } else {
            $session = $f['sessions'][$f['mode'] === 'same' ? 0 : $n % 3];
            $b = app(WidgetSessions::class)->authenticate($session['token'], $session['instance_id'], 'race', 'http://localhost:33320');
            $result = app(WidgetCoordinator::class)->claim($b['actor'], $b['session'], $f['notification']);
        }
    } catch (ContractException $e) {
        $result = ['denied' => $e->errorCode];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
widgetCheck(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'isolated MySQL');
widgetCheck(DB::table('users')->count() === 0, 'no application users');
$institution = Institution::factory()->create();
$users = User::factory()->count(2)->create(['institution_id' => $institution->id, 'role' => 'member']);
$actor = realtimeActor($users[0]);
$sender = realtimeActor($users[1]);
$file = __DIR__.'/../.testing/widget-race-'.bin2hex(random_bytes(6)).'.json';
$processes = [];
$measurements = [];
$run = function (string $mode, array $payload) use ($file, &$processes, &$measurements): array {
    foreach (glob($file.'.*') as $p) {
        unlink($p);
    }
    file_put_contents($file, json_encode(['mode' => $mode] + $payload, JSON_THROW_ON_ERROR));
    $batch = [];
    for ($n = 0; $n < 6; $n++) {
        $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $ext) {
            array_push($cmd, '-d', 'extension='.$ext);
        }
        $p = new Process([...$cmd, __FILE__, 'child', $file, (string) $n], dirname(__DIR__), timeout: 90);
        $p->start();
        $batch[] = $p;
        $processes[] = $p;
    }
    $deadline = microtime(true) + 60;
    while (count(glob($file.'.ready.*')) < 6) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Child startup timeout');
        }usleep(10000);
    }
    $start = hrtime(true);
    file_put_contents($file.'.go', 'go');
    $results = [];
    foreach ($batch as $p) {
        $p->wait();
        widgetCheck($p->isSuccessful(), $mode.' child '.$p->getErrorOutput());
        $results[] = json_decode($p->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }
    $measurements[$mode] = round((hrtime(true) - $start) / 1e6, 2);

    return $results;
};
try {
    app(WidgetApplications::class)->register('race', $institution->id, 'Race', ['http://localhost:33320'], 'local', ['chat', 'notifications']);
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $sessions = app(WidgetSessions::class);
    $co = app(WidgetCoordinator::class);
    $code = $sessions->issue($actor, 'race', 'http://localhost:33320', $challenge);
    $r = $run('exchange', ['code' => $code, 'verifier' => $verifier]);
    widgetCheck(count(array_filter($r, fn ($v) => $v['success'] ?? false)) === 1, 'one grant exchange succeeds under six processes');
    $bindings = [];
    $issued = [];
    for ($n = 0; $n < 3; $n++) {
        $code = $sessions->issue($actor, 'race', 'http://localhost:33320', $challenge);
        $s = $sessions->exchange('race', 'http://localhost:33320', $code, $verifier);
        $issued[] = $s;
        $b = $sessions->authenticate($s['token'], $s['instance_id'], 'race', 'http://localhost:33320');
        $bindings[] = $b;
        $co->heartbeat($actor, $b['session'], ['visible' => $n === 0, 'focused' => $n === 0, 'interacted' => $n === 0]);
    }
    $chat = app(ChatCommands::class);
    $conversation = $chat->direct($sender, $actor->userId);
    $chat->send($sender, $conversation, 'first', 'First');
    $notification = app(NotificationInbox::class)->current($actor)[0];
    $r = $run('same', ['sessions' => $issued, 'notification' => $notification['id']]);
    $won = array_values(array_filter($r, fn ($v) => $v['claimed'] ?? false));
    widgetCheck(count($won) === 1, 'one lease for six simultaneous claims by same instance');
    $co->acknowledge($actor, $bindings[0]['session'], $won[0]['event_id'], $won[0]['lease']);
    widgetCheck(! $co->claim($actor, $bindings[0]['session'], $notification['id'])['claimed'], 'acknowledged event is deduplicated');
    $chat->send($sender, $conversation, 'second', 'Second');
    $notification = collect(app(NotificationInbox::class)->current($actor))->first(fn ($n) => $n['id'] !== $notification['id']);
    $r = $run('different', ['sessions' => $issued, 'notification' => $notification['id']]);
    $won = array_values(array_filter($r, fn ($v) => $v['claimed'] ?? false));
    widgetCheck(count($won) === 1, 'one lease across three competing instances');
    $co->remove($actor, $bindings[0]['session']->id);
    $co->heartbeat($actor, $bindings[1]['session'], ['visible' => true, 'focused' => true, 'interacted' => true]);
    $recovered = $co->claim($actor, $bindings[1]['session'], $notification['id']);
    widgetCheck($recovered['claimed'], 'closed owner lease reassigned');
    DB::table('collab_devices')->where('id', $actor->deviceId)->update(['revoked_at' => now()]);
    try {
        $co->acknowledge($actor, $bindings[1]['session'], $recovered['event_id'], $recovered['lease']);
        throw new RuntimeException('Revoked device acknowledged');
    } catch (ContractException) {
        widgetCheck(true, 'revocation stops acknowledgement');
    }
    file_put_contents(__DIR__.'/../.testing/phase8-concurrency.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "WIDGET MYSQL CONCURRENCY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    Cache::store('database')->forget('widget:'.$actor->institutionId.':'.$actor->userId.':'.$actor->deviceId);
    DB::table('collab_chat_conversations')->where('institution_id', $institution->id)->delete();
    DB::table('users')->where('institution_id', $institution->id)->delete();
    DB::table('institutions')->where('id', $institution->id)->delete();
    foreach (array_merge([$file], glob($file.'.*')) as $p) {
        if (is_file($p)) {
            unlink($p);
        }
    }
}
