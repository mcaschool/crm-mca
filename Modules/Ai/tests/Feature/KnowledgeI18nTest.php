<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Form;
use Modules\Ai\Livewire\Knowledge\Agents;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Bloque 4d: el Centro de Conocimiento y la ficha del asesor salen COMPLETOS en el idioma
 * activo (ES: textos en español; EN: todos traducidos en lang/en.json, sin mezclas).
 *
 * @return array{0: User, 1: Bot}
 */
function i18nCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $bot = Bot::factory()->create(['status' => 'active', 'assistant_name' => 'Lola', 'type' => 'ia', 'slug' => 'lola']);

    return [User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']), $bot];
}

it('con ES el Centro y la ficha salen en español', function () {
    [$admin, $bot] = i18nCtx();
    app()->setLocale('es');

    Livewire::actingAs($admin)->test(Library::class)
        ->assertSee('Sincronizar biblioteca')->assertSee('Todas')->assertSee('Nombre')->assertSee('Acciones')
        ->assertDontSee('Sync library')->assertDontSee('Actions');
    Livewire::actingAs($admin)->test(Agents::class)
        ->assertSee('Programas que puede recomendar')->assertDontSee('Programs it can recommend');
    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSee('Configurar asesor')->assertSee('Guardar cambios')->assertSee('Re-sincronizar')->assertSee('Copiar')
        ->assertDontSee('Save changes');
});

it('con EN el Centro y la ficha salen en inglés', function () {
    [$admin, $bot] = i18nCtx();
    app()->setLocale('en');

    Livewire::actingAs($admin)->test(Library::class)
        ->assertSee('Sync library')->assertSee('Academic Program')->assertSee('Knowledge Base')->assertSee('Upload')
        ->assertSee('Actions')->assertSee('Line:')
        ->assertDontSee('Sincronizar biblioteca')->assertDontSee('Elegir archivos')->assertDontSee('Filtrar por línea');
    Livewire::actingAs($admin)->test(Agents::class)
        ->assertSee('Programs it can recommend')->assertDontSee('Programas que puede recomendar');
    Livewire::actingAs($admin)->test(Form::class, ['bot' => $bot])
        ->assertSee('Configure advisor')->assertSee('Save changes')->assertSee('Re-sync')->assertSee('Copy')
        ->assertDontSee('Configurar asesor')->assertDontSee('Guardar cambios');
});
