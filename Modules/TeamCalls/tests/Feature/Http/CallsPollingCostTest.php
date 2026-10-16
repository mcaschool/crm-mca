<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Coste del sondeo temporal (hasta CollaborationRealtime): consultas por petición y bloqueos de filas.
| Ver la llamada o sondear «en curso» no debe bloquear filas (FOR UPDATE) si no hay nada vencido que
| aplicar: el sondeo de varias pestañas no puede competir con los webhooks ni con las operaciones.
*/

beforeEach(function () {
    TC::fakes();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    [$this->ana, $this->beto] = [TC::user($this->inst), TC::user($this->inst)];
});

/** @return array{queries: int, locks: int, teamcalls: int} */
function tcMeasure(callable $request): array
{
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $request();
    $tc = array_filter($sql, fn ($s) => str_contains($s, 'collab_call_'));

    return [
        'queries' => count($sql),
        // Bloqueos de TeamCalls (el registro/validación del dispositivo por petición es de CollaborationCore).
        'locks' => count(array_filter($tc, fn ($s) => stripos($s, 'for update') !== false || stripos($s, 'lock in share mode') !== false)),
        'teamcalls' => count($tc),
    ];
}

it('sondeo de «en curso» sin llamadas: pocas consultas de TeamCalls y ningún bloqueo', function () {
    Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertOk(); // calienta la sesión
    $cost = tcMeasure(fn () => Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertOk());
    expect($cost['locks'])->toBe(0)->and($cost['teamcalls'])->toBeLessThanOrEqual(2);
});

it('sondeo de «en curso» y de la llamada sonando o en curso: sin bloqueos si no hay nada vencido', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    $live = tcMeasure(fn () => Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertOk()->assertJsonPath('meta.count', 1));
    $show = tcMeasure(fn () => Http::get($this->ana, 'show', $id)->assertOk()->assertJsonPath('data.status', 'ringing'));
    expect($live['locks'])->toBe(0)->and($show['locks'])->toBe(0)
        ->and($live['teamcalls'])->toBeLessThanOrEqual(3)->and($show['teamcalls'])->toBeLessThanOrEqual(3);
});

it('lo vencido se sigue aplicando al consultar (solo entonces se bloquea la llamada)', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    $this->travel(46)->seconds();

    $show = tcMeasure(fn () => Http::get($this->ana, 'show', $id)->assertOk()->assertJsonPath('data.status', 'missed'));
    expect($show['locks'])->toBeGreaterThan(0);
    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 0);
});
