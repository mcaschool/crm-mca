<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Profile\MiPerfil;
use Modules\Institutions\Models\Institution;
use PragmaRX\Google2FA\Google2FA;

/** Código TOTP válido en este instante para un secreto dado. */
function totpFor(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

function twoFactorUser(array $overrides = []): User
{
    $inst = Institution::factory()->create();
    $user = User::factory()->create(array_merge([
        'institution_id' => $inst->id,
        'role' => 'marketing',
    ], $overrides));
    app(CurrentInstitution::class)->set($inst->id);

    return $user;
}

// --- Enrolamiento desde "Mi perfil" ------------------------------------------

it('activa el 2FA: genera secreto, confirma con codigo y muestra codigos de recuperacion', function () {
    $user = twoFactorUser()->refresh();
    // Empieza SIN 2FA para poder enrolarlo.
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    test()->actingAs($user);

    $comp = Livewire::test(MiPerfil::class)->call('enableTwoFactor');
    $secret = (string) $user->fresh()->two_factor_secret;
    expect($secret)->not->toBe('');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse(); // aun sin confirmar

    $comp->set('confirmCode', totpFor($secret))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    expect(count($user->fresh()->two_factor_recovery_codes ?? []))->toBe(8);
    expect(AuditLog::withoutGlobalScopes()->where('action', '2fa.enabled')->count())->toBe(1);
});

it('rechaza la confirmacion con un codigo incorrecto (no activa el 2FA)', function () {
    $user = twoFactorUser()->refresh();
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    test()->actingAs($user);

    Livewire::test(MiPerfil::class)
        ->call('enableTwoFactor')
        ->set('confirmCode', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors('confirmCode');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// --- Desafio en el login -----------------------------------------------------

it('tras contraseña correcta, un usuario con 2FA va al desafio (aun sin autenticar)', function () {
    $user = twoFactorUser(['email' => 'c@example.com']);

    test()->post('/login', ['email' => 'c@example.com', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    test()->assertGuest();
    expect(session('login.2fa_user_id'))->toBe($user->id);
});

it('el codigo TOTP correcto supera el desafio y entra al panel', function () {
    twoFactorUser(['email' => 'c@example.com', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    test()->post('/login', ['email' => 'c@example.com', 'password' => 'password']);

    test()->post('/two-factor-challenge', ['code' => totpFor('JBSWY3DPEHPK3PXP')])
        ->assertRedirect(route('dashboard', absolute: false));

    test()->assertAuthenticated();
});

it('un codigo TOTP incorrecto NO supera el desafio', function () {
    twoFactorUser(['email' => 'c@example.com', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    test()->post('/login', ['email' => 'c@example.com', 'password' => 'password']);

    test()->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    test()->assertGuest();
    expect(AuditLog::withoutGlobalScopes()->where('action', 'auth.2fa_failed')->count())->toBeGreaterThanOrEqual(1);
});

it('un codigo de recuperacion supera el desafio y se consume (un solo uso)', function () {
    $user = twoFactorUser([
        'email' => 'c@example.com',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
    ]);

    test()->post('/login', ['email' => 'c@example.com', 'password' => 'password']);
    test()->post('/two-factor-challenge', ['recovery_code' => 'AAAAA-BBBBB'])
        ->assertRedirect(route('dashboard', absolute: false));

    test()->assertAuthenticated();
    // El codigo usado ya no está en la lista.
    expect($user->fresh()->two_factor_recovery_codes)->not->toContain('AAAAA-BBBBB');
    expect($user->fresh()->two_factor_recovery_codes)->toContain('CCCCC-DDDDD');
});

// --- Politica obligatoria -----------------------------------------------------

it('la politica obligatoria empuja a "Mi perfil" a quien no tiene 2FA', function () {
    $user = twoFactorUser()->refresh();
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    test()->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.me'));
    // Mi perfil sí es accesible (para poder activarlo).
    test()->actingAs($user)->get('/mi-perfil')->assertOk();
});

// --- Cifrado en reposo --------------------------------------------------------

it('guarda el secreto 2FA CIFRADO en la base de datos (no en texto plano)', function () {
    $user = twoFactorUser(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    expect($raw)->not->toBe('')->and($raw)->not->toContain('JBSWY3DPEHPK3PXP');
    // El accessor descifra correctamente.
    expect($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP');
});
