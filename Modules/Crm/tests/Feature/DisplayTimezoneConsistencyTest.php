<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Agenda\Form;
use Modules\Crm\Models\MeetingProfile;
use Modules\Crm\Services\Agenda\AgendaDirectory;
use Modules\Crm\Support\UserTimezone;
use Modules\Institutions\Models\Institution;

/*
| Una sola zona de presentación por persona en todo el panel (verificación previa a producción):
| el Calendario (AgendaDirectory), la Bandeja y las fichas (UserTimezone) y la zona propuesta al
| crear una actividad siguen la misma regla: perfil de reuniones → institución → agenda → app.
*/

function tzUser(string $institutionTz): User
{
    $institution = Institution::factory()->create(['timezone' => $institutionTz]);
    app(CurrentInstitution::class)->set($institution->id);

    return User::factory()->create(['institution_id' => $institution->id, 'role' => 'admissions', 'status' => 'active']);
}

it('sin perfil de reuniones, Calendario, Bandeja y formulario usan la zona de la institución', function () {
    config(['crm.agenda.default_timezone' => 'UTC']);           // como en producción
    $user = tzUser('America/New_York');

    expect(app(AgendaDirectory::class)->timezoneFor($user->id))->toBe('America/New_York')
        ->and(UserTimezone::for($user))->toBe('America/New_York');

    Livewire::actingAs($user)->test(Form::class)->assertSet('timezone', 'America/New_York');
});

it('con perfil, cada asesor ve su propia zona aunque la institución esté en otra', function () {
    $bogota = tzUser('America/New_York');
    MeetingProfile::query()->create(['user_id' => $bogota->id, 'timezone' => 'America/Bogota']);
    $madrid = User::factory()->create(['institution_id' => $bogota->institution_id, 'role' => 'admissions', 'status' => 'active']);
    MeetingProfile::query()->create(['user_id' => $madrid->id, 'timezone' => 'Europe/Madrid']);

    expect(app(AgendaDirectory::class)->timezoneFor($bogota->id))->toBe('America/Bogota')
        ->and(UserTimezone::for($bogota))->toBe('America/Bogota')
        ->and(app(AgendaDirectory::class)->timezoneFor($madrid->id))->toBe('Europe/Madrid')
        ->and(UserTimezone::for($madrid))->toBe('Europe/Madrid');

    Livewire::actingAs($madrid)->test(Form::class)->assertSet('timezone', 'Europe/Madrid');
});
