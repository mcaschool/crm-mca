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

    // Orden de la lista fija (PE antes que DA); fuera de Microcredenciales no hay subgrupo de área.
    expect(strpos($html, 'Programas Ejecutivos'))->toBeLessThan(strpos($html, 'Diplomas Avanzados'))
        ->and($html)->not->toContain('Liderazgo');
});

it('solo Microcredenciales agrupa por área; el resto de líneas es una lista plana por nombre', function () {
    [, $admin, $sophia] = linesCtx();
    $lider = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $rh = ProgramCategory::factory()->create(['name_es' => 'Recursos Humanos']);
    linesProgram('MC-001', 'microcredenciales', ['name_es' => 'Micro Liderazgo', 'category_id' => $lider->id]);
    linesProgram('MC-002', 'microcredenciales', ['name_es' => 'Micro Talento', 'category_id' => $rh->id]);
    $pe = linesProgram('PE-001', 'programas_ejecutivos', ['name_es' => 'Zeta Ejecutivo', 'category_id' => $lider->id]);
    linesProgram('PE-002', 'programas_ejecutivos', ['name_es' => 'Álgebra Ejecutiva', 'category_id' => $rh->id]);
    linesProgram('PE-003', 'programas_ejecutivos', ['name_es' => 'Mando Ejecutivo']);
    $sophia->programs()->attach($pe->id);

    $component = Livewire::actingAs($admin)->test(Agents::class);
    $blocks = collect($component->viewData('lineBlocks'))->keyBy('key');

    // Microcredenciales: subgrupos de área con sus acciones.
    expect($blocks['microcredenciales']['grouped'])->toBeTrue()
        ->and(collect($blocks['microcredenciales']['areas'])->pluck('label')->all())->toBe(['Liderazgo', 'Recursos Humanos'])
        ->and($blocks['microcredenciales']['rows'])->toBe([]);

    // Programas Ejecutivos: lista plana ordenada por nombre (sin acentos), sin áreas; contadores intactos.
    expect($blocks['programas_ejecutivos']['grouped'])->toBeFalse()
        ->and($blocks['programas_ejecutivos']['areas'])->toBe([])
        ->and(collect($blocks['programas_ejecutivos']['rows'])->pluck('code')->all())->toBe(['PE-002', 'PE-003', 'PE-001'])
        ->and($blocks['programas_ejecutivos']['programs_assigned'])->toBe(1)
        ->and($blocks['programas_ejecutivos']['programs_total'])->toBe(3);

    // En pantalla: la lista plana se ve sin desplegar nada y las acciones de área solo existen en MC.
    $html = $component->html();
    $peBlock = substr($html, (int) strpos($html, 'Programas Ejecutivos'));
    expect($peBlock)->toContain('Zeta Ejecutivo')->toContain('Álgebra Ejecutiva')
        ->not->toContain('Asignar toda el área')->not->toContain('toggleProgramAreaOpen')
        ->toContain('Asignar línea completa');
    expect(substr_count($html, 'Asignar toda el área'))->toBe(2); // una por área de Microcredenciales

    // El buscador filtra también la lista plana, y el interruptor sigue funcionando.
    $component->set('programSearch', 'Mando')->assertSee('Mando Ejecutivo')->assertDontSee('Zeta Ejecutivo')
        ->call('toggleProgram', $pe->id);
    expect($sophia->programs()->count())->toBe(0);
});

it('aislamiento: la lista plana de una línea no muestra programas de otra institución', function () {
    [$inst, $admin] = linesCtx();
    linesProgram('DA-001', 'diplomas_avanzados', ['name_es' => 'Diploma Propio']);
    $other = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($other->id, fn () => linesProgram('DA-002', 'diplomas_avanzados', ['name_es' => 'Diploma Ajeno']));
    app(CurrentInstitution::class)->set($inst->id);

    $component = Livewire::actingAs($admin)->test(Agents::class)->assertSee('Diploma Propio')->assertDontSee('Diploma Ajeno');
    $da = collect($component->viewData('lineBlocks'))->firstWhere('key', 'diplomas_avanzados');
    expect(collect($da['rows'])->pluck('code')->all())->toBe(['DA-001'])->and($da['programs_total'])->toBe(1);
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

    expect(fn () => app(LineAssignmentService::class)->assignLine($sophia, 'cursos_libres'))->toThrow(InvalidArgumentException::class);
});

it('el área dentro de Microcredenciales solo asigna los programas de esa línea', function () {
    [, $admin, $sophia] = linesCtx();
    $area = ProgramCategory::factory()->create(['name_es' => 'Liderazgo']);
    $mc = linesProgram('MC-001', 'microcredenciales', ['category_id' => $area->id]);
    linesProgram('PE-001', 'programas_ejecutivos', ['category_id' => $area->id]); // área heredada fuera de MC

    Livewire::actingAs($admin)->test(Agents::class)->call('assignProgramArea', (string) $area->id, 'microcredenciales');

    expect($sophia->programs()->pluck('programs.id')->all())->toBe([$mc->id]);
});

it('un no-Admin no puede asignar ni quitar líneas', function () {
    [$inst, , $sophia] = linesCtx();
    linesProgram('DA-001', 'diplomas_avanzados');
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);

    Livewire::actingAs($marketing)->test(Agents::class)->assertForbidden();
    expect($sophia->programs()->count())->toBe(0);
});
