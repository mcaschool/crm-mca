<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Profile\MiPerfil;
use Modules\Institutions\Models\Institution;

/*
| Cambio de contraseña en «Mi perfil» (verificación previa a producción): solo la cuenta
| autenticada, nueva clave validada y confirmada, nunca expuesta en auditoría, y al cambiarla se
| cierran las DEMÁS sesiones de la cuenta y se rota el token «recordarme» (sin pedir la clave
| actual, por decisión de producto c96f640, una sesión ajena no debe sobrevivir al cambio).
*/

function pwdUser(array $attrs = []): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return User::factory()->create(array_merge(['institution_id' => $institution->id, 'role' => 'admissions', 'status' => 'active',
        'password' => Hash::make('ClaveAnterior123!'), 'remember_token' => 'token-anterior-0000'], $attrs));
}

it('al cambiar la contraseña cierra las otras sesiones de la cuenta, conserva la actual y rota «recordarme»', function () {
    config(['session.driver' => 'database']);
    $user = pwdUser();
    $other = User::factory()->create(['institution_id' => $user->institution_id]);
    $this->actingAs($user);
    $current = session()->getId();
    $row = fn (string $id, int $uid) => ['id' => $id, 'user_id' => $uid, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()];
    DB::table('sessions')->insert([$row($current, $user->id), $row('portatil-robado', $user->id), $row('movil', $user->id), $row('ajena', $other->id)]);

    Livewire::test(MiPerfil::class)
        ->set('password', 'NuevaClave123!')->set('password_confirmation', 'NuevaClave123!')
        ->call('updatePassword')->assertHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current])
        ->and(DB::table('sessions')->where('id', 'ajena')->exists())->toBeTrue()          // otras cuentas intactas
        ->and($user->fresh()->getRememberToken())->not->toBe('token-anterior-0000')
        ->and(Hash::check('NuevaClave123!', $user->fresh()->password))->toBeTrue();
});

it('valida la nueva contraseña: obligatoria, confirmada y con la longitud mínima; si falla no cambia nada', function () {
    $user = pwdUser();
    $this->actingAs($user);

    Livewire::test(MiPerfil::class)->set('password', '')->call('updatePassword')->assertHasErrors(['password' => 'required']);
    Livewire::test(MiPerfil::class)->set('password', 'NuevaClave123!')->set('password_confirmation', 'otra')
        ->call('updatePassword')->assertHasErrors(['password' => 'confirmed']);
    Livewire::test(MiPerfil::class)->set('password', 'corta')->set('password_confirmation', 'corta')
        ->call('updatePassword')->assertHasErrors('password');

    expect(Hash::check('ClaveAnterior123!', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'account.password_changed')->exists())->toBeFalse();
});

it('solo cambia la contraseña de la cuenta autenticada y nunca la deja en la auditoría', function () {
    $user = pwdUser();
    $victim = User::factory()->create(['institution_id' => $user->institution_id, 'password' => Hash::make('ClaveVictima123!')]);
    $this->actingAs($user);

    // No hay forma de indicar otra cuenta: el componente actúa sobre auth()->user().
    Livewire::test(MiPerfil::class)
        ->set('password', 'NuevaClave123!')->set('password_confirmation', 'NuevaClave123!')
        ->call('updatePassword');

    expect(Hash::check('ClaveVictima123!', $victim->fresh()->password))->toBeTrue();
    $logs = AuditLog::withoutGlobalScopes()->where('action', 'account.password_changed')->get();
    expect($logs)->toHaveCount(1)
        ->and((int) $logs->first()->auditable_id)->toBe($user->id)
        ->and(json_encode($logs->first()->getAttributes()))->not->toContain('NuevaClave123!');
});

it('sin sesión o sin 2FA no se llega al formulario de contraseña', function () {
    $this->get('/mi-perfil')->assertRedirect('/login');
});
