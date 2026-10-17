<?php

declare(strict_types=1);

use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4I · LiveKit caído: el primer fallo de conexión se detecta con reintentos acotados y abre el
| DISYUNTOR; mientras dure, todo falla al instante (sin repetir esperas), no se inician llamadas que
| sonarían sin poder conectarse y la interfaz conoce el estado (meta.media de «en curso»). Pasado el
| plazo, la siguiente operación vuelve a probar. Servidor LiveKit simulado (Http::fake).
*/

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot(['breaker_seconds' => 30]);
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('primer fallo: reintento acotado y disyuntor abierto; después, 503 inmediato sin volver a LiveKit', function () {
    $id = Http::start($this->ana, $this->beto->id, 'outage-0001')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'down';

    Http::post($this->beto, 'credentials', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');
    expect(FakeLiveKit::$calls)->toBe(['unreachable', 'unreachable']); // 1 intento + 1 reintento de conexión

    FakeLiveKit::$calls = [];
    Http::post($this->beto, 'credentials', $id)->assertStatus(503);
    Http::post($this->ana, 'join', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');
    expect(FakeLiveKit::$calls)->toBe([]); // al instante: ni una petición más mientras dure el disyuntor
    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertOk()->assertJsonPath('meta.media', 'down');
});

it('con el servidor de medios caído no se inicia ninguna llamada que sonaría sin poder conectarse', function () {
    $id = Http::start($this->ana, $this->beto->id, 'outage-0002')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'down';
    Http::post($this->beto, 'credentials', $id)->assertStatus(503); // abre el disyuntor

    $count = fn () => Call::query()->withoutGlobalScopes()->count();
    $before = $count();
    Http::start($this->carla, $this->beto->id, 'outage-0003')->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');
    Http::as($this->carla)->withHeader('Idempotency-Key', 'outage-0004')
        ->postJson('/api/v1/collaboration/calls', ['user_ids' => [$this->ana->id], 'media' => 'video'])->assertStatus(503);
    expect($count())->toBe($before); // nadie queda sonando
    Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 1); // solo la llamada previa
});

it('pasado el plazo vuelve a probar: si LiveKit ya responde, se recupera sin intervención', function () {
    $id = Http::start($this->ana, $this->beto->id, 'outage-0005')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'down';
    Http::post($this->beto, 'credentials', $id)->assertStatus(503);

    FakeLiveKit::$failure = null;
    $this->travel(20)->seconds();
    Http::post($this->beto, 'credentials', $id)->assertStatus(503); // todavía abierto (30 s)
    $this->travel(11)->seconds();
    Http::post($this->beto, 'credentials', $id)->assertOk()->assertJsonStructure(['data' => ['token', 'server_url']]);
    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.media', 'available');
    Http::start($this->carla, $this->ana->id, 'outage-0006')->assertCreated();
});

it('HTTP 5xx también abre el disyuntor; «no existe» (404) no, y 0 lo desactiva', function () {
    $id = Http::start($this->ana, $this->beto->id, 'outage-0007')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'error';
    Http::post($this->beto, 'credentials', $id)->assertStatus(503);
    expect(app(MediaRoomService::class)->available())->toBeFalse();

    FakeLiveKit::boot(['breaker_seconds' => 30]);
    app(MediaRoomService::class)->closeRoom('sala-que-no-existe'); // 404 de LiveKit: idempotente, no es caída
    expect(app(MediaRoomService::class)->available())->toBeTrue();

    // Disyuntor desactivado (0): cada operación vuelve a intentarlo. Servicio recién resuelto con esa configuración.
    FakeLiveKit::boot(['breaker_seconds' => 0]);
    FakeLiveKit::$failure = 'down';
    $media = app(MediaRoomService::class);
    foreach ([1, 2] as $attempt) {
        FakeLiveKit::$calls = [];
        expect(fn () => $media->prepareRoom('sala-x', 5))->toThrow(\Modules\TeamCalls\Exceptions\CallException::class);
        expect(FakeLiveKit::$calls)->toHaveCount(2)->and($media->available())->toBeTrue();
    }
});

it('sin servidor de medios configurado se mantiene el comportamiento previo: se inicia y unirse da 503', function () {
    config(['teamcalls.media.driver' => 'none']);
    app()->forgetInstance(MediaRoomService::class);
    app()->forgetInstance(\Modules\TeamCalls\Services\CallService::class);
    Http::start($this->ana, $this->beto->id, 'outage-0008')->assertCreated();
    Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.media', 'unconfigured');
});
