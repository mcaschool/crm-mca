<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Exceptions\CallException;
use Modules\TeamCalls\Integration\LiveKit\LiveKitConfig;
use Modules\TeamCalls\Integration\LiveKit\LiveKitMediaRooms;
use Modules\TeamCalls\Integration\LiveKit\LiveKitTokens;
use Modules\TeamCalls\Support\MediaGrant;

/*
| Pruebas contra un LiveKit REAL (opcionales). Solo corren si se definen, para un servidor de
| DESARROLLO (nunca producción):
|   TEAMCALLS_LIVEKIT_REAL_API_URL=http://127.0.0.1:7880  TEAMCALLS_LIVEKIT_REAL_KEY=…  TEAMCALLS_LIVEKIT_REAL_SECRET=…
| Procedimiento para levantarlo: tests/LiveKitDev/README.md. Sin esas variables se omiten.
*/

beforeEach(function () {
    $url = (string) getenv('TEAMCALLS_LIVEKIT_REAL_API_URL');
    if ($url === '' || (string) getenv('TEAMCALLS_LIVEKIT_REAL_KEY') === '' || (string) getenv('TEAMCALLS_LIVEKIT_REAL_SECRET') === '') {
        $this->markTestSkipped('Sin LiveKit real de desarrollo (TEAMCALLS_LIVEKIT_REAL_*).');
    }
    if (! preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $url)) {
        $this->markTestSkipped('Solo contra un LiveKit local de desarrollo.');
    }
    config(['teamcalls.media.driver' => 'livekit', 'teamcalls.media.livekit' => [
        'api_url' => $url, 'client_url' => str_replace('http', 'ws', $url),
        'api_key' => getenv('TEAMCALLS_LIVEKIT_REAL_KEY'), 'api_secret' => getenv('TEAMCALLS_LIVEKIT_REAL_SECRET'),
        'timeout' => 3, 'connect_timeout' => 2, 'retries' => 1, 'retry_sleep_ms' => 100,
    ]]);
    app()->forgetInstance(MediaRoomService::class);
    $this->media = app(MediaRoomService::class);
    $this->config = LiveKitConfig::fromConfig();
    $this->room = 'tc-realtest-'.bin2hex(random_bytes(6));
})->group('livekit-real');

afterEach(function () {
    if (isset($this->media)) {
        $this->media->closeRoom($this->room);
    }
});

function lkValidate(string $token): array
{
    $r = Http::get(LiveKitConfig::fromConfig()->apiUrl.'/rtc/validate', ['access_token' => $token]);

    return [$r->status(), trim($r->body())];
}

function lkGrant(string $room): MediaGrant
{
    return new MediaGrant($room, 'u900.realtest', 900, 'realtest', true, true, true, false, 120);
}

it('API de salas real: crear, listar, participantes, retirar y cerrar (idempotentes)', function () {
    expect($this->media)->toBeInstanceOf(LiveKitMediaRooms::class);
    $this->media->prepareRoom($this->room, 3);
    $this->media->prepareRoom($this->room, 3);
    expect($this->media->rooms())->toContain($this->room)
        ->and($this->media->participants($this->room))->toBe([])
        ->and($this->media->participants('tc-no-existe-'.bin2hex(random_bytes(3))))->toBe([]); // 200 vacío, no 404

    $this->media->removeParticipant($this->room, 'u1.nadie');       // 404 not_found → idempotente
    $this->media->admitParticipant(lkGrant($this->room));           // 404 not_found → idempotente
    $this->media->closeRoom('tc-no-existe-'.bin2hex(random_bytes(3))); // 404 not_found → idempotente
    $this->media->closeRoom($this->room);
    expect($this->media->rooms())->not->toContain($this->room);
})->group('livekit-real');

it('credenciales reales: válidas solo para salas creadas por el backend; firma y vigencia', function () {
    $tokens = new LiveKitTokens($this->config);
    expect(lkValidate($tokens->participant(lkGrant($this->room))['token']))->toBe([404, 'requested room does not exist']); // auto_create=false

    $this->media->prepareRoom($this->room, 3);
    $ok = $tokens->participant(lkGrant($this->room))['token'];
    expect(lkValidate($ok))->toBe([200, 'success']);

    [, $payload] = explode('.', $ok);
    $claims = json_decode(JWT::urlsafeB64Decode($payload), true);
    $expired = array_merge($claims, ['nbf' => time() - 600, 'exp' => time() - 300]);
    expect(lkValidate(JWT::encode($expired, $this->config->secret(), 'HS256'))[0])->toBe(401)
        ->and(lkValidate(JWT::encode($claims, str_repeat('z', 40), 'HS256'))[0])->toBe(401);
})->group('livekit-real');

it('errores reales: token de servicio sin permiso (401) y servidor caído → media_unavailable', function () {
    $now = time();
    $noGrant = JWT::encode(['iss' => $this->config->apiKey, 'sub' => 'x', 'nbf' => $now, 'exp' => $now + 60, 'video' => ['roomJoin' => true]], $this->config->secret(), 'HS256');
    $r = Http::withToken($noGrant)->asJson()->post($this->config->apiUrl.'/twirp/livekit.RoomService/ListRooms', new stdClass);
    expect($r->status())->toBe(401)->and($r->json('code'))->toBe('unauthenticated');

    config(['teamcalls.media.livekit.api_url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(MediaRoomService::class);
    expect(fn () => app(MediaRoomService::class)->prepareRoom($this->room, 2))->toThrow(CallException::class, 'media server');
})->group('livekit-real');
