<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 4F · interfaz de llamadas grupales y pantalla compartida: la página publica las rutas
| EXISTENTES para invitar y retirar (las autoriza el servidor, ya probado en CallApiTest) y los
| elementos de la sala; la lógica sin DOM (mosaico, avisos, permisos de LiveKit) se prueba con
| node --test. Los permisos de pantalla por tipo de llamada están en LiveKitAdapterTest.
*/

beforeEach(function () {
    TC::fakes();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    $this->ana = TC::user($this->inst, ['name' => 'Ana Uno']);
});

it('la página publica invitar y retirar con la API existente, y la sala con pantalla principal, mosaico y controles', function () {
    $html = (string) Http::as($this->ana)->get(route('collab.calls.ui'))->assertOk()->getContent();
    preg_match('~<script type="application/json" id="tc-config">(.*?)</script>~s', $html, $m);
    $config = json_decode(html_entity_decode($m[1]), true);

    expect($config['routes']['invite'])->toBe(route('collab.calls.invite', ['call' => '__CALL__']))
        ->and($config['routes']['revoke'])->toBe(route('collab.calls.revoke', ['call' => '__CALL__']))
        ->and($config['i18n'])->toHaveKeys(['screen_start', 'screen_stop', 'screen_ended', 'toast_joined', 'toast_left', 'toast_reconnecting', 'remove_confirm', 'mic_is_off', 'cam_is_off']);
    expect($config['i18n'])->toHaveKeys(['pin', 'unpin', 'view_grid', 'view_speaker', 'q_good', 'q_unstable', 'q_lost']); // fase 4G
    foreach (['id="tc-room"', 'id="tc-focus"', 'id="tc-tiles"', 'id="tc-screen"', 'id="tc-people-btn"', 'id="tc-invite-panel"', 'id="tc-toasts"', 'id="tc-fullscreen"',
        'id="tc-view"', 'id="tc-back-screen"'] as $el) {
        expect($html)->toContain($el);
    }
    // Compartir pantalla arranca oculto: solo aparece cuando LiveKit confirma el permiso de esa fuente.
    expect($html)->toMatch('~id="tc-screen"[^>]*hidden~');
});

it('mosaico, avisos y permisos de pantalla (node --test)', function () {
    if (! Process::run(['node', '--version'])->successful()) {
        $this->markTestSkipped('Node no está disponible.');
    }
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=tap', module_path('TeamCalls', 'tests/js/group.test.cjs')]);
    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('# fail 0');
});
