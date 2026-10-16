<?php

declare(strict_types=1);

/*
| Fase 4B · entrega REAL por WebSocket de los eventos de llamada: servidor Reverb local (reverb:start),
| clientes WebSocket reales por dispositivo, canal privado firmado por RealtimeController, worker real del
| outbox con ReverbPublisher + ReverbWakeTransport reales. Comprueba que el aviso
| `collaboration.sync.required` llega a los dispositivos participantes, no a ajenos ni a revocados, y que
| el SyncFeed que el aviso dispara devuelve los call.* autorizados.
|
| Uso (base AISLADA, se recrea):
|   APP_ENV=testing DB_DATABASE=crm_mca_integration_4b_worker_test php Modules/TeamCalls/tests/Integration4B/reverb-calls.php
*/

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Http\RealtimeController;
use Modules\CollaborationRealtime\Services\DeviceChannels;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\RecordingCollaboration;
use Modules\TeamCalls\Tests\Support\TC;
use Symfony\Component\Process\Process;

const RV_PORT = 33328;
$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! app()->environment('testing') || $database !== 'crm_mca_integration_4b_worker_test') {
    fwrite(STDERR, "refused: requires APP_ENV=testing and the dedicated crm_mca_integration_4b_worker_test database\n");
    exit(2);
}
// Credenciales FICTICIAS solo para este servidor local de pruebas.
config(['reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => RV_PORT,
    'reverb.apps.apps.0.key' => 'tc-local-key', 'reverb.apps.apps.0.secret' => 'tc-local-secret-not-real', 'reverb.apps.apps.0.app_id' => 'tc-local',
    'reverb.apps.apps.0.allowed_origins' => ['localhost'],
    'broadcasting.connections.reverb.key' => 'tc-local-key', 'broadcasting.connections.reverb.secret' => 'tc-local-secret-not-real',
    'broadcasting.connections.reverb.app_id' => 'tc-local', 'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    'broadcasting.connections.reverb.options.port' => RV_PORT, 'broadcasting.connections.reverb.options.scheme' => 'http',
    'broadcasting.connections.reverb.options.useTLS' => false]);
if (($argv[1] ?? '') === 'server') {
    Artisan::call('reverb:start', ['--host' => '127.0.0.1', '--port' => RV_PORT]);
    exit;
}

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
    $failures += $ok ? 0 : 1;
}

function rvRead($socket, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($socket, $length - strlen($data));
        if ($part === false || $part === '') {
            throw new RuntimeException('timeout');
        }
        $data .= $part;
    }

    return $data;
}

/** @return array<string,mixed>|null siguiente trama de texto (null si no llega a tiempo) */
function rvReceive($socket, float $seconds = 3.0): ?array
{
    stream_set_timeout($socket, (int) ceil($seconds));
    try {
        $head = rvRead($socket, 2);
    } catch (RuntimeException) {
        return null;
    }
    $length = ord($head[1]) & 127;
    if ($length === 126) {
        $length = unpack('n', rvRead($socket, 2))[1];
    }

    return json_decode(rvRead($socket, $length), true, 32, JSON_THROW_ON_ERROR);
}

function rvSend($socket, array $message): void
{
    $text = json_encode($message, JSON_THROW_ON_ERROR);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < strlen($text); $i++) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    $n = strlen($text);
    fwrite($socket, chr(129).($n < 126 ? chr(128 | $n) : chr(254).pack('n', $n)).$mask.$masked);
}

/** Conecta y se suscribe al canal privado del dispositivo del actor (firma real del servidor). */
function rvConnect(ActorContext $actor)
{
    $socket = stream_socket_client('tcp://127.0.0.1:'.RV_PORT, $errno, $error, 2) ?: throw new RuntimeException($error);
    fwrite($socket, "GET /app/tc-local-key?protocol=7&client=teamcalls-test&version=1 HTTP/1.1\r\nHost: 127.0.0.1:".RV_PORT
        ."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))
        ."\r\nSec-WebSocket-Version: 13\r\nOrigin: http://localhost\r\n\r\n");
    stream_set_timeout($socket, 3);
    $headers = '';
    while (! str_ends_with($headers, "\r\n\r\n")) {
        $headers .= rvRead($socket, 1);
    }
    if (! str_contains($headers, ' 101 ')) {
        throw new RuntimeException('handshake rejected');
    }
    $hello = rvReceive($socket);
    $socketId = json_decode((string) $hello['data'], true)['socket_id'];
    $channel = app(DeviceChannels::class)->name($actor);
    $request = Request::create('/api/v1/collaboration/realtime/auth', 'POST', ['socket_id' => $socketId, 'channel_name' => $channel]);
    $request->attributes->set('collaboration.actor', $actor);
    $auth = app(RealtimeController::class)->authorize($request, app(DeviceChannels::class), app(BroadcastManager::class))->getData(true);
    rvSend($socket, ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth['auth']]]);
    $ok = rvReceive($socket);
    if (($ok['event'] ?? '') !== 'pusher_internal:subscription_succeeded') {
        throw new RuntimeException('subscription rejected');
    }

    return $socket;
}

/** ¿Llega un aviso de sincronización a este socket? (ignora pings) */
function rvWoken($socket, float $seconds = 3.0): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $frame = rvReceive($socket, max(0.5, $deadline - microtime(true)));
        if ($frame === null) {
            return false;
        }
        if (($frame['event'] ?? '') === 'collaboration.sync.required') {
            return true;
        }
    }

    return false;
}

Artisan::call('migrate:fresh', ['--force' => true]);
$command = [PHP_BINARY];
$server = new Process([...$command, __FILE__, 'server'], $root, timeout: null);
$server->start();
$deadline = microtime(true) + 20;
do {
    $probe = @stream_socket_client('tcp://127.0.0.1:'.RV_PORT, $e1, $e2, .2);
    usleep(200000);
} while (! $probe && microtime(true) < $deadline);
if (! $probe) {
    fwrite(STDERR, "Reverb did not start\n".$server->getErrorOutput());
    exit(1);
}
fclose($probe);

try {
    TC::$rec = new RecordingCollaboration;
    app()->instance(MediaRoomService::class, TC::$rec);
    app()->instance(PushNotifier::class, TC::$rec);
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $mk = fn () => User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions', 'status' => 'active']);
    [$ana, $beto, $carla] = [$mk(), $mk(), $mk()];
    $a = RealCollaboration::actor($ana);
    $b = RealCollaboration::actor($beto);
    $c = RealCollaboration::actor($carla);
    $wsB = rvConnect($b);
    $wsC = rvConnect($c);
    check(true, 'real Reverb: private device channels signed and subscribed');

    $gw = app(CallsGateway::class);
    $s = $gw->start($a, CallTarget::users([$beto->id]), CallMedia::Audio, new CommandMetadata('rv-start-0001', 'rv-corr-01'));
    $n = 0;
    while ($n < 100 && app(OutboxWorker::class)->runOne($institution->id)) {
        $n++;
    }
    check(rvWoken($wsB), 'invitee device receives collaboration.sync.required over real WebSocket');
    check(! rvWoken($wsC, 1.5), 'non-participant device receives nothing');
    $events = collect(app(SyncFeed::class)->read($b, new PageRequest(null, 100))->events)->filter(fn ($e) => $e->aggregateId === $s->id);
    check($events->pluck('type')->contains('call.ringing'), 'sync after the wake returns call.ringing for the invitee');

    // Dispositivo revocado: no recibe más avisos aunque siga conectado al socket (se vacía lo ya recibido).
    while (rvWoken($wsB, 1.0)) {
    }
    app(DeviceRegistry::class)->revoke($b, $b->deviceId);
    while (app(OutboxWorker::class)->runOne($institution->id)) {
    }
    $gw->cancel($a, $s->id, new CommandMetadata('rv-cancel-001', 'rv-corr-02'));
    while (app(OutboxWorker::class)->runOne($institution->id)) {
    }
    check(! rvWoken($wsB, 1.5), 'revoked device is not woken for later call events');
} finally {
    $server->stop(2);
}
echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);
