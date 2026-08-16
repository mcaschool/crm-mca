<?php

declare(strict_types=1);

use Modules\Catalog\Models\Program;
use Modules\Catalog\Models\ProgramCategory;
use Modules\Chat\Services\MatcherService;
use Modules\Chat\Support\LevelMapper;
use Modules\Core\Tenancy\CurrentInstitution;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Event;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\ProgramInterest;
use Modules\Institutions\Models\Bot;
use Modules\Institutions\Models\Institution;

function matcherSetup(): array
{
    $institution = Institution::factory()->create();
    app(CurrentInstitution::class)->set($institution->id);
    $bot = Bot::factory()->create();
    $contact = Contact::factory()->create();
    $tech = ProgramCategory::factory()->create(['name_es' => 'Tecnologia']);

    return [$bot, $contact, $tech];
}

function program(int $categoryId, string $level, string $goal, string $status = 'active', int $order = 0): Program
{
    return Program::factory()->create([
        'category_id' => $categoryId, 'level' => $level, 'goal' => $goal, 'status' => $status, 'display_order' => $order,
    ]);
}

// --- Mapeo de nivel (seniority + educacion) ---
it('mapea seniority+educacion a nivel (educacion solo afina a la baja)', function () {
    expect(LevelMapper::resolve('desarrollo', 'universitario_completo'))->toBe('intermedio');
    expect(LevelMapper::resolve('desarrollo', 'secundaria'))->toBe('inicial'); // baja un escalon
    expect(LevelMapper::resolve('directivo', 'posgrado'))->toBe('avanzado');
    expect(LevelMapper::resolve('directivo', 'tecnico'))->toBe('intermedio'); // baja de avanzado
    expect(LevelMapper::resolve('estudiante', 'secundaria'))->toBe('inicial'); // no baja de inicial
});

it('nivel 1: area+nivel+meta devuelve solo el match exacto y activo', function () {
    [$bot, $contact, $tech] = matcherSetup();
    $expected = program($tech->id, 'intermedio', 'ascenso');
    program($tech->id, 'inicial', 'ascenso');           // otro nivel
    program($tech->id, 'intermedio', 'ascenso', 'inactive'); // inactivo -> excluido
    $other = ProgramCategory::factory()->create();
    program($other->id, 'intermedio', 'ascenso');        // otra area

    $result = app(MatcherService::class)->match($bot, $contact, null, [
        'area' => (string) $tech->id, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo', 'motivacion' => 'ascender',
    ]);

    expect($result->tier)->toBe(1);
    expect($result->level)->toBe('intermedio');
    expect($result->programs->pluck('id')->all())->toBe([$expected->id]);
});

it('degrada a nivel 2 (area+meta cualquier nivel) cuando no hay match exacto de nivel', function () {
    [$bot, $contact, $tech] = matcherSetup();
    // No hay intermedio+ascenso, pero si inicial+ascenso.
    $p = program($tech->id, 'inicial', 'ascenso');

    $result = app(MatcherService::class)->match($bot, $contact, null, [
        'area' => (string) $tech->id, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo',
    ]);

    expect($result->tier)->toBe(2);
    expect($result->programs->pluck('id')->all())->toContain($p->id);
});

it('degrada a nivel 3 (mejores del area) cuando no hay meta coincidente', function () {
    [$bot, $contact, $tech] = matcherSetup();
    $p = program($tech->id, 'avanzado', 'direccion', 'active', 5);

    $result = app(MatcherService::class)->match($bot, $contact, null, [
        'area' => (string) $tech->id, 'meta' => 'emprender', 'seniority' => 'inicio', 'educacion' => 'secundaria',
    ]);

    expect($result->tier)->toBe(3);
    expect($result->programs->pluck('id')->all())->toContain($p->id);
});

it('NUNCA devuelve vacio: sin programas sugiere Celia', function () {
    [$bot, $contact, $tech] = matcherSetup();
    // Sin programas en el area.

    $result = app(MatcherService::class)->match($bot, $contact, null, [
        'area' => (string) $tech->id, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'posgrado',
    ]);

    expect($result->tier)->toBe(4);
    expect($result->suggestCelia())->toBeTrue();
});

it('registra lead + program_interest + evento used_matcher', function () {
    [$bot, $contact, $tech] = matcherSetup();
    program($tech->id, 'intermedio', 'ascenso');

    app(MatcherService::class)->match($bot, $contact, null, [
        'area' => (string) $tech->id, 'meta' => 'ascenso', 'seniority' => 'desarrollo', 'educacion' => 'universitario_completo', 'motivacion' => 'ascender',
    ]);

    expect(Lead::query()->where('contact_id', $contact->id)->where('source', 'widget_matcher')->count())->toBe(1);
    expect(ProgramInterest::query()->where('contact_id', $contact->id)->count())->toBeGreaterThan(0);
    $event = Event::query()->where('event_type', 'used_matcher')->first();
    expect($event)->not->toBeNull();
    expect($event->event_data['motivacion'])->toBe('ascender'); // motivacion como senal
});
