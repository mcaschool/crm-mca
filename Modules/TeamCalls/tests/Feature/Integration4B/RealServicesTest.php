<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\CollaborationCore\Contracts\CallsGateway;
use Modules\CollaborationCore\Contracts\DeviceRegistry;
use Modules\CollaborationCore\Contracts\PresenceService;
use Modules\CollaborationCore\Data\CallTarget;
use Modules\CollaborationCore\Data\CommandMetadata;
use Modules\CollaborationCore\Data\ConversationRef;
use Modules\CollaborationCore\Data\PageRequest;
use Modules\CollaborationCore\Enums\CallMedia;
use Modules\CollaborationCore\Enums\CallStatus;
use Modules\CollaborationCore\Enums\ConversationType;
use Modules\CollaborationCore\Enums\ManualPresence;
use Modules\CollaborationCore\Enums\ParticipantState;
use Modules\CollaborationCore\Exceptions\ContractException;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\CollaborationRealtime\Contracts\SyncFeed;
use Modules\CollaborationRealtime\Integration\RevocationConsumer;
use Modules\CollaborationRealtime\Services\EventCodec;
use Modules\CollaborationRealtime\Services\RevocationDelivery;
use Modules\TeamCalls\Integration\Collaboration\TeamCallsRevocationConsumer;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\TC;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\SpaceCommands;

/*
| Fase 4B · integración con los servicios REALES de CollaborationCore (actor, dispositivos, acceso,
| presencia, revocaciones), CollaborationRealtime (recorder transaccional, SyncFeed, entrega de
| revocaciones con recibos) y TeamChat (ConversationDirectory, conversaciones y grupos) sobre MySQL.
| Dobles solo para el servidor de medios y el push (RecordingCollaboration). La entrega por el WORKER
| (claim, lease, reintentos) y el envío a Reverb se prueban fuera de la transacción de prueba: ver
| tests/Integration4B/worker.php y la prueba con Reverb real.
*/

function rc_cmd(string $key): CommandMetadata
{
    return new CommandMetadata($key, 'corr-'.substr(md5($key), 0, 12));
}

function rc_code(callable $fn): string
{
    try {
        $fn();
    } catch (ContractException $e) {
        return $e->errorCode;
    }

    return 'sin-error';
}

/** Entrega las revocaciones pendientes con el servicio real de Realtime (lo que hace el worker tras reclamarlas). */
function rc_deliver(int $institutionId): int
{
    return RealCollaboration::deliver($institutionId);
}

function rc_state(string $callId, int $userId): ?string
{
    return CallParticipant::query()->where('call_id', $callId)->where('user_id', $userId)->first()?->state->value;
}

beforeEach(function () {
    RealCollaboration::setUp();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
    $this->gw = app(CallsGateway::class);
});

/** Llamada grupal activa con todos unidos por la puerta común (dispositivos reales de Core). */
function rc_active(object $t, array $users, bool $media = false): array
{
    TC::$rec->confirms = $media;
    $actors = [];
    foreach ($users as $u) {
        $actors[$u->id] = RealCollaboration::actor($u);
    }
    $host = $users[0];
    $s = $t->gw->start($actors[$host->id], CallTarget::users(array_map(fn ($u) => (int) $u->id, array_slice($users, 1))), CallMedia::Video, rc_cmd('act-'.$host->id.'-'.count($users)));
    foreach (array_slice($users, 1) as $u) {
        $t->gw->accept($actors[$u->id], $s->id, rc_cmd('acc-'.$u->id.'-'.$s->id));
        $t->gw->join($actors[$u->id], $s->id, rc_cmd('joi-'.$u->id.'-'.$s->id));
    }
    $t->gw->join($actors[$host->id], $s->id, rc_cmd('joi-'.$host->id.'-'.$s->id));
    if ($media) { // conexión confirmada por el servidor de medios (como el webhook de LiveKit)
        foreach ($users as $u) {
            TC::service()->applyMediaEvent($s->id, 'evt-'.$u->id.'-'.$s->id, 'joined', (int) $u->id, $actors[$u->id]->deviceId, 'PA_'.$u->id);
        }
    }

    return [$s->id, $actors];
}

it('solo Colaboración: llama por la API definitiva y la heredada sin acceso al CRM, que le sigue cerrado', function () {
    $solo = TC::user($this->inst, ['role' => 'member', 'department' => null]);
    expect($solo->canWorkCrm())->toBeFalse()->and($solo->canAccessApplication('collaboration'))->toBeTrue();

    $res = Http::as($solo)->withHeader('Idempotency-Key', 'solo-http-0001')
        ->postJson('/api/v1/collaboration/calls', ['user_ids' => [$this->beto->id], 'media' => 'audio']);
    $res->assertCreated()->assertJsonPath('data.status', 'ringing')->assertJsonPath('data.initiator_id', $solo->id);
    $id = $res->json('data.id');
    // Repetir la misma orden: misma llamada (idempotencia del inicio).
    Http::as($solo)->withHeader('Idempotency-Key', 'solo-http-0001')
        ->postJson('/api/v1/collaboration/calls', ['user_ids' => [$this->beto->id], 'media' => 'audio'])->assertCreated()->assertJsonPath('data.id', $id);
    Http::as($solo)->withHeader('Idempotency-Key', 'solo-cancel-01')->postJson("/api/v1/collaboration/calls/{$id}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    // La API heredada (la que usa hoy la interfaz) también funciona con el contexto de Core.
    Http::start($solo, $this->carla->id, 'solo-old-0001')->assertCreated();
    Http::as($solo)->get('/dashboard')->assertForbidden();

    // Sin comando idempotente o con identificadores del cliente: rechazado con el formato de Core.
    test()->flushHeaders();
    Http::as($solo)->postJson('/api/v1/collaboration/calls', ['user_ids' => [$this->beto->id], 'media' => 'audio'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    Http::as($solo)->withHeader('Idempotency-Key', 'solo-http-0002')
        ->postJson('/api/v1/collaboration/calls', ['user_ids' => [$this->beto->id], 'media' => 'audio', 'device_id' => 'mine'])
        ->assertStatus(422);
});

it('sin MFA en la sesión, o sin collaboration.use, ni la API definitiva ni la heredada admiten la petición', function () {
    $this->actingAs($this->ana)->getJson('/api/v1/collaboration/calls')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');
    $this->actingAs($this->ana)->getJson(route('collab.calls.index'))->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');

    $admin = TC::user($this->inst, ['role' => 'admin']);
    app(ApplicationAccess::class)->set($admin, $this->beto, 'collaboration', false);
    Http::as($this->beto)->getJson('/api/v1/collaboration/calls')->assertStatus(403);
    Http::get($this->beto, 'index')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('dispositivos de Core: cada sesión es uno; el primero que contesta gana y el otro recibe answered_elsewhere', function () {
    $a = RealCollaboration::actor($this->ana);
    $b1 = RealCollaboration::actor($this->beto, str_repeat('a', 40));
    $b2 = RealCollaboration::actor($this->beto, str_repeat('b', 40));
    expect($b1->deviceId)->not->toBe($b2->deviceId)->and($b1->deviceId)->toMatch('/^[0-9A-Z]{26}$/');

    $s = $this->gw->start($a, CallTarget::users([$this->beto->id]), CallMedia::Audio, rc_cmd('dev-start-01'));
    $this->gw->accept($b1, $s->id, rc_cmd('dev-acc-001'));
    expect(rc_code(fn () => $this->gw->accept($b2, $s->id, rc_cmd('dev-acc-002'))))->toBe('answered_elsewhere');

    // El aviso efímero queda en el outbox real: caduca pronto y excluye el dispositivo que contestó.
    $row = collect(RealCollaboration::events($this->inst->id, 'call', $s->id))->first(fn ($e) => str_contains($e->envelope, 'call.answered_elsewhere'));
    expect($row)->not->toBeNull()->and((bool) $row->ephemeral)->toBeTrue()->and($row->expires_at)->not->toBeNull()
        ->and(json_decode($row->audience, true))->toEqual(['except' => $b1->deviceId, 'users' => [$this->beto->id]]);
});

it('eventos call.*: outbox real dentro de la transacción, versión contigua, entrega por SyncFeed solo a participantes; sin publicación paralela', function () {
    [$id, $actors] = rc_active($this, [$this->ana, $this->beto]);
    $rows = RealCollaboration::events($this->inst->id, 'call', $id);
    expect(array_map(fn ($r) => (int) $r->aggregate_version, $rows))->toBe(range(1, count($rows)))
        ->and(Call::query()->findOrFail($id)->event_sequence)->toBe(count($rows) + 0)
        ->and(TC::$rec->published)->toBe([]); // nada por el puerto propio

    $feed = app(SyncFeed::class);
    $mine = collect($feed->read($actors[$this->beto->id], new PageRequest(null, 100))->events)->filter(fn ($e) => $e->aggregateId === $id);
    $other = collect($feed->read(RealCollaboration::actor($this->carla), new PageRequest(null, 100))->events)->filter(fn ($e) => $e->aggregateId === $id);
    expect($mine->pluck('type')->all())->toContain('call.started', 'call.accepted', 'call.connected')
        ->and($mine->pluck('type')->all())->not->toContain('call.answered_elsewhere') // efímero: no es historial
        ->and($other->all())->toBe([])
        ->and($mine->every(fn ($e) => ! array_key_exists('device_id', $e->data)))->toBeTrue();

    // Una transacción revertida no deja ni la llamada ni sus eventos.
    try {
        DB::transaction(function () use ($actors) {
            $this->gw->start($actors[$this->ana->id], CallTarget::users([$this->carla->id]), CallMedia::Audio, rc_cmd('rollback-key-1'));
            throw new RuntimeException('falla después');
        });
    } catch (RuntimeException) {
    }
    expect(Call::query()->where('idempotency_key', 'rollback-key-1')->exists())->toBeFalse()
        ->and(DB::table('collab_events')->where('envelope', 'like', '%rollback%')->exists())->toBeFalse();
});

it('revocar un dispositivo: deja de operar al instante; el consumidor lo saca, rota la sala y los demás siguen', function () {
    [$id, $actors] = rc_active($this, [$this->ana, $this->beto, $this->carla], media: true);
    $room = Call::query()->findOrFail($id)->room_name;
    $beto = $actors[$this->beto->id];

    app(DeviceRegistry::class)->revoke($beto, $beto->deviceId);
    // 1. Sin esperar al consumidor: ni operaciones ni credenciales nuevas.
    expect(rc_code(fn () => $this->gw->credentials($beto, $id)))->toBe('device_revoked')
        ->and(rc_code(fn () => $this->gw->join($beto, $id, rc_cmd('rev-join-01'))))->toBe('device_revoked');

    // 2. Revocación durable entregada a TeamCalls (con recibo).
    expect(rc_deliver($this->inst->id))->toBeGreaterThanOrEqual(1);
    $call = Call::query()->with('participants')->findOrFail($id);
    expect(rc_state($id, $this->beto->id))->toBe('left')
        ->and($call->status->value)->toBe('active') // Ana y Carla siguen
        ->and(rc_state($id, $this->carla->id))->toBe('joined')
        ->and($call->room_name)->not->toBe($room) // sala rotada: las credenciales anteriores no sirven
        ->and(TC::$rec->closed)->toContain($room)
        ->and(CallEvent::query()->where('call_id', $id)->where('type', 'call.participant.revoked')->where('subject_id', $this->beto->id)->count())->toBe(1);

    // 3. Repetir la entrega no repite efectos (recibo + operaciones idempotentes).
    $event = DB::table('collab_events')->where('envelope', 'like', '%collaboration.device.revoked%')->first();
    app(RevocationDelivery::class)->deliver(app(EventCodec::class)->decode($event)->event);
    expect(CallEvent::query()->where('call_id', $id)->where('type', 'call.participant.revoked')->count())->toBe(1);

    // 4. Desde otro dispositivo válido, Beto puede volver (no es una expulsión).
    $back = $this->gw->join(RealCollaboration::actor($this->beto, str_repeat('n', 40)), $id, rc_cmd('rev-back-01'));
    expect($back->viewerState)->toBe(ParticipantState::Joined);
});

it('retirar collaboration.use: expulsión de sus llamadas, sin credenciales ni nuevas sesiones; los demás siguen', function () {
    [$id, $actors] = rc_active($this, [$this->ana, $this->beto, $this->carla], media: true);
    $admin = TC::user($this->inst, ['role' => 'admin']);
    app(ApplicationAccess::class)->set($admin, $this->carla, 'collaboration', false);

    expect(rc_code(fn () => $this->gw->credentials($actors[$this->carla->id], $id)))->not->toBe('sin-error')
        ->and(rc_code(fn () => RealCollaboration::actor($this->carla, str_repeat('z', 40))))->toBe('forbidden');
    rc_deliver($this->inst->id);
    $carla = CallParticipant::query()->where('call_id', $id)->where('user_id', $this->carla->id)->firstOrFail();
    expect($carla->revoked_at)->not->toBeNull()->and($carla->state->value)->toBe('left')
        ->and(Call::query()->findOrFail($id)->status->value)->toBe('active')
        ->and(CallEvent::query()->where('call_id', $id)->where('type', 'call.participant.revoked')->first()?->data)->toBe(['reason' => 'application_revoked']);
});

it('un fallo temporal del consumidor deja la revocación pendiente y se recupera al reintentar', function () {
    [$id, $actors] = rc_active($this, [$this->ana, $this->beto, $this->carla]);
    $real = app(TeamCallsRevocationConsumer::class);
    $fails = 1;
    app()->instance(RevocationConsumer::class, new class($real, $fails) implements RevocationConsumer
    {
        public function __construct(private RevocationConsumer $inner, private int $fails) {}

        public function consume(\Modules\CollaborationRealtime\Data\EventEnvelope $event): void
        {
            if ($this->fails-- > 0) {
                throw new RuntimeException('base de datos no disponible');
            }
            $this->inner->consume($event);
        }
    });
    app(DeviceRegistry::class)->revoke($actors[$this->beto->id], $actors[$this->beto->id]->deviceId);

    expect(fn () => rc_deliver($this->inst->id))->toThrow(RuntimeException::class);
    expect(rc_state($id, $this->beto->id))->toBe('joined')
        ->and(DB::table('collab_revocation_receipts')->count())->toBe(0); // sin recibo: el outbox reintentará
    rc_deliver($this->inst->id);
    expect(rc_state($id, $this->beto->id))->toBe('left')->and(DB::table('collab_revocation_receipts')->count())->toBe(1);
});

it('presencia real: en llamada solo mientras corresponde, sin tocar el estado manual; limpieza tras salir', function () {
    $ana = RealCollaboration::actor($this->ana);
    app(PresenceService::class)->setManualStatus($ana, ManualPresence::Busy);
    [$id, $actors] = rc_active($this, [$this->ana, $this->beto]);
    $snap = fn ($u) => app(PresenceService::class)->snapshot($ana, [(int) $u->id])[0];
    expect($snap($this->ana)->callId)->toBe($id)->and($snap($this->beto)->callId)->toBe($id)
        ->and($snap($this->ana)->manualStatus)->toBe(ManualPresence::Busy);

    $this->gw->leave($actors[$this->beto->id], $id, rc_cmd('pres-leave-1'));
    expect($snap($this->beto)->callId)->toBeNull(); // limpiada tras salir (autorización histórica)
    $this->gw->end($actors[$this->ana->id], $id, rc_cmd('pres-end-001'));
    expect($snap($this->ana)->callId)->toBeNull()->and($snap($this->ana)->manualStatus)->toBe(ManualPresence::Busy);
});

it('llamada desde un chat individual de TeamChat: el otro miembro lo resuelve TeamChat; ajenos no; dejar el chat expulsa', function () {
    $ana = RealCollaboration::actor($this->ana);
    $beto = RealCollaboration::actor($this->beto);
    $chat = app(ChatCommands::class)->direct($ana, $this->beto->id);
    $ref = new ConversationRef(ConversationType::Direct, $chat);

    $s = $this->gw->start($ana, CallTarget::conversation($ref), CallMedia::Video, rc_cmd('chat-start-01'));
    expect(collect($s->participants)->pluck('userId')->sort()->values()->all())->toBe(collect([$this->ana->id, $this->beto->id])->sort()->values()->all())
        ->and($s->conversation?->id)->toBe($chat)
        ->and($this->gw->activeFor($beto, $ref)?->id)->toBe($s->id);
    expect(rc_code(fn () => $this->gw->start(RealCollaboration::actor($this->carla), CallTarget::conversation($ref), CallMedia::Video, rc_cmd('chat-start-02'))))
        ->toBe('forbidden');

    $this->gw->accept($beto, $s->id, rc_cmd('chat-acc-001'));
    $this->gw->join($beto, $s->id, rc_cmd('chat-join-01'));
    app(ChatCommands::class)->membership($beto, $chat, false); // Beto deja la conversación
    rc_deliver($this->inst->id);
    $p = CallParticipant::query()->where('call_id', $s->id)->where('user_id', $this->beto->id)->firstOrFail();
    expect($p->revoked_at)->not->toBeNull()
        ->and(rc_code(fn () => $this->gw->credentials($beto, $s->id)))->toBe('forbidden');
});

it('grupo de TeamChat: solo suenan los elegidos, todos validados; la baja del grupo expulsa y una baja antigua no expulsa una admisión nueva', function () {
    $ana = RealCollaboration::actor($this->ana);
    $dani = TC::user($this->inst);
    $group = app(SpaceCommands::class)->create($ana, 'group', 'grp-cmd-0001', 'Equipo de admisiones', '', [$this->beto->id, $this->carla->id]);
    $ref = new ConversationRef(ConversationType::Group, $group);

    expect(rc_code(fn () => $this->gw->start($ana, CallTarget::conversation($ref, [$dani->id]), CallMedia::Audio, rc_cmd('grp-start-00'))))->toBe('forbidden');
    $s = $this->gw->start($ana, CallTarget::conversation($ref, [$this->carla->id]), CallMedia::Audio, rc_cmd('grp-start-01'));
    expect(collect($s->participants)->pluck('userId')->sort()->values()->all())->toBe(collect([$this->ana->id, $this->carla->id])->sort()->values()->all());
    $carla = RealCollaboration::actor($this->carla);
    $this->gw->accept($carla, $s->id, rc_cmd('grp-acc-0001'));

    // Baja del grupo durante la llamada → expulsión al entregarse.
    app(SpaceCommands::class)->member($ana, 'conversation', $group, 'grp-cmd-0002', $this->carla->id, 'remove');
    rc_deliver($this->inst->id);
    expect(CallParticipant::query()->where('call_id', $s->id)->where('user_id', $this->carla->id)->first()?->revoked_at)->not->toBeNull();

    // Revocación retrasada: Beto sale y vuelve a entrar al grupo; la baja antigua llega después de su nueva admisión.
    $this->gw->end($ana, $s->id, rc_cmd('grp-end-0001'));
    app(SpaceCommands::class)->member($ana, 'conversation', $group, 'grp-cmd-0003', $this->beto->id, 'remove');
    $this->travel(3)->seconds();
    app(SpaceCommands::class)->member($ana, 'conversation', $group, 'grp-cmd-0004', $this->beto->id, 'add');
    $s2 = $this->gw->start($ana, CallTarget::conversation($ref, [$this->beto->id]), CallMedia::Audio, rc_cmd('grp-start-02'));
    $beto = RealCollaboration::actor($this->beto);
    $this->gw->accept($beto, $s2->id, rc_cmd('grp-acc-0002'));
    rc_deliver($this->inst->id);
    expect(rc_state($s2->id, $this->beto->id))->toBe('accepted')
        ->and(CallParticipant::query()->where('call_id', $s2->id)->where('user_id', $this->beto->id)->first()?->revoked_at)->toBeNull();
});

it('aislamiento institucional con servicios reales: otra institución no ve la llamada ni sus eventos', function () {
    [$id] = rc_active($this, [$this->ana, $this->beto]);
    $other = TC::institution();
    $intruder = RealCollaboration::actor(TC::user($other));
    expect(rc_code(fn () => $this->gw->find($intruder, $id)))->toBe('not_found')
        ->and(collect(app(SyncFeed::class)->read($intruder, new PageRequest(null, 100))->events)->filter(fn ($e) => $e->aggregateId === $id)->all())->toBe([]);
});

it('idempotencia de todos los comandos con dispositivos reales: misma clave = mismo efecto; otro dispositivo = conflicto', function () {
    $a = RealCollaboration::actor($this->ana);
    $b = RealCollaboration::actor($this->beto, str_repeat('c', 40));
    $b2 = RealCollaboration::actor($this->beto, str_repeat('d', 40));
    $s = $this->gw->start($a, CallTarget::users([$this->beto->id]), CallMedia::Audio, rc_cmd('idem-start-1'));
    $this->gw->accept($b, $s->id, rc_cmd('idem-acc-001'));
    $this->gw->join($b, $s->id, rc_cmd('idem-join-01'));
    $this->gw->leave($b, $s->id, rc_cmd('idem-leave-1'));
    // Repetir el join tras salir NO vuelve a unir; con otro dispositivo, la misma clave es un conflicto.
    expect($this->gw->join($b, $s->id, rc_cmd('idem-join-01'))->viewerState)->toBe(ParticipantState::Left)
        ->and(rc_code(fn () => $this->gw->join($b2, $s->id, rc_cmd('idem-join-01'))))->toBe('idempotency_conflict');
    $first = $this->gw->end($a, $s->id, rc_cmd('idem-end-001'))->status;
    expect($this->gw->end($a, $s->id, rc_cmd('idem-end-001'))->status)->toBe($first)
        ->and(in_array($first, [CallStatus::Ended, CallStatus::Cancelled, CallStatus::Failed], true))->toBeTrue();
});
