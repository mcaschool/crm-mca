<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Institutions\Models\Institution;

/**
 * Permiso POR USUARIO del modo código (no atado a un rol). El Admin siempre lo tiene;
 * a los demás se les concede uno a uno (users.can_email_code). Requiere poder enviar
 * correo (el modo código vive en el editor de correo).
 */
it('el Administrador SIEMPRE puede usar el modo código, aunque no tenga el flag', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'can_email_code' => false]);

    expect($admin->canUseEmailCodeMode())->toBeTrue();
});

it('un asesor que envía correo NO puede usar el modo código sin el permiso; SÍ con él', function () {
    $institution = Institution::factory()->create();

    $sinFlag = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions', 'can_email_code' => false]);
    expect($sinFlag->canSendEmail())->toBeTrue();
    expect($sinFlag->canUseEmailCodeMode())->toBeFalse();

    $conFlag = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions', 'can_email_code' => true]);
    expect($conFlag->canUseEmailCodeMode())->toBeTrue();
});

it('Marketing (no envía correo) no puede usar el modo código aunque tenga el flag', function () {
    $institution = Institution::factory()->create();
    $marketing = User::factory()->create(['institution_id' => $institution->id, 'role' => 'marketing', 'can_email_code' => true]);

    expect($marketing->canSendEmail())->toBeFalse();
    expect($marketing->canUseEmailCodeMode())->toBeFalse();
});
