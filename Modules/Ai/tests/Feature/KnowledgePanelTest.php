<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Index as KnowledgeIndex;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Gating por rol y sincronizacion de la base de conocimiento: solo Administrador.
 */
function knowledgeUser(string $role): User
{
    $institution = Institution::factory()->create();

    app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create(['status' => 'active']));

    return User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
}

it('un Administrador accede a Conocimiento', function () {
    $this->actingAs(knowledgeUser('admin'))->get('/knowledge')->assertOk();
});

it('Marketing NO accede a Conocimiento', function () {
    $this->actingAs(knowledgeUser('marketing'))->get('/knowledge')->assertForbidden();
});

it('Admisiones NO accede a Conocimiento', function () {
    $this->actingAs(knowledgeUser('admissions'))->get('/knowledge')->assertForbidden();
});

it('el boton Sincronizar procesa la carpeta y crea las fuentes', function () {
    Storage::fake('knowledge');
    Storage::disk('knowledge')->put('celia_kb_general_es.md',
        "# Conocimiento general\n<!-- Codigo: KB-MC-GENERAL-001 · Idioma: es -->\n\n## Que es\nUna microcredencial.");

    $admin = knowledgeUser('admin');

    // El componente resuelve el bot por contexto de institucion (que en HTTP fija
    // el middleware del panel); aqui lo establecemos explicitamente.
    app(CurrentInstitution::class)->runFor($admin->institution_id, function () use ($admin) {
        Livewire::actingAs($admin)->test(KnowledgeIndex::class)
            ->call('sync')
            ->assertHasNoErrors();

        expect(KnowledgeSource::query()->where('code', 'KB-MC-GENERAL-001')->count())->toBe(1);
    });
});
