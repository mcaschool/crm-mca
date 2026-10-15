<?php

declare(strict_types=1);

use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Compatibilidad con el formato REAL de LiveKit: cuerpos de webhooks capturados de LiveKit 1.13.10
| (fase 3B, tests/Fixtures/livekit) con su formato exacto (camelCase, espacios, ids EV_…, createdAt
| como texto). Solo se sustituyen sala, identidad y sesión por las de la prueba y se firman con el
| secreto de pruebas: la firma con el servidor real se verificó aparte (ver README, fase 3B).
*/

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot();
    $this->inst = TC::institution();
    [$this->ana, $this->beto] = [TC::user($this->inst), TC::user($this->inst)];
});

/** Cuerpo real con los valores de esta prueba, firmado como LiveKit (sha256 del cuerpo exacto). */
function lkReal(string $event, string $room, string $identity = '', string $sid = ''): \Illuminate\Testing\TestResponse
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/livekit/'.$event.'.json'), true);
    $raw = str_replace(['{{ROOM}}', '{{IDENTITY}}', '{{SID}}'], [$room, $identity, $sid], $fixture['raw']);
    // Cada evento real tiene su propio id (EV_…): uno por evento y sesión, estable para los reintentos.
    $raw = (string) preg_replace('/"id":"EV_[A-Za-z0-9]+"/', '"id":"EV_'.substr(md5($event.$room.$sid), 0, 12).'"', $raw);
    $token = \Firebase\JWT\JWT::encode(['iss' => FakeLiveKit::KEY, 'nbf' => time(), 'iat' => time(), 'exp' => time() + 300,
        'sha256' => base64_encode(hash('sha256', $raw, true))], FakeLiveKit::SECRET, 'HS256');

    return test()->call('POST', route('api.teamcalls.media.livekit'), [], [], [],
        ['CONTENT_TYPE' => $fixture['content_type'], 'HTTP_AUTHORIZATION' => $token], $raw); // sin «Bearer», como LiveKit
}

it('los webhooks reales de LiveKit se verifican, se interpretan y mueven el motor', function () {
    $call = TC::call($this->ana, [$this->beto->id], 'lk-real-0001');
    TC::service()->accept($this->beto, $call->id, 'b1');
    TC::service()->join($this->beto, $call->id, 'b1');
    TC::service()->join($this->ana, $call->id, 'pc-'.$this->ana->id);
    $room = TC::fresh($call)->room_name;
    $beto = 'u'.$this->beto->id.'.b1';
    $ana = 'u'.$this->ana->id.'.pc-'.$this->ana->id;

    FakeLiveKit::$rooms[$room] = ['max' => 25];
    lkReal('room_started', $room)->assertOk()->assertJsonPath('data.outcome', 'applied');
    FakeLiveKit::connect($room, $beto, 'PA_realB');
    lkReal('participant_joined', $room, $beto, 'PA_realB')->assertOk()->assertJsonPath('data.outcome', 'applied');
    lkReal('track_published', $room, $beto, 'PA_realB')->assertOk()->assertJsonPath('data.outcome', 'ignored');
    FakeLiveKit::connect($room, $ana, 'PA_realA');
    lkReal('participant_joined', $room, $ana, 'PA_realA')->assertOk();

    $p = CallParticipant::query()->where('call_id', $call->id)->where('user_id', $this->beto->id)->sole();
    expect(TC::fresh($call)->status->value)->toBe('active')
        ->and($p->media_session)->toBe('PA_realB')
        ->and(FakeLiveKit::$admitted)->toHaveKeys([$room.'|'.$beto, $room.'|'.$ana]);

    // El mismo webhook real repetido (LiveKit reintenta) no cambia nada.
    lkReal('participant_joined', $room, $beto, 'PA_realB')->assertOk();
    expect(\Modules\TeamCalls\Models\CallEvent::query()->where('call_id', $call->id)->where('type', 'call.media.joined')->count())->toBe(2);

    // Salida real + margen de reconexión + cierre real de la sala.
    lkReal('participant_left', $room, $beto, 'PA_realB')->assertOk();
    expect(CallParticipant::query()->where('call_id', $call->id)->where('user_id', $this->beto->id)->value('media_state')->value)->toBe('disconnected');
    lkReal('room_finished', $room)->assertOk();
    expect(Call::query()->findOrFail($call->id)->status->value)->toBe('ended');
});

it('un cuerpo real alterado después de firmarlo se rechaza', function () {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/livekit/room_started.json'), true);
    $raw = str_replace('{{ROOM}}', 'tc-x', $fixture['raw']);
    $token = \Firebase\JWT\JWT::encode(['iss' => FakeLiveKit::KEY, 'nbf' => time(), 'exp' => time() + 300,
        'sha256' => base64_encode(hash('sha256', $raw, true))], FakeLiveKit::SECRET, 'HS256');
    // Re-serializar el JSON (p. ej. compactarlo) cambia los bytes: la firma solo vale sobre el cuerpo original.
    $compacted = json_encode(json_decode($raw, true));
    test()->call('POST', route('api.teamcalls.media.livekit'), [], [], [], ['CONTENT_TYPE' => 'application/webhook+json', 'HTTP_AUTHORIZATION' => $token], $compacted)
        ->assertUnauthorized();
});
