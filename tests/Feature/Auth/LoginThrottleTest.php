<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Audit\Models\AuditLog;
use Modules\Institutions\Models\Institution;

// Cache limpia entre tests: el bloqueo (creciente) vive en cache y no debe filtrarse.
beforeEach(fn () => Cache::flush());

function throttleUser(string $email): User
{
    $institution = Institution::factory()->create();

    // Sin 2FA: estas pruebas verifican que el login por contraseña llega al dashboard
    // (el segundo factor se prueba aparte en TwoFactorChallengeTest).
    return User::factory()->withoutTwoFactor()->create([
        'institution_id' => $institution->id,
        'email' => $email,
        'status' => 'active',
    ]);
}

/** Envía un intento de login. */
function attemptLogin(string $email, string $password = 'mala')
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

it('permite 5 intentos fallidos y bloquea el 6º con mensaje de bloqueo', function () {
    throttleUser('t1@example.com');
    $failed = trans('auth.failed');

    // Los 5 primeros fallos muestran el mensaje genérico de credenciales.
    for ($i = 0; $i < 5; $i++) {
        attemptLogin('t1@example.com')->assertSessionHasErrors(['email' => $failed]);
    }

    // El 6º muestra el mensaje de BLOQUEO (60 s en el primer escalón).
    $throttle = trans('auth.throttle', ['seconds' => 60, 'minutes' => 1]);
    attemptLogin('t1@example.com')->assertSessionHasErrors(['email' => $throttle]);
});

it('el mensaje NO revela si el correo existe (mismo texto exista o no)', function () {
    throttleUser('existe@example.com');
    $failed = trans('auth.failed');

    // Correo existente (contraseña mala) y correo inexistente -> MISMO mensaje genérico.
    attemptLogin('existe@example.com')->assertSessionHasErrors(['email' => $failed]);
    attemptLogin('no-existe@example.com')->assertSessionHasErrors(['email' => $failed]);
});

it('credenciales válidas entran y NO quedan bloqueadas', function () {
    $user = throttleUser('valido@example.com');

    $resp = attemptLogin('valido@example.com', 'password');

    $this->assertAuthenticated();
    $resp->assertRedirect(route('dashboard', absolute: false));
});

it('un login correcto resetea el contador de intentos', function () {
    throttleUser('reset@example.com');

    // 4 fallos (sin llegar al bloqueo) + login correcto -> resetea el contador.
    for ($i = 0; $i < 4; $i++) {
        attemptLogin('reset@example.com');
    }
    attemptLogin('reset@example.com', 'password');
    $this->assertAuthenticated();
    auth()->logout();

    // Si el contador se reseteó, otros 4 fallos + login correcto siguen entrando
    // (si no se hubiera reseteado, el 6º fallo acumulado habría bloqueado).
    for ($i = 0; $i < 4; $i++) {
        attemptLogin('reset@example.com');
    }
    attemptLogin('reset@example.com', 'password');
    $this->assertAuthenticated();
});

it('el bloqueo expira y las credenciales válidas NO quedan fuera para siempre', function () {
    throttleUser('expira@example.com');

    for ($i = 0; $i < 6; $i++) {
        attemptLogin('expira@example.com');
    }
    // Bloqueado: incluso la contraseña CORRECTA se rechaza mientras dura.
    attemptLogin('expira@example.com', 'password');
    $this->assertGuest();

    // Al expirar el bloqueo (60s), las credenciales válidas SÍ entran.
    $this->travel(61)->seconds();
    attemptLogin('expira@example.com', 'password');
    $this->assertAuthenticated();
});

it('el bloqueo es CRECIENTE (el segundo dura más que el primero)', function () {
    throttleUser('escala@example.com');

    // 1er bloqueo (60s).
    for ($i = 0; $i < 6; $i++) {
        attemptLogin('escala@example.com');
    }
    // Expira el 1º y se provoca el 2º bloqueo.
    $this->travel(61)->seconds();
    for ($i = 0; $i < 6; $i++) {
        attemptLogin('escala@example.com');
    }

    // A los 61s (ya habría pasado un bloqueo de 60s) el 2º SIGUE activo -> es más largo.
    $this->travel(61)->seconds();
    attemptLogin('escala@example.com', 'password');
    $this->assertGuest();

    // Pasado el escalón (300s) sí se desbloquea.
    $this->travel(300)->seconds();
    attemptLogin('escala@example.com', 'password');
    $this->assertAuthenticated();
});

it('audita el intento fallido y el bloqueo por fuerza bruta', function () {
    throttleUser('audit@example.com');

    for ($i = 0; $i < 6; $i++) {
        attemptLogin('audit@example.com');
    }

    $failed = AuditLog::withoutGlobalScopes()->where('action', 'auth.login_failed')->get();
    $lockout = AuditLog::withoutGlobalScopes()->where('action', 'auth.lockout')->get();

    expect($failed->count())->toBeGreaterThanOrEqual(5);
    expect($lockout->count())->toBeGreaterThanOrEqual(1);
    // El correo se registra (no es secreto); la contraseña NUNCA.
    expect($failed->first()->changes['email'] ?? null)->toBe('audit@example.com');
    expect(json_encode($failed->first()->changes))->not->toContain('mala');
});
