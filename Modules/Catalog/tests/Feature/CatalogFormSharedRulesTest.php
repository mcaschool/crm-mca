<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Audit\Models\AuditLog;
use Modules\Catalog\Livewire\Programs\Form;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Catálogo → Nuevo/Editar programa reutiliza las reglas COMUNES de ProgramProvisioningService
 * (las mismas que «+ Añadir programa» del Centro de Conocimiento): duplicados por institución,
 * normalización, formato de código y URL opcional, líneas de programa y auditoría del alta.
 *
 * @return array{0: Institution, 1: User}
 */
function sharedCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);

    return [$inst, User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin'])];
}

/** @param  array<string, mixed>  $fields */
function catalogNew(User $admin, array $fields): Testable
{
    $component = Livewire::actingAs($admin)->test(Form::class);
    foreach ($fields + ['code' => 'PE-500', 'name_es' => 'Programa Quinientos', 'line' => 'programas_ejecutivos', 'url' => 'https://x.test/pe-500'] as $k => $v) {
        $component->set($k, $v);
    }

    return $component->call('save');
}

it('crea normalizando el nombre, con URL opcional, y deja el alta en la auditoría común (método catálogo)', function () {
    [$inst, $admin] = sharedCtx();

    catalogNew($admin, ['name_es' => '  Programa   Quinientos ', 'url' => '', 'code' => ' PE-500 '])->assertHasNoErrors();

    $program = Program::query()->where('code', 'PE-500')->firstOrFail();
    expect($program->name_es)->toBe('Programa Quinientos')
        ->and($program->url)->toBe('')
        ->and($program->institution_id)->toBe($inst->id);

    $log = AuditLog::query()->where('action', 'program.created')->firstOrFail();
    expect($log->changes)->toMatchArray(['code' => 'PE-500', 'method' => 'catalogo'])->and($log->user_id)->toBe($admin->id);
});

it('rechaza con el mismo criterio que la Biblioteca: código repetido (sin mayúsculas) y nombre normalizado', function () {
    [, $admin] = sharedCtx();
    Program::factory()->create(['code' => 'PE-500', 'name_es' => 'Programa Quinientos', 'line' => 'programas_ejecutivos']);

    catalogNew($admin, ['code' => 'pe-500', 'name_es' => 'Otro nombre'])
        ->assertHasErrors('code')->assertSee('Ya existe un programa con este nombre o código');
    catalogNew($admin, ['code' => 'PE-501', 'name_es' => 'programa  QUINIENTOS'])->assertHasErrors('code');

    // La Biblioteca da el mismo veredicto sobre el mismo dato.
    Livewire::actingAs($admin)->test(Library::class)->call('openAddProgram')
        ->set('newProgramName', 'Otro nombre')->set('newProgramCode', 'pe-500')->set('newProgramLine', 'programas_ejecutivos')
        ->call('createProgram')->assertHasErrors('newProgramCode');

    expect(Program::query()->count())->toBe(1)->and(AuditLog::query()->where('action', 'program.created')->count())->toBe(0);
});

it('la unicidad del código es por institución (antes el Catálogo la comprobaba en toda la tabla)', function () {
    [$inst, $admin] = sharedCtx();
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => Program::factory()->create(['code' => 'PE-500', 'name_es' => 'Programa Quinientos']));
    app(CurrentInstitution::class)->set($inst->id);

    catalogNew($admin, [])->assertHasNoErrors();
    expect(Program::query()->where('code', 'PE-500')->count())->toBe(1);
});

it('valida el formato de código y de URL y solo admite líneas de programa (o sin línea)', function () {
    [, $admin] = sharedCtx();

    catalogNew($admin, ['code' => 'PE 500!'])->assertHasErrors(['code' => 'regex'])->assertSee('Código no válido');
    catalogNew($admin, ['url' => 'www.sin-esquema.test'])->assertHasErrors(['url' => 'regex']);
    catalogNew($admin, ['line' => 'general_institucional'])->assertHasErrors(['line' => 'in']);
    catalogNew($admin, ['line' => 'estancias', 'code' => 'EST-500', 'name_es' => 'Estancia Quinientos'])->assertHasNoErrors();
    catalogNew($admin, ['line' => '', 'code' => 'X-500', 'name_es' => 'Sin línea'])->assertHasNoErrors();

    expect(Program::query()->pluck('line', 'code')->all())->toBe(['EST-500' => 'estancias', 'X-500' => null]);
});

it('editar un programa no choca consigo mismo ni vuelve a auditar un alta, pero sí con otro programa', function () {
    [, $admin] = sharedCtx();
    $program = Program::factory()->create(['code' => 'PE-500', 'name_es' => 'Programa Quinientos', 'line' => 'programas_ejecutivos', 'url' => 'https://x.test/a']);
    Program::factory()->create(['code' => 'PE-600', 'name_es' => 'Programa Seiscientos', 'line' => 'programas_ejecutivos']);

    Livewire::actingAs($admin)->test(Form::class, ['program' => $program])
        ->set('name_en', 'Program Five Hundred')->call('save')->assertHasNoErrors();
    Livewire::actingAs($admin)->test(Form::class, ['program' => $program->fresh()])
        ->set('code', 'PE-600')->call('save')->assertHasErrors('code');

    expect($program->fresh()->code)->toBe('PE-500')->and($program->fresh()->name_en)->toBe('Program Five Hundred')
        ->and(AuditLog::query()->where('action', 'program.created')->count())->toBe(0);
});
