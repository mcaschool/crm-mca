<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Identity\Livewire\Profile\MiPerfil;
use Modules\Identity\Livewire\Users\Form as UsersForm;
use Modules\Identity\Livewire\Users\Index as UsersIndex;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Livewire\Integrations\Configure as IntegrationsConfigure;
use Modules\Integrations\Livewire\Integrations\Index as IntegrationsIndex;
use Modules\Integrations\Models\Integration;

function auditAdmin(): User
{
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($admin);

    return $admin;
}

/** ¿Existe un registro de auditoria con esta accion? */
function hasAudit(string $action): bool
{
    return AuditLog::withoutGlobalScopes()->where('action', $action)->exists();
}

// --- AUTENTICACION -----------------------------------------------------------

it('audita el inicio de sesión correcto (sin 2FA)', function () {
    $inst = Institution::factory()->create();
    User::factory()->withoutTwoFactor()->create(['institution_id' => $inst->id, 'email' => 'ok@example.com']);

    test()->post('/login', ['email' => 'ok@example.com', 'password' => 'password']);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'auth.login_success')->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->not->toBeNull();
    expect($row->changes['email'])->toBe('ok@example.com');
    expect($row->ip)->not->toBeNull();
    expect($row->institution_id)->toBe($inst->id);
});

it('audita el inicio de sesión tras superar el 2FA', function () {
    $inst = Institution::factory()->create();
    // Factory por defecto = 2FA activo con códigos de recuperación conocidos.
    User::factory()->create(['institution_id' => $inst->id, 'email' => 'tfa@example.com']);

    test()->post('/login', ['email' => 'tfa@example.com', 'password' => 'password']);
    test()->post('/two-factor-challenge', ['recovery_code' => 'AAAAA-BBBBB']);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'auth.login_success')->first();
    expect($row)->not->toBeNull();
    expect($row->changes['via'] ?? null)->toBe('2fa');
});

it('audita el cierre de sesión', function () {
    $admin = auditAdmin();

    test()->post('/logout');

    expect(hasAudit('auth.logout'))->toBeTrue();
});

it('audita el restablecimiento de contraseña', function () {
    $inst = Institution::factory()->create();
    $user = User::factory()->withoutTwoFactor()->create(['institution_id' => $inst->id, 'email' => 'reset@example.com']);
    $token = Password::createToken($user);

    test()->post('/reset-password', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'NuevaClave123!',
        'password_confirmation' => 'NuevaClave123!',
    ]);

    expect(hasAudit('auth.password_reset'))->toBeTrue();
});

// --- GESTION DE USUARIOS ------------------------------------------------------

it('audita la creación de un usuario y la invitación enviada', function () {
    auditAdmin();

    Livewire::test(UsersForm::class)
        ->set('name', 'Nuevo Asesor')
        ->set('email', 'nuevo@example.com')
        ->set('role', 'admissions')
        ->set('status', 'active')
        ->call('save');

    expect(hasAudit('user.created'))->toBeTrue();
    expect(hasAudit('user.invited'))->toBeTrue();
});

it('audita el cambio de rol', function () {
    $admin = auditAdmin();
    $target = User::factory()->withoutTwoFactor()->create([
        'institution_id' => $admin->institution_id, 'role' => 'admissions',
    ]);

    Livewire::test(UsersForm::class, ['user' => $target])
        ->set('role', 'marketing')
        ->call('save');

    $row = AuditLog::withoutGlobalScopes()->where('action', 'user.role_changed')->first();
    expect($row)->not->toBeNull();
    expect($row->changes['from'])->toBe('admissions');
    expect($row->changes['to'])->toBe('marketing');
});

it('audita la activación/desactivación desde el listado', function () {
    $admin = auditAdmin();
    $target = User::factory()->withoutTwoFactor()->create([
        'institution_id' => $admin->institution_id, 'status' => 'active',
    ]);

    Livewire::test(UsersIndex::class)->call('toggleActive', $target->id);

    expect(hasAudit('user.deactivated'))->toBeTrue();
});

it('audita el acceso al número de identidad (dato cifrado) al abrir la ficha', function () {
    $admin = auditAdmin();
    $target = User::factory()->withoutTwoFactor()->create([
        'institution_id' => $admin->institution_id, 'national_id' => '9988776655',
    ]);

    Livewire::test(UsersForm::class, ['user' => $target]);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'user.national_id_viewed')->first();
    expect($row)->not->toBeNull();
    expect($row->changes['field'])->toBe('national_id');
    // NUNCA el valor descifrado.
    expect(json_encode($row->changes))->not->toContain('9988776655');
});

it('audita el cambio de contraseña propio', function () {
    $inst = Institution::factory()->create();
    $user = User::factory()->create([
        'institution_id' => $inst->id, 'password' => Hash::make('OldPass123!'),
    ]);
    app(CurrentInstitution::class)->set($inst->id);
    test()->actingAs($user);

    Livewire::test(MiPerfil::class)
        ->set('current_password', 'OldPass123!')
        ->set('password', 'NuevaClave123!')
        ->set('password_confirmation', 'NuevaClave123!')
        ->call('updatePassword');

    expect(hasAudit('account.password_changed'))->toBeTrue();
});

// --- INTEGRACIONES / SECRETOS -------------------------------------------------

it('audita crear, probar, activar/desactivar y rotar credencial de una integración', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]); // sin red real
    auditAdmin();

    // Crear
    Livewire::test(IntegrationsConfigure::class, ['type' => 'ai_provider'])
        ->set('provider', 'openai')
        ->set('inputs.api_key', 'sk-PRIMERSECRETO-1234')
        ->set('inputs.base_url', 'https://api.openai.com/v1')
        ->call('save');
    expect(hasAudit('integration.created'))->toBeTrue();

    $integration = Integration::query()->where('type', 'ai_provider')->firstOrFail();

    // Probar conexión
    Livewire::test(IntegrationsIndex::class)->call('test', $integration->id);
    expect(hasAudit('integration.tested'))->toBeTrue();

    // Activar/desactivar
    Livewire::test(IntegrationsIndex::class)->call('toggle', $integration->id);
    expect(hasAudit('integration.activated') || hasAudit('integration.deactivated'))->toBeTrue();

    // Rotar credencial (editar con un secreto nuevo)
    Livewire::test(IntegrationsConfigure::class, ['type' => 'ai_provider'])
        ->set('provider', 'openai')
        ->set('inputs.api_key', 'sk-SEGUNDOSECRETO-5678')
        ->set('inputs.base_url', 'https://api.openai.com/v1')
        ->call('save');

    $rotated = AuditLog::withoutGlobalScopes()->where('action', 'integration.updated')->first();
    expect($rotated)->not->toBeNull();
    expect($rotated->changes['credentials_rotated'] ?? null)->toContain('api_key');
});

// --- REDACCION: NUNCA valores sensibles en claro ------------------------------

it('NUNCA registra secretos, contraseñas ni el secreto TOTP en claro', function () {
    $admin = auditAdmin();

    // Genera eventos que involucran secretos/contraseñas/TOTP.
    Livewire::test(IntegrationsConfigure::class, ['type' => 'ai_provider'])
        ->set('provider', 'openai')
        ->set('inputs.api_key', 'sk-NUNCA-DEBE-APARECER-9999')
        ->set('inputs.base_url', 'https://api.openai.com/v1')
        ->call('save');

    $target = User::factory()->withoutTwoFactor()->create([
        'institution_id' => $admin->institution_id, 'national_id' => '1122334455',
    ]);
    Livewire::test(UsersForm::class, ['user' => $target]);

    // Barrido: ninguna fila contiene secretos/contraseña/TOTP/identidad en claro.
    $blob = AuditLog::withoutGlobalScopes()->get()
        ->map(fn ($r) => json_encode($r->changes))
        ->implode(' ');

    expect($blob)->not->toContain('sk-NUNCA-DEBE-APARECER-9999');
    expect($blob)->not->toContain('password');
    expect($blob)->not->toContain('JBSWY3DPEHPK3PXP'); // secreto TOTP del factory
    expect($blob)->not->toContain('AAAAA-BBBBB');       // código de recuperación
    expect($blob)->not->toContain('1122334455');        // número de identidad
});
