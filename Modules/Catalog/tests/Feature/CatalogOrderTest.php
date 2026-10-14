<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Catalog\Livewire\Programs\Index;
use Modules\Catalog\Models\Program;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Institution;

/*
| Orden del catálogo (etapa 7): con display_order repetido (los 77 programas importados tenían 1)
| Subir/Bajar no hacían nada. Ahora la secuencia se renumera 1..n en el orden visible y se mueven
| de verdad, con límites, persistencia, sin duplicados y solo en la institución activa.
*/

/** @return array{0:User,1:array<string,Program>} */
function orderCtx(array $names = ['Alfa', 'Beta', 'Gamma', 'Delta', 'Épsilon']): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $user = User::factory()->create(['institution_id' => $institution->id, 'role' => 'admin']);
    $programs = [];
    foreach ($names as $name) {
        $programs[$name] = Program::factory()->create(['display_order' => 1, 'name_es' => $name]);
    }

    return [$user, $programs];
}

/** @return list<string> */
function orderNames(): array
{
    return Program::query()->orderBy('display_order')->orderBy('name_es')->orderBy('id')->pluck('name_es')->all();
}

it('con empates, Bajar y Subir mueven de verdad y dejan una secuencia 1..n sin duplicados', function () {
    [$user, $p] = orderCtx();
    expect(orderNames())->toBe(['Alfa', 'Beta', 'Delta', 'Épsilon', 'Gamma']);   // orden visible inicial (É = E)

    Livewire::actingAs($user)->test(Index::class)->call('moveDown', $p['Alfa']->id);
    expect(orderNames())->toBe(['Beta', 'Alfa', 'Delta', 'Épsilon', 'Gamma'])
        ->and(Program::query()->orderBy('display_order')->pluck('display_order')->all())->toBe([1, 2, 3, 4, 5]);

    Livewire::actingAs($user)->test(Index::class)->call('moveUp', $p['Gamma']->id);
    expect(orderNames())->toBe(['Beta', 'Alfa', 'Delta', 'Gamma', 'Épsilon']);

    // Persiste: un componente nuevo lee el mismo orden.
    $html = Livewire::actingAs($user)->test(Index::class)->html();
    expect(strpos($html, 'Gamma'))->toBeLessThan(strpos($html, 'Épsilon'))
        ->and(strpos($html, 'Beta'))->toBeLessThan(strpos($html, 'Alfa'));
});

it('respeta los límites: subir el primero o bajar el último no cambia nada', function () {
    [$user, $p] = orderCtx(['A', 'B', 'C']);
    Livewire::actingAs($user)->test(Index::class)->call('moveDown', $p['A']->id);   // normaliza: B, A, C = 1, 2, 3
    $before = Program::query()->orderBy('id')->pluck('display_order', 'name_es')->all();

    Livewire::actingAs($user)->test(Index::class)->call('moveUp', $p['B']->id)->call('moveDown', $p['C']->id);

    expect($before)->toBe(['A' => 2, 'B' => 1, 'C' => 3])
        ->and(Program::query()->orderBy('id')->pluck('display_order', 'name_es')->all())->toBe($before);
});

it('solo reordena la institución activa y no toca otros datos del programa', function () {
    [, $other] = orderCtx(['Ajeno A', 'Ajeno B']);
    $otherInstitution = $other['Ajeno A']->institution_id;

    [$user, $p] = orderCtx(['Propio A', 'Propio B']);
    $snapshot = $p['Propio A']->only(['code', 'name_es', 'name_en', 'status', 'category_id', 'level', 'goal', 'url']);

    Livewire::actingAs($user)->test(Index::class)->call('moveDown', $p['Propio A']->id);

    expect(DB::table('programs')->where('institution_id', $otherInstitution)->pluck('display_order')->all())->toBe([1, 1])
        ->and($p['Propio A']->fresh()->only(array_keys($snapshot)))->toBe($snapshot)
        ->and($p['Propio A']->fresh()->display_order)->toBe(2);
});

it('Admisiones no puede reordenar', function () {
    [, $p] = orderCtx(['A1', 'B1']);
    $admissions = User::factory()->create(['institution_id' => $p['A1']->institution_id, 'role' => 'admissions']);

    Livewire::actingAs($admissions)->test(Index::class)->assertForbidden();
    expect(DB::table('programs')->whereIn('id', [$p['A1']->id, $p['B1']->id])->pluck('display_order')->all())->toBe([1, 1]);
});

it('la migración fija una secuencia estable por institución conservando el orden visible', function () {
    [, $a] = orderCtx(['Zeta', 'Alfa', 'Mu']);
    $instA = $a['Zeta']->institution_id;
    [, $b] = orderCtx(['Beta', 'Alfa']);
    $instB = $b['Beta']->institution_id;
    DB::table('programs')->where('id', $a['Mu']->id)->update(['display_order' => 0]);   // ya delante de todos

    (require base_path('Modules/Catalog/database/migrations/2026_10_14_100000_normalize_program_display_order.php'))->up();

    $seq = fn (int $inst) => DB::table('programs')->where('institution_id', $inst)->orderBy('display_order')->pluck('display_order', 'name_es')->all();
    expect($seq($instA))->toBe(['Mu' => 1, 'Alfa' => 2, 'Zeta' => 3])
        ->and($seq($instB))->toBe(['Alfa' => 1, 'Beta' => 2]);
});
