<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Fase 3D: estabilidad de la interfaz web. Núcleo JS (pestañas, eventos repetidos, cadencia del sondeo,
| mensajes sin credenciales, tiempo real) con node --test; cierre de sesión; credenciales nunca en el
| almacenamiento del navegador; parcial reutilizable sin el layout del CRM.
*/

beforeEach(function () {
    TC::fakes();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    [$this->ana, $this->beto] = [TC::user($this->inst), TC::user($this->inst)];
});

it('núcleo JS: dos pestañas, eventos repetidos, cadencia del sondeo, mensajes sin credenciales y tiempo real (node --test)', function () {
    $node = Process::run(['node', '--version']);
    if (! $node->successful()) {
        $this->markTestSkipped('Node no está disponible para las pruebas del núcleo JS.');
    }
    $run = Process::path(base_path())->timeout(120)->run(['node', '--test', '--test-reporter=tap', module_path('TeamCalls', 'tests/js/core.test.cjs')]);
    expect($run->exitCode())->toBe(0, $run->output().$run->errorOutput())
        ->and($run->output())->toContain('# fail 0');
});

it('el JS de la interfaz no guarda nada en el navegador (ni credenciales ni estado de llamadas)', function () {
    foreach (['teamcalls-core.js', 'teamcalls-ui.js'] as $file) {
        $js = (string) file_get_contents(module_path('TeamCalls', 'resources/assets/js/'.$file));
        expect($js)->not->toContain('localStorage')->not->toContain('sessionStorage')
            ->not->toContain('indexedDB')->not->toContain('document.cookie')->not->toMatch('/console\.(log|info|debug)\(/');
    }
});

it('cerrar sesión saca a la persona de su llamada en ESE dispositivo y ya no puede pedir credenciales', function () {
    $id = Http::start($this->ana, $this->beto->id, 'ui-logout-0001')->json('data.id');
    Http::post($this->beto, 'accept', $id)->assertOk();
    Http::post($this->beto, 'join', $id)->assertOk();
    $device = CallParticipant::query()->where('call_id', $id)->where('user_id', $this->beto->id)->value('device_id');

    Http::as($this->beto)->post(route('logout'))->assertRedirect();

    $p = CallParticipant::query()->where('call_id', $id)->where('user_id', $this->beto->id)->sole();
    expect($p->state->value)->toBe('left')
        ->and(TC::$rec->removed)->toContain(TC::fresh(\Modules\TeamCalls\Models\Call::query()->findOrFail($id))->room_name.'|u'.$this->beto->id.'.'.$device);

    auth()->forgetGuards();
    $this->postJson(route('collab.calls.credentials', $id))->assertUnauthorized(); // sin sesión: sin credenciales
});

it('cerrar sesión en un dispositivo no saca a la persona de la llamada que atiende en otro', function () {
    $call = TC::call($this->ana, [$this->beto->id], 'ui-logout-0002');
    TC::service()->accept($this->beto, $call->id, 'beto-movil'); // otro dispositivo, no esta sesión web

    Http::as($this->beto)->get(route('collab.calls.ui'))->assertOk(); // la sesión web obtiene su propio dispositivo
    Http::as($this->beto)->post(route('logout'))->assertRedirect();

    expect(CallParticipant::query()->where('call_id', $call->id)->where('user_id', $this->beto->id)->value('state')->value)->toBe('accepted');
});

it('parcial reutilizable (MCA One): sin layout ni componentes del CRM, autocontenido', function () {
    $html = view('teamcalls::partials.calls', [
        'config' => ['me' => ['id' => 1, 'name' => 'X'], 'routes' => [], 'i18n' => []],
        'assets' => ['teamcalls-ui.css' => '/a.css', 'livekit-client.umd.js' => '/lk.js', 'teamcalls-core.js' => '/core.js', 'teamcalls-ui.js' => '/ui.js'],
    ])->render();

    expect($html)->toContain('class="tc-root"')->toContain('id="tc-elsewhere"')
        ->and(strpos($html, '/core.js'))->toBeLessThan(strpos($html, '/ui.js'))
        ->and($html)->not->toContain('mca-shell')->not->toContain('livewire')->not->toContain('ui-badge')->not->toContain('mca-pagehead');
});

it('la interfaz sigue deshabilitada fuera de los entornos autorizados aunque se active el interruptor', function () {
    $env = app()['env'];
    foreach (['production', 'staging'] as $other) {
        app()['env'] = $other;
        try {
            Http::as($this->ana)->get(route('collab.calls.ui'))->assertNotFound();
            Http::as($this->ana)->get(route('collab.calls.ui.asset', ['asset' => 'teamcalls-core.js']))->assertNotFound();
        } finally {
            app()['env'] = $env;
        }
    }
});
