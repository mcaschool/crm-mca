<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Form;
use Modules\Catalog\Livewire\Programs\Index;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Gestion del catalogo en el panel: gating por rol, CRUD, bilinguismo y orden.
 */
function catalogUser(string $role): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
    app(CurrentInstitution::class)->set($institution->id);

    return $user;
}

it('Admisiones NO accede al catalogo', function () {
    $this->actingAs(catalogUser('admissions'))->get('/catalog')->assertForbidden();
});

it('Administrador y Marketing SI acceden al catalogo', function () {
    $this->actingAs(catalogUser('admin'))->get('/catalog')->assertOk();
    $this->actingAs(catalogUser('marketing'))->get('/catalog')->assertOk();
});

it('abre la edicion de un programa por HTTP (route-model-binding con scope global)', function () {
    // Regresion: el binding de un modelo con scope global exige que el contexto de
    // institucion se fije ANTES de SubstituteBindings (prioridad de middleware).
    $user = catalogUser('admin');
    $program = Program::factory()->create(['name_es' => 'Editable']);

    $this->actingAs($user)->get(route('catalog.programs.edit', $program))
        ->assertOk()
        ->assertSee('Editable');
});

it('crea un programa con etiquetas', function () {
    $this->actingAs(catalogUser('admin'));

    Livewire::test(Form::class)
        ->set('code', 'MC-900')
        ->set('name_es', 'Programa Nuevo')
        ->set('url', 'https://x/mc-900')
        ->set('tagsCsv', 'tema-x, dominante-y')
        ->call('save')
        ->assertRedirect(route('catalog.programs.index'));

    $program = Program::query()->where('code', 'MC-900')->first();
    expect($program)->not->toBeNull();
    expect($program->tags()->pluck('tag')->all())->toEqualCanonicalizing(['tema-x', 'dominante-y']);
});

it('completa el nombre en ingles de un programa (bilingue)', function () {
    $this->actingAs(catalogUser('marketing'));
    $program = Program::factory()->create(['name_es' => 'Solo Espanol', 'name_en' => null]);

    Livewire::test(Form::class, ['program' => $program])
        ->assertSet('name_en', '')
        ->set('name_en', 'English Name')
        ->call('save');

    expect($program->fresh()->name_en)->toBe('English Name');
    expect($program->fresh()->name_es)->toBe('Solo Espanol');
});

it('activa y desactiva un programa', function () {
    $this->actingAs(catalogUser('admin'));
    $program = Program::factory()->create(['status' => 'active']);

    Livewire::test(Index::class)->call('toggleActive', $program->id);

    expect($program->fresh()->status)->toBe('inactive');
});

it('reordena programas intercambiando el display_order', function () {
    $this->actingAs(catalogUser('admin'));
    $first = Program::factory()->create(['display_order' => 10, 'name_es' => 'Primero']);
    $second = Program::factory()->create(['display_order' => 20, 'name_es' => 'Segundo']);

    Livewire::test(Index::class)->call('moveUp', $second->id);

    expect($second->fresh()->display_order)->toBe(10);
    expect($first->fresh()->display_order)->toBe(20);
});
