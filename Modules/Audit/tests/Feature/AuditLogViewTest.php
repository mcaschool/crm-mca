<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Audit\Livewire\Logs\Index as AuditIndex;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

function auditViewUser(string $role = 'admin'): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
    app(CurrentInstitution::class)->set($institution->id);
    test()->actingAs($user);

    return $user;
}

/** Crea una fila de auditoria con created_at controlable. */
function seedLog(User $actor, string $action, array $overrides = []): AuditLog
{
    $log = AuditLog::factory()->create(array_merge([
        'user_id' => $actor->id,
        'action' => $action,
        'auditable_type' => 'App\\Models\\User',
        'auditable_id' => $actor->id,
    ], $overrides));

    if (isset($overrides['created_at'])) {
        $log->forceFill(['created_at' => $overrides['created_at']])->save();
    }

    return $log;
}

it('el Administrador ve la vista de auditoría con acción, actor e IP legibles', function () {
    $admin = auditViewUser('admin');
    seedLog($admin, 'auth.login_success', ['ip' => '203.0.113.9']);

    Livewire::test(AuditIndex::class)
        ->assertOk()
        ->assertSee('Auditoría de seguridad')
        ->assertSee('Inicio de sesión')   // etiqueta legible de la acción
        ->assertSee($admin->name)          // actor
        ->assertSee('203.0.113.9');        // IP
});

it('la vista NO permite editar ni borrar (solo lectura, sin acciones de escritura)', function () {
    $admin = auditViewUser('admin');
    seedLog($admin, 'user.created');

    // El componente no expone métodos de mutación de filas.
    expect(method_exists(AuditIndex::class, 'delete'))->toBeFalse();
    expect(method_exists(AuditIndex::class, 'destroy'))->toBeFalse();
    expect(method_exists(AuditIndex::class, 'update'))->toBeFalse();
});

it('Marketing NO puede acceder por URL directa', function () {
    auditViewUser('marketing');

    test()->get('/audit')->assertForbidden();
});

it('Admisiones NO puede acceder por URL directa', function () {
    auditViewUser('admissions');

    test()->get('/audit')->assertForbidden();
});

it('el Administrador SÍ accede por URL directa', function () {
    auditViewUser('admin');

    test()->get('/audit')->assertOk()->assertSee('Auditoría de seguridad');
});

it('filtra por tipo de acción', function () {
    $admin = auditViewUser('admin');
    seedLog($admin, 'auth.login_success', ['ip' => '10.0.0.1']);
    seedLog($admin, 'user.deactivated', ['ip' => '10.0.0.2']);

    Livewire::test(AuditIndex::class)
        ->set('action', 'user.deactivated')
        ->assertSee('10.0.0.2')
        ->assertDontSee('10.0.0.1');
});

it('filtra por actor', function () {
    $admin = auditViewUser('admin');
    $other = User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'admin', 'name' => 'Otra Persona']);
    seedLog($admin, 'auth.login_success', ['ip' => '10.0.0.1']);
    seedLog($other, 'auth.login_success', ['ip' => '10.0.0.2']);

    Livewire::test(AuditIndex::class)
        ->set('actor', (string) $other->id)
        ->assertSee('10.0.0.2')
        ->assertDontSee('10.0.0.1');
});

it('filtra por rango de fechas', function () {
    $admin = auditViewUser('admin');
    seedLog($admin, 'auth.login_success', ['ip' => '10.0.0.9', 'created_at' => now()->subDays(10)]);
    seedLog($admin, 'auth.login_success', ['ip' => '10.0.0.8', 'created_at' => now()]);

    Livewire::test(AuditIndex::class)
        ->set('from', now()->format('Y-m-d'))
        ->assertSee('10.0.0.8')
        ->assertDontSee('10.0.0.9');
});

it('pagina (no carga todo) cuando hay muchos eventos', function () {
    $admin = auditViewUser('admin');
    AuditLog::factory()->count(30)->create([
        'user_id' => $admin->id,
        'action' => 'auth.login_success',
        'auditable_type' => 'App\\Models\\User',
        'auditable_id' => $admin->id,
    ]);

    $component = Livewire::test(AuditIndex::class);
    $logs = $component->viewData('logs');

    expect($logs->total())->toBe(30);
    expect($logs->perPage())->toBe(25);
    expect($logs->hasPages())->toBeTrue();
});

it('está aislada por institución (no muestra eventos de otra institución)', function () {
    $admin = auditViewUser('admin');
    seedLog($admin, 'auth.login_success', ['ip' => '10.0.0.1']);

    // Evento en OTRA institución.
    $instB = Institution::factory()->create();
    $adminB = User::factory()->create(['institution_id' => $instB->id, 'role' => 'admin']);
    app(CurrentInstitution::class)->set($instB->id);
    seedLog($adminB, 'auth.login_success', ['ip' => '10.9.9.9']);

    // Volvemos a la institución A: no debe ver el evento de B.
    app(CurrentInstitution::class)->set((int) $admin->institution_id);
    Livewire::test(AuditIndex::class)
        ->assertSee('10.0.0.1')
        ->assertDontSee('10.9.9.9');
});
