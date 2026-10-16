<?php

declare(strict_types=1);
require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\Institutions\Models\Institution;
use Modules\TeamChat\Services\ChatAttachments;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\SpaceCommands;
use Symfony\Component\Process\Process;

config(['filesystems.disks.teamchat_private.root' => __DIR__.'/../.testing/uploads-race']);
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
    $actor = new ActorContext($f['tenant'], $v['user'], $v['device'], $v['session'], 'upload-race', AuthenticationLevel::Mfa);
    $upload = UploadedFile::fake()->createWithContent('race.txt', 'Concurrent private attachment bytes.');
    file_put_contents($argv[2].'.ready.'.$index, 'ready');
    while (! is_file($argv[2].'.go')) {
        usleep(1000);
    }
    $start = hrtime(true);
    try {
        $files = app(ChatAttachments::class);
        $result = match ($job['type']) {
            'prepare' => $files->prepare($actor, $job['conversation'], 'same-upload', 'race.txt', 'document', 'text/plain', $upload->getSize(), hash_file('sha256', $upload->getPathname()))['id'],
            'upload' => $files->upload($actor, $job['id'], $upload)['id'],
            'send' => app(ChatCommands::class)->send($actor, $job['conversation'], $job['command'], '', [$job['id']]),
            'remove' => app(SpaceCommands::class)->member($actor, 'conversation', $job['conversation'], 'remove-upload-race', $f['actors'][1]['user'], 'remove'),
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
$actors = User::factory()->count(3)->create(['institution_id' => $institution->id, 'role' => 'member'])->map(fn ($u) => realtimeActor($u))->all();
$file = __DIR__.'/../.testing/uploads-race-'.bin2hex(random_bytes(6)).'.json';
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
    $direct = app(ChatCommands::class)->direct($actors[0], $actors[1]->userId);
    $r = $run('same_preparation', array_fill(0, 6, ['type' => 'prepare', 'conversation' => $direct]));
    spaceCheck(count(array_unique(array_column($r, 'result'))) === 1, 'one upload identity');
    $id = $r[0]['result'];
    $r = $run('same_upload', array_fill(0, 6, ['type' => 'upload', 'id' => $id]));
    spaceCheck(count(array_column($r, 'result')) === 6, 'six uploads succeed idempotently');
    spaceCheck(DB::table('collab_chat_uploads')->count() === 1 && Storage::disk('teamchat_private')->get($institution->id.'/'.$id.'/content') === 'Concurrent private attachment bytes.', 'one complete private file');
    $r = $run('same_attachment_message', array_fill(0, 6, ['type' => 'send', 'id' => $id, 'conversation' => $direct, 'command' => 'same-message']));
    spaceCheck(count(array_unique(array_column($r, 'result'))) === 1 && count(array_column($r, 'result')) === 6, 'one committed message');
    spaceCheck(DB::table('collab_chat_messages')->count() === 1 && DB::table('collab_notification_intents')->count() === 1, 'no duplicate message or notification');
    spaceCheck(DB::table('collab_events')->count() === 2 && DB::table('collab_outbox')->count() === 2, 'one creation and one message event');
    $group = app(SpaceCommands::class)->create($actors[0], 'group', 'race-group', 'Race', '', [$actors[1]->userId, $actors[2]->userId]);
    $u = UploadedFile::fake()->createWithContent('race.txt', 'Concurrent private attachment bytes.');
    $pending = app(ChatAttachments::class)->prepare($actors[1], $group, 'race-upload', 'race.txt', 'document', 'text/plain', $u->getSize(), hash_file('sha256', $u->getPathname()));
    $run('upload_versus_expulsion', [
        ['type' => 'upload', 'id' => $pending['id'], 'actor' => 1],
        ['type' => 'remove', 'conversation' => $group],
    ]);
    foreach (['status', 'send'] as $op) {
        try {
            if ($op === 'status') {
                app(ChatAttachments::class)->status($actors[1], $pending['id']);
            } else {
                app(ChatCommands::class)->send($actors[1], $group, 'after-revocation', '', [$pending['id']]);
            }
            throw new RuntimeException('Revoked participant still authorized');
        } catch (ContractException) {
            spaceCheck(true, 'expelled user denied '.$op);
        }
    }
    spaceCheck(DB::table('collab_chat_uploads')->where('id', $pending['id'])->value('message_id') === null, 'raced upload cannot become a message after revocation');
    file_put_contents(__DIR__.'/../.testing/phase6-concurrency.json', json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "ATTACHMENTS MYSQL CONCURRENCY PASS\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    Storage::disk('teamchat_private')->deleteDirectory((string) $institution->id);
    DB::table('collab_chat_conversations')->where('institution_id', $institution->id)->delete();
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
