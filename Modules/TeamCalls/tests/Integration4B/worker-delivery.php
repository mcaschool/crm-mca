<?php

declare(strict_types=1);

/*
| Fase 4B · camino COMPLETO de entrega con el worker real de CollaborationRealtime, fuera de cualquier
| transacción de prueba (OutboxWorker exige una conexión confirmada):
|   llamada real → outbox → worker (claim/lease) → ReverbPublisher (audiencia y políticas reales) → aviso;
|   revocación real (DeviceRegistry/ApplicationAccess) → outbox → worker → RevocationDelivery → consumidor
|   de TeamCalls (recibo), fallo temporal con reintento y lease perdido.
| Dobles: el servidor de medios (RecordingCollaboration) y, salvo TEAMCALLS_REVERB_REAL=1, el envío de red
| del aviso (WakeTransport), que se registra. Con TEAMCALLS_REVERB_REAL=1 se usa el transporte real.
|
| Uso (base de datos AISLADA que termine en _test; se recrea entera):
|   APP_ENV=testing DB_DATABASE=crm_mca_integration_4b_worker_test php Modules/TeamCalls/tests/Integration4B/worker-delivery.php
*/

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationRealtime\Data\EventEnvelope;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\CollaborationRealtime\Integration\WakeTransport;
use Modules\CollaborationRealtime\Services\DeviceChannels;
use Modules\CollaborationRealtime\Services\OutboxWorker;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Contracts\PushNotifier;
use Modules\TeamCalls\Integration\Collaboration\TeamCallsRevocationConsumer;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\RecordingCollaboration;
use Modules\TeamCalls\Tests\Support\TC;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! app()->environment('testing') || $database !== 'crm_mca_integration_4b_worker_test') {
    fwrite(STDERR, "refused: requires APP_ENV=testing and the dedicated crm_mca_integration_4b_worker_test database\n");
    exit(2);
}
Artisan::call('migrate:fresh', ['--force' => true]);

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
}

$wakes = new ArrayObject;
$real = getenv('TEAMCALLS_REVERB_REAL') === '1';
if (! $real) {
    app()->instance(WakeTransport::class, new class($wakes) implements WakeTransport
    {
        public function __construct(private ArrayObject $wakes) {}

        public function send(string $privateChannel): void
        {
            $this->wakes[] = $privateChannel;
        }
    });
}
TC::$rec = new RecordingCollaboration;
TC::$rec->confirms = true; // conexiones confirmadas por el servidor de medios (como con LiveKit)
app()->instance(MediaRoomService::class, TC::$rec);
app()->instance(PushNotifier::class, TC::$rec);

$institution = Institution::factory()->create();
app(CurrentInstitution::class)->set($institution->id);
$mk = fn (array $o = []) => User::factory()->create($o + ['institution_id' => $institution->id, 'role' => 'admissions', 'status' => 'active']);
[$ana, $beto, $carla, $admin] = [$mk(), $mk(), $mk(), $mk(['role' => 'admin'])];
$actors = [];
foreach ([$ana, $beto, $carla] as $u) {
    $actors[$u->id] = RealCollaboration::actor($u);
}
$gw = app(CallsGateway::class);
$cmd = fn (string $k) => new CommandMetadata(str_pad($k, 12, '0'), 'corr-'.substr(md5($k), 0, 10));
$drain = function () use ($institution): int {
    $n = 0;
    while ($n < 500 && app(OutboxWorker::class)->runOne($institution->id)) {
        $n++;
    }

    return $n;
};
$state = fn (string $id, int $u) => CallParticipant::query()->where('call_id', $id)->where('user_id', $u)->first()?->state->value;

// 1. Eventos de llamada: el worker entrega a los dispositivos autorizados (participantes), una sola vez.
$s = $gw->start($actors[$ana->id], CallTarget::users([$beto->id, $carla->id]), CallMedia::Video, $cmd('w-start-0001'));
foreach ([$beto, $carla] as $u) {
    $gw->accept($actors[$u->id], $s->id, $cmd('w-acc-'.$u->id));
    $gw->join($actors[$u->id], $s->id, $cmd('w-join-'.$u->id));
}
$gw->join($actors[$ana->id], $s->id, $cmd('w-join-host'));
foreach ([$ana, $beto, $carla] as $u) {
    app(\Modules\TeamCalls\Services\CallService::class)->applyMediaEvent($s->id, 'evt-'.$u->id, 'joined', (int) $u->id, $actors[$u->id]->deviceId, 'PA_'.$u->id);
}
$pending = DB::table('collab_outbox')->where('institution_id', $institution->id)->where('status', 'pending')->count();
$processed = $drain();
check($pending > 0 && $processed >= $pending, "worker processes the call.* outbox ({$processed} rows)");
check(DB::table('collab_outbox')->where('institution_id', $institution->id)->whereNotIn('status', ['published', 'suppressed', 'expired'])->count() === 0, 'no outbox row left pending or failed');
$channels = array_map(fn ($a) => app(DeviceChannels::class)->name($a), $actors);
if (! $real) {
    check(count($wakes) > 0 && array_diff(array_unique($wakes->getArrayCopy()), array_values($channels)) === [], 'wake-ups only to participant device channels');
    $before = count($wakes);
    $drain();
    check(count($wakes) === $before, 'second drain does not re-send (transport receipts)');
}
$outsider = $mk();
check(! in_array(app(DeviceChannels::class)->name(RealCollaboration::actor($outsider)), $wakes->getArrayCopy(), true), 'outsider device never woken');

// 2. Revocación de dispositivo: durable, entregada por el worker al consumidor de TeamCalls.
$room = Call::query()->findOrFail($s->id)->room_name;
app(DeviceRegistry::class)->revoke($actors[$beto->id], $actors[$beto->id]->deviceId);
$drain();
check($state($s->id, $beto->id) === 'left', 'device revocation → participant left via worker delivery');
check(DB::table('collab_revocation_receipts')->where('institution_id', $institution->id)->count() === 1, 'one consumer receipt');
check(Call::query()->findOrFail($s->id)->status->value === 'active' && $state($s->id, $carla->id) === 'joined', 'other participants continue');
if (! $real) {
    $wakesBefore = count($wakes);
    $gw->invite($actors[$ana->id], $s->id, [$outsider->id], $cmd('w-invite-01'));
    $drain();
    $newWakes = array_slice($wakes->getArrayCopy(), $wakesBefore);
    check(! in_array($channels[$beto->id], $newWakes, true), 'revoked device is not woken for later events');
}

// 3. Fallo temporal del consumidor: queda pendiente con reintento y se recupera.
$inner = app(TeamCallsRevocationConsumer::class);
$fails = 1;
app()->instance(RevocationConsumer::class, new class($inner, $fails) implements RevocationConsumer
{
    public function __construct(private RevocationConsumer $inner, private int $fails) {}

    public function consume(EventEnvelope $event): void
    {
        if ($this->fails-- > 0) {
            throw new RuntimeException('temporary failure');
        }
        $this->inner->consume($event);
    }
});
app(ApplicationAccess::class)->set($admin, $carla, 'collaboration', false);
$drain();
$row = DB::table('collab_outbox')->join('collab_events', 'collab_events.id', '=', 'collab_outbox.event_id')
    ->where('collab_outbox.institution_id', $institution->id)->where('collab_events.envelope', 'like', '%collaboration.access.revoked%')
    ->select('collab_outbox.*')->first();
check($row !== null && $row->status === 'pending' && (int) $row->attempts === 1, 'consumer failure keeps the revocation pending (attempt 1)');
check(CallParticipant::query()->where('call_id', $s->id)->where('user_id', $carla->id)->value('revoked_at') === null, 'failed access revocation left no expulsion (only its device revocation applied)');
// Vence el backoff del reintento (sin mover el reloj: la llamada sigue viva).
DB::table('collab_outbox')->where('event_id', $row->event_id)->update(['available_at' => now()->subSecond()]);
$drain();
check($state($s->id, $carla->id) === 'left' && CallParticipant::query()->where('call_id', $s->id)->where('user_id', $carla->id)->value('revoked_at') !== null,
    'retry delivers: access revocation expels');

// 4. Lease perdido tras confirmar: el reclamo vencido se reprocesa sin repetir efectos (recibo).
$receipts = DB::table('collab_revocation_receipts')->count();
$events = DB::table('collab_call_events')->where('call_id', $s->id)->where('type', 'call.participant.revoked')->count();
DB::table('collab_outbox')->where('event_id', $row->event_id)->update(['status' => 'claimed', 'claim' => 'lost-worker', 'lease_until' => now()->subMinute()]);
$drain();
check(DB::table('collab_revocation_receipts')->count() === $receipts
    && DB::table('collab_call_events')->where('call_id', $s->id)->where('type', 'call.participant.revoked')->count() === $events,
    'expired lease reprocessed without duplicate effects');
check(DB::table('collab_outbox')->where('event_id', $row->event_id)->value('status') === 'published', 'reclaimed revocation marked published');

check(Call::query()->findOrFail($s->id)->room_name !== $room && in_array($room, TC::$rec->closed, true), 'room rotated and old room closed after commit');

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);
