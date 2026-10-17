<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Http\CallLaunch;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4H · habilitación para PREPRODUCCIÓN. La interfaz sigue cerrada por defecto; solo se abre con el
| interruptor y nombrando el entorno de forma explícita, y producción nunca. En el entorno habilitado
| se exige la misma pila: sesión, 2FA, CollaborationCore (collaboration.use, dispositivo, institución).
| «staging» representa la preproducción simulando el entorno de la aplicación.
*/

function tc_in_env(string $env, callable $fn): mixed
{
    $previous = app()['env'];
    app()['env'] = $env;
    try {
        return $fn();
    } finally {
        app()['env'] = $previous;
    }
}

/** @return array<string, mixed> */
function tc_page_config(mixed $response): array
{
    preg_match('~<script type="application/json" id="tc-config">(.*?)</script>~s', (string) $response->getContent(), $m);

    return json_decode(html_entity_decode($m[1] ?? 'null'), true);
}

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    $this->ana = TC::user($this->inst, ['name' => 'Ana Uno']);
});

it('cerrada por defecto: sin interruptor no existe; con interruptor, la preproducción solo si se nombra su entorno', function () {
    // Configuración arrancada por defecto: interruptor apagado y solo desarrollo y pruebas.
    expect(config('teamcalls.ui.enabled'))->toBeFalse()
        ->and(config('teamcalls.ui.environments'))->toBe(['local', 'testing']);

    foreach (['testing', 'staging'] as $env) {
        tc_in_env($env, function () {
            Http::as($this->ana)->get(route('collab.calls.ui'))->assertNotFound();
            $this->get(route('collab.calls.ui.launcher'))->assertNotFound();
            Http::as($this->ana)->getJson(route('collab.calls.ui.contacts'))->assertNotFound();
        });
    }

    config(['teamcalls.ui.enabled' => true]); // interruptor, pero «staging» no está en la lista
    tc_in_env('staging', function () {
        expect(CallLaunch::enabled())->toBeFalse();
        Http::as($this->ana)->get(route('collab.calls.ui'))->assertNotFound();
    });
});

it('producción nunca, aunque se active el interruptor y se liste «production»', function () {
    config(['teamcalls.ui.enabled' => true, 'teamcalls.ui.environments' => ['production', 'staging']]);
    tc_in_env('production', function () {
        expect(CallLaunch::enabled())->toBeFalse()
            ->and(trim(view('teamcalls::partials.launcher')->render()))->toBe('');
        Http::as($this->ana)->get(route('collab.calls.ui'))->assertNotFound();
        $this->get(route('collab.calls.ui.launcher'))->assertNotFound();
        Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertNotFound();
    });
});

it('preproducción habilitada: un colaborador autorizado entra; sin sesión, sin 2FA o sin collaboration.use, no', function () {
    config(['teamcalls.ui.enabled' => true, 'teamcalls.ui.environments' => ['staging'], 'teamcalls.ui.synthetic_media' => true]);
    tc_in_env('staging', function () {
        $page = Http::as($this->ana)->get(route('collab.calls.ui', ['sintetico' => 1]))->assertOk()
            ->assertSee(__('teamcalls.ui.preprod_notice'))->assertDontSee(__('teamcalls.ui.dev_notice'));
        $config = tc_page_config($page);
        expect($config['stage'])->toBe('preproduction')
            ->and($config['synthetic'])->toBeFalse(); // medios sintéticos: solo en desarrollo
        Http::as($this->ana)->getJson(route('collab.calls.ui.contacts'))->assertOk();
        $this->get(route('collab.calls.ui.launcher'))->assertOk();

        // Sin sesión: al login (página) o 401 (JSON). Sin 2FA: a «Mi perfil» o 403.
        auth()->forgetGuards();
        $this->get(route('collab.calls.ui'))->assertRedirect('/login');
        $this->getJson(route('collab.calls.ui.contacts'))->assertUnauthorized();
        $sin2fa = TC::user($this->inst, TC::NO_2FA);
        Http::as($sin2fa)->get(route('collab.calls.ui'))->assertRedirect(route('profile.me'));

        // Sin collaboration.use (retirado por un administrador): ni la página ni la API de la interfaz.
        $beto = TC::user($this->inst, ['name' => 'Beto Dos']);
        Http::as($beto)->get(route('collab.calls.ui'))->assertOk();
        app(ApplicationAccess::class)->set(TC::user($this->inst, ['role' => 'admin']), $beto, 'collaboration', false);
        Http::as($beto->fresh())->get(route('collab.calls.ui'))->assertForbidden();
        Http::as($beto->fresh())->getJson(route('collab.calls.ui.contacts'))->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        // Aislamiento institucional: los contactos son solo de la institución propia.
        $foreign = TC::user(TC::institution(), ['name' => 'Ajena Tres']);
        app(CurrentInstitution::class)->set($this->inst->id);
        $contacts = Http::as($this->ana)->getJson(route('collab.calls.ui.contacts'))->assertOk()->json('data.contacts');
        expect(collect($contacts)->pluck('id'))->not->toContain($foreign->id);
    });
});

it('la página no expone credenciales ni rutas administrativas; tiempo real solo por TLS fuera de desarrollo', function () {
    FakeLiveKit::boot();
    config(['teamcalls.ui.enabled' => true, 'teamcalls.ui.environments' => ['testing', 'staging'],
        'broadcasting.connections.reverb.key' => 'clave-publica-reverb', 'broadcasting.connections.reverb.secret' => 'secreto-reverb-no-publicar']);

    foreach (['testing', 'staging'] as $env) {
        tc_in_env($env, function () {
            $html = (string) Http::as($this->ana)->get(route('collab.calls.ui'))->assertOk()->getContent();
            expect($html)->not->toContain(FakeLiveKit::SECRET)->not->toContain(FakeLiveKit::KEY)->not->toContain(FakeLiveKit::URL)
                ->not->toContain('secreto-reverb-no-publicar')->not->toContain('/api/teamcalls/media')->not->toContain('collaboration/widget/authorize');
            foreach (tc_page_config(Http::as($this->ana)->get(route('collab.calls.ui')))['routes'] as $url) {
                // Solo la interfaz y la API de llamadas de la propia persona: nada administrativo.
                expect($url)->toMatch('~^'.preg_quote(url('/'), '~').'/(colaboracion/(teamcalls|llamadas)|api/v1/collaboration/calls)~');
            }
        });
    }

    config(['teamcalls.realtime.scheme' => 'http']);
    $socket = fn () => tc_page_config(Http::as($this->ana)->get(route('collab.calls.ui')))['realtime']['socket'];
    expect($socket()['key'])->toBe('clave-publica-reverb'); // desarrollo: ws local permitido
    expect(tc_in_env('staging', $socket)['key'])->toBe(''); // preproducción sin TLS: solo sondeo
    config(['teamcalls.realtime.scheme' => 'https']);
    expect(tc_in_env('staging', $socket)['key'])->toBe('clave-publica-reverb');
});

it('fin de sesión en la página: sesión o dispositivo revocados liberan medios y dejan de consultar (node --test)', function () {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Node no está disponible.');
    }
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=tap', module_path('TeamCalls', 'tests/js/group.test.cjs')]);
    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('# fail 0');
});
