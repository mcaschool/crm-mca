<?php

declare(strict_types=1);

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Enums\CallMedia;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Integration\LiveKit\LiveKitMediaRooms;
use Modules\TeamCalls\Integration\Provisional\UnconfiguredMediaRooms;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Adaptador LiveKit contra un servidor LiveKit SIMULADO (Http::fake): configuración que falla cerrado,
| credenciales firmadas en el backend con alcance mínimo, API de salas, errores de comunicación y 503.
| No prueba audio ni video reales: eso exige un servidor LiveKit operativo.
*/

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot();
    $this->inst = TC::institution();
    [$this->ana, $this->beto] = [TC::user($this->inst), TC::user($this->inst)];
});

it('con LiveKit elegido y completo se usa el adaptador real, que solo da por conectado lo que confirma LiveKit', function () {
    $media = app(MediaRoomService::class);
    expect($media)->toBeInstanceOf(LiveKitMediaRooms::class)
        ->and($media->available())->toBeTrue()->and($media->confirmsConnections())->toBeTrue();
});

it('configuración incompleta o insegura: falla cerrado (sin servidor de medios, 503)', function (array $over, ?string $env = null) {
    FakeLiveKit::boot($over);
    if (isset($over['driver'])) {
        config(['teamcalls.media.driver' => $over['driver']]);
    }
    $previous = app()['env'];
    if ($env !== null) {
        app()['env'] = $env;
    }
    try {
        app()->forgetInstance(MediaRoomService::class);
        expect(app(MediaRoomService::class))->toBeInstanceOf(UnconfiguredMediaRooms::class);
    } finally {
        app()['env'] = $previous;
    }
})->with([
    'driver none' => [['driver' => 'none']],
    'sin URL del API' => [['api_url' => null]],
    'sin URL de clientes' => [['client_url' => '']],
    'sin API key' => [['api_key' => '']],
    'secreto débil (< 32)' => [['api_secret' => 'corto']],
    'URL con credenciales incrustadas' => [['api_url' => 'https://user:pass@livekit.test']],
    'producción con http/ws' => [['api_url' => 'http://livekit.test', 'client_url' => 'ws://rtc.test'], 'production'],
]);

it('credencial: firmada en el backend, para la sala de ESA llamada, una identidad por dispositivo y permisos mínimos', function () {
    $call = TC::call($this->ana, [$this->beto->id], 'lk-key-0001', CallMedia::Video);
    TC::service()->accept($this->beto, $call->id, 'b1');

    $credentials = TC::service()->credentials($this->beto, $call->id, 'b1');
    $claims = FakeLiveKit::decode($credentials->token);
    $room = TC::fresh($call)->room_name;

    expect($credentials->serverUrl)->toBe(FakeLiveKit::CLIENT_URL)
        ->and($credentials->identity)->toBe('u'.$this->beto->id.'.b1')
        ->and($claims['iss'])->toBe(FakeLiveKit::KEY)->and($claims['sub'])->toBe('u'.$this->beto->id.'.b1')
        ->and($claims['exp'] - $claims['nbf'])->toBe(300)
        // Solo entrar, oculto y SIN medios: LiveKit autohospedado no revoca credenciales, así que los
        // permisos se conceden aparte (UpdateParticipant) cuando el backend valida la conexión real.
        ->and($claims['video'])->toMatchArray(['room' => $room, 'roomJoin' => true, 'canSubscribe' => false, 'canPublish' => false,
            'canPublishData' => false, 'canPublishSources' => [], 'hidden' => true,
            'roomCreate' => false, 'roomList' => false, 'roomAdmin' => false, 'roomRecord' => false, 'canUpdateOwnMetadata' => false])
        ->and(json_decode($claims['metadata'], true))->toBe(['user_id' => $this->beto->id, 'role' => 'member'])
        ->and($credentials->token)->not->toContain(FakeLiveKit::SECRET)
        // La sala la creó el backend antes de emitir la credencial, con el límite de participantes.
        ->and(FakeLiveKit::$rooms[$room]['max'])->toBe(25)
        ->and($room)->toStartWith('tc-')->and($room)->not->toContain((string) $call->id); // nombre opaco

    // Otra llamada: otra sala; el nombre no se parece al id de la llamada (no es un ULID contiguo).
    TC::service()->end($this->ana, $call->id);
    $audio = TC::call($this->ana, [$this->beto->id], 'lk-key-0002', CallMedia::Audio);
    TC::service()->accept($this->beto, $audio->id, 'b1');
    $audioClaims = FakeLiveKit::decode(TC::service()->credentials($this->beto, $audio->id, 'b1')->token);
    expect($audioClaims['video']['room'])->not->toBe($room)
        ->and($audioClaims['video']['room'])->toMatch('/^tc-[0-9a-f]{32}$/')
        ->and(substr($audioClaims['video']['room'], 3, 10))->not->toBe(strtolower(substr((string) $audio->id, 0, 10)));
});

it('permisos concedidos al validar la conexión: según el medio, sin metadatos propios', function (CallMedia $media, array $sources) {
    $call = TC::call($this->ana, [$this->beto->id], 'lk-key-perm', $media);
    TC::service()->accept($this->beto, $call->id, 'b1');
    $room = TC::fresh($call)->room_name;
    TC::service()->credentials($this->beto, $call->id, 'b1');
    FakeLiveKit::connect($room, 'u'.$this->beto->id.'.b1', 'PA_b');
    TC::service()->applyMediaEvent($call->id, 'EV_1', 'joined', $this->beto->id, 'b1', 'PA_b');

    $update = collect(\Illuminate\Support\Facades\Http::recorded())->map(fn ($pair) => $pair[0])
        ->first(fn ($r) => str_ends_with($r->url(), '/UpdateParticipant'));
    expect($update)->not->toBeNull()
        ->and($update->data()['identity'])->toBe('u'.$this->beto->id.'.b1')
        ->and($update->data()['permission'])->toMatchArray(['can_subscribe' => true, 'can_publish' => true, 'can_publish_data' => true,
            'can_publish_sources' => $sources, 'hidden' => false, 'can_update_metadata' => false])
        ->and(FakeLiveKit::$admitted)->toHaveKey($room.'|u'.$this->beto->id.'.b1');
})->with([
    'voz' => [CallMedia::Audio, ['MICROPHONE']],
    'video' => [CallMedia::Video, ['MICROPHONE', 'CAMERA', 'SCREEN_SHARE', 'SCREEN_SHARE_AUDIO']],
]);

it('credenciales caducadas: LiveKit las rechazaría; la vigencia está acotada por configuración', function () {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');
    $token = TC::service()->credentials($this->beto, $call->id, 'b1')->token;

    JWT::$timestamp = time() + 301;
    try {
        expect(fn () => JWT::decode($token, new Key(FakeLiveKit::SECRET, 'HS256')))->toThrow(ExpiredException::class);
    } finally {
        JWT::$timestamp = null;
    }

    config(['teamcalls.join_token_ttl' => 5]);
    $short = FakeLiveKit::decode(TC::service()->credentials($this->beto, $call->id, 'b1')->token);
    config(['teamcalls.join_token_ttl' => 999999]);
    $long = FakeLiveKit::decode(TC::service()->credentials($this->beto, $call->id, 'b1')->token);
    expect($short['exp'] - $short['nbf'])->toBe(60)->and($long['exp'] - $long['nbf'])->toBe(3600);
});

it('API de salas: participantes reales, salas abiertas, desconectar y cerrar (idempotentes)', function () {
    $media = app(MediaRoomService::class);
    $media->prepareRoom('tc-sala-prueba', 5);
    $media->prepareRoom('tc-sala-prueba', 5); // idempotente
    FakeLiveKit::connect('tc-sala-prueba', 'u7.web.abc', 'PA_1');

    $real = $media->participants('tc-sala-prueba');
    expect($real)->toHaveCount(1)->and($real[0]->identity)->toBe('u7.web.abc')->and($real[0]->session)->toBe('PA_1')
        ->and($real[0]->joinedAt)->not->toBeNull()
        ->and($media->participants('tc-no-existe'))->toBe([])
        ->and($media->rooms())->toBe(['tc-sala-prueba']);

    $media->removeParticipant('tc-sala-prueba', 'u7.web.abc');
    $media->removeParticipant('tc-sala-prueba', 'u7.web.abc'); // ya no está: no es un error
    $media->closeRoom('tc-sala-prueba');
    $media->closeRoom('tc-sala-prueba');
    expect(FakeLiveKit::$removed)->toBe(['tc-sala-prueba|u7.web.abc'])->and(FakeLiveKit::$rooms)->toBe([]);
});

it('errores de comunicación: reintentos acotados y luego media_unavailable, sin filtrar secretos', function (string $failure, int $attempts) {
    $call = TC::call($this->ana, [$this->beto->id]);
    TC::service()->accept($this->beto, $call->id, 'b1');
    FakeLiveKit::$failure = $failure;

    try {
        TC::service()->credentials($this->beto, $call->id, 'b1');
        $this->fail('debía fallar');
    } catch (CallException $e) {
        expect($e->reason)->toBe('media_unavailable')->and($e->status)->toBe(503)
            ->and($e->getMessage())->not->toContain(FakeLiveKit::SECRET);
    }
    expect(count(FakeLiveKit::$calls))->toBe($attempts); // 1 intento + 2 reintentos

    // Desconectar o cerrar con el servidor caído no rompe la operación del motor (se registra y sigue).
    TC::service()->end($this->ana, $call->id);
    expect(TC::fresh($call)->status->value)->toBe('ended');
})->with([
    'sin conexión' => ['down', 3],
    'HTTP 500' => ['error', 3],
]);

it('servidor de medios no disponible: la API mantiene el 503 de la fase 2', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    FakeLiveKit::$failure = 'down';
    Http::post($this->beto, 'credentials', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable')->assertJsonMissingPath('data');

    config(['teamcalls.media.driver' => 'none']);
    app()->forgetInstance(MediaRoomService::class);
    Http::post($this->beto, 'join', $id)->assertStatus(503)->assertJsonPath('error.code', 'media_unavailable');
    Http::post($this->beto, 'credentials', $id)->assertStatus(503);
});
