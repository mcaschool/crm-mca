<?php

declare(strict_types=1);

use Modules\CollaborationCore\Services\ApplicationAccess;
use Modules\TeamCalls\Http\CallLaunch;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4I · aviso de llamada entrante en CUALQUIER pantalla del CRM: el layout incluye el aviso de TeamCalls
| solo donde las llamadas están habilitadas (nunca en producción), con sesión y collaboration.use, y no en
| la propia página de llamadas. Sin secretos. «Aceptar» abre la llamada con answer=1 (CallLaunch).
*/

function notifier_html(mixed $user, string $url = '/profile'): string
{
    return (string) Http::as($user)->get($url)->assertOk()->getContent();
}

beforeEach(function () {
    TC::fakes();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    $this->ana = TC::user($this->inst, ['name' => 'Ana Uno']);
});

it('en cualquier pantalla del CRM (habilitado): aviso con la API propia, el tiempo real público y sin secretos', function () {
    FakeLiveKit::boot();
    config(['broadcasting.connections.reverb.key' => 'clave-publica', 'broadcasting.connections.reverb.secret' => 'secreto-reverb']);
    $html = notifier_html($this->ana);
    expect($html)->toContain('id="tc-incoming-config"')->toContain('teamcalls-incoming.js')->toContain('teamcalls-incoming.css')
        ->toContain('lanzador.js')
        ->not->toContain(FakeLiveKit::SECRET)->not->toContain(FakeLiveKit::KEY)->not->toContain('secreto-reverb')->not->toContain('/api/teamcalls/media');
    preg_match('~<script type="application/json" id="tc-incoming-config">(.*?)</script>~s', $html, $m);
    $config = json_decode(html_entity_decode($m[1]), true);
    expect($config['me'])->toBe(['id' => $this->ana->id])
        ->and($config['routes']['live'])->toBe(route('collab.calls.ui.live'))
        ->and($config['routes']['decline'])->toBe(route('collab.calls.decline', ['call' => '__CALL__']))
        ->and($config['realtime']['socket']['key'])->toBe('clave-publica')
        ->and($config['i18n'])->toHaveKeys(['incoming_from', 'accept', 'decline', 'missed_from', 'call_back']);
});

it('no se incluye: deshabilitado, en producción, sin collaboration.use, en la propia página de llamadas o desactivado', function () {
    config(['teamcalls.ui.enabled' => false]);
    expect(notifier_html($this->ana))->not->toContain('tc-incoming-config');

    config(['teamcalls.ui.enabled' => true, 'teamcalls.ui.environments' => ['testing', 'production']]);
    $env = app()['env'];
    app()['env'] = 'production';
    try {
        expect(view('teamcalls::partials.incoming')->render())->not->toContain('tc-incoming-config');
    } finally {
        app()['env'] = $env;
    }

    config(['teamcalls.ui.environments' => ['local', 'testing']]);
    expect(notifier_html($this->ana, route('collab.calls.ui')))->not->toContain('tc-incoming-config'); // la página avisa por sí misma

    config(['teamcalls.ui.incoming.enabled' => false]); // cuando TeamChat/el widget asuman los avisos
    expect(notifier_html($this->ana))->not->toContain('tc-incoming-config');
    config(['teamcalls.ui.incoming.enabled' => true]);

    $beto = TC::user($this->inst);
    app(ApplicationAccess::class)->set(TC::user($this->inst, ['role' => 'admin']), $beto, 'collaboration', false);
    expect(notifier_html($beto->fresh()))->not->toContain('tc-incoming-config');
    expect(notifier_html($this->ana))->toContain('tc-incoming-config');
});

it('«Aceptar» desde el aviso: answer=1 solo para abrir una llamada existente', function () {
    $q = fn (array $query, string $site = 'same-origin') => CallLaunch::fromRequest(\Illuminate\Http\Request::create('/colaboracion/teamcalls', 'GET', $query, [], [], ['HTTP_SEC_FETCH_SITE' => $site]));
    $call = '01hzy3j9s2q8w6n4r7t5v1x0bc';
    expect($q(['call' => $call, 'answer' => '1']))->toBe(['action' => 'open', 'call' => $call, 'answer' => true, 'confirm' => false])
        ->and($q(['call' => $call, 'answer' => '1'], 'cross-site')['confirm'])->toBeTrue() // un enlace ajeno nunca contesta solo
        ->and($q(['call' => $call, 'answer' => '2'])['action'])->toBe('invalid')
        ->and($q(['media' => 'audio', 'user' => '5', 'answer' => '1'])['action'])->toBe('invalid');
});
