<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\TeamCalls\Integration\Collaboration\CallEventPolicy;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4I · contrato de eventos call.* para TeamChat y el widget (docs/TEAMCALLS-TEAMCHAT-INTEGRATION-4I.md):
| esquema congelado, consumo por la API común de CollaborationRealtime (/api/v1/collaboration/sync) con los
| servicios reales de Core y Realtime, autorización por participante e institución, y lectura sin
| duplicados. Si un cambio rompe este contrato, la prueba lo dice antes que la integración de Codex.
*/

/** @return list<array<string, mixed>> eventos call.* que lee esa persona desde su cursor */
function contract_events(mixed $user, string $cursor): array
{
    $page = Http::as($user)->getJson('/api/v1/collaboration/sync?limit=100&cursor='.urlencode($cursor))->assertOk()->json('data');

    return array_values(array_filter($page['events'], fn (array $e) => str_starts_with((string) $e['type'], 'call.')));
}

function contract_cursor(mixed $user): string
{
    return (string) Http::as($user)->getJson('/api/v1/collaboration/sync?bootstrap=1&limit=1')->assertOk()->json('data.cursor');
}

beforeEach(function () {
    RealCollaboration::setUp();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

it('esquema congelado de los eventos call.* (campos obligatorios y opcionales además de call_id)', function () {
    expect(CallEventPolicy::SCHEMAS)->toBe([
        'call.started' => [['media', 'scope'], []],
        'call.ringing' => [['user_id', 'media'], []],
        'call.accepted' => [['user_id'], []],
        'call.answered_elsewhere' => [['user_id'], []],
        'call.declined' => [['user_id'], []],
        'call.missed' => [['user_id'], []],
        'call.connected' => [[], []],
        'call.ended' => [['status', 'reason'], []],
        'call.participant.connecting' => [['user_id'], []],
        'call.participant.joined' => [['user_id'], []],
        'call.participant.left' => [['user_id'], []],
        'call.participant.revoked' => [['user_id', 'reason'], []],
        'call.media.joined' => [['user_id', 'applied'], ['rejected']],
        'call.media.left' => [['user_id', 'applied'], ['rejected']],
        'call.media.room_finished' => [['participants'], []],
        'call.media.room_rotated' => [['reason'], []],
    ]);
});

it('quien recibe la llamada lee call.ringing y el cierre por la API común; un tercero no lee nada; sin duplicados', function () {
    $beto = contract_cursor($this->beto);
    $carla = contract_cursor($this->carla);
    $id = Http::start($this->ana, $this->beto->id, 'contract-0001', 'video')->assertCreated()->json('data.id');

    $events = contract_events($this->beto, $beto);
    $ringing = collect($events)->firstWhere('type', 'call.ringing');
    expect($ringing)->not->toBeNull()
        ->and($ringing['data'])->toMatchArray(['call_id' => $id, 'user_id' => $this->beto->id, 'media' => 'video'])
        ->and($ringing['id'])->toBeString()->not->toBeEmpty()
        ->and(json_encode($events))->not->toContain('room')->not->toContain('token')->not->toContain('identity');
    // Releer desde el mismo cursor devuelve los mismos ids: el consumidor deduplica por id (EventDeduper).
    expect(collect(contract_events($this->beto, $beto))->pluck('id')->all())->toBe(collect($events)->pluck('id')->all());
    expect(contract_events($this->carla, $carla))->toBe([]); // no participa: ni sabe que existe

    Http::post($this->ana, 'cancel', $id)->assertOk();
    $ended = collect(contract_events($this->beto, $beto))->firstWhere('type', 'call.ended');
    expect($ended['data'])->toMatchArray(['call_id' => $id, 'status' => 'cancelled']);
});

it('rechazar y perder: quien llama lee el rechazo y el cierre; quien no contestó lee su llamada perdida', function () {
    $ana = contract_cursor($this->ana);
    $beto = contract_cursor($this->beto);
    $id = Http::start($this->ana, $this->beto->id, 'contract-0002')->json('data.id');
    Http::post($this->beto, 'decline', $id)->assertOk();
    $types = collect(contract_events($this->ana, $ana))->pluck('type')->all();
    expect($types)->toContain('call.declined')->toContain('call.ended');

    $id2 = Http::start($this->ana, $this->beto->id, 'contract-0003')->json('data.id');
    $this->travel(46)->seconds(); // sin respuesta
    Http::get($this->beto, 'show', $id2)->assertJsonPath('data.status', 'missed');
    $missed = collect(contract_events($this->beto, $beto))->first(fn ($e) => $e['type'] === 'call.missed' && $e['data']['call_id'] === $id2);
    expect($missed['data'])->toMatchArray(['user_id' => $this->beto->id]);
});

it('otra institución no lee los eventos de llamadas ajenas', function () {
    $foreign = TC::user(TC::institution());
    $cursor = contract_cursor($foreign);
    app(\Modules\Core\Tenancy\CurrentInstitution::class)->set($this->inst->id);
    Http::start($this->ana, $this->beto->id, 'contract-0004')->assertCreated();
    expect(contract_events($foreign, $cursor))->toBe([]);
});

it('lógica del aviso global y de los fallos: sin duplicados, causas distinguidas (node --test)', function () {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Node no está disponible.');
    }
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=tap', module_path('TeamCalls', 'tests/js/group.test.cjs')]);
    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())->and($run->output())->toContain('# fail 0');
});
