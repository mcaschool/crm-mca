<?php

declare(strict_types=1);

require __DIR__.'/collaboration-runtime.php';
require __DIR__.'/RealtimeFixtures.php';

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Contracts\TransactionalEventRecorder;
use Modules\CollaborationRealtime\Data\DeliveryAudience;
use Modules\CollaborationRealtime\Http\RealtimeController;
use Modules\CollaborationRealtime\Services\DeviceChannels;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Institutions\Models\Institution;
use Symfony\Component\Process\Process;

config(['reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => 33318,
    'reverb.apps.apps.0.key' => 'local-test-key', 'reverb.apps.apps.0.secret' => 'local-test-secret', 'reverb.apps.apps.0.app_id' => 'local-test',
    'reverb.apps.apps.0.allowed_origins' => ['localhost'],
    'broadcasting.connections.reverb.key' => 'local-test-key', 'broadcasting.connections.reverb.secret' => 'local-test-secret',
    'broadcasting.connections.reverb.app_id' => 'local-test', 'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    'broadcasting.connections.reverb.options.port' => 33318, 'broadcasting.connections.reverb.options.scheme' => 'http',
    'broadcasting.connections.reverb.options.useTLS' => false]);
if (($argv[1] ?? '') === 'server') {
    Artisan::call('reverb:start', ['--host' => '127.0.0.1', '--port' => 33318]);
    exit;
}
function wsCheck(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
function wsReadBytes($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($socket, $length - strlen($data));
        if ($part === false || $part === '') {
            throw new RuntimeException('WebSocket timeout/close');
        }
        $data .= $part;
    }

    return $data;
}
function wsReceive($socket): array
{
    $head = wsReadBytes($socket, 2);
    $length = ord($head[1]) & 127;
    if ($length === 126) {
        $length = unpack('n', wsReadBytes($socket, 2))[1];
    }
    if ($length > 65535 || $length === 127) {
        throw new RuntimeException('Unexpected test frame length');
    }
    $text = wsReadBytes($socket, $length);

    return json_decode($text, true, 16, JSON_THROW_ON_ERROR);
}
function wsSend($socket, array $message): void
{
    $text = json_encode($message, JSON_THROW_ON_ERROR);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < strlen($text); $i++) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    $length = strlen($text);
    fwrite($socket, chr(129).($length < 126 ? chr(128 | $length) : chr(254).pack('n', $length)).$mask.$masked);
}
function wsOpen(string $origin = 'localhost')
{
    $socket = stream_socket_client('tcp://127.0.0.1:33318', $errno, $error, 2);
    if (! $socket) {
        throw new RuntimeException($error);
    }
    stream_set_timeout($socket, 3);
    fwrite($socket, "GET /app/local-test-key?protocol=7&client=test&version=1 HTTP/1.1\r\nHost: 127.0.0.1:33318\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\nOrigin: http://$origin\r\n\r\n");
    $headers = '';
    while (! str_ends_with($headers, "\r\n\r\n")) {
        $headers .= wsReadBytes($socket, 1);
    }
    if (! str_contains($headers, '101')) {
        throw new RuntimeException('Handshake rejected: '.$headers);
    }

    return $socket;
}
function wsSubscribe($socket, $actor): string
{
    $hello = wsReceive($socket);
    wsCheck($hello['event'] === 'pusher:connection_established', 'real Reverb connection established');
    $id = json_decode($hello['data'], true, 8, JSON_THROW_ON_ERROR)['socket_id'];
    $channel = app(DeviceChannels::class)->name($actor);
    $request = Request::create('/api/v1/collaboration/realtime/auth', 'POST', ['socket_id' => $id, 'channel_name' => $channel]);
    $request->attributes->set('collaboration.actor', $actor);
    $auth = app(RealtimeController::class)->authorize($request, app(DeviceChannels::class), app(BroadcastManager::class))->getData(true);
    wsSend($socket, ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth['auth']]]);
    $result = wsReceive($socket);
    wsCheck($result['event'] === 'pusher_internal:subscription_succeeded', 'authorized private subscription accepted');

    return $channel;
}
wsCheck(str_replace('\\', '/', rtrim(DB::selectOne('SELECT @@datadir AS path')->path, '/\\')) === str_replace('\\', '/', realpath(__DIR__.'/../.testing/mysql')), 'dedicated local database');
wsCheck(DB::table('users')->count() === 0, 'no real users');
$command = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
foreach (['mbstring', 'openssl', 'pdo_mysql', 'fileinfo', 'intl', 'gd', 'pdo_sqlite', 'curl'] as $extension) {
    array_push($command, '-d', 'extension='.$extension);
}
$server = new Process([...$command, __FILE__, 'server'], dirname(__DIR__), timeout: null);
$i = Institution::factory()->create();
$u = User::factory()->create(['institution_id' => $i->id, 'role' => 'member']);
$a = realtimeActor($u);
$sockets = [];
try {
    realtimePolicies();
    $server->start();
    $deadline = microtime(true) + 15;
    do {
        $probe = @stream_socket_client('tcp://127.0.0.1:33318', $errno, $error, .1);
        if ($probe) {
            fclose($probe);
            break;
        }
        if (! $server->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException($server->getErrorOutput().$server->getOutput());
        }
        usleep(100000);
    } while (true);
    $socket = wsOpen();
    $sockets[] = $socket;
    $channel = wsSubscribe($socket, $a);
    $cut = app(SyncFeed::class)->bootstrap($a, new PageRequest)->cursor;
    DB::transaction(fn () => app(TransactionalEventRecorder::class)->append(new InstitutionContext($i->id), realtimeEvent($a, 'ws-event'), new DeliveryAudience($i->id, [$u->id])));
    app(OutboxWorker::class)->runOne($i->id);
    $live = wsReceive($socket);
    wsCheck($live['event'] === 'collaboration.sync.required' && json_decode($live['data'], true) === ['v' => 1], 'real broker sends only content-free wake');
    wsCheck(! str_contains(json_encode($live), 'ws-event'), 'no identifiers or domain payload leaked on socket');
    wsCheck(app(SyncFeed::class)->read($a, new PageRequest($cut))->events[0]->id === 'ws-event', 'authorized API recovers event after live hint');
    fclose($socket);
    array_pop($sockets);
    DB::transaction(fn () => app(TransactionalEventRecorder::class)->append(new InstitutionContext($i->id), realtimeEvent($a, 'ws-offline', 2), new DeliveryAudience($i->id, [$u->id])));
    app(OutboxWorker::class)->runOne($i->id);
    $socket = wsOpen();
    $sockets[] = $socket;
    wsSubscribe($socket, $a);
    wsCheck(count(app(SyncFeed::class)->read($a, new PageRequest($cut))->events) === 2, 'reconnect replays offline events and deduplicates by id');
    $bad = wsOpen();
    $sockets[] = $bad;
    wsReceive($bad);
    wsSend($bad, ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => 'local-test-key:forged']]);
    wsCheck(wsReceive($bad)['event'] === 'pusher:error', 'forged subscription rejected by real broker');
    app(DeviceRegistry::class)->revoke($a, $a->deviceId);
    try {
        app(SyncFeed::class)->read($a, new PageRequest($cut));
        throw new RuntimeException('revoked sync accepted');
    } catch (\Modules\CollaborationCore\Exceptions\ContractException $e) {
        wsCheck($e->errorCode === 'device_revoked', 'active subscription cannot bypass revocation');
    }
    DB::transaction(fn () => app(TransactionalEventRecorder::class)->append(new InstitutionContext($i->id), realtimeEvent($a, 'ws-revoked', 3), new DeliveryAudience($i->id, [$u->id])));
    app(OutboxWorker::class)->runOne($i->id);
    wsCheck(DB::table('collab_outbox')->where('event_id', 'ws-revoked')->value('status') === 'suppressed', 'revoked connected device excluded from subsequent publication');
    $originRejected = false;
    try {
        $origin = wsOpen('untrusted.invalid');
        $sockets[] = $origin;
        $response = wsReceive($origin);
        $originRejected = $response['event'] === 'pusher:error';
    } catch (RuntimeException) {
        $originRejected = true;
    }
    wsCheck($originRejected, 'unapproved Origin rejected');
    echo "REAL REVERB CONNECTION RECONNECTION AND REVOCATION PASS\n";
} finally {
    foreach ($sockets as $socket) {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
    $server->stop();
    DB::table('users')->where('id', $u->id)->delete();
    DB::table('institutions')->where('id', $i->id)->delete();
}
