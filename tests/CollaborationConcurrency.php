<?php

declare(strict_types=1);
// Standalone multi-process contract check. Never run against a shared database.
require __DIR__.'/collaboration-runtime.php';
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\NotificationDispatcher;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Data\ActorContext;
use Modules\CollaborationCore\Data\AuthenticatedSessionContext;
use Modules\CollaborationCore\Data\DeviceRegistration;
use Modules\CollaborationCore\Data\InstitutionContext;
use Modules\CollaborationCore\Data\NotificationAudience;
use Modules\CollaborationCore\Data\NotificationIntent;
use Modules\CollaborationCore\Enums\AuthenticationLevel;
use Modules\CollaborationCore\Enums\DevicePlatform;
use Modules\Institutions\Models\Institution;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    $payload = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $deadline = microtime(true) + 20;
    while (! is_file($payload['barrier'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(20000);
    }
    $session = new AuthenticatedSessionContext($payload['institution'], $payload['user'], $payload['session'], 'race_request', AuthenticationLevel::Mfa);
    $device = app(DeviceRegistry::class)->register($session, new DeviceRegistration(DevicePlatform::Web, 'Race'));
    $actor = new ActorContext($session->institutionId, $session->userId, $device->id, $session->sessionId, $session->requestId, $session->authenticationLevel);
    app(PresenceService::class)->heartbeat($actor);
    app(NotificationDispatcher::class)->enqueue(new InstitutionContext($session->institutionId),
        new NotificationIntent($payload['notification'], 'test.race', 'resource_race', 'event_race', $payload['notification'], new DateTimeImmutable($payload['expires'])),
        new NotificationAudience($session->institutionId, [$session->userId]));
    echo $device->id;
    exit(0);
}
$institution = Institution::factory()->create();
$user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'member']);
$barrier = __DIR__.'/../.testing/barrier-'.bin2hex(random_bytes(6));
$payload = json_encode(['institution' => (int) $institution->id, 'user' => (int) $user->id, 'session' => hash('sha256', random_bytes(32)),
    'notification' => (string) Illuminate\Support\Str::ulid(), 'expires' => gmdate('c', time() + 300), 'barrier' => $barrier], JSON_THROW_ON_ERROR);
$processes = [];
try {
    for ($i = 0; $i < 4; $i++) {
        $p = new Process([PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=pdo_mysql', '-d', 'extension=fileinfo', __FILE__, 'worker', $payload], dirname(__DIR__));
        $p->setTimeout(60);
        $p->start();
        $processes[] = $p;
    }
    file_put_contents($barrier, 'go');
    $ids = [];
    foreach ($processes as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException($p->getErrorOutput().$p->getOutput());
        }
        $ids[] = trim($p->getOutput());
    }
    if (count(array_unique($ids)) !== 1) {
        throw new RuntimeException('Duplicate devices');
    }
    foreach (['collab_devices', 'collab_presence', 'collab_presence_devices', 'collab_notification_intents'] as $table) {
        if (DB::table($table)->where('institution_id', $institution->id)->count() !== 1) {
            throw new RuntimeException('Duplicate state: '.$table);
        }
    }
    echo "PASS: 4 concurrent processes; 1 device, 1 aggregate, 1 heartbeat, 1 notification; all responses agree.\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop();
        }
    }
    if (is_file($barrier)) {
        unlink($barrier);
    }
    User::query()->where('institution_id', $institution->id)->delete();
    $institution->delete();
}
