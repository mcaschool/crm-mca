<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\TeamCalls\Contracts\MediaRoomService;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4I · teamcalls:health: salud y métricas para la supervisión de staging, sin secretos. Código 0 sano,
| 1 degradado (servidor de medios inalcanzable o cola de eventos retrasada).
*/

/** @return array{0: int, 1: array<string, mixed>, 2: string} */
function health(): array
{
    $code = Artisan::call('teamcalls:health', ['--json' => true]);
    $out = Artisan::output();

    return [$code, json_decode($out, true), $out];
}

beforeEach(function () {
    TC::fakes();
    FakeLiveKit::boot(['breaker_seconds' => 30]);
    config(['broadcasting.connections.reverb.key' => 'clave-publica', 'broadcasting.connections.reverb.secret' => 'secreto-reverb']);
    $this->inst = TC::institution();
    [$this->ana, $this->beto] = [TC::user($this->inst), TC::user($this->inst)];
});

it('sano: LiveKit alcanzable, salas, llamadas vivas y cola; sin secretos', function () {
    $id = Http::start($this->ana, $this->beto->id, 'health-0001')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    Http::post($this->beto, 'credentials', $id)->assertOk(); // crea la sala en LiveKit

    [$code, $report, $raw] = health();
    expect($code)->toBe(0)->and($report['status'])->toBe('ok')
        ->and($report['media'])->toMatchArray(['configured' => true, 'reachable' => true, 'breaker_open' => false, 'rooms' => 1])
        ->and($report['calls']['live'])->toBe(1)
        ->and($report['realtime']['key_configured'])->toBeTrue()
        ->and($report['ui']['production_blocked'])->toBeTrue()
        ->and($raw)->not->toContain(FakeLiveKit::SECRET)->not->toContain('secreto-reverb')->not->toContain('clave-publica');
});

it('degradado: LiveKit caído (abre el disyuntor) → código 1; sin servidor de medios configurado no se considera caído', function () {
    FakeLiveKit::$failure = 'down';
    [$code, $report] = health();
    expect($code)->toBe(1)->and($report['status'])->toBe('degraded')
        ->and($report['media'])->toMatchArray(['reachable' => false, 'breaker_open' => true]);

    config(['teamcalls.media.driver' => 'none']);
    app()->forgetInstance(MediaRoomService::class);
    [$code, $report] = health();
    expect($code)->toBe(0)->and($report['media']['configured'])->toBeFalse();
});
