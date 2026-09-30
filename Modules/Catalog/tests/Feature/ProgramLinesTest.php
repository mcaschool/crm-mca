<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Lines\Manage;
use Modules\Catalog\Models\ProgramLine;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/**
 * Fase 1 — Categorías de FORMACIÓN gestionables (program_lines): gating, crear/editar,
 * y aislamiento por institución. Eje separado de las áreas; no toca programas.
 */
function linesUser(string $role): User
{
    $institution = Institution::factory()->create();
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => $role, 'status' => 'active']);
    app(CurrentInstitution::class)->set($institution->id);

    return $user;
}

it('SOLO Administrador accede a las categorías de formación; los demás NO (ni por URL directa)', function () {
    $this->actingAs(linesUser('admin'))->get('/catalog/lines')->assertOk();
    $this->actingAs(linesUser('marketing'))->get('/catalog/lines')->assertForbidden();
    $this->actingAs(linesUser('admissions'))->get('/catalog/lines')->assertForbidden();
});

it('crea una categoría de formación desde el panel', function () {
    $this->actingAs(linesUser('admin'));

    Livewire::test(Manage::class)
        ->set('newNameEs', 'Programas Ejecutivos')
        ->call('addLine')
        ->assertHasNoErrors();

    expect(ProgramLine::query()->where('name_es', 'Programas Ejecutivos')->exists())->toBeTrue();
});

it('edita nombre (es/en), estado y orden de una categoría', function () {
    $user = linesUser('admin');
    $this->actingAs($user);
    $line = ProgramLine::factory()->create(['name_es' => 'Original', 'status' => 'active', 'display_order' => 0]);

    Livewire::test(Manage::class)
        ->set("rows.{$line->id}.name_es", 'Diplomas Avanzados')
        ->set("rows.{$line->id}.name_en", 'Advanced Diplomas')
        ->set("rows.{$line->id}.status", 'inactive')
        ->set("rows.{$line->id}.display_order", 3)
        ->call('save')
        ->assertHasNoErrors();

    $line->refresh();
    expect($line->name_es)->toBe('Diplomas Avanzados');
    expect($line->name_en)->toBe('Advanced Diplomas');
    expect($line->status)->toBe('inactive');
    expect($line->display_order)->toBe(3);
});

it('no guarda una categoría con nombre en español vacío', function () {
    $user = linesUser('admin');
    $this->actingAs($user);
    $line = ProgramLine::factory()->create(['name_es' => 'Intacta']);

    Livewire::test(Manage::class)
        ->set("rows.{$line->id}.name_es", '   ')
        ->call('save')
        ->assertHasErrors("rows.{$line->id}.name_es");

    expect($line->refresh()->name_es)->toBe('Intacta');
});

it('cada institución solo ve sus propias categorías de formación (aislamiento)', function () {
    // Categoría de OTRA institución.
    $otherInst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($otherInst->id, fn () => ProgramLine::factory()->create(['name_es' => 'De otra institución']));

    $this->actingAs(linesUser('admin'));
    Livewire::test(Manage::class)->assertDontSee('De otra institución');
});
