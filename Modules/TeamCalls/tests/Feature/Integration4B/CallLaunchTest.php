<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Modules\TeamCalls\Http\CallLaunch;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\RealCollaboration;
use Modules\TeamCalls\Tests\Support\TC;
use Modules\TeamChat\Services\ChatCommands;
use Modules\TeamChat\Services\SpaceCommands;

/*
| Fase 4E · punto de entrada a la interfaz de llamadas desde otras aplicaciones (TeamChat, widget):
| la intención llega a la página solo como FORMATO (CallLaunch) y la página la ejecuta con la API
| existente, que autoriza conversación, membresía y participantes con los servicios reales de Core y
| TeamChat. El lanzador JS se prueba con node --test (tests/js/launcher.test.cjs).
*/

/** @return array<string, mixed> */
function launch_config(TestResponse $res): array
{
    preg_match('~<script type="application/json" id="tc-config">(.*?)</script>~s', (string) $res->getContent(), $m);

    return json_decode(html_entity_decode($m[1] ?? 'null'), true);
}

/** @param array<string, string> $query */
function launch_intent(mixed $user, array $query, ?string $site = 'same-origin'): mixed
{
    $req = Http::as($user);
    if ($site !== null) {
        $req = $req->withHeader('Sec-Fetch-Site', $site);
    }

    return launch_config($req->get(route('collab.calls.ui', $query))->assertOk())['intent'];
}

beforeEach(function () {
    RealCollaboration::setUp();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst, ['name' => 'Ana']), TC::user($this->inst, ['name' => 'Beto']), TC::user($this->inst, ['name' => 'Carla'])];
});

it('intención: cada forma admitida llega a la página como formato validado; lo mal formado queda inválido', function () {
    $conv = '01HZY3J9S2Q8W6N4R7T5V1X0BC';
    $ok = [
        [['media' => 'video', 'user' => '42'], ['action' => 'start', 'media' => 'video', 'key' => null, 'user' => 42]],
        [['media' => 'audio', 'conversation' => "direct:$conv"], ['action' => 'start', 'media' => 'audio', 'key' => null, 'conversation' => ['type' => 'direct', 'id' => $conv]]],
        [['media' => 'audio', 'conversation' => "channel:$conv", 'invitees' => '5,6,5', 'key' => 'l-abc.123:9'],
            ['action' => 'start', 'media' => 'audio', 'key' => 'l-abc.123:9', 'conversation' => ['type' => 'channel', 'id' => $conv], 'invitees' => [5, 6]]],
        [['media' => 'video', 'conversation' => "group:$conv", 'invitees' => '7'], ['action' => 'start', 'media' => 'video', 'key' => null, 'conversation' => ['type' => 'group', 'id' => $conv], 'invitees' => [7]]],
        [['call' => strtolower($conv)], ['action' => 'open', 'call' => strtolower($conv)]], // ULID de TeamCalls: tal cual
    ];
    foreach ($ok as [$query, $expected]) {
        expect(launch_intent($this->ana, $query))->toBe($expected + ['confirm' => false]);
    }

    $bad = [
        ['user' => '42'],                                                        // sin medio
        ['media' => 'fax', 'user' => '42'],
        ['media' => 'audio', 'user' => '0'],
        ['media' => 'audio', 'user' => '42', 'conversation' => "direct:$conv"],   // destino ambiguo
        ['media' => 'audio', 'user' => '42', 'invitees' => '5'],
        ['media' => 'audio', 'conversation' => "direct:$conv", 'invitees' => '5'], // la otra persona la resuelve el servidor
        ['media' => 'audio', 'conversation' => "group:$conv"],                    // grupo sin selección explícita
        ['media' => 'audio', 'conversation' => "channel:$conv", 'invitees' => implode(',', range(1, 25))],
        ['media' => 'audio', 'conversation' => "team:$conv", 'invitees' => '5'],
        ['media' => 'audio', 'conversation' => 'direct:../x'],
        ['call' => $conv, 'media' => 'audio'],
        ['call' => 'no-es-un-ulid'],
        ['media' => 'audio', 'user' => '42', 'key' => 'corta'],
    ];
    foreach ($bad as $query) {
        expect(launch_intent($this->ana, $query))->toBe(['action' => 'invalid', 'confirm' => false], json_encode($query));
    }

    expect(launch_intent($this->ana, []))->toBeNull()
        ->and(launch_intent($this->ana, ['sintetico' => '1']))->toBeNull();
});

it('una navegación que no viene de la propia aplicación nunca inicia ni abre nada sola: pide confirmación', function () {
    $q = ['media' => 'video', 'user' => (string) $this->beto->id];
    expect(launch_intent($this->ana, $q, 'cross-site')['confirm'])->toBeTrue()
        ->and(launch_intent($this->ana, $q, 'same-site')['confirm'])->toBeTrue()
        ->and(launch_intent($this->ana, $q, 'none')['confirm'])->toBeTrue()
        ->and(launch_intent($this->ana, $q, null)['confirm'])->toBeTrue()
        ->and(launch_intent($this->ana, $q)['confirm'])->toBeFalse();
});

it('CallLaunch::url genera el contrato que entiende la página y rechaza intenciones no válidas', function () {
    $url = CallLaunch::url(['media' => 'video', 'conversation' => ['type' => 'group', 'id' => '01HZY3J9S2Q8W6N4R7T5V1X0BC'], 'invitees' => [3, 4]]);
    expect($url)->toStartWith(route('collab.calls.ui').'?');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(launch_intent($this->ana, $query))->toMatchArray(['action' => 'start', 'invitees' => [3, 4]]);

    expect(fn () => CallLaunch::url(['media' => 'video', 'conversation' => 'group:01HZY3J9S2Q8W6N4R7T5V1X0BC']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CallLaunch::url(['call' => 'x']))->toThrow(InvalidArgumentException::class);
});

it('lanzador: solo en desarrollo con el interruptor, sin sesión ni cookies; el parcial no imprime nada si no está habilitado', function () {
    $js = $this->get(route('collab.calls.ui.launcher'))->assertOk();
    expect($js->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($js->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($js->headers->getCookies())->toBe([]);
    $source = (string) file_get_contents(module_path('TeamCalls', 'resources/assets/js/teamcalls-launcher.js'));
    expect($source)->not->toContain('localStorage')->not->toContain('sessionStorage')->not->toContain('document.cookie')
        ->not->toMatch('/console\.(log|info|debug)\(/');

    $html = view('teamcalls::partials.launcher', ['nonce' => 'n0nce'])->render();
    expect($html)->toContain(route('collab.calls.ui.launcher'))->toContain('data-page="'.route('collab.calls.ui').'"')
        ->toContain('data-active="'.route('collaboration.calls.active').'"')->toContain('nonce="n0nce"');

    config(['teamcalls.ui.enabled' => false]);
    $this->get(route('collab.calls.ui.launcher'))->assertNotFound();
    expect(trim(view('teamcalls::partials.launcher')->render()))->toBe('');

    config(['teamcalls.ui.enabled' => true]);
    $env = app()['env'];
    app()['env'] = 'production';
    try {
        $this->get(route('collab.calls.ui.launcher'))->assertNotFound();
        expect(trim(view('teamcalls::partials.launcher')->render()))->toBe('');
    } finally {
        app()['env'] = $env;
    }
});

it('conversación privada: la página inicia por la API definitiva, la otra persona abre la llamada existente y un tercero no', function () {
    $chat = app(ChatCommands::class)->direct(RealCollaboration::actor($this->ana), $this->beto->id);
    $cfg = launch_config(Http::as($this->ana)->withHeader('Sec-Fetch-Site', 'same-origin')
        ->get(route('collab.calls.ui', ['media' => 'video', 'conversation' => "direct:$chat", 'key' => 'l-direct-0001']))->assertOk());
    expect($cfg['intent'])->toMatchArray(['action' => 'start', 'conversation' => ['type' => 'direct', 'id' => $chat], 'confirm' => false]);

    // Lo que hace la página con esa intención (teamcalls-ui.js · startConversationCall).
    $start = fn ($user, $key, $conversation) => Http::as($user)->withHeader('Idempotency-Key', 'ui-'.$key)
        ->postJson($cfg['routes']['conversation_store'], ['media' => 'video', 'conversation' => $conversation]);
    $res = $start($this->ana, 'l-direct-0001', ['type' => 'direct', 'id' => $chat])->assertCreated();
    $id = $res->json('data.id');
    expect($res->json('data.conversation'))->toBe(['type' => 'direct', 'id' => $chat])
        ->and(collect($res->json('data.participants'))->pluck('state', 'user_id')->all())
        ->toEqual([$this->ana->id => 'accepted', $this->beto->id => 'ringing']);
    // Repetir la misma intención (misma clave) no crea otra llamada.
    expect($start($this->ana, 'l-direct-0001', ['type' => 'direct', 'id' => $chat])->json('data.id'))->toBe($id);

    // Beto abre la llamada existente (desde TeamChat o el aviso): la página la consulta y le suena.
    expect(launch_intent($this->beto, ['call' => $id]))->toBe(['action' => 'open', 'call' => $id, 'confirm' => false]);
    Http::get($this->beto, 'show', $id)->assertOk()->assertJsonPath('data.viewer_state', 'ringing');

    // Carla: la intención se acepta como formato, pero el servidor no le deja ver la llamada ni llamar en esa conversación.
    expect(launch_intent($this->carla, ['call' => $id])['action'])->toBe('open');
    expect(Http::get($this->carla, 'show', $id)->status())->toBeIn([403, 404]);
    expect($start($this->carla, 'l-direct-0002', ['type' => 'direct', 'id' => $chat])->status())->toBeIn([403, 404]);
});

it('grupos y canales: la invitación explícita de la intención se valida en el servidor contra la membresía', function () {
    $spaces = app(SpaceCommands::class);
    $a = RealCollaboration::actor($this->ana);
    $team = $spaces->create($a, 'team', 'team-cmd-4e01', 'Admisiones', '', [$this->beto->id, $this->carla->id]);
    $channel = $spaces->create($a, 'channel', 'chan-cmd-4e01', 'Coordinación', '', [$this->beto->id], $team, 'private');
    $url = route('collaboration.calls.store');
    $start = fn (array $invitees, string $key) => Http::as($this->ana)->withHeader('Idempotency-Key', $key)
        ->postJson($url, ['media' => 'audio', 'conversation' => ['type' => 'channel', 'id' => $channel], 'invitees' => $invitees]);

    expect($start([$this->carla->id], 'ui-l-chan-0001')->status())->toBe(403); // del equipo, no del canal
    expect($start([], 'ui-l-chan-0002')->status())->toBeGreaterThanOrEqual(400); // sin selección explícita
    $ok = $start([$this->beto->id], 'ui-l-chan-0003')->assertCreated();
    expect(collect($ok->json('data.participants'))->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->ana->id, $this->beto->id])->sort()->values()->all());
});

it('lanzador JS: validación, URL, reutilizar la ventana sin recargarla, ventanas bloqueadas y botones declarativos (node --test)', function () {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Node no está disponible para las pruebas del lanzador JS.');
    }
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=tap', module_path('TeamCalls', 'tests/js/launcher.test.cjs')]);
    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('# fail 0');
});
