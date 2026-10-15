<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $asset = realpath(__DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $public = realpath(__DIR__.'/../public').DIRECTORY_SEPARATOR;
    if ($asset && str_starts_with($asset, $public) && is_file($asset) && pathinfo($asset, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
}
// Dedicated local-only fixture/server. It never reads a production DSN.
require __DIR__.'/collaboration-runtime.php';

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Institutions\Models\Institution;

if (str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) !== str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql'))) {
    throw new RuntimeException('Wrong test datadir');
}
config(['app.env' => 'local', 'app.debug' => false, 'app.url' => 'http://127.0.0.1:33319',
    'session.driver' => 'database', 'session.cookie' => 'mca_teamchat_test', 'session.secure' => false,
    'cache.default' => 'file',
    'reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => 33318,
    'reverb.apps.apps.0.key' => 'local-test-key', 'reverb.apps.apps.0.secret' => 'local-test-secret', 'reverb.apps.apps.0.app_id' => 'local-test',
    'reverb.apps.apps.0.allowed_origins' => ['127.0.0.1', 'localhost'],
    'broadcasting.connections.reverb.key' => 'local-test-key', 'broadcasting.connections.reverb.secret' => 'local-test-secret',
    'broadcasting.connections.reverb.app_id' => 'local-test', 'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    'broadcasting.connections.reverb.options.port' => 33318, 'broadcasting.connections.reverb.options.scheme' => 'http', 'broadcasting.connections.reverb.options.useTLS' => false,
    'teamchat.reverb_host' => '127.0.0.1', 'teamchat.reverb_port' => 33318, 'teamchat.reverb_scheme' => 'http']);
$fixture = __DIR__.'/../.testing/teamchat-browser.json';
$mode = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? 'seed');
if ($mode === 'http') {
    if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit;
    }
    $asset = realpath(__DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $public = realpath(__DIR__.'/../public').DIRECTORY_SEPARATOR;
    if ($asset && str_starts_with($asset, $public) && is_file($asset) && pathinfo($asset, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
    $app->handleRequest(Illuminate\Http\Request::capture());
    exit;
}
if ($mode === 'reverb') {
    Artisan::call('reverb:start', ['--host' => '127.0.0.1', '--port' => 33318]);
    exit;
}
if ($mode === 'code') {
    echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    exit;
}
if ($mode === 'seed') {
    if (DB::table('users')->count() !== 0 || is_file($fixture)) {
        throw new RuntimeException('Test DB must be empty; do not overwrite fixtures.');
    }
    Artisan::call('migrate', ['--force' => true]);
    $i = Institution::factory()->create(['name' => 'MCA · TeamChat Local']);
    $ids = [];
    foreach (['alice' => 'Ana Rivera', 'bob' => 'Bruno Martínez'] as $key => $name) {
        $u = User::factory()->create(['institution_id' => $i->id, 'role' => 'member', 'name' => $name, 'email' => $key.'@teamchat.invalid', 'password' => Illuminate\Support\Facades\Hash::make('LocalChat-Test-2026!'), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $ids[$key] = $u->id;
    }
    file_put_contents($fixture, json_encode(['institution' => $i->id] + $ids, JSON_THROW_ON_ERROR));
    echo "Two isolated fixture users ready\n";
    exit;
}
$f = json_decode(file_get_contents($fixture), true, 16, JSON_THROW_ON_ERROR);
if ($mode === 'drain') {
    while (true) {
        for ($n = 0; $n < 100 && app(OutboxWorker::class)->runOne($f['institution']); $n++) {
        }usleep(200000);
    }
}
if ($mode === 'history') {
    $d = DB::table('collab_devices')->where('user_id', $f['alice'])->whereNull('revoked_at')->firstOrFail();
    $actor = new \Modules\CollaborationCore\Data\ActorContext($f['institution'], $f['alice'], $d->id, $d->session_hash, 'history-fixture', \Modules\CollaborationCore\Enums\AuthenticationLevel::Mfa);
    $service = app(\Modules\TeamChat\Services\ChatCommands::class);
    $id = $service->direct($actor, $f['bob']);
    for ($n = 0; $n < 40; $n++) {
        $service->send($actor, $id, ($argv[2] ?? 'history').'-'.$n, 'Historial '.$n.' · mensaje de prueba local.');
    }
    echo "Forty persisted fixture messages\n";
    exit;
}
if ($mode === 'verify') {
    $deadline = microtime(true) + 45;
    while (DB::table('collab_outbox')->where('institution_id', $f['institution'])->whereIn('status', ['pending', 'claimed'])->exists()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker did not drain pending events');
        }
        usleep(200000);
    }
    if (DB::table('collab_outbox')->where('institution_id', $f['institution'])->where('status', 'failed')->exists()) {
        throw new RuntimeException('Failed outbox events');
    }
    app(\Modules\CollaborationRealtime\Services\NotificationDelivery::class)->reconcile($f['institution'], 1000);
    $accepted = DB::table('collab_notification_delivery')->where('institution_id', $f['institution'])->where('status', 'transport_accepted')->count();
    if ($accepted < 1) {
        throw new RuntimeException('No Core notification transport receipts');
    }
    echo 'PASS real Reverb outbox drained; Core notifications transport accepted: '.$accepted."\n";
    exit;
}
if ($mode === 'cleanup') {
    DB::table('collab_chat_conversations')->where('institution_id', $f['institution'])->delete();
    DB::table('sessions')->whereIn('user_id', [$f['alice'], $f['bob']])->delete();
    DB::table('users')->where('institution_id', $f['institution'])->delete();
    DB::table('audit_logs')->where('institution_id', $f['institution'])->delete();
    DB::table('institutions')->where('id', $f['institution'])->delete();
    unlink($fixture);
    echo "Fixture removed\n";
    exit;
}
throw new RuntimeException('Unknown mode');
