<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Form;
use Modules\Catalog\Livewire\Programs\Index;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Catalog\Models\ProgramLine;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Fase 4 — Gestión manual de programas: crear (todos los campos, incl. recomendador),
 * editar (course_id bloqueado), archivar/restaurar y eliminar definitivo. Solo Admin.
 */
function mngUser(string $role = 'admin'): User
{
    $inst = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $inst->id, 'role' => $role, 'status' => 'active']);
    app(CurrentInstitution::class)->set($inst->id);

    return $user;
}

it('crea un programa a mano con TODOS los campos (incl. nivel/meta/perfil); code queda NULL', function () {
    $this->actingAs(mngUser('admin'));
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $line = ProgramLine::factory()->create(['name_es' => 'Microcredenciales']);

    Livewire::test(Form::class)
        ->set('course_idnumber', 'manual-001')
        ->set('name_es', 'Programa Manual')
        ->set('name_en', 'Manual Program')
        ->set('line_id', $line->id)          // categoría de formación
        ->set('category_id', $area->id)      // área temática (eje separado)
        ->set('level', 'intermedio')
        ->set('goal', 'ascenso')
        ->set('profile', 'Mandos medios')
        ->set('learnings_es', 'A; B; C')
        ->set('url', 'https://mca/manual-001')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('catalog.programs.index'));

    $p = Program::query()->where('course_idnumber', 'manual-001')->first();
    expect($p)->not->toBeNull();
    expect($p->code)->toBeNull();                 // no se inventa MC-XXX
    expect($p->line_id)->toBe($line->id);         // categoría de formación
    expect($p->category_id)->toBe($area->id);     // área temática
    expect($p->level)->toBe('intermedio');
    expect($p->goal)->toBe('ascenso');
    expect($p->profile)->toBe('Mandos medios');
    expect($p->learnings_es)->toBe('A; B; C');
});

it('course_id es obligatorio y único al crear', function () {
    $this->actingAs(mngUser('admin'));
    Program::factory()->create(['course_idnumber' => 'dup']);

    Livewire::test(Form::class)->set('name_es', 'X')->call('save')->assertHasErrors('course_idnumber');
    Livewire::test(Form::class)->set('course_idnumber', 'dup')->set('name_es', 'X')->set('url', 'https://x')->call('save')->assertHasErrors('course_idnumber');
});

it('al EDITAR, course_id está bloqueado: no cambia aunque se manipule, y el área no se altera', function () {
    $user = mngUser('admin');
    $this->actingAs($user);
    $area = ProgramCategory::factory()->create();
    $p = Program::factory()->create(['course_idnumber' => 'orig-id', 'name_es' => 'Original', 'category_id' => $area->id]);

    Livewire::test(Form::class, ['program' => $p])
        ->assertSet('course_idnumber', 'orig-id')
        ->set('course_idnumber', 'HACKEADO')     // intento de manipulación
        ->set('name_es', 'Editado')
        ->call('save')
        ->assertHasNoErrors();

    $p->refresh();
    expect($p->course_idnumber)->toBe('orig-id');   // NO cambió (bloqueado)
    expect($p->name_es)->toBe('Editado');
    expect($p->category_id)->toBe($area->id);        // área intacta
});

it('crea una categoría de formación nueva al vuelo desde el formulario', function () {
    $this->actingAs(mngUser('admin'));

    Livewire::test(Form::class)
        ->set('course_idnumber', 'nl-1')
        ->set('name_es', 'Con línea nueva')
        ->set('newLineName', 'Diplomas Avanzados')
        ->set('url', 'https://x')
        ->call('save')
        ->assertHasNoErrors();

    $line = ProgramLine::query()->where('name_es', 'Diplomas Avanzados')->first();
    expect($line)->not->toBeNull();
    expect(Program::query()->where('course_idnumber', 'nl-1')->value('line_id'))->toBe($line->id);
});

it('archiva (borrado suave), lo saca de la lista activa, y lo restaura', function () {
    $this->actingAs(mngUser('admin'));
    $p = Program::factory()->create(['name_es' => 'Archivable']);

    Livewire::test(Index::class)
        ->assertSee('Archivable')
        ->call('archive', $p->id)
        ->assertDontSee('Archivable')               // fuera de la lista activa
        ->set('showArchived', true)
        ->assertSee('Archivable')                   // aparece en archivados
        ->call('restore', $p->id)
        ->set('showArchived', false)
        ->assertSee('Archivable');                  // restaurado

    expect(Program::query()->whereKey($p->id)->exists())->toBeTrue();
});

it('elimina definitivamente solo tras confirmación', function () {
    $this->actingAs(mngUser('admin'));
    $p = Program::factory()->create();
    $p->delete(); // archivado

    Livewire::test(Index::class)
        ->set('showArchived', true)
        ->call('confirmDelete', $p->id)
        ->assertSet('deletingId', $p->id)           // pide confirmación (no borra aún)
        ->call('deleteForever');

    expect(Program::withTrashed()->whereKey($p->id)->exists())->toBeFalse();   // ya no existe
});

it('la gestión de programas es solo Admin (Marketing → 403 por URL directa)', function () {
    $this->actingAs(mngUser('marketing'))->get('/catalog/create')->assertForbidden();
    $this->actingAs(mngUser('admissions'))->get('/catalog')->assertForbidden();
    $this->actingAs(mngUser('admin'))->get('/catalog/create')->assertOk();
});
