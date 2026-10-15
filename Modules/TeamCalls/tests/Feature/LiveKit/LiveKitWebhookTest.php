<?php

declare(strict_types=1);

use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Webhooks firmados de LiveKit (simulados): autenticidad sobre el cuerpo original, la conexión REAL
| como única vía a «activa», eventos duplicados, retrasados y desordenados, credenciales reutilizadas
| tras una expulsión, otros dispositivos, otras instituciones y salas ajenas.
*/

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

/** Webhook de conexión/desconexión de una persona de la llamada (como lo enviaría LiveKit). */
function lkEvent(string $event, Call $call, string $identity, string $sid, ?string $id = null): \Illuminate\Testing\TestResponse
{
    if ($event === 'participant_joined') {
        FakeLiveKit::connect($call->room_name, $identity, $sid); // el cliente está realmente en la sala
    }

    return FakeLiveKit::webhook(FakeLiveKit::participantEvent($event, $call->room_name, $identity, $sid, $id));
}

function lkParticipant(Call $call, int $userId): CallParticipant
{
    return CallParticipant::query()->where('call_id', $call->id)->where('user_id', $userId)->sole();
}

/** Llamada de Ana a Beto, contestada y con ambos «conectándose» (unidos por la API, sin medios aún). */
function lkConnecting(object $t, ?array $to = null): Call
{
    $to ??= [$t->beto->id];
    $call = TC::call($t->ana, $to, 'lk-wh-0001');
    foreach ($to as $i => $id) {
        $user = \App\Models\User::query()->findOrFail($id);
        TC::service()->accept($user, $call->id, 'd'.$id);
        TC::service()->join($user, $call->id, 'd'.$id);
    }
    TC::service()->join($t->ana, $call->id, 'pc-'.$t->ana->id);

    return TC::fresh($call);
}

it('aceptar y unirse no basta: la llamada solo es activa cuando LiveKit confirma dos conexiones reales', function () {
    $call = lkConnecting($this);
    expect($call->status->value)->toBe('connecting')
        ->and(lkParticipant($call, $this->beto->id)->state->value)->toBe('joined')
        ->and(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('connecting');

    lkEvent('participant_joined', $call, 'u'.$this->beto->id.'.d'.$this->beto->id, 'PA_beto1')
        ->assertOk()->assertJsonPath('data.outcome', 'applied');
    expect(TC::fresh($call)->status->value)->toBe('connecting')
        ->and(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('connected');

    lkEvent('participant_joined', $call, 'u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_ana1')->assertOk();
    $fresh = TC::fresh($call);
    expect($fresh->status->value)->toBe('active')->and($fresh->connected_at)->not->toBeNull()
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.connected')->count())->toBe(1);
});

it('firmas inválidas: 401 y nada cambia', function (array $tamper) {
    $call = lkConnecting($this);
    $payload = FakeLiveKit::participantEvent('participant_joined', $call->room_name, 'u'.$this->beto->id.'.d'.$this->beto->id, 'PA_x');
    FakeLiveKit::webhook($payload, $tamper)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');

    expect(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('connecting')
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.joined')->count())->toBe(0);
})->with([
    'otro secreto' => [['secret' => 'otro-secreto-que-no-es-el-de-livekit-000000']],
    'otra API key (iss)' => [['iss' => 'APIotraclave']],
    'cuerpo alterado tras firmar' => [['body' => '{"event":"participant_joined","room":{"name":"tc-otra"}}']],
    'hash del cuerpo incorrecto' => [['sha256' => base64_encode(hash('sha256', 'otro', true))]],
    'sin encabezado Authorization' => [['header' => false]],
    'token caducado' => [['exp' => time() - 3600]],
]);

it('sin LiveKit configurado la ruta de webhooks no existe; cuerpos mal formados se rechazan', function () {
    config(['teamcalls.media.driver' => 'none']);
    FakeLiveKit::webhook(FakeLiveKit::roomEvent('room_started', 'tc-x'))->assertNotFound();

    config(['teamcalls.media.driver' => 'livekit']);
    FakeLiveKit::webhook(['sin' => 'evento'])->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
    FakeLiveKit::webhook(['event' => 'track_published', 'room' => ['name' => 'tc-x']])->assertOk()->assertJsonPath('data.outcome', 'ignored');
});

it('eventos duplicados se aplican una sola vez', function () {
    $call = lkConnecting($this);
    $identity = 'u'.$this->beto->id.'.d'.$this->beto->id;
    foreach (range(1, 3) as $i) {
        lkEvent('participant_joined', $call, $identity, 'PA_b1', 'EV_same')->assertOk();
    }
    lkEvent('participant_joined', $call, $identity, 'PA_b1', 'EV_other_id_same_session')->assertOk(); // misma conexión, otro id

    expect(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.joined')->count())->toBe(2)
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.joined')->get()->filter(fn ($e) => $e->data['applied'])->count())->toBe(1)
        ->and(FakeLiveKit::$removed)->toBe([]); // la repetición no desconecta a nadie
});

it('desordenados: si la desconexión llega antes que la conexión, gana la desconexión y no se reactiva nada', function () {
    $call = lkConnecting($this);
    $identity = 'u'.$this->beto->id.'.d'.$this->beto->id;

    lkEvent('participant_left', $call, $identity, 'PA_late')->assertOk();
    expect(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('disconnected'); // margen de reconexión

    // La conexión de esa misma sesión llega tarde: se rechaza y se desconecta (esa sesión ya terminó).
    lkEvent('participant_joined', $call, $identity, 'PA_late')->assertOk();
    expect(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('disconnected')
        ->and(FakeLiveKit::$removed)->toContain($call->room_name.'|'.$identity);

    // No volvió en el margen: sale de la llamada; en 1:1 la llamada termina y no se reactiva.
    $this->travel(21)->seconds();
    expect(TC::service()->find($this->ana, $call->id)->status)->toBe('ended')
        ->and(lkParticipant($call, $this->beto->id)->state->value)->toBe('left');
    lkEvent('participant_joined', $call, $identity, 'PA_after')->assertOk();
    expect(TC::fresh($call)->status->value)->toBe('ended')->and(FakeLiveKit::$deleted)->toContain($call->room_name);
});

it('una desconexión tardía de una sesión anterior no saca a quien ya se reconectó', function () {
    $call = lkConnecting($this, [$this->beto->id, $this->carla->id]);
    $identity = 'u'.$this->beto->id.'.d'.$this->beto->id;
    lkEvent('participant_joined', $call, $identity, 'PA_old')->assertOk();
    lkEvent('participant_joined', $call, $identity, 'PA_new')->assertOk(); // reconexión (nueva sesión)
    lkEvent('participant_left', $call, $identity, 'PA_old')->assertOk(); // llega tarde

    $p = lkParticipant($call, $this->beto->id);
    expect($p->state->value)->toBe('joined')->and($p->media_state->value)->toBe('connected')->and($p->media_session)->toBe('PA_new');

    lkEvent('participant_left', $call, $identity, 'PA_new')->assertOk();
    expect(lkParticipant($call, $this->beto->id)->state->value)->toBe('joined');
    $this->travel(21)->seconds();
    $this->artisan('teamcalls:expire-stale')->assertSuccessful(); // sin consultas: lo aplica el comando
    expect(lkParticipant($call, $this->beto->id)->state->value)->toBe('left');
});

it('orden REAL de LiveKit al reconectarse (sale la sesión vieja y luego entra la nueva): sigue en la llamada', function () {
    $call = lkConnecting($this);
    $beto = 'u'.$this->beto->id.'.d'.$this->beto->id;
    lkEvent('participant_joined', $call, $beto, 'PA_1')->assertOk();
    lkEvent('participant_joined', $call, 'u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_a')->assertOk();
    expect(TC::fresh($call)->status->value)->toBe('active');

    // Secuencia observada con LiveKit 1.13.10 (reconexión completa o misma identidad entrando otra vez).
    lkEvent('participant_left', $call, $beto, 'PA_1')->assertOk();
    lkEvent('participant_joined', $call, $beto, 'PA_2')->assertOk();

    $p = lkParticipant($call, $this->beto->id);
    expect(TC::fresh($call)->status->value)->toBe('active') // la llamada 1:1 NO se corta
        ->and($p->state->value)->toBe('joined')->and($p->media_state->value)->toBe('connected')->and($p->media_session)->toBe('PA_2')
        ->and(FakeLiveKit::$admitted)->toHaveKey($call->room_name.'|'.$beto)
        ->and(FakeLiveKit::$removed)->toBe([]);
    $this->travel(30)->seconds();
    expect(TC::service()->find($this->ana, $call->id)->status)->toBe('active');
});

it('expulsado de una llamada que sigue: la sala rota y ninguna credencial anterior vuelve a servir', function () {
    $call = lkConnecting($this, [$this->beto->id, $this->carla->id]);
    $beto = 'u'.$this->beto->id.'.d'.$this->beto->id;
    $carla = 'u'.$this->carla->id.'.d'.$this->carla->id;
    TC::service()->credentials($this->beto, $call->id, 'd'.$this->beto->id); // crea la sala; Beto tiene credencial vigente
    foreach ([[$beto, 'PA_b'], [$carla, 'PA_c'], ['u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_a']] as [$identity, $sid]) {
        lkEvent('participant_joined', $call, $identity, $sid)->assertOk();
    }
    $oldRoom = $call->room_name;
    expect(FakeLiveKit::$admitted)->toHaveKey($oldRoom.'|'.$beto); // estaba admitido con medios

    TC::service()->revoke($this->ana, $call->id, $this->beto->id);
    $fresh = TC::fresh($call);
    expect(lkParticipant($call, $this->beto->id)->revoked_at)->not->toBeNull()
        ->and(lkParticipant($call, $this->beto->id)->state->value)->toBe('left')
        ->and($fresh->status->value)->toBe('active') // Ana y Carla siguen en la llamada
        ->and($fresh->room_name)->not->toBe($oldRoom)->and($fresh->room_name)->toMatch('/^tc-[0-9a-f]{32}$/')
        ->and(FakeLiveKit::$deleted)->toContain($oldRoom) // la sala vieja se cierra: sus credenciales ya no sirven
        ->and(lkParticipant($call, $this->carla->id)->media_state->value)->toBe('disconnected') // debe reconectarse
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.room_rotated')->count())->toBe(1)
        ->and(TC::reason(fn () => TC::service()->credentials($this->beto, $call->id, 'd'.$this->beto->id)))->toBe('forbidden')
        ->and(TC::reason(fn () => TC::service()->join($this->beto, $call->id, 'd'.$this->beto->id)))->toBe('forbidden');

    // Beto intenta volver con su credencial (o con una renovada por LiveKit): es de la sala vieja.
    FakeLiveKit::$deleted = [];
    lkEvent('participant_joined', $call, $beto, 'PA_b_reuse')->assertOk()->assertJsonPath('data.outcome', 'unknown_room');
    expect(FakeLiveKit::$deleted)->toBe([$oldRoom])->and(lkParticipant($call, $this->beto->id)->state->value)->toBe('left');

    // Carla pide credenciales nuevas (sala nueva) y se reconecta: admitida otra vez.
    $token = TC::service()->credentials($this->carla, $call->id, 'd'.$this->carla->id)->token;
    expect(FakeLiveKit::decode($token)['video']['room'])->toBe($fresh->room_name);
    lkEvent('participant_joined', $fresh, $carla, 'PA_c2')->assertOk()->assertJsonPath('data.outcome', 'applied');
    expect(lkParticipant($call, $this->carla->id)->media_state->value)->toBe('connected')
        ->and(FakeLiveKit::$admitted)->toHaveKey($fresh->room_name.'|'.$carla)
        ->and(FakeLiveKit::$admitted)->not->toHaveKey($fresh->room_name.'|'.$beto);

    // Volver a invitarle es una decisión explícita de quien llama: le readmite.
    TC::service()->invite($this->ana, $call->id, [$this->beto->id]);
    expect(lkParticipant($call, $this->beto->id)->revoked_at)->toBeNull()->and(lkParticipant($call, $this->beto->id)->state->value)->toBe('ringing');
});

it('si tras rotar la sala nadie vuelve a conectarse, la llamada termina (media_lost) y no queda activa', function () {
    $call = lkConnecting($this, [$this->beto->id, $this->carla->id]);
    foreach ([['u'.$this->beto->id.'.d'.$this->beto->id, 'PA_b'], ['u'.$this->carla->id.'.d'.$this->carla->id, 'PA_c'], ['u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_a']] as [$identity, $sid]) {
        lkEvent('participant_joined', $call, $identity, $sid)->assertOk();
    }
    TC::service()->revoke($this->ana, $call->id, $this->beto->id);
    expect(TC::fresh($call)->status->value)->toBe('active');

    $this->travel(91)->seconds();
    $this->artisan('teamcalls:expire-stale')->assertSuccessful();
    expect(TC::fresh($call)->status->value)->toBe('ended')->and(TC::fresh($call)->end_reason)->toBe('media_lost');
});

it('CollaborationCore retira el acceso de una persona: sale de todas sus llamadas vivas con rotación', function () {
    $call = lkConnecting($this, [$this->beto->id, $this->carla->id]);
    foreach ([['u'.$this->beto->id.'.d'.$this->beto->id, 'PA_b'], ['u'.$this->carla->id.'.d'.$this->carla->id, 'PA_c'], ['u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_a']] as [$identity, $sid]) {
        lkEvent('participant_joined', $call, $identity, $sid)->assertOk();
    }
    $oldRoom = $call->room_name;

    expect(app(\Modules\TeamCalls\Contracts\CallsGateway::class)->revokeAccess($this->beto->id, 'user_deactivated'))->toBe(1)
        ->and(app(\Modules\TeamCalls\Contracts\CallsGateway::class)->revokeAccess($this->beto->id))->toBe(0); // idempotente
    expect(lkParticipant($call, $this->beto->id)->revoked_at)->not->toBeNull()
        ->and(TC::fresh($call)->room_name)->not->toBe($oldRoom)
        ->and(CallEvent::query()->where('call_id', $call->id)->where('type', 'call.participant.revoked')->sole()->data['reason'])->toBe('user_deactivated');
});

it('otro dispositivo de la misma persona no toma la llamada: su conexión se rechaza', function () {
    $call = lkConnecting($this);
    $intruso = 'u'.$this->beto->id.'.otra-pestana';
    lkEvent('participant_joined', $call, $intruso, 'PA_other')->assertOk();

    $p = lkParticipant($call, $this->beto->id);
    expect($p->device_id)->toBe('d'.$this->beto->id)->and($p->media_state->value)->toBe('connecting')
        ->and(FakeLiveKit::$removed)->toBe([$call->room_name.'|'.$intruso]);
});

it('aislamiento: identidades de otra institución, de no participantes o ajenas a TeamCalls se desconectan', function () {
    $call = lkConnecting($this);
    $intruder = TC::user(TC::institution());
    app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($this->inst->id);

    foreach (['u'.$intruder->id.'.web.x' => 'applied', 'u'.$this->carla->id.'.d1' => 'applied', 'hacker' => 'unknown_identity'] as $identity => $outcome) {
        lkEvent('participant_joined', $call, $identity, 'PA_'.md5($identity))->assertOk()->assertJsonPath('data.outcome', $outcome);
        expect(FakeLiveKit::$removed)->toContain($call->room_name.'|'.$identity);
    }
    expect(CallParticipant::query()->where('call_id', $call->id)->count())->toBe(2)
        ->and(TC::fresh($call)->status->value)->toBe('connecting');
});

it('salas: una de TeamCalls sin llamada se cierra; la de otra aplicación se ignora; la de una llamada terminada se cierra', function () {
    FakeLiveKit::$rooms['tc-huerfana'] = ['max' => 2];
    FakeLiveKit::webhook(FakeLiveKit::roomEvent('room_started', 'tc-huerfana'))->assertOk()->assertJsonPath('data.outcome', 'unknown_room');
    FakeLiveKit::webhook(FakeLiveKit::roomEvent('room_started', 'otra-aplicacion'))->assertOk()->assertJsonPath('data.outcome', 'ignored');
    expect(FakeLiveKit::$deleted)->toBe(['tc-huerfana']);

    $call = lkConnecting($this);
    TC::service()->end($this->ana, $call->id);
    FakeLiveKit::$deleted = [];
    FakeLiveKit::$rooms[$call->room_name] = ['max' => 2]; // alguien volvió a abrirla
    FakeLiveKit::webhook(FakeLiveKit::roomEvent('room_started', $call->room_name, 'EV_restart'))->assertOk();
    expect(FakeLiveKit::$deleted)->toBe([$call->room_name])->and(TC::fresh($call)->status->value)->toBe('ended');
});

it('room_finished: quien seguía conectado queda desconectado y la llamada termina sin transiciones inválidas', function () {
    $call = lkConnecting($this);
    lkEvent('participant_joined', $call, 'u'.$this->beto->id.'.d'.$this->beto->id, 'PA_b')->assertOk();
    lkEvent('participant_joined', $call, 'u'.$this->ana->id.'.pc-'.$this->ana->id, 'PA_a')->assertOk();
    expect(TC::fresh($call)->status->value)->toBe('active');

    foreach (range(1, 2) as $i) {
        FakeLiveKit::webhook(FakeLiveKit::roomEvent('room_finished', $call->room_name, 'EV_fin'))->assertOk();
    }
    $fresh = TC::fresh($call);
    expect($fresh->status->value)->toBe('ended')->and($fresh->end_reason)->toBe('everyone_left')
        ->and(lkParticipant($call, $this->beto->id)->media_state->value)->toBe('disconnected');

    // Eventos tardíos de esa llamada no la reactivan.
    lkEvent('participant_left', $call, 'u'.$this->beto->id.'.d'.$this->beto->id, 'PA_b')->assertOk();
    lkEvent('participant_joined', $call, 'u'.$this->beto->id.'.d'.$this->beto->id, 'PA_b2')->assertOk();
    expect(TC::fresh($call)->status->value)->toBe('ended');
});
