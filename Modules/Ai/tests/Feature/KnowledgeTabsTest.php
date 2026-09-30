<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Agents;
use Modules\Ai\Livewire\Knowledge\Library;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Pestañas del Centro de Conocimiento: la activa la pasa cada vista explícitamente, así que
 * sigue marcada tras una acción de Livewire (cuya petición no es la ruta de la página).
 */
function tabsAdmin(): User
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    Bot::factory()->create(['status' => 'active', 'slug' => 'sophia', 'assistant_name' => 'Sophia']);

    return User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
}

/** Estado de las dos pestañas en el HTML: ['library' => bool, 'agents' => bool] (aria-selected). */
function tabsState(string $html): array
{
    $state = [];
    foreach (['library' => route('ai.knowledge.library'), 'agents' => route('ai.knowledge.agents')] as $key => $url) {
        preg_match('/<a href="'.preg_quote(e($url), '/').'" role="tab" aria-selected="(true|false)"/', $html, $m);
        $state[$key] = ($m[1] ?? null) === 'true';
    }

    return $state;
}

it('«Por agente» sigue activa al cargar y tras acciones de Livewire', function () {
    $admin = tabsAdmin();

    expect(tabsState(test()->actingAs($admin)->get(route('ai.knowledge.agents'))->assertOk()->getContent()))
        ->toBe(['library' => false, 'agents' => true]);

    $component = Livewire::actingAs($admin)->test(Agents::class);
    expect(tabsState($component->html()))->toBe(['library' => false, 'agents' => true]);

    $component->call('toggleDocsOpen', 'programas_ejecutivos');
    expect(tabsState($component->html()))->toBe(['library' => false, 'agents' => true]);

    $component->set('programSearch', 'x');
    expect(tabsState($component->html()))->toBe(['library' => false, 'agents' => true]);
});

it('«Biblioteca» sigue activa al cargar y tras acciones de Livewire', function () {
    $admin = tabsAdmin();

    expect(tabsState(test()->actingAs($admin)->get(route('ai.knowledge.library'))->assertOk()->getContent()))
        ->toBe(['library' => true, 'agents' => false]);

    $component = Livewire::actingAs($admin)->test(Library::class);
    expect(tabsState($component->html()))->toBe(['library' => true, 'agents' => false]);

    $component->call('filterByCategory', 'programas_ejecutivos');
    expect(tabsState($component->html()))->toBe(['library' => true, 'agents' => false]);

    $component->call('clearFilters');
    expect(tabsState($component->html()))->toBe(['library' => true, 'agents' => false]);
});
