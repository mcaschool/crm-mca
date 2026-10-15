<?php

declare(strict_types=1);

use Modules\TeamCalls\Contracts\CallsGateway;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Integration\Provisional\UnconfiguredMediaRooms;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| API web de llamadas: cada operación por HTTP, sus códigos y su formato JSON. Las reglas de fondo
| (permisos, transiciones, idempotencia) son las del motor de la fase 1; aquí se verifica que la API
| las expone de forma coherente.
*/

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('inicia una llamada individual (201) y el reintento con la misma clave devuelve la misma (200, replayed)', function () {
    $first = Http::start($this->ana, $this->beto->id, 'http-key-0001', 'video');
    $first->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('meta.replayed', false)
        ->assertJsonPath('data.status', 'ringing')
        ->assertJsonPath('data.scope', 'direct')
        ->assertJsonPath('data.media', 'video')
        ->assertJsonPath('data.initiator_id', $this->ana->id)
        ->assertJsonPath('data.viewer_state', 'accepted')
        ->assertJsonMissingPath('data.room_name')
        ->assertJsonMissingPath('data.idempotency_key');
    expect($first->headers->get('Cache-Control'))->toContain('no-store');

    $again = Http::start($this->ana, $this->beto->id, 'http-key-0001', 'video');
    $again->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
    expect(Call::query()->count())->toBe(1)
        ->and(CallEvent::query()->where('type', 'call.started')->count())->toBe(1);

    // La misma clave para otra llamada es un conflicto, no una llamada nueva.
    Http::start($this->ana, $this->carla->id, 'http-key-0001', 'video')
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

    // La clave también puede llegar en la cabecera Idempotency-Key.
    Http::post($this->ana, 'cancel', $first->json('data.id'))->assertOk();
    Http::as($this->ana)->withHeader('Idempotency-Key', 'http-key-header-01')
        ->postJson(route('collab.calls.store'), ['user_id' => $this->carla->id, 'media' => 'audio'])
        ->assertCreated()->assertJsonPath('data.media', 'audio');
    expect(Call::query()->count())->toBe(2);
});

it('contestar fija el dispositivo de la sesión; repetir es idempotente y otro dispositivo recibe answered_elsewhere', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    Http::post($this->beto, 'accept', $id, browser: 'beto-movil')->assertOk()
        ->assertJsonPath('data.status', 'connecting')->assertJsonPath('data.viewer_state', 'accepted')
        ->assertJsonPath('meta.replayed', false);
    Http::post($this->beto, 'accept', $id, browser: 'beto-movil')->assertOk()->assertJsonPath('meta.replayed', true);
    Http::post($this->beto, 'accept', $id, browser: 'beto-pc')->assertStatus(409)
        ->assertJsonPath('error.code', 'answered_elsewhere');
    Http::post($this->beto, 'join', $id, browser: 'beto-pc')->assertStatus(409)->assertJsonPath('error.code', 'answered_elsewhere');

    $p = CallParticipant::query()->where('call_id', $id)->where('user_id', $this->beto->id)->sole();
    expect($p->state->value)->toBe('accepted')
        ->and($p->device_id)->toMatch('/^web\.[a-z0-9]{24}$/') // lo decide el servidor, no el cliente
        ->and(CallEvent::query()->where('call_id', $id)->where('type', 'call.accepted')->count())->toBe(1);
});

it('rechazar termina la llamada directa; repetir es idempotente y contestar después es un conflicto de estado', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    Http::post($this->beto, 'decline', $id)->assertOk()->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.viewer_state', 'declined')->assertJsonPath('meta.replayed', false);
    Http::post($this->beto, 'decline', $id)->assertOk()->assertJsonPath('meta.replayed', true);
    Http::post($this->beto, 'accept', $id)->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
});

it('cancelar: solo quien llama y solo antes de que contesten', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    Http::post($this->beto, 'cancel', $id)->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    Http::post($this->ana, 'cancel', $id)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('meta.replayed', false);
    Http::post($this->ana, 'cancel', $id)->assertOk()->assertJsonPath('meta.replayed', true);

    $second = Http::start($this->ana, $this->beto->id, 'http-key-0002')->json('data.id');
    Http::post($this->beto, 'accept', $second)->assertOk();
    Http::post($this->ana, 'cancel', $second)->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(TC::fresh(Call::query()->findOrFail($second))->status->value)->toBe('connecting');
});

it('sin servidor de medios, unirse responde 503 y la llamada nunca figura como conectada', function () {
    app()->instance(MediaRoomService::class, new UnconfiguredMediaRooms); // el adaptador real de hoy
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk()->assertJsonPath('data.status', 'connecting');

    foreach ([$this->ana, $this->beto] as $user) {
        Http::post($user, 'join', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');
        Http::post($user, 'credentials', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable')
            ->assertJsonMissingPath('data');
    }
    $call = TC::fresh(Call::query()->findOrFail($id));
    expect($call->status->value)->toBe('connecting')->and($call->connected_at)->toBeNull()
        ->and(CallEvent::query()->where('call_id', $id)->whereIn('type', ['call.participant.joined', 'call.connected'])->count())->toBe(0);

    // Sin nadie conectado, la llamada acaba fallando por tiempo (no queda «activa» para siempre).
    $this->travel(91)->seconds();
    Http::get($this->beto, 'show', $id)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.end_reason', 'connect_timeout');
});

it('con servidor de medios (doble de pruebas): unirse, invitar, salir y finalizar', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    Http::post($this->beto, 'join', $id)->assertOk()->assertJsonPath('data.viewer_state', 'joined');
    Http::post($this->ana, 'join', $id)->assertOk()->assertJsonPath('data.status', 'active');
    Http::post($this->ana, 'join', $id)->assertOk()->assertJsonPath('meta.replayed', true);

    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertOk()
        ->assertJsonPath('data.scope', 'group')->assertJsonPath('meta.replayed', false);
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertOk()->assertJsonPath('meta.replayed', true);
    Http::post($this->carla, 'join', $id)->assertOk()->assertJsonPath('data.viewer_state', 'joined');

    // «Finalizar» de quien no es anfitrión es salir; la llamada sigue para los demás.
    Http::post($this->beto, 'end', $id)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.viewer_state', 'left');
    Http::post($this->beto, 'leave', $id)->assertOk()->assertJsonPath('meta.replayed', true);

    Http::post($this->ana, 'end', $id)->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.end_reason', 'ended_by_host');
    Http::post($this->ana, 'end', $id)->assertOk()->assertJsonPath('meta.replayed', true);
    Http::post($this->carla, 'join', $id)->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(TC::$rec->closed)->toHaveCount(1);
});

it('invitar: solo quien está en la llamada, solo a personas autorizadas y dentro del límite', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    $foreign = TC::user(TC::institution());
    app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($this->inst->id);

    Http::post($this->beto, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertForbidden(); // aún suena
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$foreign->id]])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    Http::post($this->ana, 'invite', $id, ['user_ids' => [999999]])->assertForbidden();
    Http::post($this->ana, 'invite', $id, ['user_ids' => []])->assertUnprocessable()->assertJsonPath('error.code', 'invalid');
    Http::post($this->ana, 'invite', $id, ['user_ids' => ['x']])->assertUnprocessable()->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->carla->id, $this->carla->id]])->assertUnprocessable();
    expect(CallParticipant::query()->where('call_id', $id)->count())->toBe(2);

    config(['teamcalls.max_participants' => 3]);
    [$d, $e] = [TC::user($this->inst), TC::user($this->inst)];
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$d->id, $e->id]])->assertUnprocessable()->assertJsonPath('error.code', 'too_many_participants');
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertOk()->assertJsonPath('data.scope', 'group');
});

it('historial y perdidas: solo las llamadas propias, con lo vencido ya aplicado', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    $this->travel(46)->seconds();

    Http::get($this->beto, 'missed')->assertOk()->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'missed')->assertJsonPath('data.0.viewer_state', 'missed');
    Http::get($this->ana, 'index')->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.viewer_state', 'left');
    Http::get($this->ana, 'missed')->assertOk()->assertJsonPath('meta.count', 0);
    Http::get($this->carla, 'index')->assertOk()->assertJsonPath('meta.count', 0);

    Http::get($this->ana, 'index', query: ['limit' => 0])->assertUnprocessable()->assertJsonPath('error.code', 'invalid');
    Http::get($this->ana, 'index', query: ['limit' => 'todo'])->assertUnprocessable();
    Http::get($this->ana, 'index', query: ['limit' => 1])->assertOk();
});

it('ver una llamada: participantes sí; el resto, como si no existiera', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    Http::get($this->beto, 'show', $id)->assertOk()->assertJsonPath('data.viewer_state', 'ringing')
        ->assertJsonCount(2, 'data.participants')->assertJsonPath('meta.replayed', false);
    Http::get($this->carla, 'show', $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    Http::get($this->beto, 'show', '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('credenciales: solo para el dispositivo con el que se está en la llamada y sin caché', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    Http::post($this->beto, 'credentials', $id)->assertForbidden(); // aún suena

    Http::post($this->beto, 'accept', $id, browser: 'beto-movil')->assertOk();
    $device = CallParticipant::query()->where('call_id', $id)->where('user_id', $this->beto->id)->value('device_id');
    $res = Http::post($this->beto, 'credentials', $id, browser: 'beto-movil')->assertOk()
        ->assertJsonPath('data.identity', 'u'.$this->beto->id.'.'.$device)
        ->assertJsonStructure(['data' => ['server_url', 'token', 'identity', 'expires_at']]);
    expect($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and(TC::$rec->grants[0]->canPublishVideo)->toBeFalse(); // llamada de voz: el backend decide

    Http::post($this->beto, 'credentials', $id, browser: 'beto-pc')->assertStatus(409)->assertJsonPath('error.code', 'answered_elsewhere');
    Http::post($this->carla, 'credentials', $id)->assertNotFound();
    expect(json_encode(CallEvent::query()->get()))->not->toContain('fake.u'); // nada de la credencial se guarda
});

it('compatibilidad con la fase 1: lo creado por HTTP se ve por CallsGateway y lo creado por el motor, por HTTP', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    $gateway = app(CallsGateway::class);
    expect($gateway->find($id, $this->beto)->status)->toBe('ringing');

    $engine = TC::call($this->carla, [$this->beto->id], 'engine-key-01');
    Http::get($this->beto, 'show', $engine->id)->assertOk()->assertJsonPath('data.initiator_id', $this->carla->id);
    Http::post($this->beto, 'decline', $engine->id)->assertOk()->assertJsonPath('data.status', 'declined');
});
