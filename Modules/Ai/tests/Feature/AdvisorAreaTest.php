<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\AdvisorDeletionService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Conversation;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;
use Modules\Integrations\Models\AiProcessConfig;
use Modules\Integrations\Models\Integration;

function advisorAdmin(): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($institution->id, fn () => Bot::factory()->create([
        'status' => 'active', 'slug' => 'microcredenciales', 'assistant_name' => 'Celia', 'type' => 'ia',
    ]));

    return User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
}

it('la lista de Asesores es visible para Admin y Marketing (lectura); Celia aparece', function () {
    $admin = advisorAdmin();
    $this->actingAs($admin)->get('/advisors')->assertOk()->assertSee('Celia');

    $marketing = User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'marketing']);
    $this->actingAs($marketing)->get('/advisors')->assertOk();
});

it('solo Administrador puede crear/editar asesores', function () {
    $admin = advisorAdmin();
    $this->actingAs($admin)->get('/advisors/create')->assertOk();

    $marketing = User::factory()->create(['institution_id' => $admin->institution_id, 'role' => 'marketing']);
    $this->actingAs($marketing)->get('/advisors/create')->assertForbidden();
});

it('crea un asesor IA (bot con type, slug, public_key y proceso de conversacion)', function () {
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        $integration = Integration::factory()->create(['type' => 'ai_provider', 'provider' => 'qwen', 'status' => 'active']);

        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Form::class)
            ->set('name', 'Sofia')
            ->set('type', 'ia')
            ->set('language', 'en')
            ->set('status', 'active')
            ->set('integrationId', $integration->id)
            ->set('model', 'qwen3.7-plus')
            ->call('save')
            ->assertHasNoErrors();

        $bot = Bot::query()->where('assistant_name', 'Sofia')->first();
        expect($bot)->not->toBeNull();
        expect($bot->type)->toBe('ia');
        expect($bot->slug)->not->toBe('');
        expect(strlen((string) $bot->public_key))->toBe(32);
        expect($bot->default_language)->toBe('en');

        $cfg = AiProcessConfig::query()->where('bot_id', $bot->id)->where('process', 'conversation')->first();
        expect($cfg)->not->toBeNull();
        expect($cfg->model)->toBe('qwen3.7-plus');
    });
});

it('guarda el tipo Humano como etiqueta', function () {
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Form::class)
            ->set('name', 'Asesor Humano')
            ->set('type', 'human')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $bot = Bot::query()->where('assistant_name', 'Asesor Humano')->first();
        expect($bot->type)->toBe('human');
        expect($bot->isAi())->toBeFalse();
    });
});

it('sube foto y conocimiento en edicion, y permite quitar un documento', function () {
    Storage::fake('public');
    Storage::fake('knowledge');
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        $bot = Bot::query()->where('slug', 'microcredenciales')->first();

        $md = UploadedFile::fake()->createWithContent('kb.md',
            "# General\n<!-- Codigo: KB-A · Idioma: es -->\n\n## A\nContenido.");

        $comp = Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Form::class, ['bot' => $bot]);

        // Foto
        $comp->set('avatar', UploadedFile::fake()->image('a.png', 120, 120))->call('saveAvatar')->assertHasNoErrors();
        expect($bot->refresh()->avatar_path)->toBe('advisors/microcredenciales/avatar.png');
        Storage::disk('public')->assertExists('advisors/microcredenciales/avatar.png');

        // Conocimiento (upsert + sync)
        $comp->set('docs', [$md])->call('uploadKnowledge')->assertHasNoErrors();
        $source = KnowledgeSource::query()->where('bot_id', $bot->id)->where('code', 'KB-A')->first();
        expect($source)->not->toBeNull();
        expect($source->source_file)->toBe('kb.md');
        Storage::disk('knowledge')->assertExists('microcredenciales/kb.md');

        // Quitar el documento (borra fila y archivo)
        $comp->call('removeKnowledge', $source->id)->assertHasNoErrors();
        expect(KnowledgeSource::query()->where('code', 'KB-A')->exists())->toBeFalse();
        Storage::disk('knowledge')->assertMissing('microcredenciales/kb.md');
    });
});

it('la guarda impide eliminar un asesor activo o con historico; permite uno limpio inactivo', function () {
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        $guard = new AdvisorDeletionService;

        // Activo (como Celia) -> bloqueado.
        $active = Bot::query()->where('slug', 'microcredenciales')->first();
        expect($guard->canDelete($active))->toBeFalse();

        // Inactivo pero con historico (una conversacion) -> bloqueado.
        $withHistory = Bot::factory()->create(['status' => 'inactive', 'slug' => 'con-historico']);
        Conversation::factory()->create(['bot_id' => $withHistory->getKey()]);
        expect($guard->canDelete($withHistory))->toBeFalse();

        // Inactivo y sin historico -> eliminable.
        $clean = Bot::factory()->create(['status' => 'inactive', 'slug' => 'limpio']);
        expect($guard->canDelete($clean))->toBeTrue();
    });
});

it('protege al asesor activo del widget: rechaza su eliminacion aunque se confirme', function () {
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        $celia = Bot::query()->where('slug', 'microcredenciales')->first(); // active

        Livewire::actingAs(User::query()->where('role', 'admin')->first())
            ->test(Form::class, ['bot' => $celia])
            ->set('confirmingDelete', true)
            ->set('deleteConfirmName', 'Celia')
            ->call('deleteAdvisor');

        expect(Bot::query()->find($celia->getKey()))->not->toBeNull(); // sigue existiendo
    });
});

it('elimina un asesor limpio con confirmacion por nombre y borra sus archivos', function () {
    Storage::fake('public');
    Storage::fake('knowledge');
    $admin = advisorAdmin();

    app(CurrentInstitution::class)->runFor($admin->institution_id, function () {
        $bot = Bot::factory()->create(['status' => 'inactive', 'assistant_name' => 'Prueba', 'slug' => 'prueba']);
        Storage::disk('knowledge')->put('prueba/kb.md', '# x');
        Storage::disk('public')->put('advisors/prueba/avatar.png', 'x');
        $bot->avatar_path = 'advisors/prueba/avatar.png';
        $bot->save();

        $comp = Livewire::actingAs(User::query()->where('role', 'admin')->first())->test(Form::class, ['bot' => $bot]);

        // Nombre incorrecto -> error, no borra.
        $comp->call('confirmDelete')->set('deleteConfirmName', 'otro')->call('deleteAdvisor')->assertHasErrors('deleteConfirmName');
        expect(Bot::query()->find($bot->getKey()))->not->toBeNull();

        // Nombre exacto -> borra (fila + archivos).
        $comp->set('deleteConfirmName', 'Prueba')->call('deleteAdvisor');
        expect(Bot::query()->find($bot->getKey()))->toBeNull();
        Storage::disk('knowledge')->assertMissing('prueba/kb.md');
        Storage::disk('public')->assertMissing('advisors/prueba/avatar.png');
    });
});
