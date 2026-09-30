<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Agents;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Services\LineAssignmentService;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * «Por agente» organizado por LÍNEA: cada bloque junta las dos capas del agente (documentos
 * de conocimiento y programas recomendables, con el área como subgrupo) y ofrece
 * «Asignar / Quitar línea completa» (acción puntual, institución actual).
 *
 * @return array{0: Institution, 1: User, 2: Bot}
 */
function linesCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $sophia = Bot::factory()->create(['status' => 'active', 'slug' => 'sophia', 'assistant_name' => 'Sophia']);
    SelectedAdvisor::set($sophia->id);

    return [$inst, $admin, $sophia];
}

function linesSource(string $code, string $line, string $status = 'active'): KnowledgeSource
{
    return KnowledgeSource::factory()->create(['code' => $code, 'name' => 'Doc '.$code, 'bot_id' => null, 'category' => $line, 'status' => $status]);
}

/** @param  array<string, mixed>  $attrs */
function linesProgram(string $code, string $line, array $attrs = []): Program
{
    return Program::factory()->create($attrs + ['code' => $code, 'name_es' => 'Programa '.$code, 'line' => $line, 'status' => 'active']);
}

it('agrupa ambas capas por línea con contadores explícitos de documentos y programas', function () {
    [, $admin, $sophia] = linesCtx();
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $doc = linesSource('PE-FAQ', 'programas_ejecutivos');
    linesSource('PE-MODELO', 'programas_ejecutivos');
    linesSource('DA-DOC', 'diplomas_avanzados');
    $pe = linesProgram('PE-001', 'programas_ejecutivos', ['category_id' => $area->id]);
    linesProgram('PE-002', 'programas_ejecutivos', ['category_id' => $area->id]);
    linesProgram('DA-001', 'diplomas_avanzados');
    $sophia->knowledgeSources()->attach($doc->id, ['is_active' => true]);
    $sophia->programs()->attach($pe->id);

    $html = Livewire::actingAs($admin)->test(Agents::class)
        ->assertSee('Programas Ejecutivos')->assertSee('Diplomas Avanzados')
        ->assertSee('Documentos 1/2')->assertSee('Programas 1/2')   // Programas Ejecutivos
        ->assertSee('Documentos 0/1')->assertSee('Programas 0/1')   // Diplomas Avanzados
        ->assertSee('Asignar línea completa')->assertSee('Quitar línea completa')
        ->assertDontSee('Microcredenciales')                        // sin contenido: no se muestra
        ->html();

    // Orden de la lista fija (PE antes que DA) y el área como subgrupo dentro de la línea.
    expect(strpos($html, 'Programas Ejecutivos'))->toBeLessThan(strpos($html, 'Diplomas Avanzados'))
        ->and($html)->toContain('Liderazgo');
});

it('los documentos de cada línea van plegados: se ven contadores y botones, y se despliegan con un clic', function () {
    [, $admin] = linesCtx();
    linesSource('PE-FAQ', 'programas_ejecutivos');
    linesProgram('PE-001', 'programas_ejecutivos');

    Livewire::actingAs($admin)->test(Agents::class)
        ->assertSee('Documentos 0/1')->assertSee('Programas 0/1')->assertSee('Asignar línea completa')
        ->assertDontSee('Doc PE-FAQ')->assertDontSee('Usar toda la categoría')
        ->call('toggleDocsOpen', 'programas_ejecutivos')
        ->assertSee('Doc PE-FAQ')->assertSee('Usar toda la categoría')
        ->call('toggleDocsOpen', 'programas_ejecutivos')
        ->assertDontSee('Doc PE-FAQ');
});

it('asignar línea completa activa sus documentos activos y asigna sus programas activos; quitarla los retira', function () {
    [, $admin, $sophia] = linesCtx();
    $d1 = linesSource('DA-D1', 'diplomas_avanzados');
    $d2 = linesSource('DA-D2', 'diplomas_avanzados');
    $dOff = linesSource('DA-OFF', 'diplomas_avanzados', 'inactive');
    $other = linesSource('PE-D', 'programas_ejecutivos');
    $p1 = linesProgram('DA-001', 'diplomas_avanzados');
    $p2 = linesProgram('DA-002', 'diplomas_avanzados');
    $pOff = linesProgram('DA-003', 'diplomas_avanzados', ['status' => 'inactive']);
    $pe = linesProgram('PE-001', 'programas_ejecutivos');
    $sophia->knowledgeSources()->attach($d2->id, ['is_active' => false]); // pausada: se reactiva

    Livewire::actingAs($admin)->test(Agents::class)->call('assignLine', 'diplomas_avanzados')->assertHasNoErrors();

    $state = $sophia->knowledgeSources()->get()->mapWithKeys(fn ($s) => [$s->code => (bool) $s->pivot->is_active])->all();
    expect($state)->toBe(['DA-D1' => true, 'DA-D2' => true])
        ->and($sophia->programs()->pluck('programs.id')->sort()->values()->all())->toBe([$p1->id, $p2->id]);

    // Otra línea asignada a mano: quitar DA no la toca.
    $sophia->programs()->attach($pe->id);
    $sophia->knowledgeSources()->attach($other->id, ['is_active' => true]);

    Livewire::actingAs($admin)->test(Agents::class)->call('detachLine', 'diplomas_avanzados')->assertHasNoErrors();

    expect($sophia->knowledgeSources()->pluck('code')->all())->toBe(['PE-D'])
        ->and($sophia->programs()->pluck('programs.id')->all())->toBe([$pe->id]);
    expect($dOff->fresh())->not->toBeNull()->and($pOff->fresh())->not->toBeNull(); // no se borra nada
});

it('las acciones de línea no afectan a otros agentes', function () {
    [, $admin, $sophia] = linesCtx();
    $celia = Bot::factory()->create(['status' => 'active', 'slug' => 'microcredenciales', 'assistant_name' => 'Celia']);
    $doc = linesSource('MC-KB', 'microcredenciales');
    $mc = linesProgram('MC-001', 'microcredenciales');
    $celia->knowledgeSources()->attach($doc->id, ['is_active' => true]);
    $celia->programs()->attach($mc->id);

    Livewire::actingAs($admin)->test(Agents::class)->call('assignLine', 'microcredenciales')->call('detachLine', 'microcredenciales');

    expect($celia->knowledgeSources()->count())->toBe(1)->and($celia->programs()->count())->toBe(1)
        ->and($sophia->knowledgeSources()->count())->toBe(0)->and($sophia->programs()->count())->toBe(0);
});

it('aislamiento: asignar una línea nunca toma fuentes ni programas de otra institución', function () {
    [$inst, , $sophia] = linesCtx();
    $mine = linesSource('DA-MIO', 'diplomas_avanzados');
    $minePrg = linesProgram('DA-001', 'diplomas_avanzados');

    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, function () {
        linesSource('DA-AJENO', 'diplomas_avanzados');
        linesProgram('DA-001', 'diplomas_avanzados');
    });
    app(CurrentInstitution::class)->set($inst->id);

    $n = app(LineAssignmentService::class)->assignLine($sophia, 'diplomas_avanzados');

    expect($n)->toBe(['sources' => 1, 'programs' => 1])
        ->and($sophia->knowledgeSources()->pluck('knowledge_sources.id')->all())->toBe([$mine->id])
        ->and($sophia->programs()->pluck('programs.id')->all())->toBe([$minePrg->id]);
});

it('una línea fuera de la lista fija se rechaza', function () {
    [, , $sophia] = linesCtx();

    expect(fn () => app(LineAssignmentService::class)->assignLine($sophia, 'estancias'))->toThrow(InvalidArgumentException::class);
});

it('el área dentro de una línea solo asigna los programas de esa línea', function () {
    [, $admin, $sophia] = linesCtx();
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $pe = linesProgram('PE-001', 'programas_ejecutivos', ['category_id' => $area->id]);
    linesProgram('DA-001', 'diplomas_avanzados', ['category_id' => $area->id]);

    Livewire::actingAs($admin)->test(Agents::class)->call('assignProgramArea', (string) $area->id, 'programas_ejecutivos');

    expect($sophia->programs()->pluck('programs.id')->all())->toBe([$pe->id]);
});

it('un no-Admin no puede asignar ni quitar líneas', function () {
    [$inst, , $sophia] = linesCtx();
    linesProgram('DA-001', 'diplomas_avanzados');
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    Livewire::actingAs($marketing)->test(Agents::class)->assertForbidden();
    expect($sophia->programs()->count())->toBe(0);
});
