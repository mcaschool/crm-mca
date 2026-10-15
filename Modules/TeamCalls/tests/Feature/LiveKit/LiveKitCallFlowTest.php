<?php

declare(strict_types=1);

use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Flujo completo por la API web con LiveKit SIMULADO: estados distintos para aceptada, conectándose,
| conectada de verdad, desconectada, activa y terminada; expulsión; sala impuesta por el cliente;
| servidor caído; participantes reales y barrido de salas. No sustituye una prueba con LiveKit real.
*/

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

function lkFlowJoined(Call|string $call, \App\Models\User $user, string $sid): void
{
    $room = FakeLiveKit::room($call);
    $identity = FakeLiveKit::identity($user, $call instanceof Call ? $call->id : $call);
    FakeLiveKit::connect($room, $identity, $sid);
    FakeLiveKit::webhook(FakeLiveKit::participantEvent('participant_joined', $room, $identity, $sid))->assertOk();
}

function lkMediaState(string $callId, int $userId): ?string
{
    return CallParticipant::query()->where('call_id', $callId)->where('user_id', $userId)->first()?->media_state?->value;
}

it('por la API: aceptada → conectándose → conectada (LiveKit) → activa → terminada', function () {
    $id = Http::start($this->ana, $this->beto->id, 'lk-http-0001', 'video')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk()->assertJsonPath('data.status', 'connecting');
    expect(lkMediaState($id, $this->beto->id))->toBeNull(); // aceptar no es conectarse

    Http::post($this->beto, 'join', $id)->assertOk()->assertJsonPath('data.status', 'connecting');
    expect(lkMediaState($id, $this->beto->id))->toBe('connecting');
    $credentials = Http::post($this->beto, 'credentials', $id)->assertOk()->json('data');
    $claims = FakeLiveKit::decode($credentials['token']);
    expect($credentials['server_url'])->toBe(FakeLiveKit::CLIENT_URL)
        ->and($claims['video']['room'])->toBe(FakeLiveKit::room($id))
        ->and($credentials['identity'])->toBe(FakeLiveKit::identity($this->beto, $id));

    Http::post($this->ana, 'join', $id)->assertOk();
    Http::post($this->ana, 'credentials', $id)->assertOk();
    lkFlowJoined($id, $this->beto, 'PA_beto');
    Http::get($this->beto, 'show', $id)->assertJsonPath('data.status', 'connecting'); // una sola conexión real
    lkFlowJoined($id, $this->ana, 'PA_ana');

    $show = Http::get($this->beto, 'show', $id)->assertOk()->assertJsonPath('data.status', 'active');
    expect(collect($show->json('data.participants'))->pluck('media_state')->all())->toBe(['connected', 'connected'])
        ->and($show->getContent())->not->toContain('PA_beto')->not->toContain('room'); // sin sesión ni sala

    Http::get($this->ana, 'media', $id)->assertOk()->assertJsonCount(2, 'data.participants')
        ->assertJsonPath('data.participants.0.authorized', true);
    Http::get($this->carla, 'media', $id)->assertNotFound();

    Http::post($this->ana, 'end', $id)->assertOk()->assertJsonPath('data.status', 'ended');
    expect(FakeLiveKit::$deleted)->toBe([FakeLiveKit::room($id)])->and(lkMediaState($id, $this->beto->id))->toBe('disconnected');
});

it('expulsar por la API: solo quien inició; sin credenciales nuevas; volver a invitar le readmite', function () {
    $id = Http::start($this->ana, $this->beto->id, 'lk-http-0002', 'video')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertOk();
    Http::post($this->carla, 'accept', $id)->assertOk();
    Http::post($this->beto, 'join', $id)->assertOk();
    lkFlowJoined($id, $this->beto, 'PA_b');
    $oldRoom = FakeLiveKit::room($id);
    $betoIdentity = FakeLiveKit::identity($this->beto, $id);

    Http::post($this->carla, 'revoke', $id, ['user_id' => $this->beto->id])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    Http::post($this->ana, 'revoke', $id, ['user_id' => $this->ana->id])->assertUnprocessable()->assertJsonPath('error.code', 'invalid');
    Http::post($this->ana, 'revoke', $id, ['user_id' => 999999])->assertNotFound();
    Http::post($this->ana, 'revoke', $id, ['user_id' => 'beto'])->assertUnprocessable();

    $res = Http::post($this->ana, 'revoke', $id, ['user_id' => $this->beto->id])->assertOk()->assertJsonPath('meta.replayed', false);
    expect(collect($res->json('data.participants'))->firstWhere('user_id', $this->beto->id))->toMatchArray(['revoked' => true, 'state' => 'left']);
    Http::post($this->ana, 'revoke', $id, ['user_id' => $this->beto->id])->assertOk()->assertJsonPath('meta.replayed', true);
    // Desconectado de la sala y, como la llamada sigue (Ana y Carla), la sala rota: la vieja se cierra.
    expect(FakeLiveKit::$removed)->toBe([$oldRoom.'|'.$betoIdentity])
        ->and(FakeLiveKit::$deleted)->toBe([$oldRoom])->and(FakeLiveKit::room($id))->not->toBe($oldRoom);

    Http::post($this->beto, 'credentials', $id)->assertForbidden();
    Http::post($this->beto, 'join', $id)->assertForbidden();
    Http::post($this->beto, 'accept', $id)->assertStatus(409);

    Http::post($this->ana, 'invite', $id, ['user_ids' => [$this->beto->id]])->assertOk();
    Http::post($this->beto, 'accept', $id)->assertOk()->assertJsonPath('data.viewer_state', 'accepted');
    Http::post($this->beto, 'credentials', $id)->assertOk();
});

it('el cliente no elige sala ni identidad; una credencial de otra llamada no sirve en esta sala', function () {
    $a = Http::start($this->ana, $this->beto->id, 'lk-http-0003')->json('data.id');
    Http::post($this->beto, 'accept', $a)->assertOk();
    Http::post($this->beto, 'credentials', $a, ['room' => 'tc-otra-sala'])->assertUnprocessable();
    Http::post($this->beto, 'credentials', $a, ['identity' => 'u1.x'])->assertUnprocessable();
    $token = Http::post($this->beto, 'credentials', $a)->assertOk()->json('data.token');
    expect(FakeLiveKit::decode($token)['video']['room'])->toBe(FakeLiveKit::room($a));

    // Carla tiene su propia llamada; la identidad de Beto conectándose a esa sala se rechaza.
    $b = Http::start($this->carla, $this->ana->id, 'lk-http-0004')->json('data.id');
    $roomB = FakeLiveKit::room($b);
    $betoIdentity = FakeLiveKit::identity($this->beto, $a);
    FakeLiveKit::connect($roomB, $betoIdentity, 'PA_wrong');
    FakeLiveKit::webhook(FakeLiveKit::participantEvent('participant_joined', $roomB, $betoIdentity, 'PA_wrong'))->assertOk();
    expect(FakeLiveKit::$removed)->toBe([$roomB.'|'.$betoIdentity]);
});

it('LiveKit caído: credenciales 503 y la llamada no llega a activa (acaba fallando por tiempo)', function () {
    $id = Http::start($this->ana, $this->beto->id, 'lk-http-0005')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'down';
    Http::post($this->beto, 'join', $id)->assertOk()->assertJsonPath('data.status', 'connecting');
    Http::post($this->ana, 'join', $id)->assertOk()->assertJsonPath('data.status', 'connecting');
    Http::post($this->beto, 'credentials', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');

    $this->travel(91)->seconds();
    Http::get($this->beto, 'show', $id)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.end_reason', 'connect_timeout');
});

it('barrido: cierra salas huérfanas, desconecta a quien sobra y da por desconectado a quien ya no está', function () {
    $id = Http::start($this->ana, $this->beto->id, 'lk-http-0006')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    Http::post($this->beto, 'join', $id)->assertOk();
    Http::post($this->ana, 'join', $id)->assertOk();
    Http::post($this->beto, 'credentials', $id)->assertOk(); // crea la sala
    lkFlowJoined($id, $this->beto, 'PA_b');
    lkFlowJoined($id, $this->ana, 'PA_a');
    $room = FakeLiveKit::room($id);

    FakeLiveKit::$rooms['tc-huerfana'] = ['max' => 2];
    FakeLiveKit::$rooms['otra-aplicacion'] = ['max' => 2];
    FakeLiveKit::connect($room, 'u'.$this->carla->id.'.colado', 'PA_c'); // nunca llegó su webhook
    unset(FakeLiveKit::$connected[$room][FakeLiveKit::identity($this->beto, $id)]); // se perdió su desconexión
    $this->travel(2)->minutes();

    $this->artisan('teamcalls:media-sweep')->assertSuccessful();

    expect(FakeLiveKit::$deleted)->toBe(['tc-huerfana'])
        ->and(FakeLiveKit::$removed)->toBe([$room.'|u'.$this->carla->id.'.colado'])
        ->and(lkMediaState($id, $this->beto->id))->toBe('disconnected');
    // Beto no vuelve en el margen de reconexión: sale; en 1:1 la llamada termina y su sala se cierra.
    $this->travel(21)->seconds();
    Http::get($this->ana, 'show', $id)->assertJsonPath('data.status', 'ended');
    expect(FakeLiveKit::$deleted)->toBe(['tc-huerfana', $room])
        ->and(FakeLiveKit::$rooms)->toHaveKey('otra-aplicacion');
});

it('sin servidor de medios el barrido no hace nada', function () {
    config(['teamcalls.media.driver' => 'none']);
    app()->forgetInstance(\Modules\TeamCalls\Contracts\MediaRoomService::class);
    $this->artisan('teamcalls:media-sweep')->assertSuccessful()->expectsOutputToContain('Salas: 0');
    expect(FakeLiveKit::$calls)->toBe([]);
});
