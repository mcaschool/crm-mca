<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Modules\Ai\Livewire\Knowledge\Agents;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Support\SelectedAdvisor;
use Modules\Ai\Tests\Support\FakeAiChatClient;
use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Database\Seeders\ChatTreeSeeder;
use Modules\Chat\Services\MatcherService;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\ProgramInterestService;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

/**
 * Centro de Conocimiento — Bloque 4c: programas que cada asesor puede recomendar (pivote
 * bot_program). Emparejador, sus opciones y el saludo de Celia solo usan los asignados.
 *
 * @return array{0: Institution, 1: User, 2: Bot}
 */
function paCtx(): array
{
    $inst = Institution::factory()->create();
    app(CurrentInstitution::class)->set($inst->id);
    $admin = User::factory()->create(['institution_id' => $inst->id, 'role' => 'admin']);
    $celia = Bot::factory()->create(['status' => 'active', 'slug' => 'microcredenciales', 'assistant_name' => 'Celia', 'public_key' => str_repeat('p', 32)]);

    return [$inst, $admin, $celia];
}

/** @param  array<string, mixed>  $attrs */
function paProgram(string $code, array $attrs = []): Program
{
    return Program::factory()->create(['code' => $code, 'status' => 'active', 'level' => 'intermedio', 'goal' => 'ascenso'] + $attrs);
}

/** @return array<int, int> ids devueltos por el emparejador de $bot */
function paMatch(Bot $bot, int $areaId): array
{
    return app(MatcherService::class)->match($bot, null, null, [
        'area' => (string) $areaId, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo',
    ])->programs->pluck('id')->all();
}

// --- Emparejador -------------------------------------------------------------

it('el emparejador de Celia NO devuelve programas activos no asignados', function () {
    [, , $celia] = paCtx();
    $area = ProgramCategory::factory()->create();
    $mc = paProgram('MC-001', ['category_id' => $area->id]);
    $pe = paProgram('PE-001', ['category_id' => $area->id]); // activo, mismo filtro, NO asignado
    $celia->programs()->attach($mc->id);

    expect(paMatch($celia, $area->id))->toBe([$mc->id]);
});

it('un bot sin programas asignados no recomienda ninguno', function () {
    [, , $celia] = paCtx();
    $area = ProgramCategory::factory()->create();
    paProgram('MC-001', ['category_id' => $area->id]);

    $result = app(MatcherService::class)->match($celia, null, null, [
        'area' => (string) $area->id, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo',
    ]);

    expect($result->programs)->toBeEmpty()->and($result->tier)->toBe(4);
});

// --- Opciones del emparejador (widget) --------------------------------------

it('matcherOptions no ofrece áreas ni metas que solo existan en programas no asignados', function () {
    [, , $celia] = paCtx();
    $own = ProgramCategory::factory()->create(['name_es' => 'Area Propia']);
    $foreign = ProgramCategory::factory()->create(['name_es' => 'Area Ajena']);
    $celia->programs()->attach(paProgram('MC-001', ['category_id' => $own->id, 'goal' => 'ascenso'])->id);
    paProgram('PE-001', ['category_id' => $foreign->id, 'goal' => 'emprender']); // no asignado

    $res = test()->withHeaders(['X-Bot-Key' => $celia->public_key])->getJson('/api/v1/widget/matcher-options')->assertOk();

    expect(collect($res->json('area'))->pluck('value')->all())->toBe([$own->id])
        ->and(collect($res->json('meta'))->pluck('value')->all())->toBe(['ascenso']);
});

// --- Saludo de Celia ---------------------------------------------------------

it('el saludo no cita programas vistos que no están asignados al bot actual', function () {
    [$inst, , $celia] = paCtx();
    (new ChatTreeSeeder)->run();
    app()->instance(AiChatClient::class, new FakeAiChatClient('{"reply": "x", "action": "answer"}'));
    $h = ['X-Bot-Key' => $celia->public_key];

    $session = test()->withHeaders($h)->postJson('/api/v1/widget/session', [])->json('session_id');
    test()->withHeaders($h)->postJson('/api/v1/widget/lead', [
        'session_id' => $session, 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => true,
    ])->assertOk();

    app(CurrentInstitution::class)->runFor($inst->id, function () use ($celia) {
        $contact = Contact::query()->where('email', 'ana@example.com')->firstOrFail();
        $foreign = paProgram('PE-777', ['name_es' => 'Programa Ajeno Ejecutivo']);
        app(ProgramInterestService::class)->record($contact, $foreign, $celia->id, 'matcher');
    });

    // Solo vio un programa NO asignado: el saludo va sin la frase de programas vistos.
    $reply = (string) test()->withHeaders($h)->postJson('/api/v1/widget/celia/start', ['session_id' => $session])->json('reply');
    expect($reply)->not->toContain('Programa Ajeno Ejecutivo')->not->toContain('Vi que te interesó');

    // Con uno asignado visto, se cita ese (y el ajeno sigue sin aparecer).
    app(CurrentInstitution::class)->runFor($inst->id, function () use ($celia) {
        $contact = Contact::query()->where('email', 'ana@example.com')->firstOrFail();
        $own = paProgram('MC-555', ['name_es' => 'Programa Propio Micro']);
        $celia->programs()->attach($own->id);
        app(ProgramInterestService::class)->record($contact, $own, $celia->id, 'matcher');
    });
    $reply = (string) test()->withHeaders($h)->postJson('/api/v1/widget/celia/start', ['session_id' => $session])->json('reply');
    expect($reply)->toContain('Programa Propio Micro')->not->toContain('Programa Ajeno Ejecutivo');
});

// --- UI «Por agente»: asignación ---------------------------------------------

it('asigna y quita un programa con su interruptor', function () {
    [, $admin, $celia] = paCtx();
    SelectedAdvisor::set($celia->id);
    $p = paProgram('PE-001');

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleProgram', $p->id)->assertHasNoErrors();
    expect($celia->programs()->pluck('programs.id')->all())->toBe([$p->id]);

    Livewire::actingAs($admin)->test(Agents::class)->call('toggleProgram', $p->id);
    expect($celia->programs()->count())->toBe(0);
});

it('asigna y quita toda un área (y la de programas sin área)', function () {
    [, $admin, $celia] = paCtx();
    SelectedAdvisor::set($celia->id);
    $area = ProgramCategory::factory()->create();
    $a1 = paProgram('MC-001', ['category_id' => $area->id]);
    $a2 = paProgram('MC-002', ['category_id' => $area->id, 'status' => 'inactive']);
    $other = paProgram('MC-003', ['category_id' => ProgramCategory::factory()->create()->id]);
    $loose = paProgram('MC-004', ['category_id' => null]);

    Livewire::actingAs($admin)->test(Agents::class)->call('assignProgramArea', (string) $area->id);
    expect($celia->programs()->pluck('programs.id')->sort()->values()->all())->toBe([$a1->id, $a2->id]);

    Livewire::actingAs($admin)->test(Agents::class)->call('assignProgramArea', 'sin_area');
    expect($celia->programs()->pluck('programs.id')->all())->toContain($loose->id)->not->toContain($other->id);

    Livewire::actingAs($admin)->test(Agents::class)->call('detachProgramArea', (string) $area->id);
    expect($celia->programs()->pluck('programs.id')->all())->toBe([$loose->id]);
});

it('asigna y quita todos los resultados de la búsqueda (ej. PE-)', function () {
    [, $admin, $celia] = paCtx();
    SelectedAdvisor::set($celia->id);
    $pes = collect(range(1, 3))->map(fn (int $i) => paProgram('PE-00'.$i));
    $mc = paProgram('MC-001');

    Livewire::actingAs($admin)->test(Agents::class)
        ->set('programSearch', 'PE-')
        ->assertSee('Asignar todos los resultados')
        ->call('assignProgramResults');
    expect($celia->programs()->pluck('programs.id')->sort()->values()->all())->toBe($pes->pluck('id')->all());

    Livewire::actingAs($admin)->test(Agents::class)->set('programSearch', 'PE-')->call('detachProgramResults');
    expect($celia->programs()->count())->toBe(0);
    expect($mc->fresh())->not->toBeNull();
});

it('un no-Admin no accede ni puede asignar programas', function () {
    [$inst, , $celia] = paCtx();
    $marketing = User::factory()->create(['institution_id' => $inst->id, 'role' => 'marketing']);
    $p = paProgram('PE-001');

    test()->actingAs($marketing)->get('/centro-conocimiento/agentes')->assertForbidden();
    Livewire::actingAs($marketing)->test(Agents::class)->assertForbidden();
    expect($celia->programs()->count())->toBe(0);
    expect($p->fresh())->not->toBeNull();
});

// --- Migración de datos ------------------------------------------------------

it('la migración asigna a Celia (por slug) solo los MC- de su institución; nada a Lola', function () {
    [$inst, , $celia] = paCtx();
    $lola = Bot::factory()->create(['status' => 'active', 'slug' => 'lola', 'assistant_name' => 'Lola']);
    $mc1 = paProgram('MC-001');
    $mc2 = paProgram('MC-002', ['status' => 'inactive']);
    paProgram('PE-001');
    $trashed = paProgram('MC-003');
    $trashed->delete();

    // Otra institución con su propio MC-001: no debe acabar en la Celia de esta institución.
    $otherInst = Institution::factory()->create();
    app(CurrentInstitution::class)->runFor($otherInst->id, fn () => paProgram('MC-001'));

    $migration = require base_path('Modules/Ai/database/migrations/2026_09_28_120100_assign_microcredential_programs_to_celia.php');
    $migration->up();
    $migration->up(); // idempotente

    expect($celia->programs()->pluck('programs.id')->sort()->values()->all())->toBe([$mc1->id, $mc2->id]);
    expect($lola->programs()->count())->toBe(0);
});
