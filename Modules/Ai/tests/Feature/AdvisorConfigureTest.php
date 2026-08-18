<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Configure;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Ficha del Asesor: gating por rol (solo Admin) y los tres campos configurables
 * (nombre, avatar, conocimiento con auto-sync).
 */
function advisorUser(string $role): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create(['status' => 'active', 'slug' => 'microcredenciales', 'assistant_name' => 'Celia']));

    return User::factory()->create(['institution_id' => $institution->id, 'role' => $role]);
}

it('un Administrador accede a la ficha del Asesor; Marketing no', function () {
    $this->actingAs(advisorUser('admin'))->get('/advisor')->assertOk();
    $this->actingAs(advisorUser('marketing'))->get('/advisor')->assertForbidden();
});

it('guarda el nombre del asesor (lo leen widget y saludos)', function () {
    $admin = advisorUser('admin');

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Configure::class)
            ->set('name', 'Sofía')
            ->call('saveName')
            ->assertHasNoErrors();

        expect(Bot::query()->where('status', 'active')->first()->assistant_name)->toBe('Sofía');
    });
});

it('sube y fija la foto de perfil en el disco publico', function () {
    Storage::fake('public');
    $admin = advisorUser('admin');

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Configure::class)
            ->set('avatar', UploadedFile::fake()->image('celia.png', 200, 200))
            ->call('saveAvatar')
            ->assertHasNoErrors();

        $bot = Bot::query()->where('status', 'active')->first();
        expect($bot->avatar_path)->toBe('advisors/microcredenciales/avatar.png');
        Storage::disk('public')->assertExists('advisors/microcredenciales/avatar.png');
        expect($bot->avatarUrl())->toContain('advisors/microcredenciales/avatar.png');
    });
});

it('sube un .md y sincroniza el conocimiento automaticamente (upsert)', function () {
    Storage::fake('knowledge');
    $admin = advisorUser('admin');

    $md = UploadedFile::fake()->createWithContent(
        'kb_general.md',
        "# Conocimiento general\n<!-- Codigo: KB-MC-GENERAL-001 · Idioma: es -->\n\n## Que es\nUna microcredencial es una unidad academica."
    );

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () use ($md) {
        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Configure::class)
            ->set('docs', [$md])
            ->call('uploadKnowledge')
            ->assertHasNoErrors();

        $bot = Bot::query()->where('status', 'active')->first();
        // El archivo se guardo en la subcarpeta del asesor.
        Storage::disk('knowledge')->assertExists('microcredenciales/kb_general.md');
        // Y se sincronizo a knowledge_sources (upsert por codigo).
        $source = KnowledgeSource::query()->where('bot_id', $bot->id)->where('code', 'KB-MC-GENERAL-001')->first();
        expect($source)->not->toBeNull();
        expect($source->last_synced_at)->not->toBeNull();
    });
});

it('rechaza un archivo que no es .md', function () {
    Storage::fake('knowledge');
    $admin = advisorUser('admin');

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Configure::class)
            ->set('docs', [UploadedFile::fake()->create('nota.txt', 5)])
            ->call('uploadKnowledge')
            ->assertHasErrors('docs');

        expect(KnowledgeSource::query()->count())->toBe(0);
    });
});
