<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Tests\Support\FakeLiveKit;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Interfaz web de llamadas (fase 3C): solo desarrollo, misma pila del panel (sesión, 2FA, institución),
| sin secretos ni salas en la página, recursos de lista cerrada y las dos lecturas que usa la interfaz
| (contactos y llamadas en curso). El comportamiento en el navegador se validó con LiveKit real (README).
*/

beforeEach(function () {
    TC::fakes();
    config(['teamcalls.ui.enabled' => true]);
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst, ['name' => 'Ana Uno']), TC::user($this->inst, ['name' => 'Beto Dos']), TC::user($this->inst, ['name' => 'Carla Tres'])];
});

dataset('ui-rutas', [
    'página' => ['collab.calls.ui', []],
    'recurso' => ['collab.calls.ui.asset', ['asset' => 'teamcalls-ui.js']],
    'contactos' => ['collab.calls.ui.contacts', []],
    'en curso' => ['collab.calls.ui.live', []],
]);

it('sin el interruptor, o fuera de desarrollo, la interfaz no existe (404)', function (string $name, array $params) {
    config(['teamcalls.ui.enabled' => false]);
    Http::as($this->ana)->get(route($name, $params))->assertNotFound();

    config(['teamcalls.ui.enabled' => true]);
    $env = app()['env'];
    app()['env'] = 'production';
    try {
        Http::as($this->ana)->get(route($name, $params))->assertNotFound();
    } finally {
        app()['env'] = $env;
    }
})->with('ui-rutas');

it('misma pila del panel: sin sesión al login (página) o 401 (JSON); sin 2FA a «Mi perfil» o 403', function () {
    $this->get(route('collab.calls.ui'))->assertRedirect('/login');
    $this->getJson(route('collab.calls.ui.contacts'))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

    $sin2fa = TC::user($this->inst, TC::NO_2FA);
    Http::as($sin2fa)->get(route('collab.calls.ui'))->assertRedirect(route('profile.me'));
    Http::as($sin2fa)->getJson(route('collab.calls.ui.live'))->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});

it('la página no lleva secretos, salas ni identidades: solo rutas de la API y textos', function () {
    FakeLiveKit::boot();
    config(['teamcalls.ui.enabled' => true]);
    $call = TC::call($this->ana, [$this->beto->id]);

    $res = Http::as($this->ana)->get(route('collab.calls.ui'))->assertOk()
        ->assertSee('name="csrf-token"', false)
        ->assertSee(route('collab.calls.ui.asset', ['asset' => 'livekit-client.umd.js']), false)
        ->assertSee(__('teamcalls.ui.title'));
    $html = $res->getContent();
    expect($html)->not->toContain(FakeLiveKit::SECRET)->not->toContain(FakeLiveKit::KEY)
        ->not->toContain(TC::fresh($call)->room_name)->not->toContain('api_secret')->not->toContain('u'.$this->beto->id.'.');

    preg_match('~<script type="application/json" id="tc-config">(.*?)</script>~s', $html, $m);
    $config = json_decode(html_entity_decode($m[1]), true);
    expect($config['me'])->toBe(['id' => $this->ana->id, 'name' => 'Ana Uno'])
        ->and($config['synthetic'])->toBeFalse()
        ->and($config['routes']['credentials'])->toContain('__CALL__')
        ->and(array_keys($config['routes']))->not->toContain('room')
        ->and($config['i18n'])->toHaveKey('status_in_call');
});

it('la página se presenta en el idioma del usuario', function () {
    $this->ana->forceFill(['preferred_language' => 'en'])->save();
    Http::as($this->ana->fresh())->get(route('collab.calls.ui'))->assertOk()->assertSee('Internal voice and video calls');
});

it('medios sintéticos solo si el servidor los permite y se piden expresamente', function () {
    $config = fn ($res) => json_decode(html_entity_decode(preg_replace('~^.*<script type="application/json" id="tc-config">(.*?)</script>.*$~s', '$1', $res->getContent())), true);
    expect($config(Http::as($this->ana)->get(route('collab.calls.ui', ['sintetico' => 1])))['synthetic'])->toBeFalse();
    config(['teamcalls.ui.synthetic_media' => true]);
    expect($config(Http::as($this->ana)->get(route('collab.calls.ui', ['sintetico' => 1])))['synthetic'])->toBeTrue()
        ->and($config(Http::as($this->ana)->get(route('collab.calls.ui')))['synthetic'])->toBeFalse();
});

it('recursos: lista cerrada (SDK oficial, JS y CSS del módulo), con tipo correcto', function () {
    $sdk = Http::as($this->ana)->get(route('collab.calls.ui.asset', ['asset' => 'livekit-client.umd.js']))->assertOk();
    expect($sdk->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($sdk->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect(hash_file('sha256', $sdk->getFile()->getPathname()))->toBe(hash_file('sha256', module_path('TeamCalls', 'resources/assets/vendor/livekit-client/livekit-client-2.22.3.umd.js')));
    expect(Http::as($this->ana)->get(route('collab.calls.ui.asset', ['asset' => 'teamcalls-ui.css']))->assertOk()->headers->get('Content-Type'))->toContain('text/css');

    foreach (['otro.js', 'app.blade.php', '..%2F..%2F.env', 'livekit-client-2.22.3.umd.js'] as $bad) {
        Http::as($this->ana)->get('/colaboracion/teamcalls/recursos/'.$bad)->assertNotFound();
    }
});

it('contactos: solo compañeros activos y con acceso de la misma institución, solo id y nombre', function () {
    $inactive = TC::user($this->inst, ['name' => 'Inactiva', 'status' => 'inactive']);
    $foreign = TC::user(TC::institution(), ['name' => 'Ajena']);
    app(CurrentInstitution::class)->set($this->inst->id);

    $res = Http::as($this->ana)->getJson(route('collab.calls.ui.contacts'))->assertOk();
    expect($res->json('data.contacts'))->toBe([['id' => $this->beto->id, 'name' => 'Beto Dos'], ['id' => $this->carla->id, 'name' => 'Carla Tres']])
        ->and($res->getContent())->not->toContain($this->beto->email)->not->toContain('Inactiva')->not->toContain('Ajena');
    Http::as($this->ana)->getJson(route('collab.calls.ui.contacts', ['q' => 'carl']))->assertJsonPath('data.contacts.0.name', 'Carla Tres')
        ->assertJsonCount(1, 'data.contacts');
    Http::as($this->ana)->getJson(route('collab.calls.ui.contacts', ['q' => '%']))->assertJsonCount(0, 'data.contacts');
    expect($inactive->id)->toBeInt()->and($foreign->id)->toBeInt();
});

it('en curso (sondeo temporal): la llamada entrante aparece a quien suena; nada para terceros; nada al terminar', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertOk()
        ->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.viewer_state', 'ringing');
    Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertJsonPath('data.0.viewer_state', 'accepted');
    Http::as($this->carla)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 0);

    Http::post($this->beto, 'decline', $id)->assertOk();
    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 0);
    Http::as($this->ana)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 0);

    // Lo vencido se aplica al consultar: una invitación sin respuesta deja de sonar.
    $id2 = Http::start($this->carla, $this->beto->id, 'http-key-ui02')->json('data.id');
    $this->travel(46)->seconds();
    Http::as($this->beto)->getJson(route('collab.calls.ui.live'))->assertJsonPath('meta.count', 0);
    expect($id2)->not->toBe($id);
});

it('las rutas de la interfaz no chocan con la API de llamadas', function () {
    expect(route('collab.calls.ui'))->not->toStartWith(rtrim(route('collab.calls.index'), '/').'/')
        ->and(app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/colaboracion/teamcalls/en-curso'))->getName())->toBe('collab.calls.ui.live')
        ->and(app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/colaboracion/llamadas/perdidas'))->getName())->toBe('collab.calls.missed');
});
