<?php

declare(strict_types=1);

use Modules\Core\Tenancy\CurrentInstitution;
use Modules\TeamCalls\Contracts\ConversationAccess;
use Modules\TeamCalls\Contracts\UserDirectory;
use Modules\TeamCalls\Models\Call;
use Modules\TeamCalls\Models\CallEvent;
use Modules\TeamCalls\Models\CallParticipant;
use Modules\TeamCalls\Tests\Support\Http;
use Modules\TeamCalls\Tests\Support\TC;

/*
| Seguridad de la API web: pila del panel (sesión, CSRF, auth, 2FA obligatorio), institución activa,
| estado y permisos del usuario, llamadas ajenas, manipulación de participantes y dispositivos,
| validación de entradas, límites de frecuencia y errores internos sin fugas.
*/

beforeEach(function () {
    TC::fakes();
    $this->inst = TC::institution();
    [$this->ana, $this->beto, $this->carla] = [TC::user($this->inst), TC::user($this->inst), TC::user($this->inst)];
});

/** Todas las rutas de la API: [método, nombre, ¿lleva id de llamada?]. */
dataset('rutas', [
    'historial' => ['GET', 'index', false],
    'perdidas' => ['GET', 'missed', false],
    'ver' => ['GET', 'show', true],
    'iniciar' => ['POST', 'store', false],
    'aceptar' => ['POST', 'accept', true],
    'rechazar' => ['POST', 'decline', true],
    'cancelar' => ['POST', 'cancel', true],
    'unirse' => ['POST', 'join', true],
    'salir' => ['POST', 'leave', true],
    'finalizar' => ['POST', 'end', true],
    'invitar' => ['POST', 'invite', true],
    'credenciales' => ['POST', 'credentials', true],
]);

function tcUrl(string $name, bool $withCall, string $callId = '01JZZZZZZZZZZZZZZZZZZZZZZZ'): string
{
    return route('collab.calls.'.$name, $withCall ? ['call' => $callId] : []);
}

it('sin sesión: 401 en JSON en todas las rutas (sin redirigir al login)', function (string $method, string $name, bool $withCall) {
    $res = $this->json($method, tcUrl($name, $withCall));
    $res->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
})->with('rutas');

it('sin 2FA activado: 403 two_factor_required en todas las rutas', function (string $method, string $name, bool $withCall) {
    $sin2fa = TC::user($this->inst, TC::NO_2FA); // sin segundo factor y fuera de la excepción temporal
    Http::as($sin2fa)->json($method, tcUrl($name, $withCall))
        ->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
})->with('rutas');

it('la excepción temporal de 2FA del panel del CRM NO abre Colaboración: CollaborationCore exige MFA', function () {
    $exento = TC::user($this->inst, TC::NO_2FA);
    config(['auth.two_factor.exempt_emails' => [mb_strtolower($exento->email)]]);
    Http::get($exento, 'index')->assertForbidden()->assertJsonPath('error.code', 'two_factor_required');
});

it('usuarios inactivos o sin acceso a las llamadas no operan', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    $this->beto->forceFill(['status' => 'inactive'])->save();
    Http::post($this->beto->fresh(), 'accept', $id)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    Http::get($this->beto->fresh(), 'show', $id)->assertForbidden();

    // Sin rol del CRM pero con collaboration.use: SÍ usa llamadas; sin collaboration.use, no.
    $sinRol = TC::user($this->inst, ['role' => 'member', 'department' => null]);
    Http::get($sinRol, 'index')->assertOk();
    $admin = TC::user($this->inst, ['role' => 'admin']);
    app(\Modules\CollaborationCore\Services\ApplicationAccess::class)->set($admin, $sinRol, 'collaboration', false);
    Http::get($sinRol->fresh(), 'index')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    Http::start($this->carla, $this->beto->id, 'http-key-0009')->assertForbidden(); // Beto ya no puede recibir llamadas
    expect(CallParticipant::query()->where('call_id', $id)->where('user_id', $this->beto->id)->value('state')->value)->toBe('ringing');
});

it('otra institución: sus llamadas no existen para ella y no puede llamar fuera de la suya', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    $other = TC::institution();
    $intruder = TC::user($other);
    app(CurrentInstitution::class)->set($this->inst->id);

    Http::get($intruder, 'show', $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    foreach (['accept', 'decline', 'cancel', 'join', 'leave', 'end', 'credentials'] as $action) {
        Http::post($intruder, $action, $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
    Http::post($intruder, 'invite', $id, ['user_ids' => [$this->carla->id]])->assertNotFound();
    Http::start($intruder, $this->ana->id, 'http-key-x001')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    Http::get($intruder, 'index')->assertOk()->assertJsonPath('meta.count', 0);

    app(CurrentInstitution::class)->set($this->inst->id);
    expect(Call::query()->count())->toBe(1)
        ->and(TC::states(Call::query()->findOrFail($id)))->toBe([$this->ana->id => 'accepted', $this->beto->id => 'ringing']);
});

it('institución activa distinta (modo multi-institución): Colaboración opera SIEMPRE en la institución propia del usuario', function () {
    config(['crm.multi_institution' => true]);
    $other = TC::institution();
    $super = TC::user($this->inst, ['is_super_admin' => true]);
    $stranger = TC::user($other);
    app(CurrentInstitution::class)->set($this->inst->id);

    // CollaborationCore ignora la institución elegida en la sesión: el actor es de SU institución.
    Http::as($super)->withSession(['active_institution_id' => $other->id])
        ->postJson(route('collab.calls.store'), ['user_id' => $stranger->id, 'media' => 'audio', 'idempotency_key' => 'http-key-s001'])
        ->assertForbidden(); // no puede llamar a alguien de otra institución
    Http::as($super)->withSession(['active_institution_id' => $other->id])
        ->postJson(route('collab.calls.store'), ['user_id' => $this->ana->id, 'media' => 'audio', 'idempotency_key' => 'http-key-s002'])
        ->assertCreated();
    app(CurrentInstitution::class)->runGlobally(fn () => expect(Call::query()->where('institution_id', $other->id)->count())->toBe(0)
        ->and(Call::query()->where('institution_id', $this->inst->id)->count())->toBe(1));
});

it('manipulación: el cliente no impone dispositivo, institución, quién llama ni conversación', function (string $field, mixed $value) {
    Http::as($this->ana)->postJson(route('collab.calls.store'), [
        'user_id' => $this->beto->id, 'media' => 'audio', 'idempotency_key' => 'http-key-t001', $field => $value,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid')->assertJsonStructure(['error' => ['fields' => [$field]]]);
    expect(Call::query()->count())->toBe(0);
})->with([
    'dispositivo' => ['device_id', 'beto-iphone'],
    'institución' => ['institution_id', 999],
    'quien llama' => ['initiator_id', 1],
    'conversación' => ['conversation_id', '01JTEAMCHATGROUP000000001'],
    'tipo de conversación' => ['conversation_type', 'group'],
    'llamada grupal directa' => ['user_ids', [2, 3]],
]);

it('manipulación de participantes y dispositivos en una llamada existente', function () {
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');

    // Un dispositivo enviado por el cliente se rechaza (no es confiable hasta CollaborationCore).
    Http::post($this->beto, 'accept', $id, ['device_id' => 'pc-'.$this->ana->id])->assertUnprocessable();
    // Quien actúa sale de la sesión: Carla no puede contestar «como» Beto.
    Http::post($this->carla, 'accept', $id, ['user_id' => $this->beto->id])->assertNotFound();
    Http::post($this->carla, 'decline', $id, ['user_id' => $this->beto->id])->assertNotFound();
    expect(TC::states(Call::query()->findOrFail($id)))->toBe([$this->ana->id => 'accepted', $this->beto->id => 'ringing']);

    // Beto contesta desde su sesión; otra sesión (otro dispositivo) no puede sacarlo de la llamada.
    Http::post($this->beto, 'accept', $id, browser: 'beto-movil')->assertOk();
    Http::post($this->beto, 'leave', $id, browser: 'beto-pc')->assertOk()->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('data.viewer_state', 'accepted');
    expect(CallEvent::query()->where('call_id', $id)->where('type', 'call.participant.left')->count())->toBe(0);
});

it('llamadas desde conversaciones: solo por la API definitiva (ConversationDirectory de TeamChat); la heredada las rechaza', function () {
    app()->forgetInstance(ConversationAccess::class);
    expect(app(ConversationAccess::class))->toBeInstanceOf(\Modules\TeamCalls\Integration\Collaboration\ConversationDirectoryAccess::class);
    Http::as($this->ana)->postJson(route('collab.calls.store'), ['conversation' => ['type' => 'group', 'id' => '01JTEAMCHATGROUP000000001'],
        'media' => 'audio', 'idempotency_key' => 'http-key-c001'])->assertUnprocessable();
    expect(Call::query()->count())->toBe(0);
});

it('valida entradas al iniciar', function (array $body, int $status, string $code) {
    Http::as($this->ana)->postJson(route('collab.calls.store'), $body + ['idempotency_key' => 'http-key-v001'])
        ->assertStatus($status)->assertJsonPath('error.code', $code);
    expect(Call::query()->count())->toBe(0);
})->with([
    'sin destinatario' => [['media' => 'audio'], 422, 'invalid'],
    'destinatario no numérico' => [['user_id' => 'beto', 'media' => 'audio'], 422, 'invalid'],
    'medio desconocido' => [['user_id' => 2, 'media' => 'pantalla'], 422, 'invalid'],
    'clave corta' => [['user_id' => 2, 'media' => 'audio', 'idempotency_key' => 'corta'], 422, 'invalid'],
    'clave con caracteres raros' => [['user_id' => 2, 'media' => 'audio', 'idempotency_key' => "k'; DROP TABLE x;--"], 422, 'invalid'],
    'persona inexistente' => [['user_id' => 999999, 'media' => 'audio'], 403, 'forbidden'],
]);

it('no se puede llamar a sí mismo y quien está en otra llamada recibe «busy»', function () {
    Http::start($this->ana, $this->ana->id)->assertUnprocessable()->assertJsonPath('error.code', 'invalid');
    Http::start($this->ana, $this->beto->id, 'http-key-b001')->assertCreated();
    Http::start($this->ana, $this->carla->id, 'http-key-b002')->assertStatus(409)->assertJsonPath('error.code', 'busy');
    expect(Call::query()->count())->toBe(1);
});

it('identificadores manipulados y métodos no permitidos responden en el formato de la API', function () {
    foreach (['1%20OR%201=1', 'abc', '01JZZZZZZZZZZZZZZZZZZZZZZZ', str_repeat('9', 300)] as $bad) {
        Http::as($this->ana)->getJson('/colaboracion/llamadas/'.$bad)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        Http::as($this->ana)->postJson('/colaboracion/llamadas/'.$bad.'/aceptar')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
    Http::as($this->ana)->getJson('/colaboracion/llamadas/..%2F..%2Fetc')->assertNotFound(); // ni siquiera llega a la API
    $id = Http::start($this->ana, $this->beto->id)->json('data.id');
    // 405 lo decide el enrutador ANTES de entrar en la API (formato JSON genérico de Laravel).
    Http::as($this->ana)->getJson(route('collab.calls.accept', $id))->assertStatus(405)->assertJsonStructure(['message']);
    Http::as($this->ana)->deleteJson(route('collab.calls.show', $id))->assertStatus(405);
    expect(TC::states(Call::query()->findOrFail($id)))->toBe([$this->ana->id => 'accepted', $this->beto->id => 'ringing']);
});

it('CSRF: el panel rechaza escrituras sin token (419) y acepta las que lo traen', function () {
    // Verificación REAL del middleware vigente (WidgetCsrf de CollaborationCore, que delega en el CSRF de Laravel
    // para las sesiones normales). Laravel solo la omite con env «testing»: durante estas peticiones, «local».
    $env = app()['env'];
    app()['env'] = 'local';
    try {
        $body = ['user_id' => $this->beto->id, 'media' => 'audio', 'idempotency_key' => 'http-key-f001'];

        Http::as($this->ana)->postJson(route('collab.calls.store'), $body)->assertStatus(419)->assertJsonPath('error.code', 'csrf_token_mismatch');
        Http::as($this->ana)->withHeader('Sec-Fetch-Site', 'cross-site')->postJson(route('collab.calls.store'), $body)->assertStatus(419);
        expect(Call::query()->count())->toBe(0);

        // Navegador nuevo: su sesión empieza con este token (la cookie de sesión es real).
        Http::as($this->ana, 'ana-csrf')->withSession(['_token' => 'token-de-prueba-csrf'])->withHeader('X-CSRF-TOKEN', 'token-de-prueba-csrf')
            ->postJson(route('collab.calls.store'), $body)->assertCreated();
        Http::get($this->ana, 'index')->assertOk(); // las lecturas no lo necesitan
    } finally {
        app()['env'] = $env;
    }
});

it('widget universal: sus credenciales delegadas no sirven para las llamadas (ni API definitiva ni heredada)', function () {
    test()->flushHeaders();
    $body = ['user_ids' => [$this->beto->id], 'media' => 'audio'];
    // La lista de rutas del widget no incluye las llamadas: se rechaza antes de autenticar nada.
    $this->withHeaders(['Authorization' => 'MCAWidget token-falso', 'Idempotency-Key' => 'widget-call-0001'])
        ->postJson('/api/v1/collaboration/calls', $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $legacy = $this->withHeaders(['Authorization' => 'MCAWidget token-falso'])
        ->postJson(route('collab.calls.store'), ['user_id' => $this->beto->id, 'media' => 'audio', 'idempotency_key' => 'widget-call-0002']);
    $legacy->assertForbidden()->assertJsonPath('error.code', 'forbidden'); // su código, no un 500 genérico
    expect(Call::query()->count())->toBe(0);
});

it('límite de frecuencia por persona: 429 con Retry-After', function () {
    config(['teamcalls.rate_limits.start' => 2, 'teamcalls.rate_limits.write' => 3]);
    Http::start($this->ana, $this->beto->id, 'http-key-r001')->assertCreated();
    $id = Call::query()->value('id');
    Http::start($this->ana, $this->beto->id, 'http-key-r001')->assertOk();
    $limited = Http::start($this->ana, $this->beto->id, 'http-key-r001');
    $limited->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    expect($limited->headers->has('Retry-After'))->toBeTrue();

    // El límite es de cada persona: Beto sigue pudiendo operar.
    foreach (range(1, 3) as $i) {
        Http::post($this->beto, 'accept', $id)->assertOk();
    }
    Http::post($this->beto, 'accept', $id)->assertStatus(429);
    Http::post($this->carla, 'decline', $id)->assertNotFound(); // otra persona, su propio límite
});

it('un error interno no filtra detalles (500 genérico)', function () {
    app()->instance(UserDirectory::class, new class implements UserDirectory
    {
        public function canUseCalls(\App\Models\User $user): bool
        {
            throw new RuntimeException('detalle-interno-secreto');
        }

        public function canContact(\App\Models\User $from, \App\Models\User $to): bool
        {
            return false;
        }
    });
    app()->forgetInstance(\Modules\TeamCalls\Services\CallService::class);

    $res = Http::get($this->ana, 'index');
    $res->assertStatus(500)->assertJsonPath('error.code', 'server_error')->assertJsonPath('error.message', 'Server error.');
    expect($res->getContent())->not->toContain('detalle-interno-secreto')->not->toContain('trace');
});
