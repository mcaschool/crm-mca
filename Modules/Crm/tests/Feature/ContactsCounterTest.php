<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Livewire\Contacts\Index;
use Modules\Crm\Models\Contact;
use Modules\Institutions\Models\Institution;

/*
| Contador de Contactos (etapa 7): la cabecera mostraba cuántos se habían CARGADO (máximo 200).
| Ahora muestra el total real de la institución con los filtros aplicados (COUNT, sin cargar las
| filas) y el paginador indica el tramo visible.
*/

function counterAdmin(): User
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);

    return User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin', 'status' => 'active']);
}

it('con más de 200 contactos muestra el total real, la página actual y el tramo visible', function () {
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->set($other->id);
    Contact::factory()->count(7)->create();

    $admin = counterAdmin();
    Contact::factory()->count(230)->create();

    $component = Livewire::actingAs($admin)->test(Index::class);
    $contacts = $component->viewData('contacts');

    expect($contacts->total())->toBe(230)                // solo su institución
        ->and($contacts->count())->toBe(Index::PER_PAGE) // filas cargadas en esta página
        ->and($contacts->lastPage())->toBe(10);
    $component->assertSeeHtml('<span class="ph-count">230</span>')
        ->assertSeeHtml('<b>1–25</b>')->assertSeeHtml('<b>230</b>');

    $component->call('nextPage');
    expect($component->viewData('contacts')->currentPage())->toBe(2)
        ->and($component->viewData('contacts')->firstItem())->toBe(26);
});

it('con búsqueda el total es el de los que coinciden y vuelve a la primera página', function () {
    $admin = counterAdmin();
    Contact::factory()->count(40)->create();
    Contact::factory()->count(12)->create(['first_name' => 'Zulema']);

    $component = Livewire::actingAs($admin)->test(Index::class)->call('nextPage')
        ->set('search', 'Zulema');

    $contacts = $component->viewData('contacts');
    expect($contacts->total())->toBe(12)->and($contacts->currentPage())->toBe(1)->and($contacts->count())->toBe(12);
    $component->assertSeeHtml('<span class="ph-count">12</span>');
});

it('cuenta con una consulta COUNT sin cargar todos los contactos en memoria', function () {
    $admin = counterAdmin();
    Contact::factory()->count(60)->create();

    DB::enableQueryLog();
    Livewire::actingAs($admin)->test(Index::class);
    $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from `contacts`'));
    DB::disableQueryLog();

    expect($selects->contains(fn ($q) => str_contains($q, 'count(*)')))->toBeTrue()
        ->and($selects->contains(fn ($q) => str_contains($q, 'limit '.Index::PER_PAGE)))->toBeTrue();
});
